<?php

namespace App\Http\Controllers;

use App\Services\AudioMetadataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaUploadController extends Controller
{
    /**
     * Empfängt einen Chunk einer Datei und assembliert sie wenn alle Chunks angekommen sind.
     */
    public function store(Request $request, AudioMetadataService $metadata): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480',
            'file_id' => ['required', 'string', 'regex:/^[a-f0-9\-]{36}$/'],
            'chunk_index' => 'required|integer|min:0',
            'total_chunks' => 'required|integer|min:1|max:500',
            'file_name' => ['required', 'string', 'max:255', 'regex:/\.(mp3|m4a|ogg|wav|flac)$/i'],
        ]);

        $station = auth()->user()->currentStation();

        if (! $station) {
            return response()->json(['message' => __('No station selected.')], 422);
        }

        abort_unless(auth()->user()->mayWriteMediaOn($station), 403);

        $fileId = $request->input('file_id');
        $chunkIndex = (int) $request->input('chunk_index');
        $totalChunks = (int) $request->input('total_chunks');
        $fileName = basename($request->input('file_name'));
        $chunkDir = "chunks/{$fileId}";

        // Chunk speichern
        $request->file('file')->storeAs($chunkDir, "chunk_{$chunkIndex}", 'local');

        // Noch nicht alle Chunks angekommen?
        $stored = count(Storage::disk('local')->files($chunkDir));
        if ($stored < $totalChunks) {
            return response()->json([
                'done' => false,
                'stored' => $stored,
                'total' => $totalChunks,
            ]);
        }

        // Alle Chunks da → assemblieren
        $safeBase = Str::slug(pathinfo($fileName, PATHINFO_FILENAME));
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $uid = Str::random(8);
        $finalName = "{$uid}_{$safeBase}.{$ext}";
        // Uploads land in the tenant library, which every station of the tenant shares.
        $finalPath = "tenants/{$station->tenant_id}/media/{$finalName}";
        $absPath = Storage::disk('local')->path($finalPath);

        Storage::disk('local')->makeDirectory("tenants/{$station->tenant_id}/media");

        $handle = fopen($absPath, 'wb');
        for ($i = 0; $i < $totalChunks; $i++) {
            fwrite($handle, Storage::disk('local')->get("{$chunkDir}/chunk_{$i}"));
        }
        fclose($handle);

        Storage::disk('local')->deleteDirectory($chunkDir);

        // ID3-Metadaten auslesen
        $meta = $metadata->read($absPath);

        return response()->json([
            'done' => true,
            'path' => $finalPath,
            'title' => $meta['title'],
            'artist' => $meta['artist'],
            'album' => $meta['album'],
            'duration' => $meta['duration'],
        ]);
    }
}

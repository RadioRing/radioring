<?php

namespace App\Services;

use App\Jobs\AnalyzeMediaLoudnessJob;
use App\Models\MediaFile;
use App\Models\MediaFileVersion;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class MediaReplacementService
{
    public function __construct(private readonly AudioMetadataService $metadata) {}

    /**
     * Ersetzt den Inhalt einer Mediendatei durch einen frisch hochgeladenen Upload.
     *
     * Die alte Fassung wird NICHT ueberschrieben, sondern als MediaFileVersion
     * archiviert: bereits generierte Rundowns halten den alten Pfad als Snapshot und
     * spielen sie zu Ende. Erst eine Neu-Generierung des Rundowns greift auf die neue
     * Fassung zu. Lautheit und Dauer gehoeren zur Datei, nicht zum Titel, und werden
     * deshalb zurueckgesetzt bzw. neu gemessen.
     *
     * @param  string  $newPath  Bereits hochgeladener Pfad auf der local-Disk.
     * @param  bool  $adoptMetadata  Titel/Interpret/Album aus den ID3-Tags uebernehmen.
     */
    public function replace(MediaFile $file, string $newPath, ?string $originalFilename = null, ?User $user = null, bool $adoptMetadata = false): MediaFileVersion
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($newPath)) {
            throw new \RuntimeException("Ersatzdatei nicht gefunden: {$newPath}");
        }

        if ($newPath === $file->file_path) {
            throw new \RuntimeException('Die Ersatzdatei ist die bereits hinterlegte Datei.');
        }

        $version = MediaFileVersion::create([
            'media_file_id' => $file->id,
            'file_path' => $file->file_path,
            'original_filename' => $originalFilename,
            'duration_seconds' => $file->duration_seconds,
            'loudness_lufs' => $file->loudness_lufs,
            'loudness_true_peak' => $file->loudness_true_peak,
            'replaced_by_user_id' => $user?->id,
        ]);

        $meta = $this->metadata->read($disk->path($newPath));

        $attributes = [
            'file_path' => $newPath,
            'duration_seconds' => $meta['duration'] ?? null,
            'loudness_lufs' => null,
            'loudness_true_peak' => null,
            'loudness_measured_at' => null,
        ];

        if ($adoptMetadata) {
            $attributes['title'] = $meta['title'] ?: $file->title;
            $attributes['artist'] = $meta['artist'] ?: null;
            $attributes['album'] = $meta['album'] ?: null;
        }

        $file->update($attributes);

        // Lautheit der neuen Fassung offline messen – bis dahin spielt sie ohne Gain-Korrektur.
        AnalyzeMediaLoudnessJob::dispatch($file->id);

        return $version;
    }

    /**
     * Macht eine archivierte Fassung wieder zur aktuellen Datei. Die bisher aktuelle
     * Fassung wandert ihrerseits ins Archiv; Dauer und Lautheit kommen aus dem
     * Snapshot der wiederhergestellten Fassung, eine Neumessung entfaellt.
     */
    public function restore(MediaFileVersion $version, ?User $user = null): void
    {
        $file = $version->mediaFile;

        if (! Storage::disk('local')->exists($version->file_path)) {
            throw new \RuntimeException("Fassung nicht mehr vorhanden: {$version->file_path}");
        }

        MediaFileVersion::create([
            'media_file_id' => $file->id,
            'file_path' => $file->file_path,
            'original_filename' => $file->filename(),
            'duration_seconds' => $file->duration_seconds,
            'loudness_lufs' => $file->loudness_lufs,
            'loudness_true_peak' => $file->loudness_true_peak,
            'replaced_by_user_id' => $user?->id,
        ]);

        $file->update([
            'file_path' => $version->file_path,
            'duration_seconds' => $version->duration_seconds,
            'loudness_lufs' => $version->loudness_lufs,
            'loudness_true_peak' => $version->loudness_true_peak,
            'loudness_measured_at' => $version->loudness_lufs !== null ? now() : null,
        ]);

        $version->delete();

        if ($version->loudness_lufs === null) {
            AnalyzeMediaLoudnessJob::dispatch($file->id);
        }
    }
}

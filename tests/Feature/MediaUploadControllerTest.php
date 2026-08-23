<?php

use App\Models\Station;
use App\Models\User;
use App\Services\AudioMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);

    $this->mock(AudioMetadataService::class, function ($mock) {
        $mock->shouldReceive('read')->andReturn([
            'title' => 'ID3 Titel',
            'artist' => 'Künstler',
            'album' => 'Album X',
            'duration' => 180,
        ]);
    });
});

function makeChunkPayload(string $fileId, int $index, int $total, string $name = 'track.mp3'): array
{
    return [
        'file_id' => $fileId,
        'chunk_index' => $index,
        'total_chunks' => $total,
        'file_name' => $name,
    ];
}

test('single chunk upload assembles and returns metadata', function () {
    $fileId = Str::uuid()->toString();

    $response = $this->post(route('media.upload.chunk'), [
        ...makeChunkPayload($fileId, 0, 1),
        'file' => UploadedFile::fake()->create('track.mp3', 500, 'audio/mpeg'),
    ]);

    $response->assertOk()->assertJson([
        'done' => true,
        'title' => 'ID3 Titel',
        'artist' => 'Künstler',
        'album' => 'Album X',
        'duration' => 180,
    ]);

    $path = $response->json('path');
    expect($path)->toStartWith("tenants/{$this->station->tenant_id}/media/");
    Storage::disk('local')->assertExists($path);
});

test('intermediate chunks return done=false', function () {
    $fileId = Str::uuid()->toString();

    $response = $this->post(route('media.upload.chunk'), [
        ...makeChunkPayload($fileId, 0, 3),
        'file' => UploadedFile::fake()->create('track.mp3', 200, 'audio/mpeg'),
    ]);

    $response->assertOk()->assertJson(['done' => false]);
});

test('all chunks trigger assembly and cleanup temp dir', function () {
    $fileId = Str::uuid()->toString();

    foreach (range(0, 2) as $i) {
        $this->post(route('media.upload.chunk'), [
            ...makeChunkPayload($fileId, $i, 3),
            'file' => UploadedFile::fake()->create('track.mp3', 100, 'audio/mpeg'),
        ]);
    }

    Storage::disk('local')->assertMissing("chunks/{$fileId}");
});

test('unauthenticated request is rejected', function () {
    auth()->logout();

    $this->post(route('media.upload.chunk'), [
        ...makeChunkPayload(Str::uuid()->toString(), 0, 1),
        'file' => UploadedFile::fake()->create('track.mp3', 100, 'audio/mpeg'),
    ])->assertRedirect();
});

test('invalid file extension is rejected', function () {
    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('media.upload.chunk'), [
            'file_id' => Str::uuid()->toString(),
            'chunk_index' => 0,
            'total_chunks' => 1,
            'file_name' => 'virus.exe',
            'file' => UploadedFile::fake()->create('virus.exe', 100),
        ])->assertUnprocessable();
});

test('invalid file_id format is rejected', function () {
    $this->withHeaders(['Accept' => 'application/json'])
        ->post(route('media.upload.chunk'), [
            'file_id' => '../../../etc/passwd',
            'chunk_index' => 0,
            'total_chunks' => 1,
            'file_name' => 'track.mp3',
            'file' => UploadedFile::fake()->create('track.mp3', 100, 'audio/mpeg'),
        ])->assertUnprocessable();
});

<?php

use App\Jobs\WriteMediaTagsJob;
use App\Livewire\MediaLibrary\FileModal;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use App\Services\AudioMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

/**
 * Stands in for getID3: the original file reports $current, a rewritten one $rewritten.
 *
 * @param  array{title: ?string, artist: ?string, album: ?string, duration: ?int}  $current
 */
function fakeTagReader(array $current, ?int $rewrittenDuration = null): void
{
    test()->mock(AudioMetadataService::class, function ($mock) use ($current, $rewrittenDuration) {
        $mock->shouldReceive('read')->andReturnUsing(fn (string $path) => str_contains($path, '.retag.')
            ? [...$current, 'duration' => $rewrittenDuration ?? $current['duration']]
            : $current);
    });
}

/** Lets the faked ffmpeg produce its output file, the last argument of the command. */
function fakeFfmpeg(bool $succeeds = true): void
{
    Process::fake(function (PendingProcess $process) use ($succeeds) {
        if ($succeeds) {
            file_put_contents(end($process->command), 'rewritten audio');
        }

        return Process::result(exitCode: $succeeds ? 0 : 1, errorOutput: $succeeds ? '' : 'Invalid data');
    });
}

function storedMedia(string $path, array $attributes = []): MediaFile
{
    Storage::disk('local')->put($path, 'original audio');

    return MediaFile::withoutEvents(fn () => MediaFile::factory()->create([
        'tenant_id' => test()->station->tenant_id,
        'file_path' => $path,
        'title' => 'Neuer Titel',
        'artist' => 'Neuer Interpret',
        'album' => null,
        ...$attributes,
    ]));
}

test('editing title, artist or album queues the write-back', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);
    Queue::fake();

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->set('artist', 'Anderer Interpret')
        ->call('save')
        ->assertHasNoErrors();

    Queue::assertPushed(WriteMediaTagsJob::class, fn (WriteMediaTagsJob $job) => $job->mediaFileId === $file->id);
});

test('changes that do not touch the tags queue nothing', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id]);
    Queue::fake();

    $file->update(['loudness_lufs' => -14.2, 'fade_in' => true, 'type' => 'jingle']);

    Queue::assertNotPushed(WriteMediaTagsJob::class);
});

test('the job writes the panel values into the file through ffmpeg', function () {
    Storage::fake('local');
    $file = storedMedia('tenants/tags/media/song.mp3');
    fakeTagReader(['title' => 'Alter Titel', 'artist' => 'Neuer Interpret', 'album' => 'Altes Album', 'duration' => 180]);
    fakeFfmpeg();

    WriteMediaTagsJob::dispatchSync($file->id);

    Process::assertRan(fn (PendingProcess $process) => in_array('title=Neuer Titel', $process->command, true)
        && in_array('artist=Neuer Interpret', $process->command, true)
        && in_array('album=', $process->command, true)
        && in_array('-id3v2_version', $process->command, true)
        && in_array('copy', $process->command, true));

    expect(Storage::disk('local')->get('tenants/tags/media/song.mp3'))->toBe('rewritten audio')
        ->and(Storage::disk('local')->allFiles('tenants/tags/media'))->toBe(['tenants/tags/media/song.mp3']);
});

test('the job leaves a file alone whose tags already match', function () {
    Storage::fake('local');
    $file = storedMedia('tenants/tags/media/song.mp3');
    fakeTagReader(['title' => 'Neuer Titel', 'artist' => 'Neuer Interpret', 'album' => null, 'duration' => 180]);
    Process::fake();

    WriteMediaTagsJob::dispatchSync($file->id);

    Process::assertNothingRan();
});

test('a failed ffmpeg run keeps the original file', function () {
    Storage::fake('local');
    $file = storedMedia('tenants/tags/media/song.mp3');
    fakeTagReader(['title' => 'Alter Titel', 'artist' => null, 'album' => null, 'duration' => 180]);
    fakeFfmpeg(succeeds: false);

    WriteMediaTagsJob::dispatchSync($file->id);

    expect(Storage::disk('local')->get('tenants/tags/media/song.mp3'))->toBe('original audio');
});

test('a rewritten file with a different length is thrown away', function () {
    Storage::fake('local');
    $file = storedMedia('tenants/tags/media/song.mp3');
    fakeTagReader(['title' => 'Alter Titel', 'artist' => null, 'album' => null, 'duration' => 180], rewrittenDuration: 12);
    fakeFfmpeg();

    WriteMediaTagsJob::dispatchSync($file->id);

    expect(Storage::disk('local')->get('tenants/tags/media/song.mp3'))->toBe('original audio')
        ->and(Storage::disk('local')->allFiles('tenants/tags/media'))->toBe(['tenants/tags/media/song.mp3']);
});

test('formats ffmpeg cannot tag safely are skipped', function () {
    Storage::fake('local');
    $file = storedMedia('tenants/tags/media/take.wav');
    fakeTagReader(['title' => 'Alter Titel', 'artist' => null, 'album' => null, 'duration' => 180]);
    Process::fake();

    WriteMediaTagsJob::dispatchSync($file->id);

    Process::assertNothingRan();
});

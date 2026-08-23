<?php

use App\Models\GeneratedPlaylist;
use App\Models\HourGridSlot;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Station;
use App\Models\StationOutput;
use App\Models\StationStream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // docker/.env sichern – das Command überschreibt sie
    $this->dockerEnvPath = base_path('docker/.env');
    $this->dockerEnvBackup = is_file($this->dockerEnvPath) ? file_get_contents($this->dockerEnvPath) : null;

    $this->station = Station::factory()->create(['user_id' => User::factory()]);

    $this->playlist = Playlist::create([
        'station_id' => $this->station->id,
        'name' => 'Local Test',
        'playback_mode' => 'sequential',
    ]);

    $file = MediaFile::factory()->create([
        'tenant_id' => $this->station->tenant_id,
        'type' => 'music',
        'title' => 'Track',
        'file_path' => "tenants/{$this->station->tenant_id}/media/track.mp3",
        'duration_seconds' => 180,
    ]);

    PlaylistItem::create([
        'playlist_id' => $this->playlist->id,
        'media_file_id' => $file->id,
        'position' => 0,
        'type' => 'music',
        'title' => 'Track',
        'duration_seconds' => 180,
    ]);
});

afterEach(function () {
    if ($this->dockerEnvBackup !== null) {
        file_put_contents($this->dockerEnvPath, $this->dockerEnvBackup);
    }
});

test('command configures output, stream and a current-hour rundown', function () {
    $this->artisan('radioring:prepare-local-stream', ['station' => $this->station->slug])
        ->assertSuccessful();

    $output = StationOutput::where('station_id', $this->station->id)->first();
    expect($output)->not->toBeNull();
    expect($output->host)->toBe('icecast');
    expect($output->mount)->toBe('/'.$this->station->slug);
    expect($output->enabled)->toBeTrue();

    $stream = StationStream::where('station_id', $this->station->id)->first();
    expect($stream)->not->toBeNull();
    expect($stream->container_name)->toBe('radioring-'.$this->station->slug);

    // Fallback-Slot für die aktuelle Stunde wurde angelegt
    $slot = HourGridSlot::where('station_id', $this->station->id)
        ->where('weekday', now()->dayOfWeekIso - 1)
        ->where('hour', now()->hour)
        ->first();
    expect($slot)->not->toBeNull();
    expect($slot->playlist_id)->toBe($this->playlist->id);

    // Rundown für heute + aktuelle Stunde ist ready
    $rundown = GeneratedPlaylist::where('station_id', $this->station->id)
        ->whereDate('broadcast_date', today())
        ->where('broadcast_hour', now()->hour)
        ->first();
    expect($rundown)->not->toBeNull();
    expect($rundown->status)->toBe('ready');
});

test('command writes docker env with station credentials', function () {
    $this->artisan('radioring:prepare-local-stream', ['station' => $this->station->slug])
        ->assertSuccessful();

    $content = file_get_contents(base_path('docker/.env'));

    expect($content)
        ->toContain('STATION_SLUG='.$this->station->slug)
        ->toContain('STATION_TOKEN='.$this->station->api_token)
        ->toContain('LIQUIDSOAP_API_URL=http://host.docker.internal:8000');
});

test('command writes redis relay config so the skip command reaches the container', function () {
    config([
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => 6379,
        'radioring.control_channel' => 'radioring_station_control',
    ]);

    $this->artisan('radioring:prepare-local-stream', ['station' => $this->station->slug])
        ->assertSuccessful();

    $content = file_get_contents(base_path('docker/.env'));

    expect($content)
        ->toContain('CONTAINER_NAME=radioring-'.$this->station->slug)
        ->toContain('CONTROL_CHANNEL=radioring_station_control')
        // localhost wird für die Container-Sicht auf host.docker.internal umgeschrieben
        ->toContain('REDIS_HOST=host.docker.internal')
        ->toContain('REDIS_PORT=6379');
});

test('command respects explicit playlist option for fallback slot', function () {
    $other = Playlist::create([
        'station_id' => $this->station->id,
        'name' => 'Chosen One',
        'playback_mode' => 'sequential',
    ]);

    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'title' => 'X', 'file_path' => "tenants/{$this->station->tenant_id}/media/x.mp3", 'duration_seconds' => 60]);
    PlaylistItem::create(['playlist_id' => $other->id, 'media_file_id' => $file->id, 'position' => 0, 'type' => 'music', 'title' => 'X', 'duration_seconds' => 60]);

    $this->artisan('radioring:prepare-local-stream', [
        'station' => $this->station->slug,
        '--playlist' => 'Chosen One',
    ])->assertSuccessful();

    $slot = HourGridSlot::where('station_id', $this->station->id)
        ->where('hour', now()->hour)
        ->first();

    expect($slot->playlist_id)->toBe($other->id);
});

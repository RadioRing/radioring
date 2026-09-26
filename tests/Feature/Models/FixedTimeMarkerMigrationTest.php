<?php

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Rolls the migration back, writes legacy rows, then migrates forward again.
 */
beforeEach(function () {
    $this->migration = require database_path('migrations/2026_09_26_115012_replace_start_mode_with_fixed_time_markers.php');
    $this->migration->down();

    $this->station = Station::factory()->create(['user_id' => User::factory()]);
    $this->now = now();
});

function legacyPlaylist(string $kind = 'playlist', string $startMode = 'soft'): int
{
    return DB::table('playlists')->insertGetId([
        'station_id' => test()->station->id,
        'name' => 'Legacy',
        'kind' => $kind,
        'playback_mode' => 'sequential',
        'start_mode' => $startMode,
        'created_at' => test()->now,
        'updated_at' => test()->now,
    ]);
}

function legacyItem(int $playlistId, int $position, string $type, ?int $offset = null): int
{
    return DB::table('playlist_items')->insertGetId([
        'playlist_id' => $playlistId,
        'position' => $position,
        'type' => $type,
        'title' => ucfirst($type),
        'relative_offset_seconds' => $offset,
        'created_at' => test()->now,
        'updated_at' => test()->now,
    ]);
}

/** @return list<array{type: string, offset: ?int, mode: ?string}> */
function itemsOf(int $playlistId): array
{
    return DB::table('playlist_items')
        ->where('playlist_id', $playlistId)
        ->orderBy('position')
        ->get()
        ->map(fn ($item) => ['type' => $item->type, 'offset' => $item->relative_offset_seconds, 'mode' => $item->fixed_mode])
        ->all();
}

test('a hard start playlist gets a hard 00:00 marker on top', function () {
    $playlistId = legacyPlaylist(startMode: 'hard');
    legacyItem($playlistId, 0, 'news_weather');
    legacyItem($playlistId, 1, 'fill');

    $this->migration->up();

    expect(itemsOf($playlistId))->toBe([
        ['type' => 'marker', 'offset' => 0, 'mode' => 'hard'],
        ['type' => 'news_weather', 'offset' => null, 'mode' => null],
        ['type' => 'fill', 'offset' => null, 'mode' => null],
    ]);
});

test('an element timestamp becomes a soft marker in front of it', function () {
    $playlistId = legacyPlaylist();
    legacyItem($playlistId, 0, 'music');
    legacyItem($playlistId, 1, 'fill');
    legacyItem($playlistId, 2, 'adbreak', 900);
    legacyItem($playlistId, 3, 'jingle');

    $this->migration->up();

    expect(itemsOf($playlistId))->toBe([
        ['type' => 'music', 'offset' => null, 'mode' => null],
        ['type' => 'fill', 'offset' => null, 'mode' => null],
        ['type' => 'marker', 'offset' => 900, 'mode' => 'soft'],
        ['type' => 'adbreak', 'offset' => null, 'mode' => null],
        ['type' => 'jingle', 'offset' => null, 'mode' => null],
    ]);
});

test('timestamps inside a container are dropped, as they never applied', function () {
    $containerId = legacyPlaylist(kind: 'container');
    legacyItem($containerId, 0, 'music', 600);

    $this->migration->up();

    expect(itemsOf($containerId))->toBe([
        ['type' => 'music', 'offset' => null, 'mode' => null],
    ]);
});

test('a generated hard rundown carries the hard start on its first item', function () {
    $rundownId = DB::table('generated_playlists')->insertGetId([
        'station_id' => $this->station->id,
        'broadcast_date' => '2026-05-12',
        'broadcast_hour' => 13,
        'status' => 'ready',
        'start_mode' => 'hard',
        'created_at' => $this->now,
        'updated_at' => $this->now,
    ]);

    foreach ([0, 1] as $position) {
        DB::table('generated_playlist_items')->insert([
            'generated_playlist_id' => $rundownId,
            'position' => $position,
            'title' => "Item {$position}",
            'source_type' => 'template_item',
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
    }

    DB::table('liquidsoap_states')->insert([
        'station_id' => $this->station->id,
        'current_item_position' => 0,
        'hard_start_committed_rundown_id' => $rundownId,
        'created_at' => $this->now,
        'updated_at' => $this->now,
    ]);

    $this->migration->up();

    $items = DB::table('generated_playlist_items')->where('generated_playlist_id', $rundownId)->orderBy('position')->get();

    expect($items[0]->fixed_mode)->toBe('hard')
        ->and(substr($items[0]->fixed_at, 0, 19))->toBe('2026-05-12 13:00:00')
        ->and($items[1]->fixed_at)->toBeNull()
        ->and(substr(DB::table('liquidsoap_states')->value('committed_hard_time'), 0, 19))->toBe('2026-05-12 13:00:00');
});

test('rolling back turns the markers into the old settings again', function () {
    $this->migration->up();

    $playlistId = DB::table('playlists')->insertGetId([
        'station_id' => $this->station->id, 'name' => 'New', 'kind' => 'playlist', 'playback_mode' => 'sequential',
        'created_at' => $this->now, 'updated_at' => $this->now,
    ]);
    DB::table('playlist_items')->insert([
        ['playlist_id' => $playlistId, 'position' => 0, 'type' => 'marker', 'title' => 'Fixzeit', 'relative_offset_seconds' => 0, 'fixed_mode' => 'hard', 'created_at' => $this->now, 'updated_at' => $this->now],
        ['playlist_id' => $playlistId, 'position' => 1, 'type' => 'music', 'title' => 'Music', 'relative_offset_seconds' => null, 'fixed_mode' => null, 'created_at' => $this->now, 'updated_at' => $this->now],
        ['playlist_id' => $playlistId, 'position' => 2, 'type' => 'marker', 'title' => 'Fixzeit', 'relative_offset_seconds' => 900, 'fixed_mode' => 'soft', 'created_at' => $this->now, 'updated_at' => $this->now],
        ['playlist_id' => $playlistId, 'position' => 3, 'type' => 'adbreak', 'title' => 'Adbreak', 'relative_offset_seconds' => null, 'fixed_mode' => null, 'created_at' => $this->now, 'updated_at' => $this->now],
    ]);

    $this->migration->down();

    expect(DB::table('playlists')->where('id', $playlistId)->value('start_mode'))->toBe('hard')
        ->and(DB::table('playlist_items')->where('playlist_id', $playlistId)->orderBy('position')->pluck('relative_offset_seconds', 'type')->all())
        ->toBe(['music' => null, 'adbreak' => 900]);

    // Restore the current schema.
    $this->migration->up();
});

test('a run aborted after its first schema changes can be resumed', function () {
    $playlistId = legacyPlaylist(startMode: 'hard');
    legacyItem($playlistId, 0, 'adbreak', 900);

    // State after an aborted run on MySQL: DDL kept, data conversion rolled back.
    Schema::table('playlist_items', fn ($table) => $table->string('fixed_mode', 8)->nullable());
    Schema::table('liquidsoap_states', fn ($table) => $table->dateTime('committed_hard_time')->nullable());

    $this->migration->up();

    expect(itemsOf($playlistId))->toBe([
        ['type' => 'marker', 'offset' => 0, 'mode' => 'hard'],
        ['type' => 'marker', 'offset' => 900, 'mode' => 'soft'],
        ['type' => 'adbreak', 'offset' => null, 'mode' => null],
    ]);
});

test('running the migration again changes nothing', function () {
    $playlistId = legacyPlaylist(startMode: 'hard');
    legacyItem($playlistId, 0, 'adbreak', 900);

    $this->migration->up();
    $once = itemsOf($playlistId);
    $this->migration->up();

    expect(itemsOf($playlistId))->toBe($once)->toHaveCount(3);
});

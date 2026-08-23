<?php

use App\Models\ExternalSource;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->station = Station::factory()->create();
    $this->playlist = $this->station->playlists()->create(['name' => 'P', 'playback_mode' => 'sequential']);
});

test('it converts a legacy url item into an external source reference', function () {
    $item = $this->playlist->items()->create([
        'position' => 0, 'type' => 'url', 'title' => 'Syndi', 'url' => 'https://example.com/a.mp3', 'duration_seconds' => 300,
    ]);

    $this->artisan('external-sources:migrate-legacy')->assertSuccessful();

    $item->refresh();
    expect($item->type)->toBe('external')
        ->and($item->url)->toBeNull()
        ->and($item->external_source_id)->not->toBeNull();

    $source = ExternalSource::find($item->external_source_id);
    expect($source->kind)->toBe('url')
        ->and($source->url)->toBe('https://example.com/a.mp3')
        ->and($source->expected_duration_seconds)->toBe(300);
});

test('it shares a single source across news items of the same station', function () {
    $this->playlist->items()->create(['position' => 0, 'type' => 'news', 'title' => 'N1']);
    $this->playlist->items()->create(['position' => 1, 'type' => 'news', 'title' => 'N2']);

    $this->artisan('external-sources:migrate-legacy')->assertSuccessful();

    expect(ExternalSource::where('station_id', $this->station->id)->where('kind', 'news')->count())->toBe(1);
    expect($this->playlist->items()->where('type', 'external')->count())->toBe(2);
});

test('a dry run changes nothing', function () {
    $item = $this->playlist->items()->create(['position' => 0, 'type' => 'url', 'title' => 'Syndi', 'url' => 'https://example.com/a.mp3']);

    $this->artisan('external-sources:migrate-legacy --dry-run')->assertSuccessful();

    expect($item->fresh()->type)->toBe('url');
    expect(ExternalSource::count())->toBe(0);
});

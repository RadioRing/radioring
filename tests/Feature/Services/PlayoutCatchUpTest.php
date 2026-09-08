<?php

use App\Models\GeneratedPlaylist;
use App\Models\LiquidsoapState;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\StationLog;
use App\Models\User;
use App\Services\LiquidsoapStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->station = Station::factory()->create(['user_id' => User::factory()]);
    $this->service = app(LiquidsoapStateService::class);
});

/**
 * Rundown mit fortlaufenden Items; jedes Item bekommt seine geplante Sendezeit.
 */
function catchUpRundown(Station $station, int $hour, string $startMode, int $count, int $duration = 1200): GeneratedPlaylist
{
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => $hour,
        'status' => 'ready',
        'start_mode' => $startMode,
    ]);

    $at = today()->setTime($hour, 0, 0);

    foreach (range(0, $count - 1) as $position) {
        $file = MediaFile::factory()->create([
            'tenant_id' => $station->tenant_id,
            'type' => 'music',
            'file_path' => "tenants/{$station->tenant_id}/media/h{$hour}-{$position}.mp3",
            'title' => sprintf('H%02d-%d', $hour, $position),
        ]);

        $rundown->items()->create([
            'position' => $position,
            'media_file_id' => $file->id,
            'source_type' => 'template_item',
            'title' => sprintf('H%02d-%d', $hour, $position),
            'duration_seconds' => $duration,
            'absolute_broadcast_at' => $at->copy()->addSeconds($position * $duration),
        ]);
    }

    return $rundown;
}

test('pullNextItem catches up to the current hour instead of working off a backlog', function () {
    $this->travelTo(today()->setTime(13, 47, 0));

    // Das Programm haengt zurueck: der Cursor arbeitet noch die 11-Uhr-Stunde ab.
    $behind = catchUpRundown($this->station, 11, 'soft', 1);
    // Zwischenstunde – wird uebersprungen, nicht nachgeholt.
    catchUpRundown($this->station, 12, 'soft', 3);
    // Laufende Stunde: Items um 13:00, 13:20, 13:40 → um 13:47 ist Position 2 dran.
    $current = catchUpRundown($this->station, 13, 'soft', 3);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $behind->id,
        'current_item_position' => 1,
    ]);

    $item = $this->service->pullNextItem($this->station);

    expect($item->title)->toBe('H13-2');

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($current->id)
        ->and($state->current_item_position)->toBe(3);

    expect(StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_SCHEDULE_CATCH_UP)->count())->toBe(1);
});

test('pullNextItem starts a punctual next hour at position 0 without logging a catch-up', function () {
    $this->travelTo(today()->setTime(13, 0, 30));

    $previous = catchUpRundown($this->station, 12, 'soft', 1);
    catchUpRundown($this->station, 13, 'soft', 3);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $previous->id,
        'current_item_position' => 1,
    ]);

    $item = $this->service->pullNextItem($this->station);

    // Ein kleiner Ueberhang darf die Stunde weiterhin von vorne starten – sonst
    // fielen die ersten Elemente (z. B. Nachrichten) jeder Stunde weg.
    expect($item->title)->toBe('H13-0');

    expect(StationLog::where('station_id', $this->station->id)
        ->where('event', StationLog::EVENT_SCHEDULE_CATCH_UP)->count())->toBe(0);
});

test('a hard rundown does not take over in the middle of its hour', function () {
    $this->travelTo(today()->setTime(13, 57, 0));

    // Rueckstand: der Cursor steht in einer laengst vergangenen Stunde, waehrend die
    // laufende Stunde hart startet. Frueher sprang der Cursor sofort auf deren
    // Position 0 – die Nachrichten liefen dann z. B. um 13:57 an.
    $behind = catchUpRundown($this->station, 11, 'soft', 2);
    catchUpRundown($this->station, 13, 'hard', 3);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $behind->id,
        'current_item_position' => 0,
    ]);

    $item = $this->service->pullNextItem($this->station);

    expect($item->title)->toBe('H11-0');

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($behind->id);
});

test('a hard rundown takes over at the top of its hour', function () {
    $this->travelTo(today()->setTime(13, 0, 20));

    $previous = catchUpRundown($this->station, 12, 'soft', 2);
    $hard = catchUpRundown($this->station, 13, 'hard', 2);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $previous->id,
        'current_item_position' => 0,
    ]);

    $item = $this->service->pullNextItem($this->station);

    expect($item->title)->toBe('H13-0');

    $state = LiquidsoapState::where('station_id', $this->station->id)->first();
    expect($state->current_rundown_id)->toBe($hard->id);
});

test('a hard rundown is still not pulled before its hour', function () {
    $this->travelTo(today()->setTime(12, 57, 0));

    $previous = catchUpRundown($this->station, 12, 'soft', 1);
    catchUpRundown($this->station, 13, 'hard', 2);

    LiquidsoapState::create([
        'station_id' => $this->station->id,
        'current_rundown_id' => $previous->id,
        'current_item_position' => 1,
    ]);

    expect($this->service->pullNextItem($this->station))->toBeNull();
});

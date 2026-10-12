<?php

use App\Livewire\MediaLibrary\FileModal;
use App\Livewire\Station\Edit;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use App\Services\LiquidsoapCommandService;
use App\Services\LiquidsoapScriptGenerator;
use App\Services\MediaReplacementService;
use App\Services\RundownGeneratorService;
use App\Support\PlaylistElements\ElementLengths;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** A station whose generated script does not depend on random factory values. */
function snapshotStation(array $attributes = []): Station
{
    config(['radioring.liquidsoap_api_url' => 'https://panel.example']);

    return Station::factory()->create([
        'slug' => 'snapshot-fm',
        'api_token' => 'snapshot-token',
        ...$attributes,
    ]);
}

/** A ready rundown for the current hour with one element, as /next hands it out. */
function segueRundownItem(Station $station, ?MediaFile $file, array $itemAttributes = []): GeneratedPlaylistItem
{
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => now()->hour,
        'status' => 'ready',
    ]);

    return GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => $file?->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => $file?->title ?? 'Element',
        ...$itemAttributes,
    ]);
}

function segueFile(Station $station, array $attributes = []): MediaFile
{
    return MediaFile::factory()->create([
        'tenant_id' => $station->tenant_id,
        'file_path' => "tenants/{$station->tenant_id}/media/song.mp3",
        'duration_seconds' => 200.04,
        'segue_seconds' => 192.0,
        ...$attributes,
    ]);
}

function nextBody(Station $station): string
{
    return test()->withToken($station->api_token)->get("/api/liquidsoap/{$station->slug}/next")->getContent();
}

// ── Script ──────────────────────────────────────────────────────────────────

test('without segues the generated script stays exactly what it was before segues existed', function () {
    expect(app(LiquidsoapScriptGenerator::class)->generate(snapshotStation()))->toMatchSnapshot();
});

test('with segues the programme runs through cross() with the settings from the spike', function () {
    $script = app(LiquidsoapScriptGenerator::class)->generate(snapshotStation(['segues_enabled' => true]));

    expect($script)
        ->toContain('crossed = cross(id="segue", duration=0., override_duration="liq_cross_duration", minimum=0., conservative=false, segue_transition, faded)')
        ->toContain('program = amplify({cut_gain()}, override="liq_hard_cut_gain", crossed)')
        // No overlap into an element that has to start clean.
        ->toContain('if b.metadata["radioring_hard"] == "1" or overlap <= 0. then')
        ->toContain('add(normalize=false, [b.source, fade.out(duration=overlap, a.source)])')
        // The guard that stops a running transition on a hard cut.
        ->toContain('switch(track_sensitive=false, [({not(cut_done())}, transition)])');
});

test('with segues a hard cut stops the transition and holds the programme until a new element', function (float $fadeOut) {
    config(['radioring.hard_cut_fade_out_seconds' => $fadeOut]);

    $script = app(LiquidsoapScriptGenerator::class)->generate(snapshotStation(['segues_enabled' => true]));

    $cutNow = str($script)->after('def hard_cut_now() =')->before("\nend\n")->toString();

    expect($cutNow)
        ->toContain('cut_done := true')
        ->toContain('cut_hold := true')
        ->toContain('cut_gain := 0.')
        ->toContain('source.set_queue([])')
        ->toContain('source.skip()')
        ->toContain('thread.run(delay=5.0000, fun () -> if cut_serial() == serial then release_cut_hold("timeout") end)')
        ->not->toContain('cut_gain := 1.');

    expect($script)
        ->toContain('program.on_metadata(on_program_item)')
        ->toContain('if cut_hold() and (m["radioring_hard"] == "1" or not known) then')
        ->toContain('server.register(namespace="radioring"')
        ->and(substr_count($script, 'def flush_and_skip(arg) ='))->toBe(1);
})->with([
    'with fade' => [0.8],
    'immediate cut' => [0.0],
]);

// ── Media file ──────────────────────────────────────────────────────────────

test('the segue overlap and the air duration follow from segue point and cue points', function (array $attributes, ?float $overlap, ?int $withSegues, ?int $withoutSegues) {
    $file = new MediaFile(['duration_seconds' => 200.0, ...$attributes]);

    expect($file->segueOverlap())->toBe($overlap)
        ->and($file->airDuration(true))->toBe($withSegues)
        ->and($file->airDuration(false))->toBe($withoutSegues);
})->with([
    'no segue point' => [[], null, 200, 200],
    'segue before the end' => [['segue_seconds' => 194.5], 5.5, 195, 200],
    'segue and cue points' => [['cue_in_seconds' => 4.0, 'cue_out_seconds' => 190.0, 'segue_seconds' => 186.0], 4.0, 182, 186],
    'segue at cue out' => [['cue_out_seconds' => 190.0, 'segue_seconds' => 190.0], null, 190, 190],
    'segue before cue in' => [['cue_in_seconds' => 10.0, 'segue_seconds' => 5.0], null, 190, 190],
    'unknown length' => [['duration_seconds' => null, 'segue_seconds' => 100.0], null, null, null],
]);

test('the segue annotations always carry an explicit cue out', function () {
    $file = new MediaFile(['duration_seconds' => 200.04, 'segue_seconds' => 192.0]);

    expect($file->segueAnnotations())->toBe(['liq_cross_duration' => '8.04', 'liq_cue_out' => '200.04'])
        ->and((new MediaFile(['duration_seconds' => 200.0]))->segueAnnotations())->toBe([]);
});

// ── /next ───────────────────────────────────────────────────────────────────

test('next sends the segue only to a station with segues', function (bool $segues) {
    Storage::fake('local');
    $station = Station::factory()->create(['segues_enabled' => $segues]);
    segueRundownItem($station, segueFile($station));

    $body = nextBody($station);

    if ($segues) {
        expect($body)->toContain('liq_cross_duration="8.04"')->toContain('liq_cue_out="200.04"');
    } else {
        expect($body)->not->toContain('liq_cross_duration')->not->toContain('liq_cue_out');
    }
})->with(['segues on' => [true], 'segues off' => [false]]);

test('next marks elements that must start clean on a station with segues', function (array $item, ?string $type, bool $hard) {
    Storage::fake('local');
    $station = Station::factory()->create(['segues_enabled' => true]);
    $file = $type === null ? null : segueFile($station, ['type' => $type, 'segue_seconds' => null]);
    segueRundownItem($station, $file, $item);

    expect(str_contains(nextBody($station), 'radioring_hard="1"'))->toBe($hard);
})->with([
    'plain track' => [[], 'music', false],
    'hard fixed time' => [['fixed_mode' => 'hard', 'fixed_at' => now()], 'music', true],
    'soft fixed time' => [['fixed_mode' => 'soft', 'fixed_at' => now()], 'music', false],
    'voice track' => [[], 'voicetrack', true],
    'ad break' => [['source_type' => 'adbreak'], null, true],
]);

test('next never marks hard starts on a station without segues', function () {
    Storage::fake('local');
    $station = Station::factory()->create(['segues_enabled' => false]);
    segueRundownItem($station, segueFile($station), ['fixed_mode' => 'hard', 'fixed_at' => now()]);

    expect(nextBody($station))->not->toContain('radioring_hard');
});

// ── Timing ──────────────────────────────────────────────────────────────────

test('a rundown on a segueing station places the next element at the segue point', function (bool $segues, int $expected) {
    $station = Station::factory()->create(['segues_enabled' => $segues]);
    $playlist = $station->playlists()->create(['name' => 'Morning', 'playback_mode' => 'sequential']);
    $slot = $station->hourGridSlots()->create(['weekday' => 0, 'hour' => 10, 'playlist_id' => $playlist->id]);

    $file = segueFile($station, ['duration_seconds' => 200.0, 'cue_in_seconds' => 5.0, 'segue_seconds' => 190.0]);
    $playlist->items()->create(['position' => 0, 'type' => 'music', 'title' => $file->title, 'media_file_id' => $file->id]);
    $playlist->items()->create(['position' => 1, 'type' => 'music', 'title' => $file->title, 'media_file_id' => $file->id]);

    $rundown = app(RundownGeneratorService::class)->generate($station, $slot, Carbon::parse('2026-05-12'));
    [$first, $second] = $rundown->items->sortBy('position')->values()->all();

    expect($first->duration_seconds)->toBe($expected)
        ->and((int) $first->absolute_broadcast_at->diffInSeconds($second->absolute_broadcast_at))->toBe($expected);
})->with([
    'segues on: cue in to segue' => [true, 185],
    'segues off: cue in to the end' => [false, 195],
]);

test('a fill on a segueing station plans with the segue lengths', function () {
    $station = Station::factory()->create(['segues_enabled' => true]);

    foreach (range(1, 10) as $n) {
        segueFile($station, [
            'artist' => "Artist {$n}",
            'album' => "Album {$n}",
            'file_path' => "tenants/{$station->tenant_id}/media/{$n}.mp3",
            'duration_seconds' => 310.0,
            'segue_seconds' => 300.0,
        ]);
    }

    $playlist = $station->playlists()->create(['name' => 'Fill', 'playback_mode' => 'sequential']);
    $playlist->items()->create(['position' => 0, 'type' => 'fill', 'title' => 'Fill', 'fill_max_duration_seconds' => 1800]);
    $slot = $station->hourGridSlots()->create(['weekday' => 0, 'hour' => 10, 'playlist_id' => $playlist->id]);

    $rundown = app(RundownGeneratorService::class)->generate($station, $slot, Carbon::parse('2026-05-12'));

    expect($rundown->items)->toHaveCount(6)
        ->and($rundown->items->pluck('duration_seconds')->unique()->all())->toBe([300]);
});

test('the random element estimate uses the segue point on a segueing station', function (bool $segues, int $expected) {
    $station = Station::factory()->create(['segues_enabled' => $segues]);
    segueFile($station, ['duration_seconds' => 200.0, 'segue_seconds' => 180.0]);
    segueFile($station, ['duration_seconds' => 300.0, 'segue_seconds' => null, 'file_path' => 'x.mp3']);

    $playlist = $station->playlists()->create(['name' => 'Random', 'playback_mode' => 'sequential']);
    $random = $playlist->items()->create(['position' => 0, 'type' => 'random', 'title' => 'Random']);

    expect((new ElementLengths($station))->of($random))->toBe($expected);
})->with([
    'segues on: (180 + 300) / 2' => [true, 240],
    'segues off: (200 + 300) / 2' => [false, 250],
]);

// ── Editing ─────────────────────────────────────────────────────────────────

test('the segue point is saved and loaded in the file dialog', function () {
    $user = User::factory()->create();
    $station = Station::factory()->create(['user_id' => $user->id, 'segues_enabled' => true]);
    $file = segueFile($station, ['segue_seconds' => null, 'waveform_path' => 'w.json']);

    $this->actingAs($user);
    session(['current_station_id' => $station->id]);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->assertSet('segue', '')
        ->set('segue', '193.456')
        ->call('save')
        ->assertHasNoErrors();

    expect($file->fresh()->segue_seconds)->toBe(193.46);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->assertSet('segue', '193.46')
        ->set('segue', '')
        ->call('save');

    expect($file->fresh()->segue_seconds)->toBeNull();
});

test('the segue point must lie between cue in and cue out', function (array $fields) {
    $user = User::factory()->create();
    $station = Station::factory()->create(['user_id' => $user->id]);
    $file = segueFile($station, ['segue_seconds' => null, 'waveform_path' => 'w.json']);

    $this->actingAs($user);
    session(['current_station_id' => $station->id]);

    $component = Livewire::test(FileModal::class)->call('open', $file->id);

    foreach ($fields as $field => $value) {
        $component->set($field, $value);
    }

    $component->call('save')->assertHasErrors('segue');
})->with([
    'before cue in' => [['cueIn' => '10', 'segue' => '5']],
    'at cue in' => [['cueIn' => '10', 'segue' => '10']],
    'after cue out' => [['cueOut' => '150', 'segue' => '160']],
    'past the end' => [['segue' => '250']],
]);

test('replacing a file archives its segue point and restoring brings it back', function () {
    Storage::fake('local');
    Queue::fake();

    $user = User::factory()->create();
    $station = Station::factory()->create(['user_id' => $user->id]);
    $oldPath = "tenants/{$station->tenant_id}/media/old.mp3";
    $newPath = "tenants/{$station->tenant_id}/media/new.mp3";
    Storage::disk('local')->put($oldPath, 'old');
    Storage::disk('local')->put($newPath, 'new');

    $file = segueFile($station, ['file_path' => $oldPath, 'segue_seconds' => 192.0]);

    $service = app(MediaReplacementService::class);
    $version = $service->replace($file, $newPath, 'new.mp3', $user);

    expect($version->segue_seconds)->toBe(192.0)
        ->and($file->fresh()->segue_seconds)->toBeNull();

    $service->restore($version, $user);

    expect($file->fresh()->segue_seconds)->toBe(192.0);
});

test('switching segues saves the station and restarts the player', function () {
    $owner = User::factory()->create();
    $station = Station::factory()->create(['user_id' => $owner->id]);
    $this->actingAs($owner);

    $this->mock(LiquidsoapCommandService::class, function ($mock) {
        $mock->shouldReceive('restart')->once()->andReturnTrue();
    });

    Livewire::test(Edit::class, ['station' => $station])
        ->set('seguesEnabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($station->fresh()->segues_enabled)->toBeTrue();
});

test('saving without touching segues does not restart the player', function () {
    $owner = User::factory()->create();
    $station = Station::factory()->create(['user_id' => $owner->id]);
    $this->actingAs($owner);

    $this->mock(LiquidsoapCommandService::class, function ($mock) {
        $mock->shouldNotReceive('restart');
    });

    Livewire::test(Edit::class, ['station' => $station])
        ->set('name', 'Renamed FM')
        ->call('save')
        ->assertHasNoErrors();
});

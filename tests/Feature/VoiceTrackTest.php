<?php

use App\Livewire\MediaLibrary\FileModal;
use App\Livewire\MediaLibrary\Index;
use App\Livewire\Playlist\Manager;
use App\Livewire\Rundown\Show;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\HourGridSlot;
use App\Models\MediaFile;
use App\Models\Station;
use App\Models\User;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->playlist = $this->station->playlists()->create(['name' => 'Morning', 'playback_mode' => 'sequential']);
    $this->actingAs($this->user);
});

function voiceTrack(string $title = 'Moderation 10:00'): MediaFile
{
    return test()->station->mediaFiles()->create([
        'title' => $title,
        'type' => 'voicetrack',
        'file_path' => 'tenants/test/media/'.str($title)->slug().'.mp3',
        'duration_seconds' => 30,
    ]);
}

test('a file can be marked as a voice track', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music']);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->set('type', 'voicetrack')
        ->call('save')
        ->assertHasNoErrors();

    expect($file->fresh()->type)->toBe('voicetrack');
});

test('unknown media types are rejected', function () {
    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music']);

    Livewire::test(FileModal::class)
        ->call('open', $file->id)
        ->set('type', 'podcast')
        ->call('save')
        ->assertHasErrors('type');
});

test('the media library lists voice tracks with their own badge and filter', function () {
    voiceTrack('Moderation 10:00');
    MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music', 'title' => 'Sunrise']);

    Livewire::test(Index::class)
        ->set('filterType', 'voicetrack')
        ->assertSee('Moderation 10:00')
        ->assertSee(__('Voice track'))
        ->assertDontSee('Sunrise');
});

test('a voice track picked from the palette becomes a voice track element', function () {
    $file = voiceTrack();

    Livewire::test(Manager::class, ['playlist' => $this->playlist])
        ->set('paletteMediaType', 'voicetrack')
        ->assertViewHas('paletteEntries', fn ($entries) => $entries->pluck('badge')->all() === [__('Voice track')])
        ->call('insertEntry', 'media:'.$file->id);

    expect($this->playlist->items()->first())
        ->type->toBe('voicetrack')
        ->media_file_id->toBe($file->id);
});

test('a random element never picks a voice track', function () {
    voiceTrack();
    $jingle = $this->station->mediaFiles()->create([
        'title' => 'Jingle A',
        'type' => 'jingle',
        'file_path' => 'tenants/test/media/jingle-a.mp3',
        'duration_seconds' => 8,
    ]);

    $this->playlist->items()->create(['position' => 0, 'type' => 'random', 'title' => 'Random']);
    $slot = HourGridSlot::factory()->create([
        'station_id' => $this->station->id,
        'playlist_id' => $this->playlist->id,
        'weekday' => 0,
        'hour' => 10,
    ]);

    foreach (range(1, 5) as $run) {
        $rundown = app(RundownGeneratorService::class)
            ->generate($this->station, $slot, Carbon::parse('2026-05-04'), true);

        expect($rundown->items->pluck('media_file_id')->all())->toBe([$jingle->id]);
    }
});

test('a voice track is not reachable through fill or random elements', function () {
    $this->playlist->items()->create(['position' => 0, 'type' => 'random', 'title' => 'Random']);

    expect(Livewire::test(Index::class)->instance()->isReachableByFill(voiceTrack()))->toBeFalse();
});

test('the rundown marks where a presenter speaks', function () {
    $rundown = GeneratedPlaylist::factory()->create([
        'station_id' => $this->station->id,
        'broadcast_date' => today(),
        'broadcast_hour' => 10,
        'status' => 'ready',
    ]);

    $item = GeneratedPlaylistItem::factory()->create([
        'generated_playlist_id' => $rundown->id,
        'media_file_id' => voiceTrack()->id,
        'position' => 0,
        'source_type' => 'template_item',
        'title' => 'Moderation 10:00',
        'duration_seconds' => 30,
    ]);

    expect($item->isVoiceTrack())->toBeTrue();

    Livewire::test(Show::class, ['date' => today()->toDateString(), 'hour' => 10])
        ->assertSeeHtml('bi-mic')
        ->assertSee(__('Voice track'));
});

<?php

use App\Livewire\MediaLibrary\Index;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\Station;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->station = Station::factory()->create(['user_id' => $this->user->id]);
    session(['current_station_id' => $this->station->id]);
    $this->actingAs($this->user);
});

/**
 * Fill- oder Zufalls-Element in einer Playlist dieser Station.
 *
 * @param  array<int, int>|null  $tagIds
 */
function fillItem(Station $station, string $type = 'fill', ?array $tagIds = null): PlaylistItem
{
    $playlist = Playlist::factory()->create(['station_id' => $station->id, 'name' => 'Fill-Playlist']);

    return PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'type' => $type,
        'position' => 0,
        'title' => 'Fill',
        'fill_tags' => $tagIds,
    ]);
}

test('without any fill element an unscheduled file counts as unused', function () {
    MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music']);

    Livewire::test(Index::class)
        ->assertSee(__('Unbenutzt'))
        ->assertDontSee(__('Via fill'));
});

test('a fill element without tags reaches every music file', function () {
    fillItem($this->station);

    MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music']);

    Livewire::test(Index::class)
        ->assertSee(__('Via fill'))
        ->assertDontSee(__('Unbenutzt'));
});

test('an untagged fill element does not reach jingles', function () {
    fillItem($this->station);

    MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'jingle']);

    Livewire::test(Index::class)
        ->assertSee(__('Unbenutzt'))
        ->assertDontSee(__('Via fill'));
});

test('a tagged fill element only reaches files carrying that tag', function () {
    $tag = Tag::factory()->create(['tenant_id' => $this->station->tenant_id]);
    $otherTag = Tag::factory()->create(['tenant_id' => $this->station->tenant_id]);

    fillItem($this->station, 'fill', [$tag->id]);

    $tagged = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music']);
    $tagged->tags()->attach($tag);

    $untagged = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music']);
    $untagged->tags()->attach($otherTag);

    Livewire::test(Index::class)
        ->assertSee(__('Via fill'))
        ->assertSee(__('Unbenutzt'));
});

test('a random element reaches jingles carrying its tag', function () {
    $tag = Tag::factory()->create(['tenant_id' => $this->station->tenant_id]);

    fillItem($this->station, 'random', [$tag->id]);

    $jingle = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'jingle']);
    $jingle->tags()->attach($tag);

    Livewire::test(Index::class)
        ->assertSee(__('Via fill'))
        ->assertDontSee(__('Unbenutzt'));
});

test('a file scheduled in a playlist keeps showing its usage count', function () {
    fillItem($this->station);

    $file = MediaFile::factory()->create(['tenant_id' => $this->station->tenant_id, 'type' => 'music']);
    $playlist = Playlist::factory()->create(['station_id' => $this->station->id, 'name' => 'Feste Playlist']);

    PlaylistItem::factory()->create([
        'playlist_id' => $playlist->id,
        'media_file_id' => $file->id,
        'type' => 'music',
        'position' => 0,
        'title' => $file->title,
    ]);

    Livewire::test(Index::class)
        ->assertSee('1×')
        ->assertDontSee(__('Via fill'));
});

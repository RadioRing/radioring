<?php

namespace App\Support\PlaylistElements;

use App\Models\ExternalSource;
use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\Station;
use Illuminate\Support\Collection;

/**
 * Searches everything that can go into a playlist and returns it in one shape.
 *
 * The editor shows these entries in tabs, but the search, the picking and the inserting
 * work the same way for every kind.
 */
class PlaylistPalette
{
    public const TAB_MEDIA = 'media';

    public const TAB_CONTAINER = 'container';

    public const TAB_EXTERNAL = 'external';

    public const TAB_SPECIAL = 'special';

    /**
     * @param  string  $mediaType  '', 'music' or 'jingle' to narrow the media tab
     * @return Collection<int, PaletteEntry> up to $limit entries, ordered by name
     */
    public function entries(Station $station, string $tab, string $search = '', int $limit = 40, string $mediaType = ''): Collection
    {
        return match ($tab) {
            self::TAB_CONTAINER => $this->containers($station, $search, $limit),
            self::TAB_EXTERNAL => $this->externalSources($station, $search, $limit),
            self::TAB_SPECIAL => $this->specials($search),
            default => $this->mediaFiles($station, $search, $limit, $mediaType),
        };
    }

    /** @return Collection<int, PaletteEntry> */
    private function mediaFiles(Station $station, string $search, int $limit, string $mediaType): Collection
    {
        $query = $station->mediaFiles()
            ->when($mediaType !== '', fn ($q) => $q->where('type', $mediaType))
            // Die Suchbedingungen gehoeren geklammert, sonst hebt das orWhere einen
            // gesetzten Typfilter auf und die Jingle-Liste zeigt Musiktitel.
            ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('title', 'like', '%'.$search.'%')
                ->orWhere('artist', 'like', '%'.$search.'%')
                ->orWhere('id', 'like', '%'.$search.'%')))
            ->orderBy('title')
            ->limit($limit);

        return $query->get()->map(fn (MediaFile $file) => new PaletteEntry(
            kind: self::TAB_MEDIA,
            id: (string) $file->id,
            title: $file->title,
            subtitle: $file->artist,
            durationSeconds: $file->duration_seconds,
            badge: $file->type === 'jingle' ? __('Jingle') : __('Musik'),
            badgeClass: $file->type === 'jingle' ? 'bg-warning text-dark' : 'bg-primary',
            icon: 'bi-file-earmark-music',
        ));
    }

    /** @return Collection<int, PaletteEntry> */
    private function containers(Station $station, string $search, int $limit): Collection
    {
        return $station->playlists()->containers()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->withCount('items')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Playlist $container) => new PaletteEntry(
                kind: self::TAB_CONTAINER,
                id: (string) $container->id,
                title: $container->name,
                subtitle: trans_choice('{0}empty|{1}1 element|[2,*]:count elements', $container->items_count, ['count' => $container->items_count]),
                badge: __('Container'),
                badgeClass: 'bg-secondary',
                icon: 'bi-box-seam',
            ));
    }

    /** @return Collection<int, PaletteEntry> */
    private function externalSources(Station $station, string $search, int $limit): Collection
    {
        return $station->externalSources()
            ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('broadcast_title', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (ExternalSource $source) => new PaletteEntry(
                kind: self::TAB_EXTERNAL,
                id: (string) $source->id,
                title: $source->name,
                subtitle: $source->broadcast_title,
                durationSeconds: $source->expected_duration_seconds,
                badge: __('Extern'),
                badgeClass: 'bg-info text-dark',
                icon: 'bi-rss',
            ));
    }

    /**
     * Elements that are not picked from a list but placed as they are. They are added
     * with one click and configured afterwards in the playlist.
     *
     * @return Collection<int, PaletteEntry>
     */
    private function specials(string $search): Collection
    {
        $entries = collect([
            new PaletteEntry(
                kind: self::TAB_SPECIAL,
                id: 'fill',
                title: __('Auffüllen mit Musik'),
                subtitle: __('Adds music until its budget is used up, rotation-aware'),
                badge: __('Fill'),
                badgeClass: 'bg-success',
                icon: 'bi-hourglass-split',
            ),
            new PaletteEntry(
                kind: self::TAB_SPECIAL,
                id: 'random',
                title: __('Zufälliges Element'),
                subtitle: __('One random file per rundown, optionally by tag'),
                badge: __('Zufall'),
                badgeClass: 'bg-dark',
                icon: 'bi-shuffle',
            ),
            new PaletteEntry(
                kind: self::TAB_SPECIAL,
                id: 'adbreak',
                title: __('Werbeunterbrechung (laut.fm)'),
                subtitle: __('Marks the start of the ad break'),
                badge: __('Ad Break'),
                badgeClass: 'bg-danger',
                icon: 'bi-megaphone',
            ),
        ]);

        if ($search === '') {
            return $entries;
        }

        return $entries->filter(fn (PaletteEntry $entry) => str_contains(
            mb_strtolower($entry->title.' '.$entry->badge),
            mb_strtolower($search)
        ))->values();
    }
}

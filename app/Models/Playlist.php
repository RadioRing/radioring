<?php

namespace App\Models;

use Database\Factories\PlaylistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['station_id', 'name', 'kind', 'playback_mode'])]
class Playlist extends Model
{
    /** @use HasFactory<PlaylistFactory> */
    use HasFactory;

    public const KIND_PLAYLIST = 'playlist';

    /**
     * A container is a reusable block of items (jingle + news + ad break) that is
     * embedded into real playlists instead of being scheduled on its own.
     */
    public const KIND_CONTAINER = 'container';

    /**
     * Playlists that can be put on the hour grid: everything except containers.
     *
     * @param  Builder<Playlist>  $query
     */
    #[Scope]
    protected function schedulable(Builder $query): void
    {
        $query->where('kind', self::KIND_PLAYLIST);
    }

    /** @param  Builder<Playlist>  $query */
    #[Scope]
    protected function containers(Builder $query): void
    {
        $query->where('kind', self::KIND_CONTAINER);
    }

    public function isContainer(): bool
    {
        return $this->kind === self::KIND_CONTAINER;
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PlaylistItem::class)->orderBy('position');
    }

    /** First element of the playlist. */
    public function firstItem(): HasOne
    {
        return $this->hasOne(PlaylistItem::class)->ofMany('position', 'min');
    }

    /** Does the playlist open with a hard 00:00 marker (hard start on the hour)? */
    public function startsHard(): bool
    {
        $first = $this->firstItem;

        return $first !== null && $first->isHardMarker() && (int) $first->relative_offset_seconds === 0;
    }

    /** Items in other playlists that embed this container. */
    public function embeddingItems(): HasMany
    {
        return $this->hasMany(PlaylistItem::class, 'container_playlist_id');
    }
}

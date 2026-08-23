<?php

namespace App\Models;

use Database\Factories\StationLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'station_id',
    'event',
    'source',
    'media_file_id',
    'generated_playlist_item_id',
    'generated_playlist_id',
    'title',
    'artist',
    'source_type',
    'message',
    'occurred_at',
])]
class StationLog extends Model
{
    /** @use HasFactory<StationLogFactory> */
    use HasFactory;

    public const EVENT_TRACK = 'track';

    public const EVENT_LIVE_STARTED = 'live_started';

    public const EVENT_LIVE_STOPPED = 'live_stopped';

    public const EVENT_RUNDOWN_GENERATED = 'rundown_generated';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    public function generatedPlaylistItem(): BelongsTo
    {
        return $this->belongsTo(GeneratedPlaylistItem::class);
    }

    public function generatedPlaylist(): BelongsTo
    {
        return $this->belongsTo(GeneratedPlaylist::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'station_id',
    'current_rundown_id',
    'current_item_position',
    'now_playing_item_id',
    'now_playing_title',
    'now_playing_artist',
    'now_playing_source_type',
    'now_playing_duration_seconds',
    'now_playing_started_at',
    'live_active',
    'live_title',
    'live_artist',
    'live_started_at',
    'last_pulled_at',
])]
class LiquidsoapState extends Model
{
    protected function casts(): array
    {
        return [
            'now_playing_started_at' => 'datetime',
            'last_pulled_at' => 'datetime',
            'now_playing_duration_seconds' => 'integer',
            'live_active' => 'boolean',
            'live_started_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function currentRundown(): BelongsTo
    {
        return $this->belongsTo(GeneratedPlaylist::class, 'current_rundown_id');
    }

    public function nowPlayingItem(): BelongsTo
    {
        return $this->belongsTo(GeneratedPlaylistItem::class, 'now_playing_item_id');
    }
}

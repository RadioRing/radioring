<?php

namespace App\Models;

use Database\Factories\GeneratedPlaylistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['station_id', 'hour_grid_slot_id', 'playlist_id', 'broadcast_date', 'broadcast_hour', 'status', 'generated_at', 'start_mode'])]
class GeneratedPlaylist extends Model
{
    /** @use HasFactory<GeneratedPlaylistFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'broadcast_date' => 'date',
            'generated_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function hourGridSlot(): BelongsTo
    {
        return $this->belongsTo(HourGridSlot::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GeneratedPlaylistItem::class)->orderBy('position');
    }

    public function isPlayed(): bool
    {
        return $this->status === 'played';
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}

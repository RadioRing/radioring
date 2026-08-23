<?php

namespace App\Models;

use Database\Factories\HourGridSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $weekday  0=Mo ... 6=So */
#[Fillable(['station_id', 'playlist_id', 'weekday', 'hour'])]
class HourGridSlot extends Model
{
    /** @use HasFactory<HourGridSlotFactory> */
    use HasFactory;

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function generatedPlaylists(): HasMany
    {
        return $this->hasMany(GeneratedPlaylist::class);
    }
}

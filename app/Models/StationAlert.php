<?php

namespace App\Models;

use App\Enums\StationAlertType;
use Database\Factories\StationAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One off-air episode of a station, from first seen to resolved.
 */
#[Fillable(['station_id', 'type', 'started_at', 'notified_at', 'resolved_at', 'resolved_notified_at'])]
class StationAlert extends Model
{
    /** @use HasFactory<StationAlertFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => StationAlertType::class,
            'started_at' => 'datetime',
            'notified_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resolved_notified_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * @param  Builder<StationAlert>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }
}

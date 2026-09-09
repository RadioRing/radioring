<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'station_id',
    'current_rundown_id',
    'current_item_position',
    'hard_start_committed_rundown_id',
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
    'underrun_started_at',
    'underrun_logged_at',
])]
class LiquidsoapState extends Model
{
    /**
     * Grace period in seconds a track may exceed its own duration before the snapshot
     * counts as stale. Covers small duration inaccuracies and the short gap until the
     * next on_metadata callback arrives.
     */
    public const NOW_PLAYING_STALE_GRACE_SECONDS = 60;

    /**
     * Assumed length for a track whose duration is unknown: an item that was never
     * measured, or airplay whose item was deleted by a regeneration. Without a bound the
     * snapshot would count as running forever once the callbacks stop.
     */
    public const NOW_PLAYING_UNKNOWN_DURATION_SECONDS = 900;

    /**
     * Assumed length of an adbreak. Its real length is decided by laut.fm and unknown here,
     * so the value is deliberately the length of the signal file itself: together with
     * NOW_PLAYING_STALE_GRACE_SECONDS the snapshot survives roughly a minute, long enough
     * for a normal break and short enough to expire. It used to be exempt from the check
     * altogether, which is how the dashboard sat on START_AD_BREAK for 21 minutes while the
     * programme ran dry behind it.
     */
    public const ADBREAK_ASSUMED_DURATION_SECONDS = 1;

    protected function casts(): array
    {
        return [
            'now_playing_started_at' => 'datetime',
            'last_pulled_at' => 'datetime',
            'underrun_started_at' => 'datetime',
            'underrun_logged_at' => 'datetime',
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

    /**
     * Has the snapshot outlived the track it describes?
     *
     * True once airtime exceeds the track's duration by more than the grace period and no
     * fresh on_metadata callback has arrived - typically silence during a schedule gap or
     * a container that stopped reporting. Adbreaks have no duration of their own and fall
     * back to ADBREAK_ASSUMED_DURATION_SECONDS.
     */
    public function nowPlayingHasEnded(): bool
    {
        if (! $this->now_playing_started_at) {
            return true;
        }

        $assumedDuration = match (true) {
            $this->now_playing_source_type === 'adbreak' => self::ADBREAK_ASSUMED_DURATION_SECONDS,
            default => $this->now_playing_duration_seconds ?? self::NOW_PLAYING_UNKNOWN_DURATION_SECONDS,
        };

        return (int) $this->now_playing_started_at->diffInSeconds(now())
            > $assumedDuration + self::NOW_PLAYING_STALE_GRACE_SECONDS;
    }

    /**
     * Is the station in a confirmed programme underrun?
     *
     * True once /next has been handing out nothing for longer than the configured
     * threshold. The threshold keeps the normal seconds-long gap at an hour boundary from
     * raising an alarm.
     */
    public function isUnderrun(): bool
    {
        return $this->underrunSeconds() !== null;
    }

    /**
     * How long the confirmed underrun has been going on, or null if there is none.
     */
    public function underrunSeconds(): ?int
    {
        if (! $this->underrun_started_at) {
            return null;
        }

        $seconds = (int) $this->underrun_started_at->diffInSeconds(now());

        return $seconds >= (int) config('radioring.underrun_alert_seconds', 30)
            ? $seconds
            : null;
    }
}

<?php

namespace App\Support;

use App\Models\GeneratedPlaylistItem;
use Carbon\CarbonImmutable;

/**
 * Ein Item der projizierten, durchgehenden Tages-Playlist (Anzeige-Schicht).
 *
 * Die projizierte Startzeit wird dynamisch ab dem echten Airplay-Anker
 * (now_playing_started_at) vorwärts gerechnet – nicht aus dem statisch
 * geplanten absolute_broadcast_at. Items, die durch einen Hard-Start der
 * Folgestunde vorzeitig abgeschnitten werden, sind als skipped markiert
 * (projectedStart=null → Anzeige "–"), wie in mAirList.
 */
class ProjectedPlaylistItem
{
    public function __construct(
        public readonly GeneratedPlaylistItem $item,
        public readonly ?CarbonImmutable $projectedStart,
        public readonly bool $isPlaying,
        public readonly bool $isSkipped,
        public readonly bool $isHardBoundary,
    ) {}

    public function isUpcoming(): bool
    {
        return ! $this->isPlaying && ! $this->isSkipped;
    }
}

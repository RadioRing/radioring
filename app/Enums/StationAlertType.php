<?php

namespace App\Enums;

/**
 * Conditions that trigger an alert mail.
 */
enum StationAlertType: string
{
    /** Programme ran dry, no emergency loop: silence. */
    case Silence = 'silence';

    /** Emergency loop on air instead of the programme. */
    case EmergencyLoop = 'emergency_loop';

    /** Container should run but reports no airplay. */
    case NoPlayout = 'no_playout';

    /** Grid slot for the current hour without a rundown. */
    case RundownMissing = 'rundown_missing';

    public function label(): string
    {
        return match ($this) {
            self::Silence => __('The station is sending silence'),
            self::EmergencyLoop => __('The emergency loop is on air'),
            self::NoPlayout => __('The station container is not playing anything'),
            self::RundownMissing => __('The rundown for the current hour is missing'),
        };
    }

    public function explanation(): string
    {
        return match ($this) {
            self::Silence => __('The programme ran out and there is no emergency loop to replace it. Listeners hear silence.'),
            self::EmergencyLoop => __('The programme ran out or could not be reached, and the emergency loop took over. Listeners hear the loop instead of the programme.'),
            self::NoPlayout => __('The container should be running, but it has not reported a track for a while. It may have crashed or lost its connection to RadioRing.'),
            self::RundownMissing => __('The weekly grid has a playlist for this hour, but the rundown could not be generated.'),
        };
    }
}

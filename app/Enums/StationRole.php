<?php

namespace App\Enums;

/**
 * A member's role on a station (station_users.role). The founder (stations.user_id)
 * counts as Owner; see StationPolicy.
 */
enum StationRole: string
{
    /** Everything, including settings and team. */
    case Owner = 'owner';

    /** Builds and airs the programme. */
    case Editor = 'editor';

    /** Goes live and uploads own voice tracks. */
    case Presenter = 'presenter';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Editor => __('Editor'),
            self::Presenter => __('Presenter'),
        };
    }
}

<?php

namespace App\Support;

/**
 * Size ranges for telemetry counts.
 *
 * Exact numbers would make small installations recognisable, the order of magnitude is
 * all development decisions need. The labels must match App\Enums\TelemetryBucket on
 * radioring.de, which rejects anything else.
 */
class TelemetryBucket
{
    public static function for(int $count): string
    {
        return match (true) {
            $count <= 0 => '0',
            $count <= 10 => '1-10',
            $count <= 100 => '11-100',
            $count <= 1000 => '101-1000',
            $count <= 10000 => '1001-10000',
            default => '10001+',
        };
    }
}

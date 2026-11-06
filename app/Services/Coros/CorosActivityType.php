<?php

namespace App\Services\Coros;

/**
 * COROS-Sportart (Zahlencode aus `querySportRecords`) → Stravas `type`, in
 * dem Zone3 alle Aktivitaeten fuehrt. Codes nach der Werkzeugbeschreibung
 * von COROS MCP. Wie bei Garmin: Trail und Laufband zaehlen als Lauf.
 */
class CorosActivityType
{
    public static function toInternal(int $sportType): string
    {
        return match (true) {
            in_array($sportType, [100, 101, 102, 103], true) => 'Run',
            in_array($sportType, [104, 105], true)           => 'Hike',
            $sportType === 201                               => 'VirtualRide',
            in_array($sportType, [202, 205], true)           => 'EBikeRide',
            $sportType >= 200 && $sportType < 300            => 'Ride',
            in_array($sportType, [300, 301], true)           => 'Swim',
            $sportType === 402                               => 'WeightTraining',
            default                                          => 'Workout',
        };
    }
}

<?php

namespace App\Services\Apple;

/**
 * HealthKits Trainingsarten (wie `@capgo/capacitor-health` sie nennt) in
 * Zone3s Vokabular — Stravas `type`, wie bei `GarminActivityType`.
 *
 * Grob wie dort: Laufband und Bahn sind `Run`, jede Radart `Ride`, nur der
 * Hometrainer ist `VirtualRide` (Zwift fährt man darauf; erfüllt die
 * geplante Radeinheit genauso).
 */
class AppleWorkoutType
{
    public static function toInternal(?string $type): string
    {
        return match ((string) $type) {
            'running', 'runningTreadmill', 'trackAndField' => 'Run',
            'cycling', 'handCycling'                       => 'Ride',
            'bikingStationary'                             => 'VirtualRide',
            'swimming', 'swimmingPool', 'swimmingOpenWater' => 'Swim',
            'walking'                                      => 'Walk',
            'hiking'                                       => 'Hike',
            'traditionalStrengthTraining', 'functionalStrengthTraining',
            'strengthTraining', 'weightlifting', 'coreTraining' => 'WeightTraining',
            'yoga'                                         => 'Yoga',
            'rowing', 'rowingMachine'                      => 'Rowing',
            'elliptical'                                   => 'Elliptical',
            default                                        => 'Workout',
        };
    }
}

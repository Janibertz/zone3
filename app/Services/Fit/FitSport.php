<?php

namespace App\Services\Fit;

/**
 * Sportart einer FIT-Datei (`session.sport` / `sub_sport`, FIT-Profil) in
 * Zone3s Vokabular — Stravas `type`, wie bei `GarminActivityType`.
 *
 * Nur, was Zone3 unterscheidet: virtuell (Zwift) gegen draußen, Rad gegen
 * Lauf. Alles andere wird „Workout".
 */
class FitSport
{
    private const RUNNING = 1;
    private const CYCLING = 2;
    private const TRAINING = 10;
    private const WALKING = 11;
    private const SWIMMING = 5;
    private const HIKING = 17;
    private const E_BIKING = 21;

    private const SUB_INDOOR_CYCLING = 6;
    private const SUB_STRENGTH = 20;
    private const SUB_VIRTUAL = 58;

    public static function toInternal(?int $sport, ?int $subSport): string
    {
        return match ($sport) {
            self::RUNNING  => $subSport === self::SUB_VIRTUAL ? 'VirtualRun' : 'Run',
            self::CYCLING  => in_array($subSport, [self::SUB_VIRTUAL, self::SUB_INDOOR_CYCLING], true) ? 'VirtualRide' : 'Ride',
            self::E_BIKING => 'EBikeRide',
            self::SWIMMING => 'Swim',
            self::WALKING  => 'Walk',
            self::HIKING   => 'Hike',
            self::TRAINING => $subSport === self::SUB_STRENGTH ? 'WeightTraining' : 'Workout',
            default        => 'Workout',
        };
    }

    /** Ein Name, wenn die Datei keinen mitbringt. */
    public static function defaultName(string $type): string
    {
        return match ($type) {
            'VirtualRide'    => 'Virtuelle Radfahrt',
            'Ride'           => 'Radfahrt',
            'EBikeRide'      => 'E-Bike-Fahrt',
            'VirtualRun'     => 'Virtueller Lauf',
            'Run'            => 'Lauf',
            'Swim'           => 'Schwimmen',
            'Walk'           => 'Spaziergang',
            'Hike'           => 'Wanderung',
            'WeightTraining' => 'Krafttraining',
            default          => 'Training',
        };
    }
}

<?php

namespace App\Services\Garmin;

/**
 * Garmins Sportarten in Zone3s Vokabular — und das ist Stravas `type`.
 *
 * Daran hängen `RUN_SPORTS`, `BIKE_SPORTS`, `useActivityTypes.js`, der
 * Statistik-Filter und jede Abfrage `type = 'Run'` für Umfang,
 * Schwellenpace und Bestzeiten. Eine zweite Bezeichnung daneben müsste an
 * all diesen Stellen nachgezogen werden; eine Übersetzung an einer Stelle
 * nicht.
 *
 * Bewusst grob, wie Stravas `type` selbst: Trail, Bahn und Laufband sind
 * `Run` (Strava schreibt dort auch `Run`, die Unterart steht in
 * `sport_type`), Rennrad, Gravel und Mountainbike sind `Ride`. Nur was
 * Zone3 anders behandelt, bekommt einen eigenen Typ: `VirtualRide` erfüllt
 * die geplante Radeinheit, `VirtualRun` zählt anders als ein Lauf draußen.
 *
 * Gematcht wird über Bestandteile des Namens statt über eine vollständige
 * Liste: Garmin ergänzt Sportarten (Anhang A der Activity API 2.0 hat
 * ~170), und eine neue Laufvariante soll ein Lauf sein, nicht „Workout".
 */
class GarminActivityType
{
    public static function toInternal(?string $garminType): string
    {
        $type = strtoupper((string) $garminType);

        return match (true) {
            // Zuerst, was nur dem Namen nach ein Lauf, eine Fahrt oder ein
            // Gang ist: Wechselzonen im Triathlon, Rollstuhl.
            $type === '',
            str_contains($type, 'TRANSITION'),
            str_contains($type, 'WHEELCHAIR')    => 'Workout',
            str_contains($type, 'VIRTUAL_RUN')   => 'VirtualRun',
            str_contains($type, 'RUN')           => 'Run',
            str_contains($type, 'VIRTUAL_RIDE')  => 'VirtualRide',
            str_contains($type, 'E_BIKE'),
            str_starts_with($type, 'EBIK'),
            str_starts_with($type, 'EMOUNTAIN'),
            str_starts_with($type, 'E_ENDURO')   => 'EBikeRide',
            str_contains($type, 'CYCL'),
            str_contains($type, 'BIKING'),
            str_contains($type, 'MTB'),
            $type === 'BMX'                      => 'Ride',
            str_contains($type, 'SWIM')          => 'Swim',
            str_contains($type, 'HIKING')        => 'Hike',
            str_contains($type, 'WALK')          => 'Walk',
            str_contains($type, 'STRENGTH')      => 'WeightTraining',
            $type === 'YOGA'                     => 'Yoga',
            default                              => 'Workout',
        };
    }
}

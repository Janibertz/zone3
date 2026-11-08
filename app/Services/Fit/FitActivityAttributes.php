<?php

namespace App\Services\Fit;

use App\Services\Polyline;
use App\Services\Strength\StrengthSets;

/**
 * Was eine entschlüsselte FIT-Datei über eine Aktivität sagt, in Zone3s
 * Namen. Bis zum 08.11.2026 stand das im COROS-Import; mit dem FIT-Upload
 * braucht es ein zweiter Weg, und zwei Fassungen derselben Übersetzung
 * laufen auseinander.
 */
class FitActivityAttributes
{
    /** So viele Punkte trägt die Linie höchstens — für die Karte reicht das. */
    private const MAX_POLYLINE_POINTS = 2000;

    /** Linie, Runden und die genauen Summen aus der FIT-Datei. */
    public static function fromDecoded(array $decoded, string $source): array
    {
        $attributes = [];
        $session    = $decoded['session'];

        $map = [
            'total_timer_time'   => 'moving_time',
            'total_elapsed_time' => 'elapsed_time',
            'total_distance'     => 'distance',
            'total_ascent'       => 'total_elevation_gain',
            'avg_speed'          => 'average_speed',
            'max_speed'          => 'max_speed',
            'avg_heart_rate'     => 'average_heartrate',
            'max_heart_rate'     => 'max_heartrate',
            'avg_power'          => 'average_watts',
            'normalized_power'   => 'weighted_average_watts',
        ];
        foreach ($map as $from => $to) {
            if (isset($session[$from]) && $session[$from] > 0) {
                $attributes[$to] = in_array($to, ['moving_time', 'elapsed_time'], true) ? (int) round($session[$from]) : $session[$from];
            }
        }
        if (isset($attributes['average_watts'])) {
            $attributes['device_watts'] = true;
        }

        $points = [];
        foreach ($decoded['records'] as $r) {
            if (isset($r['lat'], $r['lng'])) {
                $points[] = [round($r['lat'], 6), round($r['lng'], 6)];
            }
        }
        if (count($points) >= 2) {
            $step = (int) ceil(count($points) / self::MAX_POLYLINE_POINTS);
            $kept = $step > 1 ? array_values(array_filter($points, fn ($i) => $i % $step === 0, ARRAY_FILTER_USE_KEY)) : $points;
            if (end($kept) !== end($points)) {
                $kept[] = end($points);
            }
            $attributes['polyline'] = ['polyline' => Polyline::encode($kept)];
        }

        $laps = [];
        foreach ($decoded['laps'] as $i => $lap) {
            $moving   = (int) round($lap['total_timer_time'] ?? $lap['total_elapsed_time'] ?? 0);
            $distance = round((float) ($lap['total_distance'] ?? 0), 1);
            $laps[]   = [
                'index'             => $i + 1,
                'name'              => 'Runde ' . ($i + 1),
                'elapsed_time'      => (int) round($lap['total_elapsed_time'] ?? $moving),
                'moving_time'       => $moving,
                'distance'          => $distance,
                'average_speed'     => $lap['avg_speed'] ?? ($moving > 0 ? round($distance / $moving, 3) : null),
                'max_speed'         => $lap['max_speed'] ?? null,
                'average_heartrate' => $lap['avg_heart_rate'] ?? null,
                'max_heartrate'     => $lap['max_heart_rate'] ?? null,
                'pace_zone'         => null,
            ];
        }
        if ($laps !== []) {
            $attributes['laps'] = $laps;
        }

        // Krafttraining: dieselbe `set`-Nachricht wie bei Garmin.
        $sets = StrengthSets::fromFit($decoded['sets'] ?? [], $source);
        if ($sets !== []) {
            $attributes['strength_sets'] = $sets;
        }

        return $attributes;
    }

    /** [Sekunden, Meter gesamt] fuer die Bestzeiten. */
    public static function stream(array $records): array
    {
        $points = [];
        foreach ($records as $r) {
            if (isset($r['timestamp'], $r['distance'])) {
                $points[] = [(int) $r['timestamp'], (float) $r['distance']];
            }
        }
        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);

        return $points;
    }
}

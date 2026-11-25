<?php

namespace App\Services\Run;

/**
 * Dünnt Messpunkte einer Uhr auf einen Punkt je INTERVAL Sekunden aus
 * (Jan, 25.11.2026: 2 s). Je Fenster: Puls und Tempo gemittelt, Strecke
 * und Höhe der letzte Wert.
 *
 * Eingang je Punkt: `t` (Unix-Sekunden), `d`, `v`, `hr`, `alt` — alles außer
 * `t` darf fehlen. Ausgang spaltenweise wie `ActivityStream::$data`.
 */
class StreamSampler
{
    public const INTERVAL = 2;

    /** Weniger Punkte sind keine Kurve. */
    private const MIN_POINTS = 30;

    /**
     * @param  list<array{t: int|float, d?: ?float, v?: ?float, hr?: ?float, alt?: ?float}>  $points
     * @return array<string, list<int|float|null>>|null
     */
    public static function sample(array $points): ?array
    {
        $points = array_values(array_filter($points, fn ($p) => isset($p['t'])));
        if (count($points) < 2) {
            return null;
        }
        usort($points, fn ($a, $b) => $a['t'] <=> $b['t']);

        $t0      = (int) $points[0]['t'];
        $buckets = [];
        foreach ($points as $p) {
            $key = intdiv(max(0, (int) $p['t'] - $t0), self::INTERVAL);
            $b   = &$buckets[$key];
            $b ??= ['d' => null, 'alt' => null, 'hr' => [], 'v' => []];
            foreach (['d', 'alt'] as $k) {
                if (isset($p[$k]) && is_numeric($p[$k])) {
                    $b[$k] = (float) $p[$k];
                }
            }
            if (isset($p['hr']) && $p['hr'] > 0) {
                $b['hr'][] = (float) $p['hr'];
            }
            if (isset($p['v']) && is_numeric($p['v']) && $p['v'] >= 0) {
                $b['v'][] = (float) $p['v'];
            }
            unset($b);
        }
        ksort($buckets);

        if (count($buckets) < self::MIN_POINTS) {
            return null;
        }

        $out  = ['t' => [], 'd' => [], 'v' => [], 'hr' => [], 'alt' => []];
        $prev = null;
        foreach ($buckets as $key => $b) {
            $t = $key * self::INTERVAL;
            $v = $b['v'] ? array_sum($b['v']) / count($b['v']) : null;
            // Ohne Tempo aus der Strecke rechnen.
            if ($v === null && $b['d'] !== null && $prev && $prev['d'] !== null && $t > $prev['t']) {
                $v = max(0, ($b['d'] - $prev['d']) / ($t - $prev['t']));
            }

            $out['t'][]   = $t;
            $out['d'][]   = $b['d'] !== null ? round($b['d'], 1) : null;
            $out['v'][]   = $v !== null ? round($v, 2) : null;
            $out['hr'][]  = $b['hr'] ? (int) round(array_sum($b['hr']) / count($b['hr'])) : null;
            $out['alt'][] = $b['alt'] !== null ? round($b['alt'], 1) : null;
            $prev         = ['t' => $t, 'd' => $b['d'] ?? $prev['d'] ?? null];
        }

        foreach (['d', 'v', 'hr', 'alt'] as $k) {
            if (! array_filter($out[$k], fn ($x) => $x !== null)) {
                unset($out[$k]);
            }
        }

        return isset($out['d']) || isset($out['v']) || isset($out['hr']) ? $out : null;
    }

    /** FIT-Messpunkte (`FitDecoder`) in die Eingangsform. */
    public static function fromFit(array $records): ?array
    {
        return self::sample(array_map(fn ($r) => [
            't'   => $r['timestamp'] ?? null,
            'd'   => $r['distance'] ?? null,
            'v'   => $r['speed'] ?? null,
            'hr'  => $r['heart_rate'] ?? null,
            'alt' => $r['altitude'] ?? null,
        ], $records));
    }

    /** Garmins Messpunkte (Activity API, `activityDetails`). */
    public static function fromGarmin(array $samples): ?array
    {
        return self::sample(array_map(fn ($s) => [
            't'   => $s['startTimeInSeconds'] ?? null,
            'd'   => $s['totalDistanceInMeters'] ?? null,
            'v'   => $s['speedMetersPerSecond'] ?? null,
            'hr'  => $s['heartRate'] ?? null,
            'alt' => $s['elevationInMeters'] ?? null,
        ], $samples));
    }
}

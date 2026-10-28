<?php

namespace App\Services;

/**
 * Google Encoded Polyline — das Format, das Strava liefert und die
 * Aktivitätsseite dekodiert (`decodePolyline()` in `Activities/Show.vue`).
 *
 * Garmin schickt Messpunkte statt einer fertigen Linie. Damit Karte und
 * Rundenhervorhebung nicht zwei Formate kennen müssen, wird hier daraus
 * dieselbe Zeichenkette.
 */
class Polyline
{
    /** @param  list<array{0: float, 1: float}>  $points  [lat, lng] */
    public static function encode(array $points): string
    {
        $encoded = '';
        $prevLat = 0;
        $prevLng = 0;

        foreach ($points as [$lat, $lng]) {
            $lat = (int) round($lat * 1e5);
            $lng = (int) round($lng * 1e5);

            $encoded .= self::value($lat - $prevLat) . self::value($lng - $prevLng);

            $prevLat = $lat;
            $prevLng = $lng;
        }

        return $encoded;
    }

    private static function value(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : $value << 1;
        $chunk = '';

        while ($value >= 0x20) {
            $chunk .= chr((0x20 | ($value & 0x1f)) + 63);
            $value >>= 5;
        }

        return $chunk . chr($value + 63);
    }
}

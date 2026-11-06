<?php

namespace App\Services\Coros;

/**
 * Liest die Aktivitaetsliste aus `querySportRecords` (MCP antwortet nur mit
 * Text). Aufbau eines Eintrags, nach den echten Antworten vom 06.11.2026
 * (Beispielwerte):
 *
 *   1. Outdoor Run — 2026-01-01
 *      Location: Musterstadt Laufen
 *      Start Coordinates: 50.000000, 8.000000
 *      Time Window: startTimestamp=1767250800 | endTimestamp=1767253800
 *      Duration: 48:20 | Distance: 9.10 km
 *      Average Pace: 5:19 /km | Avg HR: 150 bpm | Calories: 600 kcal
 *      LabelId: 400000000000000001 | SportType: 100
 *
 * Linie, Runden und Bewegungszeit kommen aus der FIT-Datei; die Liste
 * liefert, was jede Aktivitaet hat, und die IDs dafuer.
 */
class CorosActivityParser
{
    /**
     * @return list<array{label_id: int, sport_type: int, title: string, name: ?string, start: ?int, end: ?int, duration: ?int, distance: ?float, avg_hr: ?int, lat: ?float, lng: ?float}>
     */
    public function records(string $text): array
    {
        $entries = preg_split('/^\s*\d+\.\s+/m', $text);
        array_shift($entries);

        $records = [];
        foreach ($entries as $entry) {
            if (! preg_match('/LabelId:\s*(\d+)\s*\|\s*SportType:\s*(\d+)/i', $entry, $id)) {
                continue;
            }

            $title = trim(preg_split('/\s+—\s+|\R/u', $entry)[0]);

            $records[] = [
                'label_id'   => (int) $id[1],
                'sport_type' => (int) $id[2],
                'title'      => $title,
                'name'       => preg_match('/^\s*Location:\s*(.+)$/mi', $entry, $m) ? trim($m[1]) : null,
                'start'      => preg_match('/startTimestamp=(\d+)/', $entry, $m) ? (int) $m[1] : null,
                'end'        => preg_match('/endTimestamp=(\d+)/', $entry, $m) ? (int) $m[1] : null,
                'duration'   => preg_match('/Duration:\s*([\d:]+)/i', $entry, $m) ? $this->seconds($m[1]) : null,
                'distance'   => preg_match('/Distance:\s*([\d.]+)\s*km/i', $entry, $m) ? round((float) $m[1] * 1000, 1) : null,
                'avg_hr'     => preg_match('/Avg HR:\s*(\d+)/i', $entry, $m) && (int) $m[1] > 0 ? (int) $m[1] : null,
                'lat'        => preg_match('/Start Coordinates:\s*(-?[\d.]+),\s*(-?[\d.]+)/i', $entry, $m) ? (float) $m[1] : null,
                'lng'        => isset($m[2]) ? (float) $m[2] : null,
            ];
        }

        return $records;
    }

    /** „queryDevices“: der Name der ersten Uhr („1. COROS PACE 3“). */
    public function deviceName(string $text): ?string
    {
        return preg_match('/^\s*1\.\s*(.+)$/m', $text, $m) ? trim($m[1]) : null;
    }

    /** „48:20“ oder „1:02:03“ → Sekunden. */
    private function seconds(string $clock): int
    {
        $parts = array_map('intval', explode(':', $clock));
        $total = 0;
        foreach ($parts as $part) {
            $total = $total * 60 + $part;
        }

        return $total;
    }
}

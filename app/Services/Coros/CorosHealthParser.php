<?php

namespace App\Services\Coros;

/**
 * Liest Erholungswerte aus den Textantworten von COROS MCP.
 *
 * MCP antwortet nur mit Fliesstext (kein `structuredContent`). Die Muster
 * hier folgen den echten Antworten eines COROS-Nutzers vom 06.11.2026
 * (`coros:probe`). Jede Methode liefert je Tag (Y-m-d) nur die Spalten von
 * `garmin_daily_metrics`, die sie wirklich gefunden hat — wie bei Garmin
 * kommt ein Tag in Teilen, und ein fehlender Wert bleibt null.
 *
 * COROS datiert Schlaf, HRV und Ruhepuls auf den Tag des Aufwachens, so wie
 * Garmin auch.
 */
class CorosHealthParser
{
    /**
     * queryDailyHealthData: „--- 20260101 ---“, darunter „Steps: 1,234“ und
     * „Stress: Avg 20“ (Beispielwerte).
     *
     * @return array<string, array<string, int>>
     */
    public function daily(string $text): array
    {
        $days = [];

        foreach ($this->blocks($text, '/^---\s*(\d{8})\s*---\s*$/m') as $ymd => $block) {
            $date = $this->ymd($ymd);
            if (! $date) {
                continue;
            }

            $values = [];
            if (preg_match('/^\s*Steps:\s*([\d.,]+)/mi', $block, $m)) {
                $values['steps'] = (int) preg_replace('/\D/', '', $m[1]);
            }
            if (preg_match('/^\s*Stress:\s*Avg\s*(\d+)/mi', $block, $m) && (int) $m[1] > 0) {
                $values['stress_avg'] = (int) $m[1];
            }

            // Ein Tag ohne jede Messung (Uhr nicht getragen) meldet 0 Schritte
            // und nichts sonst — das ist „keine Daten“, nicht „0 Schritte“.
            if (($values['steps'] ?? null) === 0 && count($values) === 1 && ! preg_match('/Sleep|Exercise:\s*[1-9]/i', $block)) {
                continue;
            }

            if ($values) {
                $days[$date] = $values;
            }
        }

        return $days;
    }

    /**
     * querySleepOverview: Datumszeile „2026-01-01“, darunter „Sleep Score: 70“
     * und „Main Sleep (asleep): 7h 0min“ (ohne Wachphasen, wie bei Garmin).
     *
     * @return array<string, array<string, int|float>>
     */
    public function sleep(string $text): array
    {
        $days = [];

        foreach ($this->blocks($text, '/^(\d{4}-\d{2}-\d{2})\s*$/m') as $date => $block) {
            $values = [];
            if (preg_match('/^\s*Sleep Score:\s*(\d+)/mi', $block, $m) && (int) $m[1] > 0) {
                $values['sleep_score'] = (int) $m[1];
            }
            if (preg_match('/^\s*Main Sleep \(asleep\):\s*(.+)$/mi', $block, $m)) {
                $hours = $this->hours($m[1]);
                if ($hours > 0) {
                    $values['sleep_hours'] = $hours;
                }
            }
            if ($values) {
                $days[$date] = $values;
            }
        }

        return $days;
    }

    /**
     * querySleepHrv: „2026-01-01:“ und darunter „HRV Avg: 60 ms“. Die
     * Zeitreihe danach traegt keine „HRV Avg“-Zeilen und faellt heraus.
     *
     * @return array<string, array<string, float>>
     */
    public function hrv(string $text): array
    {
        $days = [];

        foreach ($this->blocks($text, '/^\s*(\d{4}-\d{2}-\d{2}):\s*$/m') as $date => $block) {
            if (preg_match('/^\s*HRV Avg:\s*([\d.]+)\s*ms/mi', $block, $m) && (float) $m[1] > 0) {
                $days[$date] = ['hrv' => (float) $m[1]];
            }
        }

        return $days;
    }

    /**
     * queryRestingHeartRate: „2026-01-01: 50 bpm“.
     *
     * @return array<string, array<string, int>>
     */
    public function restingHr(string $text): array
    {
        preg_match_all('/^\s*(\d{4}-\d{2}-\d{2}):\s*(\d+)\s*bpm/mi', $text, $m, PREG_SET_ORDER);

        $days = [];
        foreach ($m as [, $date, $bpm]) {
            if ((int) $bpm > 0) {
                $days[$date] = ['resting_hr' => (int) $bpm];
            }
        }

        return $days;
    }

    /** queryFitnessAssessmentOverview: „VO2max: 45“ (Beispiel) — der aktuelle Wert, ohne Datum. */
    public function vo2max(string $text): ?float
    {
        return preg_match('/^\s*VO2max:\s*([\d.]+)/mi', $text, $m) && (float) $m[1] > 0 ? (float) $m[1] : null;
    }

    /**
     * Den Text an Kopfzeilen zerschneiden: Schluessel ist die erste Gruppe
     * des Musters, Wert der Text bis zur naechsten Kopfzeile.
     *
     * @return array<string, string>
     */
    private function blocks(string $text, string $header): array
    {
        $parts  = preg_split($header, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $blocks = [];

        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $key = $parts[$i];
            // Kommt ein Tag zweimal vor (Bewertung und Zeitreihe), gilt der erste.
            $blocks[$key] ??= $parts[$i + 1];
        }

        return $blocks;
    }

    private function ymd(string $ymd): ?string
    {
        return preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ymd, $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    /** „7h 15min“, „30 min“, „7h“ → Stunden mit zwei Stellen. */
    private function hours(string $duration): float
    {
        $h = preg_match('/(\d+)\s*h/i', $duration, $m) ? (int) $m[1] : 0;
        $min = preg_match('/(\d+)\s*min/i', $duration, $m) ? (int) $m[1] : 0;

        return round($h + $min / 60, 2);
    }
}

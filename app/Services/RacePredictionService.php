<?php

namespace App\Services;

/**
 * Die Renn-Prognose aus der Schwellenpace (Jack Daniels, T-Pace).
 *
 * Diese Formel stand dreimal im Projekt: einmal auf der Planseite, einmal
 * für das Dashboard in routes/web.php, und der Text daneben entstand aus
 * einer vierten Quelle — der Riegel-Hochrechnung aus den letzten Läufen.
 * Angezeigt wurde die eine Zahl, geschrieben die andere. Für denselben
 * Marathon standen so 3:26 in der Kachel und 3:36 im Text darunter.
 *
 * Die Zahl kommt ab jetzt von hier, für alle drei Stellen.
 */
class RacePredictionService
{
    /**
     * Die Ankerpunkte: Faktor auf die Schwellenpace je Renndistanz.
     * Unter 5 km läuft man schneller als an der Schwelle, ab Halbmarathon
     * langsamer.
     */
    public const ANCHORS = [
        '5km'           => ['km' => 5.0,     'mul' => 0.90, 'label' => '5 km'],
        '10km'          => ['km' => 10.0,    'mul' => 0.95, 'label' => '10 km'],
        'half_marathon' => ['km' => 21.0975, 'mul' => 1.03, 'label' => 'Halbmarathon'],
        'marathon'      => ['km' => 42.195,  'mul' => 1.12, 'label' => 'Marathon'],
    ];

    /**
     * @param  float        $thresholdSpeed  Schwellenpace in Minuten je Kilometer.
     * @param  string|null  $raceDistance    Schlüssel aus ANCHORS.
     * @param  float|null   $customKm        Freie Distanz, wenn kein Schlüssel passt.
     * @return array{time: string, pace: string, total_sec: int}|null
     */
    public function fromThreshold(float $thresholdSpeed, ?string $raceDistance, ?float $customKm = null): ?array
    {
        if ($thresholdSpeed <= 0) {
            return null;
        }

        if ($raceDistance !== null && isset(self::ANCHORS[$raceDistance])) {
            $km  = self::ANCHORS[$raceDistance]['km'];
            $mul = self::ANCHORS[$raceDistance]['mul'];
        } elseif ($customKm > 0) {
            $km  = $customKm;
            $mul = $this->interpolate($customKm);
        } else {
            return null;
        }

        return $this->format($thresholdSpeed * 60 * $mul, $km);
    }

    /**
     * Die vier Standarddistanzen auf einmal — für die Kacheln im Dashboard.
     *
     * @return array<string, array{label: string, pace: string, total_time: string}>
     */
    public function standardDistances(float $thresholdSpeed): array
    {
        $out = [];

        foreach (self::ANCHORS as $key => $race) {
            $p = $this->format($thresholdSpeed * 60 * $race['mul'], $race['km']);

            // Die Schlüssel im Dashboard sind kürzer als die Event-Schlüssel.
            $short = match ($key) {
                '5km'           => '5k',
                '10km'          => '10k',
                'half_marathon' => 'half',
                default         => 'marathon',
            };

            $out[$short] = [
                'label'      => $race['label'],
                'pace'       => $p['pace'],
                'total_time' => $p['time'],
            ];
        }

        return $out;
    }

    /** Zwischen den Ankerpunkten wird linear interpoliert. */
    private function interpolate(float $km): float
    {
        return match (true) {
            $km <= 5.0     => 0.90,
            $km <= 10.0    => 0.90 + ($km -  5.0)    /  5.0     * 0.05,
            $km <= 21.098  => 0.95 + ($km - 10.0)    / 11.098   * 0.08,
            $km <= 42.195  => 1.03 + ($km - 21.098)  / 21.097   * 0.09,
            default        => 1.12 + ($km - 42.195)  / 42.195   * 0.05,
        };
    }

    /** @return array{time: string, pace: string, total_sec: int} */
    private function format(float $paceSec, float $km): array
    {
        $totalSec = (int) ($paceSec * $km);

        return [
            'time'      => $this->clock($totalSec),
            'pace'      => PaceFormat::fromSeconds($paceSec),
            'total_sec' => $totalSec,
        ];
    }

    private function clock(int $sec): string
    {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}

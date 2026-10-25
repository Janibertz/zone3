<?php

namespace App\Services;

/**
 * Die Renn-Prognose aus dem VDOT (Jack Daniels).
 *
 * Diese Formel stand dreimal im Projekt: einmal auf der Planseite, einmal
 * für das Dashboard in routes/web.php, und der Text daneben entstand aus
 * einer vierten Quelle — der Riegel-Hochrechnung aus den letzten Läufen.
 * Angezeigt wurde die eine Zahl, geschrieben die andere. Für denselben
 * Marathon standen so 3:26 in der Kachel und 3:36 im Text darunter.
 *
 * Bis 25.10.2026 waren es feste Faktoren auf die Schwellenpace (5 km = T ×
 * 0,90, Marathon = T × 1,12). Sie lagen bei 5 km fast eine Minute zu
 * schnell und beim Marathon zehn Minuten zu langsam. Jetzt rechnet
 * {@see Vdot} die Zeit für jede Strecke aus derselben Formel — es gibt
 * keine Ankerpunkte und keine Interpolation mehr.
 */
class RacePredictionService
{
    /** Die Standarddistanzen. `Event::race_km` liest die Kilometer von hier. */
    public const ANCHORS = [
        '5km'           => ['km' => 5.0,     'label' => '5 km'],
        '10km'          => ['km' => 10.0,    'label' => '10 km'],
        'half_marathon' => ['km' => 21.0975, 'label' => 'Halbmarathon'],
        'marathon'      => ['km' => 42.195,  'label' => 'Marathon'],
    ];

    /**
     * @param  float|null   $vdot          Aus {@see VdotService::forProfile()}.
     * @param  string|null  $raceDistance  Schlüssel aus ANCHORS.
     * @param  float|null   $customKm      Freie Distanz, wenn kein Schlüssel passt.
     * @return array{time: string, pace: string, total_sec: int}|null
     */
    public function forVdot(?float $vdot, ?string $raceDistance, ?float $customKm = null): ?array
    {
        if (! $vdot || $vdot <= 0) {
            return null;
        }

        $km = self::ANCHORS[$raceDistance]['km'] ?? ($customKm > 0 ? $customKm : null);

        return $km ? $this->format($vdot, $km) : null;
    }

    /**
     * Die vier Standarddistanzen auf einmal — für die Kacheln im Dashboard.
     *
     * @return array<string, array{label: string, pace: string, total_time: string}>
     */
    public function standardDistances(float $vdot): array
    {
        $out = [];

        foreach (self::ANCHORS as $key => $race) {
            $p = $this->format($vdot, $race['km']);

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

    /** @return array{time: string, pace: string, total_sec: int} */
    private function format(float $vdot, float $km): array
    {
        $totalSec = (int) round(Vdot::raceSeconds($vdot, $km * 1000));

        return [
            'time'      => self::clock($totalSec),
            'pace'      => PaceFormat::fromSeconds($totalSec / $km),
            'total_sec' => $totalSec,
        ];
    }

    /** „1:31:31" oder „19:56". */
    public static function clock(int $sec): string
    {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}

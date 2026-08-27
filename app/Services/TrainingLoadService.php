<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\RunnerProfile;
use Carbon\Carbon;

/**
 * Calculates Training Stress Score (TSS) per activity and the
 * Chronic Training Load (CTL), Acute Training Load (ATL), and
 * Training Stress Balance (TSB) using exponential moving averages.
 *
 * CTL (42-day EMA) = "Fitness"
 * ATL  (7-day EMA) = "Fatigue"
 * TSB  = CTL - ATL  = "Form"
 *
 * TSS per run:
 *  - Primary:  rTSS  = duration_h × IF² × 100   (IF = threshold_pace / avg_pace)
 *  - Fallback: hrTSS = duration_h × (avg_hr / threshold_hr)² × 100
 *  - Fallback: duration_h × 60  (assume moderate effort)
 */
class TrainingLoadService
{
    /**
     * Compute current CTL/ATL/TSB for a user plus a 60-day history array
     * suitable for rendering a chart.
     *
     * @return array{ctl: float, atl: float, tsb: float, form_label: string, form_color: string, history: array}
     */
    public function calculate(int $userId): array
    {
        $profile          = RunnerProfile::where('user_id', $userId)->first();
        $thresholdPaceSec = $profile?->threshold_speed ? $profile->threshold_speed * 60 : null;
        $thresholdHr      = $profile?->threshold_heart_rate;

        // Fetch 180 days of activities — enough to warm up the 42-day EMA.
        // Only the columns used by activityTSS() — skip large polyline/laps JSON.
        $activities = Activity::where('user_id', $userId)
            ->where('start_date', '>=', Carbon::now()->subDays(180))
            ->orderBy('start_date')
            ->get(['id', 'type', 'start_date', 'moving_time', 'average_speed', 'distance', 'average_heartrate']);

        // Aggregate TSS per calendar day
        $dailyTSS = [];
        foreach ($activities as $activity) {
            $date            = Carbon::parse($activity->start_date)->toDateString();
            $dailyTSS[$date] = ($dailyTSS[$date] ?? 0.0)
                + $this->activityTSS($activity, $thresholdPaceSec, $thresholdHr);
        }

        // Walk day-by-day and apply the EMA
        $ctl         = 0.0;
        $atl         = 0.0;
        $history     = [];
        $chartCutoff = Carbon::now()->subDays(60)->toDateString();
        $cursor      = Carbon::now()->subDays(180);
        $today       = Carbon::now()->toDateString();

        while ($cursor->toDateString() <= $today) {
            $dateStr = $cursor->toDateString();
            $tss     = $dailyTSS[$dateStr] ?? 0.0;

            $ctl = $ctl + ($tss - $ctl) / 42;
            $atl = $atl + ($tss - $atl) / 7;

            if ($dateStr >= $chartCutoff) {
                $history[] = [
                    'date' => $dateStr,
                    'ctl'  => round($ctl, 1),
                    'atl'  => round($atl, 1),
                    'tsb'  => round($ctl - $atl, 1),
                    'tss'  => round($tss, 1),
                ];
            }

            $cursor->addDay();
        }

        $tsb = $ctl - $atl;

        return [
            'ctl'        => round($ctl, 1),
            'atl'        => round($atl, 1),
            'tsb'        => round($tsb, 1),
            'form_label' => $this->formLabel($tsb),
            'form_color' => $this->formColor($tsb),
            'history'    => $history,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Belastung einer Aktivität in TSS.
     *
     * Die Pace-Rechnung gilt AUSSCHLIESSLICH fürs Laufen. Vorher lief jede
     * Aktivität durch dieselbe Formel, und weil ein Rad schneller fährt als
     * ein Mensch läuft, schlug der Intensitätsfaktor am oberen Anschlag an:
     * eine Radfahrt von 25 Minuten kam auf 94 TSS, ein Zehn-Kilometer-Lauf
     * auf 64. Eine Stunde auf dem Rad zählte dreimal so viel wie derselbe
     * Lauf. In der Gegenrichtung dasselbe Bild — 40 Minuten Schwimmen
     * ergaben 6 TSS, praktisch nichts.
     *
     * Damit war CTL/ATL/TSB für jeden verfälscht, der auch etwas anderes
     * macht als laufen. Diese Werte stehen als "Form" im Plan-Prompt.
     *
     * Für Fremdsportarten trägt die Herzfrequenz: sie ist über Sportarten
     * hinweg vergleichbar, die Geschwindigkeit ist es nicht.
     */
    private function activityTSS(Activity $activity, ?float $thresholdPaceSec, ?int $thresholdHr): float
    {
        $durationHours = $activity->moving_time / 3600;
        if ($durationHours <= 0) return 0.0;

        $isRun = in_array($activity->type, \App\Models\TrainingSession::RUN_SPORTS, true);

        // 1. Pace-based rTSS — nur beim Laufen aussagekräftig
        if ($isRun && $thresholdPaceSec && $activity->average_speed > 0 && $activity->distance > 100) {
            $avgPaceSec = 1000 / $activity->average_speed; // sec per km
            // IF > 1 means faster than threshold (more stress)
            $if = $thresholdPaceSec / $avgPaceSec;
            $if = min(max($if, 0.3), 1.5); // clamp to sane range
            return round($durationHours * $if * $if * 100, 1);
        }

        // 2. HR-based hrTSS
        if ($thresholdHr && $activity->average_heartrate > 0) {
            $if = $activity->average_heartrate / $thresholdHr;
            $if = min(max($if, 0.3), 1.3);
            return round($durationHours * $if * $if * 100, 1);
        }

        // 3. Duration-based fallback (moderate effort assumed).
        //    Ohne Pulsdaten laesst sich die Intensitaet einer Fremdsportart
        //    nicht bestimmen — eine mittlere Belastung anzunehmen ist die
        //    ehrlichste Schaetzung.
        return round($durationHours * 60, 1);
    }

    private function formLabel(float $tsb): string
    {
        if ($tsb < -30) return 'Übermüdet';
        if ($tsb < -10) return 'Belastet';
        if ($tsb <=  5) return 'Optimal';
        if ($tsb <= 25) return 'Frisch';
        return 'Ausgeruht';
    }

    private function formColor(float $tsb): string
    {
        if ($tsb < -30) return 'red';
        if ($tsb < -10) return 'orange';
        if ($tsb <=  5) return 'green';
        if ($tsb <= 25) return 'blue';
        return 'gray';
    }
}

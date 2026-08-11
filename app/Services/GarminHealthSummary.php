<?php

namespace App\Services;

use App\Models\GarminDailyMetric;
use Carbon\CarbonImmutable;

/**
 * Fasst die Garmin-Gesundheitswerte für die Planung zusammen.
 *
 * Wichtig ist dabei nicht der Absolutwert: eine HRV von 45 ms ist für den
 * einen hervorragend und für den anderen ein Warnsignal. Aussagekräftig ist
 * nur die Abweichung von der eigenen Grundlinie. Deshalb wird hier immer der
 * 7-Tage-Schnitt gegen die 60-Tage-Grundlinie gestellt und als Prozentwert
 * beschrieben — damit kann ein Sprachmodell etwas anfangen, mit „45" nicht.
 */
class GarminHealthSummary
{
    private const RECENT_DAYS   = 7;
    private const BASELINE_DAYS = 60;

    /** Ab dieser Abweichung vom eigenen Schnitt wird es erwähnenswert. */
    private const NOTABLE_PCT = 5.0;

    /**
     * @return array{
     *     has_data: bool, days: int,
     *     lines: list<string>, flags: list<string>
     * }
     */
    public function forUser(int $userId, ?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::today();

        $rows = GarminDailyMetric::where('user_id', $userId)
            ->where('date', '>=', $today->subDays(self::BASELINE_DAYS)->toDateString())
            ->orderBy('date')
            ->get();

        if ($rows->isEmpty()) {
            return ['has_data' => false, 'days' => 0, 'lines' => [], 'flags' => []];
        }

        $recentFrom = $today->subDays(self::RECENT_DAYS)->toDateString();
        $recent     = $rows->filter(fn ($r) => $r->date->toDateString() >= $recentFrom);

        $lines = [];
        $flags = [];

        // HRV und Ruhepuls sind die beiden Werte, an denen sich Überlastung
        // am frühesten zeigt — fallende HRV bei steigendem Ruhepuls.
        $hrv = $this->compare($rows, $recent, 'hrv');
        if ($hrv) {
            $lines[] = sprintf(
                'HRV: %s ms im 7-Tage-Schnitt (Grundlinie %s ms, %s)',
                $hrv['recent'], $hrv['baseline'], $this->deltaLabel($hrv['pct'])
            );
            if ($hrv['pct'] <= -self::NOTABLE_PCT) {
                $flags[] = 'HRV liegt deutlich unter der Grundlinie';
            }
        }

        $rhr = $this->compare($rows, $recent, 'resting_hr');
        if ($rhr) {
            $lines[] = sprintf(
                'Ruhepuls: %s bpm im 7-Tage-Schnitt (Grundlinie %s bpm, %s)',
                $rhr['recent'], $rhr['baseline'], $this->deltaLabel($rhr['pct'])
            );
            if ($rhr['pct'] >= self::NOTABLE_PCT) {
                $flags[] = 'Ruhepuls liegt deutlich über der Grundlinie';
            }
        }

        $sleep = $this->average($recent, 'sleep_hours');
        if ($sleep !== null) {
            $score = $this->average($recent, 'sleep_score');
            $lines[] = sprintf(
                'Schlaf: %.1f h pro Nacht (7 Tage)%s',
                $sleep, $score !== null ? sprintf(', Schlafscore %d/100', round($score)) : ''
            );
            if ($sleep < 6.5) {
                $flags[] = sprintf('chronisch wenig Schlaf (%.1f h)', $sleep);
            }
        }

        $battery = $this->average($recent, 'body_battery_high');
        if ($battery !== null) {
            $low = $this->average($recent, 'body_battery_low');
            $lines[] = sprintf(
                'Body Battery: lädt im Schnitt auf %d%s',
                round($battery), $low !== null ? sprintf(', fällt auf %d', round($low)) : ''
            );
            if ($battery < 60) {
                $flags[] = sprintf('Body Battery erreicht nur %d — unvollständige Erholung', round($battery));
            }
        }

        $stress = $this->average($recent, 'stress_avg');
        if ($stress !== null) {
            $lines[] = sprintf('Stresslevel: %d/100 im Tagesschnitt', round($stress));
            if ($stress > 50) {
                $flags[] = 'dauerhaft erhöhter Stresswert';
            }
        }

        $readiness = $this->average($recent, 'training_readiness');
        if ($readiness !== null) {
            $lines[] = sprintf('Training Readiness (Garmin): %d/100 im 7-Tage-Schnitt', round($readiness));
            if ($readiness < 40) {
                $flags[] = 'Garmin meldet anhaltend niedrige Trainingsbereitschaft';
            }
        }

        return [
            'has_data' => ! empty($lines),
            'days'     => $rows->count(),
            'lines'    => $lines,
            'flags'    => $flags,
        ];
    }

    /**
     * Der fertige Prompt-Abschnitt. Ohne Daten kommt bewusst ein Hinweis
     * zurück statt gar nichts — sonst bewertet das Modell das Fehlen als
     * „alles in Ordnung".
     */
    public function toPromptSection(array $summary): string
    {
        if (! $summary['has_data']) {
            return "Garmin-Gesundheitswerte: keine Daten vorhanden (Uhr nicht verbunden oder noch nicht synchronisiert). "
                 . "Stütze dich auf das Wellbeing des Athleten.";
        }

        $text = "Garmin-Gesundheitswerte (gemessen, {$summary['days']} Tage Historie):\n- "
              . implode("\n- ", $summary['lines']);

        if ($summary['flags']) {
            $text .= "\n\n⚠️ Auffällig: " . implode('; ', $summary['flags']) . ".\n"
                . "Diese Werte sind gemessen und wiegen schwerer als die Selbsteinschätzung — "
                . "reduziere Umfang und Intensität der harten Einheiten spürbar, bevor daraus "
                . "eine Überlastung wird. Die Struktur der Woche bleibt dabei bestehen; "
                . "kürze die Einheiten, statt sie zu streichen.";
        } else {
            $text .= "\n\nDie Werte liegen im gewohnten Bereich — der geplante Aufbau kann normal weiterlaufen.";
        }

        return $text;
    }

    /** @return array{recent: float, baseline: float, pct: float}|null */
    private function compare($all, $recent, string $field): ?array
    {
        $recentAvg   = $this->average($recent, $field);
        $baselineAvg = $this->average($all, $field);

        if ($recentAvg === null || $baselineAvg === null || $baselineAvg == 0.0) {
            return null;
        }

        return [
            'recent'   => round($recentAvg, 1),
            'baseline' => round($baselineAvg, 1),
            'pct'      => round((($recentAvg - $baselineAvg) / $baselineAvg) * 100, 1),
        ];
    }

    private function average($rows, string $field): ?float
    {
        $values = $rows->pluck($field)->filter(fn ($v) => $v !== null);

        return $values->isEmpty() ? null : (float) $values->avg();
    }

    private function deltaLabel(float $pct): string
    {
        if (abs($pct) < self::NOTABLE_PCT) {
            return 'im gewohnten Bereich';
        }

        return sprintf('%+.1f %% gegenüber der Grundlinie', $pct);
    }
}

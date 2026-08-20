<?php

namespace App\Services;

use App\Models\Event;

/**
 * Die konkreten Tempos, in denen ein Plan gelaufen werden soll.
 *
 * Der Planer bekam bisher eine einzige Zahl — die Schwellenpace — und sollte
 * daraus alles Übrige selbst ableiten: das Tempo für den lockeren Lauf, für
 * den langen, für die Intervalle, und vor allem das Renntempo, auf das der
 * ganze Block hinführt. Er tat das bei jeder Neuberechnung ein bisschen
 * anders, und die Zielzeit tauchte im Plan nur als Wunsch auf, nie als Pace.
 *
 * Dabei liegen die Zonen längst im Profil und stehen dem Athleten in der
 * App vor Augen. Sie hier auszurechnen und danebenzustellen, kostet nichts
 * und macht aus „zügig an der Schwelle" eine Zahl, an der man sich im
 * Training festhalten kann.
 */
class TrainingPaceService
{
    public function __construct(private readonly RacePredictionService $prediction) {}

    /**
     * @param  float|null  $thresholdSpeed  Schwellenpace in Minuten je Kilometer (5.5 = 5:30)
     *
     * @return array{
     *     threshold: string, easy: string, long: string, marathon: string,
     *     interval: string, repetition: string,
     *     race_km: float|null, target_pace: string|null, target_time: string|null,
     *     predicted_pace: string|null, predicted_time: string|null,
     *     delta_sec: int|null, verdict: string|null
     * }|null
     */
    public function forEvent(Event $event, ?float $thresholdSpeed): ?array
    {
        if (! $thresholdSpeed || $thresholdSpeed <= 0) {
            return null;
        }

        $t = $thresholdSpeed * 60; // Sekunden je Kilometer

        // Dieselben Abstände, die RunnerProfile::calculatePaceZones() für die
        // Anzeige benutzt — der Plan muss dieselben Zahlen nennen wie die App.
        $paces = [
            'threshold'  => $this->pace($t),
            'easy'       => $this->range($t + 46, $t + 81),
            'long'       => $this->range($t + 46, $t + 95),
            'marathon'   => $this->pace($t * 1.12),
            'interval'   => $this->range($t - 12, $t + 4),
            'repetition' => $this->pace($t - 20),
            // Der numerische Wert für alles, was mit Distanzen rechnet —
            // die Leiter der langen Läufe zum Beispiel.
            'long_sec'   => (int) round($t + 70),
        ];

        $km = $this->raceKm($event);

        // Ohne Zieldistanz (Backyard) gibt es kein Renntempo — dort zählt
        // Durchhalten, nicht Zeit.
        if ($km === null) {
            return $paces + [
                'race_km'        => null,
                'target_pace'    => null,
                'target_time'    => null,
                'predicted_pace' => null,
                'predicted_time' => null,
                'delta_sec'      => null,
                'verdict'        => null,
            ];
        }

        $predicted   = $this->prediction->fromThreshold($thresholdSpeed, $event->race_distance, $km);
        $targetSec   = ($event->target_time_hours * 60 + $event->target_time_minutes) * 60;
        $targetPace  = $targetSec > 0 ? $targetSec / $km : null;

        // Positiv heisst: das Ziel ist schneller als die heutige Form.
        $deltaSec = $targetPace !== null && $predicted
            ? (int) round(($predicted['total_sec'] / $km) - $targetPace)
            : null;

        return $paces + [
            'race_km'        => round($km, 3),
            'target_pace'    => $targetPace !== null ? $this->pace($targetPace) : null,
            'target_time'    => $targetSec > 0 ? $this->clock($targetSec) : null,
            'predicted_pace' => $predicted['pace'] ?? null,
            'predicted_time' => $predicted['time'] ?? null,
            'delta_sec'      => $deltaSec,
            'verdict'        => $deltaSec === null ? null : $this->verdict($deltaSec),
        ];
    }

    /**
     * Der Abschnitt für den Prompt. Bewusst als Tabelle mit Zahlen — „locker
     * in Zone 2" ist eine Absicht, „5:08–5:43 min/km" ist eine Anweisung.
     */
    public function toPromptSection(array $p): string
    {
        $lines = [
            "- ZIELRENNTEMPO (darauf führt der Plan hin): " . ($p['target_pace'] ? "{$p['target_pace']} min/km" : 'keine Zielzeit hinterlegt'),
            "- Schwellenpace (T): {$p['threshold']} min/km",
            "- Ruhiges Dauertempo aus der heutigen Form: {$p['marathon']} min/km",
            "- Locker / Grundlage (Zone 2): {$p['easy']} min/km",
            "- Langer Lauf: {$p['long']} min/km",
            "- Intervalle (Zone 4–5): {$p['interval']} min/km",
            "- Kurze schnelle Wiederholungen: schneller als {$p['repetition']} min/km",
        ];

        $text = "\n\n**Trainingstempi (aus der gemessenen Schwellenpace berechnet — benutze DIESE Zahlen in pace_target und description, erfinde keine eigenen):**\n"
            . implode("\n", $lines);

        if ($p['target_pace']) {
            $text .= "\nFür Abschnitte im Renntempo gilt das Zielrenntempo {$p['target_pace']} min/km, nicht das Dauertempo.";
        }

        if ($p['predicted_time'] && $p['target_time']) {
            $delta = $p['delta_sec'];
            $sign  = $delta > 0 ? "{$delta} s/km schneller als die heutige Form" : abs($delta) . ' s/km langsamer als die heutige Form';

            $text .= "\n\n**Ziel gegen Ist:** Zielzeit {$p['target_time']} ({$p['target_pace']} min/km) — "
                . "aus der aktuellen Schwellenpace ergibt sich {$p['predicted_time']} ({$p['predicted_pace']} min/km). "
                . "Das Ziel verlangt also ein Renntempo, das {$sign} ist.\n"
                . "Einordnung: {$p['verdict']}";
        }

        return $text;
    }

    /** Wie ambitioniert das Ziel gemessen an der heutigen Form ist. */
    private function verdict(int $deltaSec): string
    {
        return match (true) {
            $deltaSec >  25 => 'Das Ziel liegt deutlich über der heutigen Form. Sage im description der Schlüsseleinheiten offen, was dafür nötig wäre, und plane das spezifische Renntempo trotzdem konsequent — aber ohne den Umfang zu überziehen.',
            $deltaSec >   8 => 'Das Ziel ist ambitioniert, aber erreichbar. Die Einheiten am Renntempo sind der Hebel: plane sie regelmäßig und schütze sie durch genug lockeres Volumen.',
            $deltaSec >= -8 => 'Das Ziel passt zur heutigen Form. Halte den Block stabil, setze auf spezifisches Renntempo und riskiere keine Formeinbrüche durch zu viel Härte.',
            default         => 'Das Ziel ist konservativ — die heutige Form trägt mehr. Weise in der description einer Schlüsseleinheit darauf hin, dass ein ehrgeizigeres Ziel realistisch wäre, und plane das Renntempo eher am oberen Ende.',
        };
    }

    /** Zieldistanz in Kilometern, oder null beim Backyard. */
    private function raceKm(Event $event): ?float
    {
        if ($event->isBackyard()) {
            return null;
        }

        return RacePredictionService::ANCHORS[$event->race_distance]['km']
            ?? ($event->distance_km > 0 ? (float) $event->distance_km : null);
    }

    private function pace(float $secondsPerKm): string
    {
        return sprintf('%d:%02d', (int) ($secondsPerKm / 60), (int) $secondsPerKm % 60);
    }

    private function range(float $from, float $to): string
    {
        return $this->pace($from) . '–' . $this->pace($to);
    }

    private function clock(int $sec): string
    {
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);

        return $h > 0 ? sprintf('%d:%02d Std', $h, $m) : "{$m} Min";
    }
}

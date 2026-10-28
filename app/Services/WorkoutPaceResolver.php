<?php

namespace App\Services;

use App\Models\RunnerProfile;
use App\Models\Workout;

/**
 * Aus einem geteilten Workout wird eine Einheit für DIESEN Athleten.
 *
 * Dass Workouts überhaupt teilbar sind, liegt an einer Entscheidung im
 * Builder: seine Blöcke tragen `pace_zone`, keine festen Paces. „4×1000 m
 * in Zone 5" ist für jeden Läufer richtig, „4×1000 m in 4:10" nur für
 * einen. Die Sekunden entstehen hier, aus dem VDOT des jeweiligen
 * Athleten — derselben Zahl, aus der Plan, Zonen und Prognose kommen.
 */
class WorkoutPaceResolver
{
    /**
     * Das Workout in konkrete Werte übersetzen.
     *
     * Ein Abschnitt traegt `distance_m`, wenn das Workout ihn nach Strecke
     * vorgibt — dann ist die Strecke die Ansage und `duration_min` nur die
     * daraus geschaetzte Dauer.
     *
     * @return array{steps: list<array<string, mixed>>, duration_min: int, distance_km: float|null, pace_target: string|null, zone: int|null}
     */
    public function resolve(Workout $workout, ?RunnerProfile $profile): array
    {
        $steps    = [];
        $totalMin = 0.0;
        $totalKm  = 0.0;

        foreach ($workout->blocks ?? [] as $block) {
            $type = $block['type'] ?? 'active';

            // Wiederholungs- und Progressionsblöcke tragen ihre Abschnitte
            // in `steps`; alles andere ist ein einzelner Abschnitt.
            if (! empty($block['steps']) && is_array($block['steps'])) {
                $reps = $type === 'repeat' ? max(1, (int) ($block['repetitions'] ?? 1)) : 1;

                foreach ($block['steps'] as $inner) {
                    $steps[] = $this->step($inner, $profile, $reps, $totalMin, $totalKm, $block);
                }

                continue;
            }

            $steps[] = $this->step($block, $profile, 1, $totalMin, $totalKm, $block);
        }

        // Die Pace der Einheit ist die ihres härtesten Arbeitsabschnitts —
        // die Zahl, nach der der Athlet tatsächlich läuft.
        $work = collect($steps)->where('type', 'work')->sortByDesc('zone')->first();

        return [
            'steps'        => $steps,
            'duration_min' => (int) round($totalMin),
            'distance_km'  => $totalKm > 0 ? round($totalKm, 1) : null,
            'pace_target'  => $work['pace_target'] ?? null,
            'zone'         => $work['zone'] ?? null,
        ];
    }

    /**
     * Ein Abschnitt, mit den Paces dieses Athleten.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $parent
     * @return array<string, mixed>
     */
    private function step(array $raw, ?RunnerProfile $profile, int $reps, float &$totalMin, float &$totalKm, array $parent): array
    {
        // Der Builder ist hier uneinheitlich: Wiederholungsschritte tragen
        // `pace_zone`, Progressionsschritte `zone`. Beides gilt.
        $zone = (int) ($raw['pace_zone'] ?? $raw['zone'] ?? $parent['pace_zone'] ?? 2);

        $paceSec = $this->paceSecondsForZone($profile, $zone);
        $mode    = $raw['duration_mode'] ?? 'time';
        $meters  = (int) ($raw['distance_m'] ?? 0);

        // Nach Strecke oder nach Zeit — das entscheidet der Ersteller, und
        // die Entscheidung bleibt erhalten. Frueher wurden die Meter hier in
        // Minuten umgerechnet und danach verworfen; aus „500 m schnell"
        // wurde „2 min", und die Ansage war weg.
        $byDistance = $mode === 'distance' && $meters > 0;

        if ($byDistance) {
            // Die Minuten sind ab hier nur noch eine Schaetzung: sie tragen
            // Balkenbreite und Gesamtdauer. Ohne Pace laesst sich nichts
            // schaetzen — dann steht dort nichts statt einer erfundenen Zahl.
            $minutes = $paceSec ? max(1, (int) round(($meters / 1000) * $paceSec / 60)) : null;
        } else {
            // Sekundengenau: aus „30 s Zone 5" wurde frueher „1 min" — die
            // Uhr lief dann doppelt so lange, wie der Ersteller wollte.
            $minutes = self::minutes((int) ($raw['duration_sec'] ?? 600));
        }

        $stepType = $this->stepType($raw['type'] ?? $parent['type'] ?? 'active');
        $isRest   = $stepType === 'rest';

        $totalMin += ($minutes ?? 0) * $reps;

        // Steht die Strecke fest, ist sie die Quelle — nicht der Umweg ueber
        // die schon gerundeten Minuten.
        if ($byDistance) {
            $totalKm += ($meters / 1000) * $reps;
        } elseif ($paceSec && $minutes) {
            $totalKm += ($minutes * $reps * 60) / $paceSec;
        }

        return [
            'type'         => $stepType,
            'label'        => $raw['label'] ?? $this->labelFor($stepType, $meters),
            'distance_m'   => $byDistance ? $meters : null,
            'duration_min' => $minutes,
            // Pausen bekommen kein Tempo vorgeschrieben — traben heisst traben.
            'pace_target'  => $isRest ? null : PaceFormat::fromSeconds($paceSec ?: null),
            'zone'         => $isRest ? 1 : $zone,
            'repetitions'  => $reps > 1 ? $reps : null,
        ];
    }

    /**
     * Sekunden als Minuten: ganze Minuten als int, alles andere mit der
     * Sekunde als Bruch (30 s = 0.5). Die Schritte tragen nur `duration_min`,
     * und eine Rundung auf die Minute ist bei kurzen Intervallen falsch.
     */
    private static function minutes(int $seconds): int|float
    {
        $seconds = max(1, $seconds);

        return $seconds % 60 === 0 ? intdiv($seconds, 60) : round($seconds / 60, 4);
    }

    /** Die Blocktypen des Builders auf die vier der Einheit abbilden. */
    private function stepType(string $blockType): string
    {
        return match ($blockType) {
            'warmup'          => 'warmup',
            'cooldown'        => 'cooldown',
            'rest', 'recovery' => 'rest',
            default           => 'work',
        };
    }

    private function labelFor(string $type, int $meters): string
    {
        if ($meters > 0) {
            return $meters >= 1000
                ? rtrim(rtrim(number_format($meters / 1000, 1, ',', ''), '0'), ',') . ' km'
                : "{$meters} m";
        }

        return match ($type) {
            'warmup'   => 'Einlaufen',
            'cooldown' => 'Auslaufen',
            'rest'     => 'Pause',
            default    => 'Abschnitt',
        };
    }

    /**
     * Die Pace einer Zone in Sekunden je Kilometer, aus dem VDOT des Athleten.
     *
     * Nicht die Mitte des Zonenbereichs: „Zone 4" im Builder heisst
     * Schwelle, also T, und „Zone 5" heisst Intervall, also I — siehe
     * {@see Vdot::ZONE_TARGET}. Die Mitte von Zone 5 gab es auch gar nicht,
     * der Bereich ist nach oben offen.
     */
    private function paceSecondsForZone(?RunnerProfile $profile, int $zone): ?float
    {
        $vdot = $profile?->vdot();

        return $vdot ? Vdot::paceAt($vdot, Vdot::ZONE_TARGET[max(1, min(5, $zone))]) : null;
    }
}

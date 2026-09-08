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
 * einen. Die Sekunden entstehen hier, aus der Zonentabelle des jeweiligen
 * Profils.
 *
 * Fehlt die Tabelle — neues Profil, Schwellenpace noch nicht berechnet —,
 * wird sie aus `threshold_speed` abgeleitet. Die Abstände dafür stehen
 * schon im Prompt, mit dem die Zonen sonst berechnet werden; sie hier ein
 * zweites Mal zu erfinden wäre die Sorte zweiter Wahrheit, die dieses
 * Projekt teuer bezahlt hat.
 */
class WorkoutPaceResolver
{
    /**
     * Abstand zur Schwellenpace je Zone, in Sekunden je Kilometer.
     *
     * Aus `AthleteProfileService::calculatePaceZonesWithAI()` — dieselben
     * Grenzen, nur ohne Modellaufruf, jeweils die Mitte des Bereichs.
     * Zone 5 liegt unter der Schwelle, deshalb negativ.
     *
     * @var array<int, int>
     */
    private const ZONE_OFFSET_SECONDS = [
        1 => 100,   // Recovery:  mehr als +1:21
        2 => 64,    // Easy:      +0:46 bis +1:21
        3 => 34,    // Tempo:     +0:21 bis +0:46
        4 => 10,    // Threshold: Schwelle bis +0:21
        5 => -12,   // VO2max:    unter Schwelle − 0:04
    ];

    /**
     * Das Workout in konkrete Werte übersetzen.
     *
     * @return array{steps: list<array<string, mixed>>, duration_min: int, distance_km: float|null, pace_target: string|null, zone: int|null}
     */
    public function resolve(Workout $workout, ?RunnerProfile $profile): array
    {
        $steps    = [];
        $totalMin = 0;
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
            'duration_min' => $totalMin,
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
    private function step(array $raw, ?RunnerProfile $profile, int $reps, int &$totalMin, float &$totalKm, array $parent): array
    {
        // Der Builder ist hier uneinheitlich: Wiederholungsschritte tragen
        // `pace_zone`, Progressionsschritte `zone`. Beides gilt.
        $zone = (int) ($raw['pace_zone'] ?? $raw['zone'] ?? $parent['pace_zone'] ?? 2);

        $paceSec = $this->paceSecondsForZone($profile, $zone);
        $mode    = $raw['duration_mode'] ?? 'time';
        $meters  = (int) ($raw['distance_m'] ?? 0);

        if ($mode === 'distance' && $meters > 0 && $paceSec) {
            $minutes = max(1, (int) round(($meters / 1000) * $paceSec / 60));
        } else {
            $minutes = max(1, (int) round(((int) ($raw['duration_sec'] ?? 600)) / 60));
        }

        $stepType = $this->stepType($raw['type'] ?? $parent['type'] ?? 'active');
        $isRest   = $stepType === 'rest';

        $totalMin += $minutes * $reps;

        if ($paceSec) {
            $totalKm += ($minutes * $reps * 60) / $paceSec;
        }

        return [
            'type'         => $stepType,
            'label'        => $raw['label'] ?? $this->labelFor($stepType, $meters),
            'duration_min' => $minutes,
            // Pausen bekommen kein Tempo vorgeschrieben — traben heisst traben.
            'pace_target'  => $isRest ? null : PaceFormat::fromSeconds($paceSec ?: null),
            'zone'         => $isRest ? 1 : $zone,
            'repetitions'  => $reps > 1 ? $reps : null,
        ];
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
     * Die Pace einer Zone in Sekunden je Kilometer.
     *
     * Bevorzugt aus der berechneten Zonentabelle des Athleten; dort steht
     * ein Bereich, genommen wird die Mitte. Ohne Tabelle aus der
     * Schwellenpace, mit denselben Abständen.
     */
    private function paceSecondsForZone(?RunnerProfile $profile, int $zone): ?float
    {
        $zone  = max(1, min(5, $zone));
        $zones = $profile?->pace_zones;

        if (is_array($zones) && isset($zones["z{$zone}"])) {
            $min = $this->toSeconds($zones["z{$zone}"]['min_pace'] ?? null);
            $max = $this->toSeconds($zones["z{$zone}"]['max_pace'] ?? null);

            if ($min && $max) {
                return ($min + $max) / 2;
            }

            if ($min || $max) {
                return $min ?: $max;
            }
        }

        $threshold = (float) ($profile?->threshold_speed ?? 0);

        if ($threshold <= 0) {
            return null;
        }

        return $threshold * 60 + (self::ZONE_OFFSET_SECONDS[$zone] ?? 0);
    }

    /** „5:30" → 330 Sekunden. */
    private function toSeconds(?string $pace): ?float
    {
        if (! $pace || ! str_contains($pace, ':')) {
            return null;
        }

        [$min, $sec] = array_pad(explode(':', trim($pace), 2), 2, '0');

        $seconds = ((int) $min) * 60 + (int) $sec;

        return $seconds > 0 ? (float) $seconds : null;
    }
}

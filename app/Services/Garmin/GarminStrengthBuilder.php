<?php

namespace App\Services\Garmin;

use App\Models\TrainingSession;
use App\Services\Strength\StrengthCatalog;
use InvalidArgumentException;

/**
 * Eine Kraft-, Rumpf- oder Mobilitätseinheit als Workout für die
 * Training API V2 (`sport = STRENGTH_TRAINING`).
 *
 * Je Übung ein `WorkoutRepeatStep` über die Sätze: die Übung selbst und
 * danach eine Pause. Die Uhr zeigt mit `exerciseCategory`/`exerciseName`
 * ihre eigene Animation und zählt die Wiederholungen.
 *
 * - „10" → `REPS` 10, „45 s" → `TIME` 45 s, „8–10" → die obere Zahl.
 *   Was sich nicht lesen lässt, wird `OPEN` (Rundentaste).
 * - „je Bein/Seite/Arm" verdoppelt: der Satz ist erst mit beiden Seiten
 *   fertig. Die Beschreibung des Schritts sagt, wie es gemeint ist.
 * - Ein Gewicht nur, wenn „12 kg" dasteht; „mittel" oder „Band" bleibt Text.
 *
 * Kategorie und Name kommen aus dem Katalog (`strength_exercises.garmin_*`)
 * und werden beim Senden gelesen — eine Änderung im Admin kommt beim
 * nächsten Abgleich auf der Uhr an.
 */
class GarminStrengthBuilder
{
    public const TYPES = ['strength', 'core', 'mobility'];

    /** Pause nach jedem Satz, in Sekunden. */
    public const REST_SEC = ['strength' => 60, 'core' => 30, 'mobility' => 15];

    public function __construct(private readonly StrengthCatalog $catalog) {}

    public static function supports(TrainingSession $session): bool
    {
        return in_array($session->type, self::TYPES, true)
            && is_array($session->exercises) && $session->exercises !== [];
    }

    /** @return array<string, mixed> */
    public function forSession(TrainingSession $session): array
    {
        $rest  = self::REST_SEC[$session->type] ?? 60;
        $order = 0;
        $steps = [];

        foreach (array_values($session->exercises ?? []) as $ex) {
            if (! is_array($ex)) {
                continue;
            }
            $steps[] = $this->exercise($ex, $rest, $order);
        }

        if ($order > GarminWorkoutBuilder::MAX_STEPS) {
            throw new InvalidArgumentException("Workout hat {$order} Schritte, Garmin nimmt hoechstens " . GarminWorkoutBuilder::MAX_STEPS . '.');
        }

        return [
            'workoutName'                => mb_substr($session->title ?: 'Kraft', 0, 50, 'UTF-8'),
            'description'                => $session->description ? mb_substr($session->description, 0, 1024, 'UTF-8') : null,
            'sport'                      => 'STRENGTH_TRAINING',
            'workoutProvider'            => GarminWorkoutBuilder::PROVIDER,
            'workoutSourceId'            => GarminWorkoutBuilder::PROVIDER,
            'isSessionTransitionEnabled' => false,
            'segments'                   => [[
                'segmentOrder' => 1,
                'sport'        => 'STRENGTH_TRAINING',
                'steps'        => $steps,
            ]],
        ];
    }

    /**
     * „45 s je Seite" → ['TIME', 90, 'je Seite'], „10" → ['REPS', 10, null].
     *
     * @return array{0: string, 1: ?float, 2: ?string}
     */
    public static function amount(?string $reps): array
    {
        $text = mb_strtolower(trim((string) $reps), 'UTF-8');
        $side = preg_match('/je\s+(bein|seite|arm)/u', $text, $m) ? 'je ' . ucfirst($m[1]) : null;
        $factor = $side ? 2 : 1;

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(min|minuten?)\b/u', $text, $m)) {
            return ['TIME', (float) str_replace(',', '.', $m[1]) * 60 * $factor, $side];
        }
        if (preg_match('/(\d+)\s*(s|sek|sekunden|sec)\b/u', $text, $m)) {
            return ['TIME', (float) $m[1] * $factor, $side];
        }
        if (preg_match_all('/\d+/', $text, $m) && $m[0] !== []) {
            return ['REPS', (float) max(array_map('intval', $m[0])) * $factor, $side];
        }

        return ['OPEN', null, $side];
    }

    /** „12 kg", „2×8 kg" → 12 bzw. 8; sonst null. */
    public static function kilograms(?string $load): ?float
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*kg\b/iu', (string) $load, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }

    /** @param array<string, mixed> $ex */
    private function exercise(array $ex, int $rest, int &$order): array
    {
        $entry = $this->catalog->lookup($ex);
        // Kategorie und Name nur zusammen — eine halbe Angabe könnte Garmin
        // das ganze Workout ablehnen lassen.
        $garmin = $entry?->garmin_category && $entry->garmin_name;
        $name  = (string) ($ex['name'] ?? $entry?->name ?? 'Übung');
        $sets  = max(1, min(10, (int) ($ex['sets'] ?? 1)));

        [$durationType, $durationValue] = self::amount($ex['reps'] ?? null);
        $kg = self::kilograms($ex['load'] ?? null);

        $text = trim(implode(' · ', array_filter([
            $name,
            trim((string) ($ex['reps'] ?? '')),
            trim((string) ($ex['load'] ?? '')),
            trim((string) ($ex['note'] ?? '')),
        ])));

        $repeat = [
            'type'        => 'WorkoutRepeatStep',
            'stepOrder'   => ++$order,
            'repeatType'  => 'REPEAT_UNTIL_STEPS_CMPLT',
            'repeatValue' => $sets,
            'steps'       => [],
        ];

        $repeat['steps'][] = [
            'type'              => 'WorkoutStep',
            'stepOrder'         => ++$order,
            'intensity'         => 'INTERVAL',
            'description'       => mb_substr($text, 0, 512, 'UTF-8'),
            'durationType'      => $durationType,
            'durationValue'     => $durationValue,
            'durationValueType' => null,
            'targetType'        => 'OPEN',
            'targetValue'       => null,
            'targetValueLow'    => null,
            'targetValueHigh'   => null,
            'targetValueType'   => null,
            'exerciseCategory'  => $garmin ? $entry->garmin_category : null,
            'exerciseName'      => $garmin ? $entry->garmin_name : null,
            'weightValue'       => $kg,
            'weightDisplayUnit' => $kg !== null ? 'KILOGRAM' : null,
        ];

        $repeat['steps'][] = [
            'type'              => 'WorkoutStep',
            'stepOrder'         => ++$order,
            'intensity'         => 'REST',
            'description'       => null,
            'durationType'      => 'TIME',
            'durationValue'     => (float) $rest,
            'durationValueType' => null,
            'targetType'        => 'OPEN',
            'targetValue'       => null,
            'targetValueLow'    => null,
            'targetValueHigh'   => null,
            'targetValueType'   => null,
        ];

        return $repeat;
    }
}

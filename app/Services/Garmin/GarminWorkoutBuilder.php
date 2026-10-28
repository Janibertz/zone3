<?php

namespace App\Services\Garmin;

use App\Models\TrainingSession;
use App\Services\Watch\AppleWorkoutWriter;
use InvalidArgumentException;

/**
 * Eine Laufeinheit als Workout fuer die Training API V2.
 *
 * Feldnamen nach der Training API Specification 2.0 (PDF im Portal,
 * Abschnitt 3). Die Schritte kommen in derselben Form wie bei der Apple
 * Watch, und sie werden auch genauso gelesen — zwei Uhren sollen dieselbe
 * Einheit nicht verschieden verstehen:
 *
 * - Eine Strecke ist die Ansage, die Minuten daneben sind nur geschaetzt.
 * - Aufeinanderfolgende Abschnitte mit derselben Wiederholungszahl sind ein
 *   Block (Belastung + Trabpause) → `WorkoutRepeatStep`.
 * - Eine Zielpace gilt nur fuer Belastung. Wer locker einlaeuft, soll nicht
 *   angepiept werden. Eine einzelne Pace wird ±5 % Geschwindigkeit
 *   (`AppleWorkoutWriter::paceRange()`).
 */
class GarminWorkoutBuilder
{
    /** Garmin: ein Workout mit einer Sportart hat hoechstens 100 Schritte. */
    public const MAX_STEPS = 100;

    /** `workoutProvider` und `workoutSourceId`, je hoechstens 20 Zeichen. */
    public const PROVIDER = 'Zone3';

    public function __construct(private readonly AppleWorkoutWriter $steps) {}

    /** @return array<string, mixed> */
    public function forSession(TrainingSession $session): array
    {
        return $this->build(
            $session->title ?: 'Training',
            $session->description,
            $this->steps->stepsFor($session),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $steps  Schritte im Format von `training_sessions.steps`
     * @return array<string, mixed>
     */
    public function build(string $name, ?string $description, array $steps): array
    {
        $order = 0;
        $out   = $this->blocks(array_values($steps), $order);

        if ($order > self::MAX_STEPS) {
            throw new InvalidArgumentException("Workout hat {$order} Schritte, Garmin nimmt hoechstens " . self::MAX_STEPS . '.');
        }

        return [
            'workoutName'                => mb_substr($name, 0, 50, 'UTF-8'),
            'description'                => $description ? mb_substr($description, 0, 1024, 'UTF-8') : null,
            'sport'                      => 'RUNNING',
            'workoutProvider'            => self::PROVIDER,
            'workoutSourceId'            => self::PROVIDER,
            'isSessionTransitionEnabled' => false,
            'segments'                   => [[
                'segmentOrder' => 1,
                'sport'        => 'RUNNING',
                'steps'        => $out,
            ]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function blocks(array $steps, int &$order): array
    {
        $out = [];

        for ($i = 0; $i < count($steps); $i++) {
            $reps  = max(1, (int) ($steps[$i]['repetitions'] ?? 1));
            $group = [$steps[$i]];

            while ($reps > 1 && isset($steps[$i + 1]) && (int) ($steps[$i + 1]['repetitions'] ?? 1) === $reps) {
                $group[] = $steps[++$i];
            }

            if ($reps === 1) {
                $out[] = $this->step($group[0], ++$order, inRepeat: false);
                continue;
            }

            // Die Wiederholung bekommt ihre Nummer vor ihren Schritten —
            // so zaehlt das Beispiel der Spezifikation (2, dann 3).
            $repeat = [
                'type'        => 'WorkoutRepeatStep',
                'stepOrder'   => ++$order,
                'repeatType'  => 'REPEAT_UNTIL_STEPS_CMPLT',
                'repeatValue' => $reps,
                'steps'       => [],
            ];
            foreach ($group as $step) {
                $repeat['steps'][] = $this->step($step, ++$order, inRepeat: true);
            }

            $out[] = $repeat;
        }

        return $out;
    }

    /** @param array<string, mixed> $step */
    private function step(array $step, int $order, bool $inRepeat): array
    {
        $type      = $step['type'] ?? 'work';
        $intensity = match ($type) {
            'warmup'             => 'WARMUP',
            'cooldown'           => 'COOLDOWN',
            'rest', 'recovery'   => 'RECOVERY',
            default              => $inRepeat ? 'INTERVAL' : 'ACTIVE',
        };

        $meters = (int) ($step['distance_m'] ?? 0);
        $sec    = (int) round(((float) ($step['duration_min'] ?? 0)) * 60);

        [$durationType, $durationValue, $durationUnit] = match (true) {
            $meters > 0 => ['DISTANCE', (float) $meters, 'METER'],
            $sec > 0    => ['TIME', (float) $sec, null],
            // Ohne beides beendet der Athlet den Abschnitt per Rundentaste.
            default     => ['OPEN', null, null],
        };

        $withPace = in_array($intensity, ['ACTIVE', 'INTERVAL'], true);
        $range    = $withPace ? AppleWorkoutWriter::paceRange($step['pace_target'] ?? null) : null;

        return [
            'type'              => 'WorkoutStep',
            'stepOrder'         => $order,
            'intensity'         => $intensity,
            'description'       => isset($step['label']) ? mb_substr((string) $step['label'], 0, 512, 'UTF-8') : null,
            'durationType'      => $durationType,
            'durationValue'     => $durationValue,
            'durationValueType' => $durationUnit,
            // PACE erwartet Geschwindigkeit in m/s; low ist die langsamere Grenze.
            'targetType'        => $range ? 'PACE' : 'OPEN',
            'targetValue'       => null,
            'targetValueLow'    => $range ? round($range[0], 4) : null,
            'targetValueHigh'   => $range ? round($range[1], 4) : null,
            'targetValueType'   => null,
        ];
    }
}

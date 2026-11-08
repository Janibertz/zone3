<?php

namespace App\Services\Watch;

use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Garmin\GarminWorkoutSync;

/**
 * Laufeinheiten für WorkoutKit in der iOS-App (seit 08.11.2026).
 *
 * Die App plant sie mit `WorkoutScheduler` direkt in die Training-App der
 * Apple Watch (`ios/App/App/WatchWorkoutsPlugin.swift`). Das Plugin rechnet
 * nichts; Abschnitte, Blöcke und Pace-Bereiche kommen von hier, nach
 * denselben Regeln wie die .workout-Datei (`AppleWorkoutWriter`) und die
 * Garmin-Uhr: Strecke vor Zeit, gleiche Wiederholungszahl = ein Block,
 * Pace-Alarm nur auf Belastung, ±5 % bei einer einzelnen Pace.
 *
 * Die `id` ist eine UUID aus Einheit und Inhalt. Ändert der Plan die
 * Einheit, ändert sich die ID, und die App ersetzt das Training auf der Uhr.
 */
class WorkoutKitPayload
{
    /** Wie weit die App vorausplant — dasselbe Fenster wie bei Garmin. */
    public const DAYS_AHEAD = GarminWorkoutSync::DAYS_AHEAD;

    public function __construct(private readonly AppleWorkoutWriter $writer) {}

    /**
     * Was auf der Watch stehen soll: geplante Läufe der nächsten sieben Tage
     * aus dem aktiven Plan und eingeplante Builder-Workouts, ohne Vereins-
     * termin (den gibt der Trainer vor), Rennen und Radeinheiten.
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(User $user): array
    {
        $plan = $user->activeTrainingPlan;

        $sessions = TrainingSession::where('user_id', $user->id)
            ->where(function ($q) use ($plan) {
                $q->whereNull('training_plan_id');
                if ($plan) {
                    $q->orWhere('training_plan_id', $plan->id);
                }
            })
            ->where('status', 'planned')
            ->whereDate('planned_date', '>=', today()->toDateString())
            ->whereDate('planned_date', '<=', today()->addDays(self::DAYS_AHEAD - 1)->toDateString())
            ->orderBy('planned_date')->orderBy('sort_order')
            ->get();

        $weekly    = $user->runnerProfile?->weekly_availability ?? [];
        $overrides = $plan?->availability_overrides ?? [];

        return $sessions
            ->reject(fn (TrainingSession $s) => GarminWorkoutSync::isFixedAppointment($s, $weekly, $overrides))
            ->filter(fn (TrainingSession $s) => AppleWorkoutWriter::supports($s))
            ->map(fn (TrainingSession $s) => $this->forSession($s))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function forSession(TrainingSession $session): array
    {
        $steps = $this->writer->stepsFor($session);

        $warmup = null;
        $cooldown = null;
        if ($steps && ($steps[0]['type'] ?? null) === 'warmup' && empty($steps[0]['repetitions'])) {
            $warmup = array_shift($steps);
        }
        if ($steps && (end($steps)['type'] ?? null) === 'cooldown' && empty(end($steps)['repetitions'])) {
            $cooldown = array_pop($steps);
        }

        $body = [
            'session'  => $session->id,
            'date'     => $session->planned_date->toDateString(),
            'name'     => mb_substr((string) $session->title, 0, 60),
            'activity' => 'running',
            'location' => 'outdoor',
            // Ein- und Auslaufen ohne Pace-Alarm: wer locker einläuft, soll
            // nicht angepiept werden.
            'warmup'   => $warmup ? $this->step($warmup, withPace: false) : null,
            'blocks'   => $this->blocks($steps),
            'cooldown' => $cooldown ? $this->step($cooldown, withPace: false) : null,
        ];

        return ['id' => self::uuid($session->id, $body)] + $body;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{iterations: int, steps: list<array<string, mixed>>}>
     */
    private function blocks(array $steps): array
    {
        $blocks = [];

        for ($i = 0; $i < count($steps); $i++) {
            $reps  = max(1, (int) ($steps[$i]['repetitions'] ?? 1));
            $group = [$steps[$i]];

            while ($reps > 1 && isset($steps[$i + 1]) && (int) ($steps[$i + 1]['repetitions'] ?? 1) === $reps) {
                $group[] = $steps[++$i];
            }

            $blocks[] = [
                'iterations' => $reps,
                'steps'      => array_map(function (array $step) {
                    $recovery = ($step['type'] ?? null) === 'rest';

                    return ['purpose' => $recovery ? 'recovery' : 'work'] + $this->step($step, withPace: ! $recovery);
                }, $group),
            ];
        }

        return $blocks;
    }

    /** @return array{distance_m: ?int, seconds: ?int, speed: ?array{0: float, 1: float}} */
    private function step(array $step, bool $withPace): array
    {
        $meters = (int) ($step['distance_m'] ?? 0);
        $sec    = (int) round(((float) ($step['duration_min'] ?? 0)) * 60);
        $range  = $withPace ? AppleWorkoutWriter::paceRange($step['pace_target'] ?? null) : null;

        // Steht eine Strecke da, ist sie die Ansage — die Minuten sind dann
        // nur geschätzt. Ohne beides: offen, der Athlet beendet per Tipp.
        return [
            'distance_m' => $meters > 0 ? $meters : null,
            'seconds'    => $meters > 0 ? null : ($sec > 0 ? $sec : null),
            'speed'      => $range ? [round($range[0], 4), round($range[1], 4)] : null,
        ];
    }

    /** Eine UUID (Version 5) aus Einheit und Inhalt — gleich, solange beides gleich bleibt. */
    public static function uuid(int $sessionId, array $body): string
    {
        $hash = sha1('zone3-watch|' . $sessionId . '|' . json_encode($body));

        return sprintf('%s-%s-5%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec($hash[16]) & 0x3) | 0x8),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }
}

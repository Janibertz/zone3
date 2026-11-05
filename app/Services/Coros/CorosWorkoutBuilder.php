<?php

namespace App\Services\Coros;

use App\Models\TrainingSession;
use App\Services\Watch\AppleWorkoutWriter;

/**
 * Eine Laufeinheit als COROS-Workout (`course` von MCP).
 *
 * Die Schritte werden gelesen wie bei Garmin und der Apple Watch — drei Uhren
 * sollen eine Einheit nicht verschieden verstehen:
 *
 * - Eine Strecke ist die Ansage (`targetType` 1, Meter), sonst Zeit (2,
 *   Sekunden), ohne beides frei (4, Rundentaste).
 * - Aufeinanderfolgende Abschnitte mit derselben Wiederholungszahl sind ein
 *   Block → `intervalGroup` mit `repeats` (COROS: hoechstens 20).
 * - Pace nur auf Belastung, als Bereich in Sekunden pro km (`intensityType`
 *   2). Eine einzelne Pace wird ±5 % Geschwindigkeit wie bei Garmin.
 *
 * Feldnamen aus dem Schema von `createScheduledWorkout` (tools/list am
 * 05.11.2026); ein Testworkout kam so in Jans COROS-App an.
 */
class CorosWorkoutBuilder
{
    public const MAX_REPEATS = 20;

    /** Erlaubter Pace-Bereich bei COROS, Sekunden pro km. */
    private const PACE_MIN = 120;
    private const PACE_MAX = 1499;

    public function __construct(private readonly AppleWorkoutWriter $steps) {}

    /** Was auf die COROS-Uhr darf: Laeufe ohne Rennen und Rad. Kraft kann MCP nicht. */
    public static function supports(TrainingSession $session): bool
    {
        return AppleWorkoutWriter::supports($session);
    }

    /** @return array<string, mixed> */
    public function forSession(TrainingSession $session): array
    {
        return $this->build(
            $session->title ?: 'Lauf',
            $session->description,
            $this->steps->stepsFor($session),
        );
    }

    /**
     * Was auf einem Platz stehen bleibt, dessen Einheit weggefallen ist.
     * Loeschen kann Zone3 dort nicht; der Athlet soll es nicht laufen.
     */
    public function placeholder(): array
    {
        return [
            'courseName'        => 'Entfällt (Plan in Zone3 geändert)',
            'courseDescription' => 'Diese Einheit steht in Zone3 nicht mehr an diesem Tag. Du kannst sie in der COROS-App löschen.',
            'sportType'         => 1,
            'sections'          => [['sectionType' => 2, 'targetType' => 4]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    public function build(string $name, ?string $description, array $steps): array
    {
        $description = trim((string) $description);

        return [
            'courseName'        => mb_substr($name, 0, 100, 'UTF-8'),
            // Pflichtfeld, darf nicht leer sein.
            'courseDescription' => $description !== '' ? mb_substr($description, 0, 1000, 'UTF-8') : 'Lauf aus deinem Zone3-Plan.',
            'sportType'         => 1,
            'sections'          => $this->sections(array_values($steps)) ?: [['sectionType' => 2, 'targetType' => 4]],
        ];
    }

    /** @param list<array<string, mixed>> $steps */
    private function sections(array $steps): array
    {
        $out = [];

        for ($i = 0; $i < count($steps); $i++) {
            $reps  = max(1, (int) ($steps[$i]['repetitions'] ?? 1));
            $group = [$steps[$i]];

            while ($reps > 1 && isset($steps[$i + 1]) && (int) ($steps[$i + 1]['repetitions'] ?? 1) === $reps) {
                $group[] = $steps[++$i];
            }

            if ($reps === 1) {
                $out[] = $this->section($group[0], inGroup: false);
                continue;
            }

            // Mehr als 20 Wiederholungen nimmt COROS nicht; dann zwei Bloecke.
            for ($left = $reps; $left > 0; $left -= self::MAX_REPEATS) {
                $out[] = [
                    'intervalGroup' => true,
                    'repeats'       => min($left, self::MAX_REPEATS),
                    'sets'          => array_map(fn ($s) => $this->section($s, inGroup: true), $group),
                ];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $step */
    private function section(array $step, bool $inGroup): array
    {
        $type = match ($step['type'] ?? 'work') {
            'warmup'           => 1,
            'rest', 'recovery' => 3,
            'cooldown'         => 4,
            default            => 2,
        };

        // In einem Block erlaubt COROS nur Belastung und Erholung.
        if ($inGroup && ! in_array($type, [2, 3], true)) {
            $type = 2;
        }

        $meters = (int) ($step['distance_m'] ?? 0);
        $sec    = (int) round(((float) ($step['duration_min'] ?? 0)) * 60);

        $section = match (true) {
            $meters > 0 => ['sectionType' => $type, 'targetType' => 1, 'targetValue' => $meters],
            $sec > 0    => ['sectionType' => $type, 'targetType' => 2, 'targetValue' => $sec],
            default     => ['sectionType' => $type, 'targetType' => 4],
        };

        if ($type === 2 && ($range = self::paceRange($step['pace_target'] ?? null))) {
            $section += [
                'intensityType'       => 2,
                'intensityValueStart' => $range[0],
                'intensityValueEnd'   => $range[1],
            ];
        }

        return $section;
    }

    /**
     * Der Pace-Bereich in Sekunden pro km, schnell vor langsam — aus
     * derselben Rechnung wie bei Garmin (Geschwindigkeit in m/s).
     *
     * @return array{0:int, 1:int}|null
     */
    public static function paceRange(?string $pace): ?array
    {
        $speeds = AppleWorkoutWriter::paceRange($pace);
        if (! $speeds) {
            return null;
        }

        $fast = (int) round(1000 / $speeds[1]);
        $slow = (int) round(1000 / $speeds[0]);

        $fast = max(self::PACE_MIN, min(self::PACE_MAX - 1, $fast));
        $slow = max($fast + 1, min(self::PACE_MAX, $slow));

        return [$fast, $slow];
    }
}

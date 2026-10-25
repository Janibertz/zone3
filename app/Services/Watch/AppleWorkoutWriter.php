<?php

namespace App\Services\Watch;

use App\Models\TrainingSession;
use Illuminate\Support\Str;

/**
 * Eine Laufeinheit als .workout-Datei für die Apple Watch.
 *
 * Strukturierte Trainings nimmt die Watch nur über WorkoutKit an. Eine eigene
 * iOS-App braucht es dafür nicht: die Fitness-App importiert dieselbe Datei,
 * die sie beim Teilen eines Trainings selbst erzeugt — antippen, „Hinzufügen",
 * und das Training steht in der Training-App.
 *
 * Das Format ist eine Protobuf-Serialisierung eines WorkoutKit-`WorkoutPlan`,
 * von Apple **nicht dokumentiert**. Die Feldnummern stammen aus
 * github.com/matjic/ios-workout-generator (Apache-2.0) und aus Jans Tests auf
 * dem iPhone am 25.10.2026:
 *
 * - Zeit (Typ 1, Sekunden) — vom Autor geprüft, bei Jan importiert.
 * - Strecke (Typ 3, Messwert in Feld 4, Einheit 1 = Meter) — der Autor lag
 *   hier falsch (Typ 2); abgelesen aus einem echten Export von Jan, die
 *   Vorschau zeigte „1.000m".
 * - Pace-Bereich (Alarm Geschwindigkeit/Bereich, je Grenze Messwert +
 *   „aktuell"-Block) — von drei Varianten die einzige, die importierte;
 *   Vorschau „4'10''–4'20'' KM".
 *
 * Ein iOS-Update kann das jederzeit brechen. Wer hier etwas ändert, prüft es
 * am Gerät: `AppleWorkoutWriterTest` hält nur fest, was dort funktioniert hat.
 */
class AppleWorkoutWriter
{
    /**
     * Eine einzelne Zielpace wird ein Bereich von ±5 % Geschwindigkeit —
     * dieselbe Breite, die der fit-service für Garmin setzt. Zwei Uhren mit
     * zwei Toleranzen für dieselbe Einheit wären eine doppelte Wahrheit.
     */
    public const PACE_TOLERANCE = 0.05;

    private const ACTIVITY_RUNNING = 37;   // HKWorkoutActivityType
    private const LOCATION_OUTDOOR = 3;    // HKWorkoutSessionLocationType

    private const PURPOSE_WORK     = 1;
    private const PURPOSE_RECOVERY = 2;

    private const GOAL_TIME     = 1;
    private const GOAL_DISTANCE = 3;

    private const ALERT_SPEED = 2;
    private const ALERT_RANGE = 2;

    /** Welche Einheiten sich als Lauf auf die Watch legen lassen. */
    public static function supports(TrainingSession $session): bool
    {
        return $session->isRun()
            && $session->type !== 'race_prep'
            && ! $session->hasBikeSteps();
    }

    /**
     * Ob der Knopf erscheint. Wer Garmin verbunden hat, schickt seine
     * Einheiten an die Garmin-Uhr — ein zweiter Weg zu einer zweiten Uhr
     * wäre dort nur ein Knopf mehr (Entscheidung von Jan, 25.10.2026).
     * Die Route selbst bleibt offen; sie hängt nicht an der Verbindung.
     */
    public static function offered(TrainingSession $session, bool $garminConnected): bool
    {
        return ! $garminConnected && self::supports($session);
    }

    /**
     * Die Schritte der Einheit — oder, solange keine erzeugt wurden, die
     * Einheit als ein Abschnitt. Ein Modellaufruf beim Herunterladen wäre
     * ein GET, der Geld kostet.
     *
     * @return list<array<string, mixed>>
     */
    public function stepsFor(TrainingSession $session): array
    {
        if (! empty($session->steps)) {
            return array_values($session->steps);
        }

        return [[
            'type'         => 'work',
            'distance_m'   => $session->distance_km ? (int) round($session->distance_km * 1000) : null,
            'duration_min' => $session->duration_min,
            'pace_target'  => $session->pace_target,
        ]];
    }

    /** @param list<array<string, mixed>> $steps */
    public function write(string $name, array $steps, ?string $planId = null): string
    {
        $steps = array_values($steps);

        // Ein- und Auslaufen haben bei Apple eigene Plätze, aber nur je
        // einen, und nur am Rand.
        $warmup = null;
        $cooldown = null;
        if ($steps && ($steps[0]['type'] ?? null) === 'warmup' && empty($steps[0]['repetitions'])) {
            $warmup = array_shift($steps);
        }
        if ($steps && (end($steps)['type'] ?? null) === 'cooldown' && empty(end($steps)['repetitions'])) {
            $cooldown = array_pop($steps);
        }

        $body = $this->int(1, self::ACTIVITY_RUNNING)
            . $this->int(2, self::LOCATION_OUTDOOR)
            . $this->string(3, $name);

        // Ein- und Auslaufen ohne Pace-Alarm: wer locker einläuft, soll
        // nicht angepiept werden.
        if ($warmup) {
            $body .= $this->message(4, $this->step($warmup, withPace: false));
        }
        foreach ($this->blocks($steps) as $block) {
            $body .= $this->message(5, $block);
        }
        if ($cooldown) {
            $body .= $this->message(6, $this->step($cooldown, withPace: false));
        }

        return $this->string(9, strtoupper($planId ?? (string) Str::uuid()))
            . $this->message(11, $body)
            // Steht so in jeder exportierten Datei.
            . $this->int(1000, 1)
            . $this->int(1002, 5);
    }

    /**
     * Wiederholungen werden Blöcke: aufeinanderfolgende Abschnitte mit
     * derselben Anzahl gehören zusammen (Belastung + Trabpause), genau so,
     * wie zone3 sie speichert. Alles andere ist ein Block mit einem Durchgang.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<string>
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

            $body = '';
            foreach ($group as $step) {
                $purpose = ($step['type'] ?? null) === 'rest' ? self::PURPOSE_RECOVERY : self::PURPOSE_WORK;
                $body .= $this->message(1, $this->int(1, $purpose)
                    . $this->message(2, $this->step($step, withPace: $purpose === self::PURPOSE_WORK)));
            }

            $blocks[] = $body . $this->int(2, $reps);
        }

        return $blocks;
    }

    /** @param array<string, mixed> $step */
    private function step(array $step, bool $withPace): string
    {
        $out    = '';
        $meters = (int) ($step['distance_m'] ?? 0);
        $sec    = (int) round(((float) ($step['duration_min'] ?? 0)) * 60);

        // Steht eine Strecke da, ist sie die Ansage — die Minuten sind dann
        // nur geschätzt. Ohne beides: offen, der Athlet beendet per Tipp.
        if ($meters > 0) {
            $out .= $this->message(1, $this->int(1, self::GOAL_DISTANCE) . $this->message(4, $this->measure(1, $meters)));
        } elseif ($sec > 0) {
            $out .= $this->message(1, $this->int(1, self::GOAL_TIME) . $this->message(2, $this->measure(1, $sec)));
        }

        $range = $withPace ? self::paceRange($step['pace_target'] ?? null) : null;
        if ($range) {
            [$slow, $fast] = $range; // m/s
            $current = $this->message(2, $this->measure(1, 1.0));
            $bounds  = $this->message(1, $this->message(1, $this->measure(1, $slow)) . $current)
                . $this->message(2, $this->message(1, $this->measure(1, $fast)) . $current);

            $out .= $this->message(2, $this->int(1, self::ALERT_SPEED)
                . $this->int(2, self::ALERT_RANGE)
                . $this->message(4, $this->message(2, $bounds)));
        }

        return $out;
    }

    /**
     * Aus „4:10", „4:10-4:20" oder „4:10–4:20 min/km" ein Geschwindigkeits-
     * bereich [langsam, schnell] in m/s.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function paceRange(?string $pace): ?array
    {
        if (! $pace || ! preg_match_all('/(\d{1,2}):([0-5]\d)/', $pace, $m, PREG_SET_ORDER)) {
            return null;
        }

        $secs = array_map(fn ($x) => (int) $x[1] * 60 + (int) $x[2], array_slice($m, 0, 2));
        $secs = array_filter($secs, fn ($s) => $s > 0);
        if (! $secs) {
            return null;
        }

        if (count($secs) === 1) {
            $speed = 1000 / reset($secs);

            return [$speed * (1 - self::PACE_TOLERANCE), $speed * (1 + self::PACE_TOLERANCE)];
        }

        return [1000 / max($secs), 1000 / min($secs)];
    }

    // ── Protobuf, so wenig wie nötig ─────────────────────────────────────

    private function measure(int $unit, float $amount): string
    {
        return $this->int(1, $unit) . $this->tag(2, 1) . pack('e', $amount);
    }

    private function int(int $field, int $value): string
    {
        return $this->tag($field, 0) . $this->varint($value);
    }

    private function string(int $field, string $value): string
    {
        return $this->tag($field, 2) . $this->varint(strlen($value)) . $value;
    }

    private function message(int $field, string $body): string
    {
        return $this->string($field, $body);
    }

    private function tag(int $field, int $wireType): string
    {
        return $this->varint(($field << 3) | $wireType);
    }

    private function varint(int $n): string
    {
        $out = '';
        do {
            $byte = $n & 0x7F;
            $n >>= 7;
            $out .= chr($byte | ($n ? 0x80 : 0));
        } while ($n);

        return $out;
    }
}

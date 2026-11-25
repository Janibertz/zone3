<?php

namespace App\Services\Run;

use App\Models\Activity;
use App\Services\PaceFormat;
use App\Services\Watch\AppleWorkoutWriter;

/**
 * Soll und Ist einer Laufeinheit, Block für Block, mit einem Umsetzungswert
 * 0–100 (Mockup „Zone3 Einheiten mit Profil und Soll/Ist“, Jan 25.11.2026).
 *
 * Feste Regeln, kein Modellaufruf:
 *
 * - Die Schritte werden aufgefaltet wie das Band im Frontend (`stepBand()`):
 *   jede Wiederholung ein Abschnitt, die Pause dazwischen auch.
 * - Zugeordnet wird zuerst 1:1 über die Runden der Uhr: genau so viele
 *   Runden wie Abschnitte (eine winzige Schlussrunde zählt nicht), und jede
 *   ungefähr so lang wie ihr Abschnitt. Sonst (Autolap) werden die
 *   Abschnitte der Reihe nach auf die Messpunkte gelegt (`lapsFromStream()`,
 *   seit Etappe 4); ohne Messpunkte gibt es kein Soll/Ist. Eine Einheit aus
 *   nur einem Abschnitt (lockerer Lauf) wird gegen die ganze Aktivität gelegt.
 * - Ein Block im Pace-Bereich ist 100. Jede Sekunde daneben kostet 8 Punkte,
 *   und was kürzer gelaufen wurde als geplant, zählt anteilig.
 * - Gesamt: Belastungsblöcke nach geplanter Dauer gewichtet (80 %), dazu die
 *   lockeren Teile (20 %) — die kosten nur, wenn sie mehr als 10 s zu schnell
 *   waren. Langsam einlaufen ist kein Fehler.
 */
class WorkoutExecution
{
    /** Punkte je Sekunde/km neben dem Bereich. */
    public const POINTS_PER_SECOND = 8;

    /** Lockere Teile dürfen so viel schneller sein, ohne dass es kostet. */
    public const EASY_SLACK_SECONDS = 10;

    /** Anteil der lockeren Teile am Gesamtwert. */
    public const EASY_SHARE = 0.2;

    /** Ein Block mit mindestens diesem Wert gilt als geschafft. */
    public const DONE_SCORE = 80;

    /** Höhe je Zone im Profil, wie `ZONE_HEIGHT` in useSessionTypes.js. */
    public const ZONE_HEIGHT = [1 => 0.3, 2 => 0.45, 3 => 0.65, 4 => 0.85, 5 => 1.0];

    private const RUN_TYPES = ['Run', 'TrailRun', 'VirtualRun'];

    /**
     * @param  array|null  $steps      Schritte der verknüpften Einheit
     * @param  array|null  $paceZones  `z1`..`z5` mit min_pace/max_pace
     */
    public function compare(?array $steps, Activity $activity, ?array $paceZones = null, ?array $stream = null): ?array
    {
        if (! $steps || ! in_array($activity->type, self::RUN_TYPES, true)) {
            return null;
        }

        $segments = $this->segments(array_values($steps));
        if (! $segments) {
            return null;
        }

        $method = 'laps';
        $laps   = $this->laps($activity, $segments);
        if ($laps === null) {
            // Runden passen nicht (Autolap): die geplanten Abschnitte der
            // Reihe nach auf die Messpunkte legen (Jan, 25.11.2026).
            $laps   = $stream ? $this->lapsFromStream($segments, $stream) : null;
            $method = 'stream';
        }
        if ($laps === null) {
            return null;
        }

        $rows = [];
        $from = 0;
        foreach ($segments as $i => $seg) {
            $row          = $this->evaluate($seg, $laps[$i], $paceZones);
            $to           = $laps[$i]['_to'] ?? $from + (float) ($laps[$i]['elapsed_time'] ?? 0);
            $row['from']  = (int) round($from);
            $row['to']    = (int) round($to);
            $row['range'] = ($r = AppleWorkoutWriter::paceRange($seg['pace_target'] ?? null)) ? [(int) round(1000 / $r[1]), (int) round(1000 / $r[0])] : null;
            $rows[]       = $row;
            $from         = $to;
        }

        $work = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'work'));
        // Ein Ablauf ohne Belastung (lockerer oder langer Lauf): dann zählt
        // jeder Abschnitt wie ein Block, sonst gäbe es nichts zu bewerten.
        $blocks = $work ?: array_values(array_filter($rows, fn ($r) => $r['kind'] !== 'rest'));
        if (! $blocks) {
            return null;
        }

        $blockScore = $this->weighted($blocks, 'score');
        $easy       = $work ? array_values(array_filter($rows, fn ($r) => in_array($r['kind'], ['warmup', 'cooldown'], true) && $r['easy_score'] !== null)) : [];
        $easyScore  = $easy ? $this->weighted($easy, 'easy_score') : null;
        $score      = $easyScore === null
            ? $blockScore
            : $blockScore * (1 - self::EASY_SHARE) + $easyScore * self::EASY_SHARE;

        $planned = array_map(fn ($r) => $this->part($r['planned_min'], $r['planned_zone']), $rows);
        $actual  = array_map(fn ($r) => $this->part($r['actual_min'], $r['actual_zone']), $rows);

        $n = 0;
        return [
            'score'    => (int) round($score),
            'sentence' => $this->sentence($blocks, (bool) $work),
            'easy'     => $this->easyNote($easy),
            'blocks'   => array_map(function ($b) use (&$n) {
                $n++;

                return [
                    'n'       => $n,
                    'label'   => $b['label'],
                    'score'   => $b['score'],
                    'status'  => $b['status'],
                    'zone'    => $b['actual_zone'],
                    'target'  => $b['target'],
                    'actual'  => $b['actual'],
                    'hr'      => $b['hr'],
                    'planned_amount' => $b['planned_amount'],
                    'actual_amount'  => $b['actual_amount'],
                ];
            }, $blocks),
            'planned'  => $planned,
            'actual'   => $actual,
            'method'   => $method,
            // Wo jeder Abschnitt in der Zeitreihe liegt, mit Pace-Bereich
            // (s/km schnell–langsam) — das Zielband der Verlaufskurve.
            'windows'  => array_map(fn ($r) => [
                'from' => $r['from'], 'to' => $r['to'], 'kind' => $r['kind'],
                'zone' => $r['planned_zone'], 'range' => $r['range'],
            ], $rows),
            'total'    => max(array_sum(array_column($planned, 'flex')), array_sum(array_column($actual, 'flex'))),
        ];
    }

    /**
     * Die Schritte aufgefaltet — dieselbe Regel wie `stepBand()` im Frontend.
     *
     * @return list<array<string, mixed>>
     */
    public function segments(array $steps): array
    {
        $out = [];
        for ($i = 0; $i < count($steps); $i++) {
            $s     = $steps[$i];
            $reps  = (int) ($s['repetitions'] ?? 1) > 1 ? (int) $s['repetitions'] : 1;
            $pause = ($s['type'] ?? null) === 'work' && (($steps[$i + 1]['type'] ?? null) === 'rest') ? $steps[$i + 1] : null;

            for ($r = 0; $r < $reps; $r++) {
                $out[] = $s;
                if ($pause && ($reps > 1 ? $r < $reps - 1 || (int) ($pause['repetitions'] ?? 0) >= $reps : true)) {
                    $out[] = $pause;
                }
            }
            if ($pause) {
                $i++;
            }
        }

        return $out;
    }

    /**
     * Die Runden passend zu den Abschnitten, sonst null. Eine einzelne
     * Einheit nimmt die ganze Aktivität als eine Runde.
     *
     * @return list<array<string, mixed>>|null
     */
    private function laps(Activity $activity, array $segmentList): ?array
    {
        $segments = count($segmentList);
        if ($segments === 1) {
            return [[
                'elapsed_time'      => $activity->elapsed_time,
                'moving_time'       => $activity->moving_time,
                'distance'          => $activity->distance,
                'average_heartrate' => $activity->average_heartrate,
            ]];
        }

        $laps = is_array($activity->laps) ? array_values($activity->laps) : [];
        // Die Uhr schließt nach dem letzten Abschnitt oft noch eine kurze
        // Runde ab (Stopp-Taste nach ein paar Schritten).
        while (count($laps) > $segments && $this->tiny(end($laps))) {
            array_pop($laps);
        }

        if (count($laps) !== $segments) {
            return null;
        }

        // Gleich viele Runden heißt noch nicht passend: neun Autolap-Runden
        // zu je 1 km gegen neun Abschnitte wären Zufall. Jede Runde muss
        // ungefähr so lang sein wie ihr Abschnitt.
        foreach ($segmentList as $i => $seg) {
            if (! $this->fits($seg, $laps[$i])) {
                return null;
            }
        }

        return $laps;
    }

    /** Passt die Runde in der Länge zum Abschnitt (Strecke ±25 %, Zeit ±35 %)? */
    private function fits(array $seg, array $lap): bool
    {
        if (! empty($seg['open'])) {
            return true;
        }
        if (! empty($seg['distance_m'])) {
            $ratio = (float) ($lap['distance'] ?? 0) / (float) $seg['distance_m'];

            return $ratio >= 0.75 && $ratio <= 1.25;
        }
        if (! empty($seg['duration_min'])) {
            $seconds = (float) ($lap['elapsed_time'] ?? 0) ?: (float) ($lap['moving_time'] ?? 0);
            $ratio   = $seconds / ((float) $seg['duration_min'] * 60);

            return $ratio >= 0.65 && $ratio <= 1.35;
        }

        return true;
    }

    /**
     * Die Abschnitte nacheinander auf die Messpunkte gelegt: eine Strecke
     * endet nach ihren Metern, eine Zeit nach ihren Sekunden. Ein offener
     * Abschnitt (bis Lap-Taste) hat kein Ende, das man legen könnte — dann
     * gibt es kein Soll/Ist.
     *
     * @return list<array<string, mixed>>|null
     */
    private function lapsFromStream(array $segments, array $stream): ?array
    {
        $t = $stream['t'] ?? [];
        $d = $stream['d'] ?? null;
        $n = count($t);
        if ($n < 2) {
            return null;
        }

        $laps = [];
        $i    = 0;
        foreach ($segments as $seg) {
            if (! empty($seg['open'])) {
                return null;
            }
            $start = $i;
            if (! empty($seg['distance_m'])) {
                if (! $d) {
                    return null;
                }
                $goal = ($d[$i] ?? 0) + (float) $seg['distance_m'];
                while ($i < $n - 1 && ($d[$i] ?? 0) < $goal) {
                    $i++;
                }
            } elseif (! empty($seg['duration_min'])) {
                $goal = $t[$i] + (float) $seg['duration_min'] * 60;
                while ($i < $n - 1 && $t[$i] < $goal) {
                    $i++;
                }
            } else {
                return null;
            }

            $seconds = max(0, $t[$i] - $t[$start]);
            $meters  = $d ? max(0, (float) ($d[$i] ?? 0) - (float) ($d[$start] ?? 0)) : 0;
            $hr      = array_filter(array_slice($stream['hr'] ?? [], $start, $i - $start + 1), fn ($v) => $v);
            // Ohne Strecke das Tempo aus den Messpunkten mitteln.
            if (! $d) {
                $v      = array_filter(array_slice($stream['v'] ?? [], $start, $i - $start + 1), fn ($x) => $x !== null);
                $meters = $v ? array_sum($v) / count($v) * $seconds : 0;
            }

            $laps[] = [
                'elapsed_time'      => $seconds,
                'moving_time'       => $seconds,
                'distance'          => $meters,
                'average_heartrate' => $hr ? array_sum($hr) / count($hr) : null,
                '_to'               => $t[$i],
            ];
        }

        // Kaum etwas gelaufen von dem, was geplant war: lieber nichts zeigen.
        return array_sum(array_column($laps, 'elapsed_time')) >= 60 ? $laps : null;
    }

    private function tiny(array $lap): bool
    {
        return (float) ($lap['elapsed_time'] ?? 0) < 60 || (float) ($lap['distance'] ?? 0) < 200;
    }

    private function evaluate(array $seg, array $lap, ?array $paceZones): array
    {
        $kind     = $seg['type'] ?? 'work';
        $open     = ! empty($seg['open']);
        $seconds  = (float) ($lap['moving_time'] ?? 0) ?: (float) ($lap['elapsed_time'] ?? 0);
        $meters   = (float) ($lap['distance'] ?? 0);
        $pace     = $meters > 0 && $seconds > 0 ? $seconds / ($meters / 1000) : null;
        $planMin  = (float) ($seg['duration_min'] ?? 0) ?: (! empty($seg['distance_m']) ? $seg['distance_m'] / 1000 * 5 : 0);
        $planZone = (int) ($seg['zone'] ?? 0) ?: ($kind === 'rest' ? 1 : 2);

        // [schnell, langsam] in s/km
        $range = null;
        if ($speeds = AppleWorkoutWriter::paceRange($seg['pace_target'] ?? null)) {
            $range = [1000 / $speeds[1], 1000 / $speeds[0]];
        }

        $off = 0.0; // > 0 zu langsam, < 0 zu schnell
        if ($pace !== null && $range) {
            if ($pace < $range[0]) {
                $off = $pace - $range[0];
            } elseif ($pace > $range[1]) {
                $off = $pace - $range[1];
            }
        }

        // Geschafft nach Strecke, sonst nach Zeit. Offene Abschnitte endet
        // der Athlet selbst — die sind immer vollständig.
        $completion = 1.0;
        if (! $open) {
            if (! empty($seg['distance_m'])) {
                $completion = $meters / (float) $seg['distance_m'];
            } elseif (! empty($seg['duration_min'])) {
                $completion = $seconds / ((float) $seg['duration_min'] * 60);
            }
        }

        $paceScore = $range && $pace !== null ? max(0, 100 - self::POINTS_PER_SECOND * abs($off)) : 100;
        $score     = (int) round($paceScore * min(1, max(0, $completion)));

        $easyScore = null;
        if ($range && $pace !== null) {
            $tooFast   = max(0, $range[0] - $pace - self::EASY_SLACK_SECONDS);
            $easyScore = max(0, 100 - self::POINTS_PER_SECOND * $tooFast);
        }

        $status = match (true) {
            $completion < 0.9            => 'short',
            $off <= -1                   => 'fast',
            $off >= 1                    => 'slow',
            default                      => 'ok',
        };

        return [
            'kind'           => $kind,
            'label'          => $seg['label'] ?? null,
            'score'          => $score,
            'easy_score'     => $easyScore,
            'status'         => $status,
            'off'            => (int) round($off),
            'weight'         => max(0.5, $planMin),
            'planned_min'    => $open ? 10 : max(0.5, $planMin),
            'planned_zone'   => $planZone,
            'actual_min'     => $seconds > 0 ? $seconds / 60 : 0,
            'actual_zone'    => $this->zoneOf($pace, $paceZones) ?? $planZone,
            'target'         => $seg['pace_target'] ?? null,
            'actual'         => $pace !== null ? PaceFormat::fromSeconds($pace) : null,
            'hr'             => isset($lap['average_heartrate']) ? (int) round((float) $lap['average_heartrate']) : null,
            'planned_amount' => $open ? 'bis Lap-Taste' : $this->amount($seg['distance_m'] ?? null, isset($seg['duration_min']) ? $seg['duration_min'] * 60 : null),
            'actual_amount'  => ! empty($seg['distance_m']) ? $this->km($meters) : $this->clock($seconds),
        ];
    }

    /** In welcher Pace-Zone liegt eine Pace? Null ohne Zonen. */
    public function zoneOf(?float $pace, ?array $zones): ?int
    {
        if ($pace === null || ! $zones) {
            return null;
        }

        foreach ([5, 4, 3, 2, 1] as $z) {
            $zone = $zones["z{$z}"] ?? null;
            if (! $zone) {
                continue;
            }
            $fast = $this->secs($zone['min_pace'] ?? null) ?? 0;
            $slow = $this->secs($zone['max_pace'] ?? null) ?? INF;
            if ($pace >= $fast && $pace <= $slow) {
                return $z;
            }
        }

        // Zwischen zwei Zonen (gerundete Grenzen): die langsamere nehmen.
        foreach ([1, 2, 3, 4, 5] as $z) {
            $fast = $this->secs($zones["z{$z}"]['min_pace'] ?? null);
            if ($fast !== null && $pace >= $fast) {
                return $z;
            }
        }

        return 5;
    }

    private function secs(?string $pace): ?float
    {
        return $pace && preg_match('/^(\d{1,2}):([0-5]\d)$/', trim($pace), $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    private function weighted(array $rows, string $key): float
    {
        $sum = array_sum(array_column($rows, 'weight')) ?: 1;

        return array_sum(array_map(fn ($r) => $r[$key] * $r['weight'], $rows)) / $sum;
    }

    private function part(float $minutes, int $zone): array
    {
        return ['flex' => round($minutes, 2), 'zone' => $zone, 'height' => self::ZONE_HEIGHT[$zone] ?? self::ZONE_HEIGHT[2]];
    }

    private function sentence(array $blocks, bool $work): string
    {
        $n    = count($blocks);
        $done = count(array_filter($blocks, fn ($b) => $b['score'] >= self::DONE_SCORE));
        $word = $work ? 'Blöcke' : 'Abschnitte';

        $first = match (true) {
            $n === 1 && $done === 1 => 'Genau wie geplant gelaufen.',
            $n === 1               => 'Nicht ganz wie geplant.',
            $done === $n           => $n === 2 ? "Beide {$word} geschafft." : "Alle {$this->number($n)} {$word} geschafft.",
            default                => ucfirst($this->number($done)) . " von " . $this->number($n) . " {$word}n geschafft.",
        };

        // Die größte Abweichung, wenn es eine gibt.
        $worst = null;
        foreach ($blocks as $i => $b) {
            if (abs($b['off']) >= 3 && (! $worst || abs($b['off']) > abs($worst[1]['off']))) {
                $worst = [$i + 1, $b];
            }
        }
        if ($worst) {
            [$i, $b] = $worst;
            $who = $n === 1 ? 'Du warst' : ($work ? "Block {$i} war" : "Abschnitt {$i} war");
            $first .= sprintf(' %s im Schnitt %d s %s.', $who, abs($b['off']), $b['off'] < 0 ? 'zu schnell' : 'zu langsam');
        } elseif ($short = array_filter($blocks, fn ($b) => $b['status'] === 'short')) {
            $i = array_key_first($short) + 1;
            $first .= $n === 1 ? ' Kürzer als geplant.' : ($work ? " Block {$i} war kürzer als geplant." : " Abschnitt {$i} war kürzer als geplant.");
        }

        return $first;
    }

    private function easyNote(array $easy): ?string
    {
        if (! $easy) {
            return null;
        }
        $fast = array_filter($easy, fn ($r) => $r['easy_score'] < 100);

        return $fast ? 'Ein- oder Auslaufen war schneller als locker.' : 'Ein- und Auslaufen locker genug.';
    }

    private function number(int $n): string
    {
        return [0 => 'Keiner', 1 => 'Einer', 2 => 'zwei', 3 => 'drei', 4 => 'vier', 5 => 'fünf', 6 => 'sechs', 7 => 'sieben', 8 => 'acht', 9 => 'neun', 10 => 'zehn', 11 => 'elf', 12 => 'zwölf'][$n] ?? (string) $n;
    }

    private function amount(mixed $meters, ?float $seconds): ?string
    {
        if ($meters) {
            return $meters >= 1000 ? $this->km((float) $meters) : ((int) $meters) . ' m';
        }

        return $seconds ? $this->clock($seconds) : null;
    }

    private function km(float $meters): string
    {
        return $meters < 1000 ? ((int) round($meters)) . ' m' : number_format($meters / 1000, 2, ',', '.') . ' km';
    }

    private function clock(float $seconds): string
    {
        $s = (int) round($seconds);

        return $s >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60)
            : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}

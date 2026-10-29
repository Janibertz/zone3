<?php

namespace App\Services\Run;

use App\Services\AI\SessionContentService;
use App\Services\PaceFormat;

/**
 * Der Ablauf einer Laufeinheit — fest, ohne Modell (Entscheidung von Jan,
 * 29.10.2026; Katalog im Dokument „Ablauf ohne KI: Bausteinkatalog").
 *
 * Vorher schrieb das Plan-Modell die Struktur als Text in die Beschreibung,
 * und erst beim Öffnen der Details las ein zweiter Aufruf daraus die
 * Abschnitte. Dabei ging regelmäßig etwas verloren: nur ganze Minuten (aus
 * „2×12:30" wurden 12 min), das Auslaufen wurde aufgefüllt, und die Uhr
 * bekam einen einzigen Block, solange niemand die Details geöffnet hatte.
 *
 * Das Gerüst weiß längst alles, was ein Ablauf braucht: Typ, Dauer, die
 * Minuten des Hauptteils (`hard_min`, aus 80/20 bzw. den norwegischen
 * Anteilen) und die Paces aus dem VDOT. Das Modell wählt nur noch die
 * Variante und schreibt Titel und Begründung.
 *
 * Die Schritte haben dieselbe Form wie die aus dem Builder
 * ({@see \App\Services\WorkoutPaceResolver}) und wie die Radeinheiten
 * ({@see \App\Services\Bike\BikeWorkouts}): `type`, `label`, `duration_min`
 * (darf Bruchteile tragen, 20 s = 0.333), `distance_m` bei einer Strecke,
 * `pace_target`, `zone`, `repetitions`.
 */
class RunWorkouts
{
    /** Die Varianten je Typ. Die erste ist die Vorgabe. */
    public const VARIANTS = [
        'easy_run'        => ['locker', 'locker_steigerungen'],
        'tempo_run'       => ['schwelle_bloecke', 'schwelle_dauer', 'cruise_km'],
        'interval'        => ['vo2_1000', 'vo2_3min', 'vo2_800'],
        'progressive_run' => ['progressiv'],
        'test_run'        => ['test_5k'],
        'long_run'        => ['lang_locker', 'lang_renntempo'],
    ];

    /** Norwegisches Modell: die Varianten hängen an der Rolle im Gerüst. */
    public const NORWEGIAN_VARIANTS = [
        'am'     => ['sub_6min'],
        'pm'     => ['sub_1000'],
        'single' => ['sub_10min', 'sub_6min'],
        'fast'   => ['fast_200'],
    ];

    /** Ein Block am Stück in E — die Backyard-Typen. */
    public const STEADY_TYPES = ['time_on_feet', 'night_run', 'back_to_back_long'];

    /** Was das Modell im Prompt über jede Variante liest. */
    public const DESCRIPTIONS = [
        'locker'              => 'locker am Stück',
        'locker_steigerungen' => 'locker, am Ende 5×20 s Steigerungen',
        'schwelle_bloecke'    => 'Schwelle in 2–4 Blöcken mit Trabpause',
        'schwelle_dauer'      => 'Schwelle am Stück (Tempodauerlauf)',
        'cruise_km'           => 'Kilometer an der Schwelle mit 1 min Trabpause',
        'vo2_1000'            => '1000-m-Intervalle mit 400 m Trabpause',
        'vo2_3min'            => '3-min-Intervalle mit 2 min Trabpause',
        'vo2_800'             => '800-m-Intervalle mit 400 m Trabpause',
        'progressiv'          => 'locker → Marathontempo → Schwelle',
        'test_5k'             => '5-km-Zeitversuch',
        'lang_locker'         => 'lang und locker',
        'lang_renntempo'      => 'lang, am Ende im Zielrenntempo',
        'sub_6min'            => '6-min-Blöcke knapp unter der Schwelle',
        'sub_1000'            => '1000-m-Blöcke knapp unter der Schwelle',
        'sub_10min'           => '10-min-Blöcke knapp unter der Schwelle',
        'fast_200'            => '200 m schnell mit 200 m Traben',
        'steady'              => 'locker am Stück',
    ];

    /** Ein- und Auslaufen einer harten Einheit, bevor Restzeit dazukommt. */
    public const WARMUP_MIN   = 10;
    public const COOLDOWN_MIN = 10;

    /** Norwegische Sub-Schwelle: kürzeres Auslaufen, abends bleibt oft wenig Zeit. */
    public const SUB_COOLDOWN_MIN = 5;

    /** Hauptteil, wenn das Gerüst keinen `hard_min` setzt (z. B. Coach-Chat). */
    public const DEFAULT_HARD_MIN = [
        'tempo_run'       => 25,
        'interval'        => 20,
        'progressive_run' => 15,
        'test_run'        => 20,
        'am'              => 30,
        'pm'              => 30,
        'single'          => 35,
        'fast'            => 10,
    ];

    /**
     * Ohne VDOT gibt es keine Paces — die Dauer einer Strecke muss trotzdem
     * geschätzt werden. Sekunden je km, bewusst vorsichtig.
     */
    private const FALLBACK_SEC = [
        'easy' => 360, 'long' => 370, 'marathon' => 320, 'threshold' => 300,
        'sub_threshold' => 310, 'interval' => 280, 'repetition' => 260, 'target_pace' => 320,
    ];

    /** Die Varianten, die an diesem Slot erlaubt sind. */
    public static function variantsFor(array $slot): array
    {
        $type = $slot['type'] ?? '';

        if (! empty($slot['fast'])) {
            return self::NORWEGIAN_VARIANTS['fast'];
        }

        if (! empty($slot['controlled'])) {
            return self::NORWEGIAN_VARIANTS[$slot['double'] ?? 'single'];
        }

        if (in_array($type, self::STEADY_TYPES, true)) {
            return ['steady'];
        }

        // Der lange Lauf hat keine Wahl: ob ein Renntempo-Abschnitt dazugehört,
        // hat die Leiter entschieden.
        if ($type === 'long_run') {
            return [($slot['race_km'] ?? 0) > 0 ? 'lang_renntempo' : 'lang_locker'];
        }

        // Wiedereinstieg: die Stufe entscheidet, nicht das Modell.
        if (isset($slot['comeback'])) {
            return match (true) {
                $type === 'tempo_run'    => ['schwelle_dauer'],
                $slot['comeback'] >= 3   => ['locker_steigerungen'],
                default                  => ['locker'],
            };
        }

        return self::VARIANTS[$type] ?? [];
    }

    /**
     * Die gewählte Variante, wenn sie erlaubt ist — sonst eine aus der Liste,
     * nach Kalenderwoche wechselnd, damit nicht jede Woche dasselbe kommt.
     */
    public static function pick(array $allowed, ?string $wanted, ?string $date = null): ?string
    {
        if ($allowed === []) {
            return null;
        }

        if ($wanted !== null && in_array($wanted, $allowed, true)) {
            return $wanted;
        }

        $week = $date ? (int) date('W', strtotime($date)) : 0;

        return $allowed[$week % count($allowed)];
    }

    /**
     * Den Ablauf einer Planeinheit aus ihrem Slot im Gerüst.
     *
     * Die Größe kommt aus der schon geprüften Einheit — der Validator hat
     * Dauer, Tagesbudget, 80/20 und die Leiter da bereits durchgesetzt —,
     * und nur wo sie fehlt, aus dem Slot. Die Variante wählt das Modell
     * (`workout`); fehlt sie oder ist sie nicht erlaubt, springt
     * {@see pick()} ein.
     */
    public function forSlot(array $session, array $slot, ?array $paces): ?array
    {
        $type    = $session['type'] ?? ($slot['type'] ?? '');
        $slot   += ['type' => $type];
        $variant = self::pick(self::variantsFor($slot), $session['workout'] ?? null, $session['date'] ?? null);

        if ($variant === null) {
            return null;
        }

        return $this->build($type, $variant, [
            'minutes'  => (int) ($session['duration_min'] ?? 0) ?: (int) ($slot['target_min'] ?? $slot['max_min'] ?? 0),
            'km'       => (float) ($session['distance_km'] ?? 0) ?: (float) ($slot['target_km'] ?? 0),
            'hard_min' => (int) ($session['hard_min'] ?? 0) ?: (int) ($slot['hard_min'] ?? 0),
            'race_km'  => (float) ($slot['race_km'] ?? 0),
            'role'     => ! empty($slot['fast']) ? 'fast' : (! empty($slot['controlled']) ? ($slot['double'] ?? 'single') : null),
            'paces'    => $paces ?? [],
        ]);
    }

    /**
     * Den Ablauf bauen.
     *
     * @param  array{
     *     minutes?: int|null, km?: float|null, hard_min?: int|null, race_km?: float|null,
     *     paces?: array|null, role?: string|null
     * }  $o  `role` ist die norwegische Rolle (am/pm/single/fast)
     * @return array{workout: string, steps: list<array>, duration_min: int, distance_km: ?float,
     *               pace_target: ?string, zone: int, intensity: string, summary: string}|null
     */
    public function build(string $type, string $variant, array $o = []): ?array
    {
        $paces   = $o['paces'] ?? [];
        $minutes = max(0, (int) ($o['minutes'] ?? 0));
        $role    = $o['role'] ?? null;
        $hard    = (int) ($o['hard_min'] ?? 0) ?: (self::DEFAULT_HARD_MIN[$role ?? $type] ?? 20);

        // Der Hauptteil passt in die Einheit, nicht umgekehrt: Ein- und
        // Auslaufen brauchen zusammen mindestens 10 min, die Pausen rund ein
        // Viertel des Hauptteils. Sonst würde eine 35-min-Einheit 50 min lang.
        if ($minutes > 0) {
            $hard = max(6, min($hard, (int) floor(($minutes - 10) / 1.25)));
        }

        $built = match ($variant) {
            'locker'              => $this->easy($minutes ?: 40, $paces, false),
            'locker_steigerungen' => $this->easy($minutes ?: 40, $paces, true),
            'schwelle_bloecke'    => $this->hard($minutes, $paces, $this->thresholdBlocks($hard, $paces)),
            'schwelle_dauer'      => $this->hard($minutes, $paces, $this->thresholdSteady($hard, $paces)),
            'cruise_km'           => $this->hard($minutes, $paces, $this->cruise($hard, $paces)),
            'vo2_1000'            => $this->hard($minutes, $paces, $this->vo2Distance($hard, $paces, 1000, 3, 6)),
            'vo2_800'             => $this->hard($minutes, $paces, $this->vo2Distance($hard, $paces, 800, 4, 8)),
            'vo2_3min'            => $this->hard($minutes, $paces, $this->vo2Time($hard, $paces)),
            'progressiv'          => $this->progressive($minutes ?: 50, $hard, $paces),
            'test_5k'             => $this->hard($minutes, $paces, $this->test5k($paces), 15),
            'lang_locker'         => $this->long((float) ($o['km'] ?? 0), $minutes, 0.0, $paces),
            'lang_renntempo'      => $this->long((float) ($o['km'] ?? 0), $minutes, (float) ($o['race_km'] ?? 0), $paces),
            'sub_6min'            => $this->hard($minutes, $paces, $this->subTime($hard, $paces, 6, 1, 3, 6), self::WARMUP_MIN, self::SUB_COOLDOWN_MIN),
            'sub_10min'           => $this->hard($minutes, $paces, $this->subTime($hard, $paces, 10, 2, 2, 4), self::WARMUP_MIN, self::SUB_COOLDOWN_MIN),
            'sub_1000'            => $this->hard($minutes, $paces, $this->subKm($hard, $paces), self::WARMUP_MIN, self::SUB_COOLDOWN_MIN),
            'fast_200'            => $this->hard($minutes, $paces, $this->fast200($hard, $paces)),
            'steady'              => $this->easy($minutes ?: 60, $paces, false, 'long'),
            default               => null,
        };

        if ($built === null) {
            return null;
        }

        [$steps, $summary] = $built;
        // Beim langen Lauf steht oben das Tempo des langen Blocks, nicht das
        // der letzten Kilometer.
        $main = str_starts_with($variant, 'lang_') ? $steps[0] : $this->mainStep($steps);

        return [
            'workout'      => $variant,
            'steps'        => $steps,
            'duration_min' => (int) round($this->totalMinutes($steps)),
            'distance_km'  => SessionContentService::stepsTotalKm($steps),
            'pace_target'  => $main['pace_target'] ?? null,
            'zone'         => (int) ($main['zone'] ?? 2),
            'intensity'    => match (true) {
                in_array($variant, ['locker', 'locker_steigerungen', 'lang_locker', 'steady'], true) => 'low',
                in_array($variant, ['lang_renntempo', 'progressiv', 'sub_6min', 'sub_10min', 'sub_1000'], true) => 'medium',
                default => 'high',
            },
            'summary'      => $summary,
        ];
    }

    /** Dauer einer Schritteliste in Minuten, Bruchteile eingeschlossen. */
    public function totalMinutes(array $steps): float
    {
        return array_sum(array_map(
            fn ($s) => (float) ($s['duration_min'] ?? 0) * max(1, (int) ($s['repetitions'] ?? 1)),
            $steps,
        ));
    }

    // ── Bausteine ────────────────────────────────────────────────────────

    private function easy(int $minutes, array $p, bool $strides, string $pace = 'easy'): array
    {
        if (! $strides) {
            return [[$this->step('work', $pace === 'long' ? 'Locker' : 'Lockerer Lauf', $minutes, $p[$pace] ?? null, 2)], "{$minutes} min locker"];
        }

        // 5 × (20 s Steigerung + 1 min Traben) = 6:40 am Ende.
        $steady = max(10, (int) round($minutes - 5 * (20 / 60 + 1)));

        return [[
            $this->step('work', 'Lockerer Lauf', $steady, $p['easy'] ?? null, 2),
            $this->step('work', 'Steigerung', 20 / 60, $p['repetition'] ?? null, 5, 5),
            $this->step('rest', 'Traben', 1, null, 1, 5),
        ], "{$steady} min locker + 5×20 s Steigerungen"];
    }

    /**
     * Ein- und Auslaufen um einen Hauptteil legen. Was von `$minutes` übrig
     * bleibt, geht zu gleichen Teilen in Ein- und Auslaufen — der Hauptteil
     * wird nie gestreckt. Reicht die Zeit nicht, werden Ein- und Auslaufen
     * kürzer (bis 5 min), nie der Hauptteil.
     */
    private function hard(int $minutes, array $p, array $main, int $warm = self::WARMUP_MIN, int $cool = self::COOLDOWN_MIN): array
    {
        [$mainSteps, $summary] = $main;
        $mainMin = $this->totalMinutes($mainSteps);

        if ($minutes > 0) {
            $extra = $minutes - $warm - $cool - $mainMin;
            if ($extra >= 0) {
                $warm += (int) floor($extra / 2);
                $cool  = (int) round($minutes - $warm - $mainMin);
            } else {
                $room = max(10, $minutes - $mainMin);
                $warm = max(5, (int) floor($room / 2));
                $cool = max(5, (int) round($room - $warm));
            }
        }

        return [array_merge(
            [$this->step('warmup', 'Einlaufen', $warm, $p['easy'] ?? null, 1)],
            $mainSteps,
            [$this->step('cooldown', 'Auslaufen', $cool, $p['easy'] ?? null, 1)],
        ), $summary];
    }

    private function thresholdBlocks(int $hard, array $p): array
    {
        $best = [2, 6];
        $bestDiff = PHP_INT_MAX;

        foreach ([15, 12, 10, 8, 6] as $len) {
            $reps = intdiv($hard, $len);
            if ($reps < 2 || $reps > 4) {
                continue;
            }
            $diff = $hard - $reps * $len;
            if ($diff < $bestDiff) {
                [$best, $bestDiff] = [[$reps, $len], $diff];
            }
        }

        [$reps, $len] = $best;
        $rest = $len >= 12 ? 3 : 2;

        return [[
            $this->step('work', "{$len} min Schwelle", $len, $p['threshold'] ?? null, 4, $reps),
            $this->step('rest', 'Traben', $rest, null, 1, $reps),
        ], "{$reps}×{$len} min Schwelle"];
    }

    private function thresholdSteady(int $hard, array $p): array
    {
        $len = max(10, min(30, $hard));

        return [[$this->step('work', "{$len} min Schwelle", $len, $p['threshold'] ?? null, 4)], "{$len} min Schwelle am Stück"];
    }

    private function cruise(int $hard, array $p): array
    {
        $reps = $this->clamp((int) round($hard * 60 / $this->sec($p, 'threshold')), 3, 8);

        return [[
            $this->distanceStep('work', '1000 m Schwelle', 1000, $p, 'threshold', 4, $reps),
            $this->step('rest', 'Traben', 1, null, 1, $reps),
        ], "{$reps}×1000 m Schwelle"];
    }

    private function vo2Distance(int $hard, array $p, int $meters, int $min, int $max): array
    {
        $reps = $this->clamp((int) round($hard * 60 / ($meters / 1000 * $this->sec($p, 'interval'))), $min, $max);

        return [[
            $this->distanceStep('work', "{$meters} m Intervall", $meters, $p, 'interval', 5, $reps),
            $this->distanceStep('rest', '400 m Traben', 400, $p, 'easy', 1, $reps, false),
        ], "{$reps}×{$meters} m"];
    }

    private function vo2Time(int $hard, array $p): array
    {
        $reps = $this->clamp((int) round($hard / 3), 4, 7);

        return [[
            $this->step('work', '3 min Intervall', 3, $p['interval'] ?? null, 5, $reps),
            $this->step('rest', 'Traben', 2, null, 1, $reps),
        ], "{$reps}×3 min"];
    }

    private function progressive(int $minutes, int $hard, array $p): array
    {
        $hard   = max(10, min($hard, $minutes - 20));
        $before = max(10, $minutes - $hard);
        $easy   = (int) round($before * 0.6);
        $mp     = $before - $easy;

        return [[
            $this->step('warmup', 'Locker', $easy, $p['easy'] ?? null, 2),
            $this->step('work', 'Marathontempo', $mp, $p['marathon'] ?? null, 3),
            $this->step('work', 'Schwelle', $hard, $p['threshold'] ?? null, 4),
        ], "{$easy} min locker, {$mp} min Marathontempo, {$hard} min Schwelle"];
    }

    /** 5-km-Pace ist die langsame Grenze des I-Bereichs (rund 97,5 % VO2max). */
    private function test5k(array $p): array
    {
        $pace = isset($p['interval']) ? PaceFormat::fromSeconds($this->slowest($p['interval'])) : null;

        return [[
            $this->step('work', 'Steigerung', 20 / 60, $p['repetition'] ?? null, 5, 4),
            $this->step('rest', 'Traben', 1, null, 1, 4),
            $this->distanceStep('work', '5 km Zeitversuch', 5000, ['test' => $pace], 'test', 5),
        ], '5-km-Zeitversuch'];
    }

    private function long(float $km, int $minutes, float $raceKm, array $p): array
    {
        if ($km <= 0) {
            $km = $minutes > 0 ? round($minutes * 60 / $this->sec($p, 'long'), 1) : 15.0;
        }

        $raceKm = max(0.0, min($raceKm, $km - 3));
        $easyKm = round($km - $raceKm, 1);

        $steps = [$this->distanceStep('work', $raceKm > 0 ? 'Locker' : 'Langer Lauf', (int) round($easyKm * 1000), $p, 'long', 2)];

        if ($raceKm > 0) {
            $key     = isset($p['target_pace']) ? 'target_pace' : 'marathon';
            $steps[] = $this->distanceStep('work', 'Zielrenntempo', (int) round($raceKm * 1000), $p, $key, 3);
        }

        $label = $this->km($km) . ' km' . ($raceKm > 0 ? ', die letzten ' . $this->km($raceKm) . ' km im Zielrenntempo' : ' locker');

        return [$steps, $label];
    }

    private function subTime(int $hard, array $p, int $len, int $rest, int $min, int $max): array
    {
        $reps = $this->clamp((int) round($hard / $len), $min, $max);

        return [[
            $this->step('work', "{$len} min Sub-Schwelle", $len, $p['sub_threshold'] ?? null, 4, $reps),
            $this->step('rest', 'Pause', $rest, null, 1, $reps),
        ], "{$reps}×{$len} min Sub-Schwelle"];
    }

    private function subKm(int $hard, array $p): array
    {
        $reps = $this->clamp((int) round($hard * 60 / $this->sec($p, 'sub_threshold')), 4, 10);

        return [[
            $this->distanceStep('work', '1000 m Sub-Schwelle', 1000, $p, 'sub_threshold', 4, $reps),
            $this->step('rest', 'Pause', 1, null, 1, $reps),
        ], "{$reps}×1000 m Sub-Schwelle"];
    }

    private function fast200(int $hard, array $p): array
    {
        $reps = $this->clamp((int) round($hard * 60 / (0.2 * $this->sec($p, 'repetition'))), 5, 12);

        return [[
            $this->distanceStep('work', '200 m schnell', 200, $p, 'repetition', 5, $reps),
            $this->distanceStep('rest', '200 m Traben', 200, $p, 'easy', 1, $reps, false),
        ], "{$reps}×200 m schnell"];
    }

    // ── Hilfen ───────────────────────────────────────────────────────────

    private function step(string $type, string $label, float $minutes, ?string $pace, int $zone, int $reps = 1): array
    {
        return [
            'type'         => $type,
            'label'        => $label,
            'duration_min' => round($minutes, 3),
            'distance_m'   => null,
            'pace_target'  => $type === 'rest' ? null : $pace,
            'zone'         => $zone,
            'repetitions'  => $reps > 1 ? $reps : null,
        ];
    }

    /**
     * Ein Abschnitt über eine Strecke. Die Dauer ist eine Schätzung aus der
     * Pace — sie braucht der Balken, die Ansage bleibt die Strecke.
     */
    private function distanceStep(string $type, string $label, int $meters, array $p, string $paceKey, int $zone, int $reps = 1, bool $withPace = true): array
    {
        $minutes = $meters / 1000 * $this->sec($p, $paceKey) / 60;

        return [
            'type'         => $type,
            'label'        => $label,
            'duration_min' => round($minutes, 2),
            'distance_m'   => $meters,
            'pace_target'  => $withPace && $type !== 'rest' ? ($p[$paceKey] ?? null) : null,
            'zone'         => $zone,
            'repetitions'  => $reps > 1 ? $reps : null,
        ];
    }

    /** Der Abschnitt, der oben groß steht: die längste Belastung. */
    private function mainStep(array $steps): ?array
    {
        $main = null;
        $best = -1.0;

        foreach ($steps as $s) {
            if (($s['type'] ?? '') !== 'work') {
                continue;
            }
            $total = (float) $s['duration_min'] * max(1, (int) ($s['repetitions'] ?? 1));
            // Die Belastung schlägt den lockeren Block, sobald sie nennenswert ist.
            $weight = ($s['zone'] ?? 2) >= 3 ? $total * 10 : $total;
            if ($weight > $best) {
                [$main, $best] = [$s, $weight];
            }
        }

        return $main;
    }

    /** Sekunden je km für einen Pace-Schlüssel; bei einem Bereich die Mitte. */
    private function sec(array $p, string $key): float
    {
        $pace = $p[$key] ?? null;
        if ($pace && preg_match_all('/(\d+):(\d{2})/', (string) $pace, $mm, PREG_SET_ORDER)) {
            $vals = array_map(fn ($m) => (int) $m[1] * 60 + (int) $m[2], $mm);

            return array_sum($vals) / count($vals);
        }

        return self::FALLBACK_SEC[$key] ?? 330;
    }

    private function slowest(string $range): float
    {
        preg_match_all('/(\d+):(\d{2})/', $range, $mm, PREG_SET_ORDER);

        return max(array_map(fn ($m) => (int) $m[1] * 60 + (int) $m[2], $mm));
    }

    private function clamp(int $v, int $min, int $max): int
    {
        return max($min, min($max, $v));
    }

    private function km(float $km): string
    {
        return str_replace('.', ',', (string) round($km, 1));
    }
}

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
        'easy_run'        => ['locker', 'locker_steigerungen', 'locker_bergsprints', 'locker_lauf_abc'],
        'tempo_run'       => ['schwelle_bloecke', 'schwelle_dauer', 'cruise_km', 'schwelle_pyramide', 'over_under', 'wechseltempo', 'renntempo_bloecke'],
        'interval'        => ['vo2_1000', 'vo2_3min', 'vo2_800', 'vo2_400', 'vo2_1200', 'vo2_pyramide', 'vo2_30_30', 'vo2_4x4', 'fahrtspiel_pyramide', 'fahrtspiel_1_1', 'bergintervalle'],
        'progressive_run' => ['progressiv', 'progressiv_km', 'endbeschleunigung'],
        'test_run'        => ['test_5k', 'test_30min'],
        'long_run'        => ['lang_locker', 'lang_renntempo', 'lang_steigerungen', 'lang_renntempo_bloecke', 'lang_progressiv'],
    ];

    /**
     * In welchen Phasen ein Baustein vorkommt (Katalog „Workout-Katalog
     * erweitern", von Jan abgenommen am 29.10.2026). Fehlt ein Eintrag, gilt
     * er in jeder Phase. In der Grundlage gibt es statt Bahn-Intervallen nur
     * Fahrtspiel und Bergintervalle, im Taper nur kurze Reize.
     */
    public const PHASES = [
        'locker_bergsprints'     => ['base', 'build', 'peak'],
        'locker_lauf_abc'        => ['base', 'build'],
        'cruise_km'              => ['build', 'peak'],
        'schwelle_pyramide'      => ['build', 'peak'],
        'over_under'             => ['build', 'peak'],
        'wechseltempo'           => ['build', 'peak'],
        'renntempo_bloecke'      => ['peak', 'taper', 'race_week'],
        'vo2_1000'               => ['build', 'peak'],
        'vo2_800'                => ['build', 'peak'],
        'vo2_3min'               => ['build', 'peak'],
        'vo2_400'                => ['build', 'peak', 'taper', 'race_week'],
        'vo2_1200'               => ['build', 'peak'],
        'vo2_pyramide'           => ['build', 'peak'],
        'vo2_30_30'              => ['build', 'peak', 'taper', 'race_week'],
        'vo2_4x4'                => ['build', 'peak'],
        'fahrtspiel_pyramide'    => ['base', 'build', 'peak'],
        'fahrtspiel_1_1'         => ['base', 'build', 'peak'],
        'bergintervalle'         => ['base', 'build'],
        'progressiv_km'          => ['build', 'peak'],
        'endbeschleunigung'      => ['base', 'build'],
        'test_5k'                => ['base', 'build'],
        'test_30min'             => ['base', 'build'],
        'lang_steigerungen'      => ['base', 'build'],
        'lang_renntempo_bloecke' => ['build', 'peak'],
        'lang_progressiv'        => ['base', 'build'],
    ];

    /** Brauchen einen Hang — ohne „Hügel erreichbar" im Profil nicht im Plan. */
    public const HILL_VARIANTS = ['locker_bergsprints', 'bergintervalle'];

    /**
     * Die Grundform, die nie aus der Rotation fällt: die meisten lockeren
     * und langen Läufe sollen schlicht bleiben.
     */
    public const PLAIN_VARIANTS = ['locker', 'lang_locker', 'steady'];

    /** So lange sperrt ein gelaufener Baustein sich selbst (Entscheidung von Jan). */
    public const ROTATION_DAYS = 21;

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
        'locker_bergsprints'  => 'locker, am Ende 6×10 s Bergsprint (braucht einen Hang)',
        'locker_lauf_abc'     => 'locker mit 10 min Lauf-ABC',
        'schwelle_pyramide'   => 'Schwellen-Pyramide, z. B. 4-6-8-6-4 min',
        'over_under'          => 'Over-Under: 2 min knapp unter / 1 min knapp über der Schwelle im Wechsel',
        'wechseltempo'        => 'Wechseltempo: 3 min Schwelle / 2 min Marathontempo ohne Pause',
        'renntempo_bloecke'   => 'Blöcke im Zielrenntempo mit Trabpause',
        'vo2_400'             => '400-m-Intervalle mit 200 m Trabpause',
        'vo2_1200'            => '1200-m-Intervalle mit 400 m Trabpause',
        'vo2_pyramide'        => 'Bahn-Pyramide 400-800-1200-800-400 m',
        'vo2_30_30'           => '30 s schnell / 30 s Traben im Wechsel',
        'vo2_4x4'             => '4-min-Intervalle mit 3 min Trabpause',
        'fahrtspiel_pyramide' => 'Fahrtspiel 1-2-3-4-3-2-1 min hart',
        'fahrtspiel_1_1'      => 'Fahrtspiel 1 min zügig / 1 min locker',
        'bergintervalle'      => 'Bergintervalle 75 s bergauf (braucht einen Hang)',
        'progressiv_km'       => 'jeder Kilometer etwas schneller, bis zur Schwelle',
        'endbeschleunigung'   => 'locker, am Ende Marathontempo bis Schwelle',
        'test_30min'          => '30-min-Test (Schwelle)',
        'lang_steigerungen'   => 'lang und locker, mittendrin 6×20 s Steigerungen',
        'lang_renntempo_bloecke' => 'lang, Zielrenntempo in Blöcken mittendrin',
        'lang_progressiv'     => 'lang, letzter Teil stufenweise bis Marathontempo',
        'sub_6min'            => '6-min-Blöcke knapp unter der Schwelle',
        'sub_1000'            => '1000-m-Blöcke knapp unter der Schwelle',
        'sub_10min'           => '10-min-Blöcke knapp unter der Schwelle',
        'fast_200'            => '200 m schnell mit 200 m Traben',
        'steady'              => 'locker am Stück',
    ];

    /**
     * Zweck und Gefühl je Baustein — die Beschreibung, wenn die KI keine
     * ohne Zahlen geliefert hat ({@see SessionText}). Keine Zahlen hier:
     * die stehen im Ablauf.
     */
    public const PURPOSE = [
        'locker'              => 'Locker und entspannt laufen, du könntest dich dabei unterhalten. Das baut Grundlage auf, ohne zu ermüden.',
        'locker_steigerungen' => 'Locker laufen und am Ende ein paar kurze Steigerungen: zügig, aber nie verkrampft. Sie halten die Beine schnell, ohne zu ermüden.',
        'schwelle_bloecke'    => 'Schwelle in Blöcken: kontrolliert hart, die Atmung hörbar, aber ruhig. Die Trabpausen halten die Qualität bis zum letzten Block.',
        'schwelle_dauer'      => 'Tempodauerlauf an der Schwelle: gleichmäßig und kontrolliert hart, nicht all-out. Das hebt das Tempo, das du lange halten kannst.',
        'cruise_km'           => 'Kilometer an der Schwelle mit kurzen Trabpausen: gleichmäßig laufen, jeder Abschnitt so schnell wie der erste.',
        'vo2_1000'            => 'Intervalle im 5-km-Tempo: hart, aber gleichmäßig über alle Wiederholungen. Das trainiert die maximale Sauerstoffaufnahme.',
        'vo2_800'             => 'Intervalle im 5-km-Tempo: hart, aber gleichmäßig über alle Wiederholungen. Das trainiert die maximale Sauerstoffaufnahme.',
        'vo2_3min'            => 'Harte Intervalle mit lockerem Traben dazwischen: jede Wiederholung gleich schnell, die letzte nicht langsamer als die erste.',
        'progressiv'          => 'Progressiver Lauf: locker beginnen und Abschnitt für Abschnitt schneller werden. Das schult Tempogefühl und einen starken Schluss.',
        'test_5k'             => 'Zeitversuch: gut einlaufen, dann gleichmäßig an die Grenze. Das Ergebnis schärft deine Tempi.',
        'lang_locker'         => 'Langer Lauf, durchgehend locker. Er baut Ausdauer und Fettstoffwechsel auf; lieber zu langsam als zu schnell.',
        'lang_renntempo'      => 'Langer Lauf, die letzten Kilometer im Zielrenntempo. So lernt der Körper, das Renntempo auch müde zu halten.',
        'locker_bergsprints'  => 'Locker laufen, am Ende kurze Bergsprints mit voller Erholung: explosiv bergauf, dann ruhig zurückgehen. Das baut Kraft und Laufökonomie auf, ohne zu ermüden.',
        'locker_lauf_abc'     => 'Locker laufen mit Lauf-ABC dazwischen: Skippings, Anfersen, Hopserlauf. Sauber statt schnell, das verbessert deinen Laufstil.',
        'schwelle_pyramide'   => 'Schwellen-Pyramide: die Blöcke werden länger und wieder kürzer, das Tempo bleibt gleich. Kontrolliert hart, die längsten Blöcke in der Mitte.',
        'over_under'          => 'Over-Under: knapp unter und knapp über der Schwelle im Wechsel. Der Körper lernt, Laktat beim Weiterlaufen abzubauen.',
        'wechseltempo'        => 'Wechseltempo ohne Pause: Schwelle und Marathontempo im Wechsel. Im ruhigeren Teil erholst du dich laufend, nicht stehend.',
        'renntempo_bloecke'   => 'Blöcke im Zielrenntempo: genau das Tempo, das du im Rennen laufen willst. So wird es vertraut und fühlt sich am Renntag kontrolliert an.',
        'vo2_400'             => 'Kurze, schnelle Intervalle: flüssig und locker in den Schultern, alle gleich schnell. Das schärft Tempo und Laufökonomie.',
        'vo2_1200'            => 'Lange Intervalle im 5-km-Tempo: hart, aber gleichmäßig. Die Länge macht sie zur harten Prüfung für die Sauerstoffaufnahme.',
        'vo2_pyramide'        => 'Bahn-Pyramide: die Strecken werden länger und wieder kürzer. Halte das Tempo auch auf dem langen Stück in der Mitte.',
        'vo2_30_30'           => 'Kurze schnelle Abschnitte mit gleich langem Traben: zügig, nie sprinten. Viel Zeit nahe der maximalen Sauerstoffaufnahme, ohne lange zu leiden.',
        'vo2_4x4'             => 'Lange, harte Intervalle mit lockerem Traben: hart, aber gleichmäßig über alle Wiederholungen. Klassiker für die maximale Sauerstoffaufnahme.',
        'fahrtspiel_pyramide' => 'Fahrtspiel: die harten Abschnitte werden länger und wieder kürzer, dazwischen locker traben. Lauf nach Gefühl, kurz schneller, lang kontrollierter.',
        'fahrtspiel_1_1'      => 'Fahrtspiel im Wechsel: kurz zügig, kurz locker. Spielerisch und rhythmisch, nie bis an die Grenze.',
        'bergintervalle'      => 'Bergintervalle: kräftig bergauf, aufrecht und mit aktivem Armeinsatz, locker wieder hinunter. Das baut Kraft und Tempohärte auf.',
        'progressiv_km'       => 'Jeder Kilometer ein Stück schneller: locker beginnen, kontrolliert hart enden. Das schult Tempogefühl.',
        'endbeschleunigung'   => 'Locker laufen und zum Schluss beschleunigen: erst Marathontempo, dann Schwelle. Ein starker Schluss auf müden Beinen.',
        'test_30min'          => 'Schwellentest: gut einlaufen, dann so schnell, wie du gleichmäßig durchhalten kannst. Der Schnitt am Ende zeigt deine Schwelle.',
        'lang_steigerungen'   => 'Langer Lauf, locker, mittendrin ein paar kurze Steigerungen. Sie lockern die Beine und halten den Laufstil frisch.',
        'lang_renntempo_bloecke' => 'Langer Lauf mit Blöcken im Zielrenntempo mittendrin. Zwischen den Blöcken locker, so trainierst du Renntempo und Erholung im Lauf.',
        'lang_progressiv'     => 'Langer Lauf, im letzten Teil stufenweise schneller bis ins Marathontempo. Lieber zu ruhig anfangen als zu schnell.',
        'sub_6min'            => 'Blöcke knapp unter der Schwelle: kontrolliert, nie hart. Puls und Atmung bleiben ruhig genug für viele Wiederholungen.',
        'sub_1000'            => 'Kilometer knapp unter der Schwelle: kontrolliert, nie hart. Puls und Atmung bleiben ruhig genug für viele Wiederholungen.',
        'sub_10min'           => 'Lange Blöcke knapp unter der Schwelle: kontrolliert, nie hart. Der Puls bleibt unter der Grenze.',
        'fast_200'            => 'Kurz und schnell mit lockerem Traben: flüssig und locker in den Schultern, nicht sprinten.',
        'steady'              => 'Gleichmäßig und locker am Stück, Zeit auf den Beinen zählt mehr als Tempo.',
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
        // Neu ist nur die Wahl, wie er aussieht.
        if ($type === 'long_run') {
            return self::filter(($slot['race_km'] ?? 0) > 0
                ? ['lang_renntempo', 'lang_renntempo_bloecke', 'lang_progressiv']
                : ['lang_locker', 'lang_steigerungen'], $slot);
        }

        // Wiedereinstieg: die Stufe entscheidet, nicht das Modell.
        if (isset($slot['comeback'])) {
            return match (true) {
                $type === 'tempo_run'    => ['schwelle_dauer'],
                $slot['comeback'] >= 3   => ['locker_steigerungen'],
                default                  => ['locker'],
            };
        }

        return self::filter(self::VARIANTS[$type] ?? [], $slot);
    }

    /**
     * Phase (`phase`) und Hang (`hills` = false) aus dem Slot anwenden.
     * Bleibt nichts übrig, gilt die ungefilterte Liste — ein Ablauf ist
     * besser als keiner.
     */
    private static function filter(array $variants, array $slot): array
    {
        $phase = $slot['phase'] ?? null;

        $kept = array_values(array_filter($variants, fn ($v) =>
            ($phase === null || ! isset(self::PHASES[$v]) || in_array($phase, self::PHASES[$v], true))
            && (($slot['hills'] ?? true) || ! in_array($v, self::HILL_VARIANTS, true))
        ));

        return $kept ?: $variants;
    }

    /**
     * Was beim selben Typ in den letzten {@see ROTATION_DAYS} Tagen lief,
     * fällt aus der Liste — außer der Grundform. Bleibt nichts, gilt der am
     * längsten nicht gelaufene Baustein.
     *
     * @param  array<string,string>  $history  Baustein → letztes Datum (Y-m-d)
     */
    public static function fresh(array $allowed, array $history, ?string $date): array
    {
        if ($date === null || $history === []) {
            return $allowed;
        }

        $since = date('Y-m-d', strtotime($date . ' -' . self::ROTATION_DAYS . ' days'));

        $fresh = array_values(array_filter($allowed, fn ($v) =>
            in_array($v, self::PLAIN_VARIANTS, true) || ! isset($history[$v]) || $history[$v] <= $since
        ));

        if ($fresh !== []) {
            return $fresh;
        }

        usort($allowed, fn ($a, $b) => strcmp($history[$a] ?? '', $history[$b] ?? ''));

        return [$allowed[0]];
    }

    /**
     * Den Baustein aus einer alten Beschreibung lesen — für Einheiten von
     * vor dem Katalog, deren Text schon „2×12:30" oder „6×800 m" sagt.
     * Nur ein Treffer, der erlaubt ist, zählt.
     */
    public static function guessVariant(string $type, ?string $text, array $allowed): ?string
    {
        $t = mb_strtolower((string) $text);

        $guess = match (true) {
            $t === ''                                                  => null,
            (bool) preg_match('/\d+\s*[×x]\s*800\s*m/u', $t)           => 'vo2_800',
            (bool) preg_match('/\d+\s*[×x]\s*1000\s*m|\d+\s*[×x]\s*1\s*km/u', $t) => $type === 'interval' ? 'vo2_1000' : 'cruise_km',
            (bool) preg_match('/\d+\s*[×x]\s*\d+(:\d{2})?\s*min/u', $t) => $type === 'interval' ? 'vo2_3min' : 'schwelle_bloecke',
            str_contains($t, 'steigerung')                             => 'locker_steigerungen',
            default                                                    => null,
        };

        return in_array($guess, $allowed, true) ? $guess : null;
    }

    /**
     * Die gewählte Variante, wenn sie erlaubt ist. Sonst die Grundform, wo es
     * eine gibt (ein lockerer Lauf bleibt schlicht), und ohne Grundform der
     * am längsten nicht gelaufene Baustein; unter Gleichstand wechselt die
     * Kalenderwoche.
     *
     * @param  array<string,string>  $history  Baustein → letztes Datum (Y-m-d)
     */
    public static function pick(array $allowed, ?string $wanted, ?string $date = null, array $history = []): ?string
    {
        if ($allowed === []) {
            return null;
        }

        if ($wanted !== null && in_array($wanted, $allowed, true)) {
            return $wanted;
        }

        foreach ($allowed as $v) {
            if (in_array($v, self::PLAIN_VARIANTS, true)) {
                return $v;
            }
        }

        $oldest = min(array_map(fn ($v) => $history[$v] ?? '', $allowed));
        $tied   = array_values(array_filter($allowed, fn ($v) => ($history[$v] ?? '') === $oldest));
        $week   = $date ? (int) date('W', strtotime($date)) : 0;

        return $tied[$week % count($tied)];
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
    public function forSlot(array $session, array $slot, ?array $paces, array $history = []): ?array
    {
        $type    = $session['type'] ?? ($slot['type'] ?? '');
        $slot   += ['type' => $type];
        $date    = $session['date'] ?? null;
        $allowed = self::fresh(self::variantsFor($slot), $history, $date);
        $variant = self::pick($allowed, $session['workout'] ?? null, $date, $history);

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
            'lang_steigerungen'   => $this->longStrides((float) ($o['km'] ?? 0), $minutes, $paces),
            'lang_renntempo_bloecke' => $this->longRaceBlocks((float) ($o['km'] ?? 0), $minutes, (float) ($o['race_km'] ?? 0), $paces),
            'lang_progressiv'     => $this->longProgressive((float) ($o['km'] ?? 0), $minutes, (float) ($o['race_km'] ?? 0), $paces),
            'locker_bergsprints'  => $this->easyHillSprints($minutes ?: 45, $paces),
            'locker_lauf_abc'     => $this->easyDrills($minutes ?: 45, $paces),
            'schwelle_pyramide'   => $this->hard($minutes, $paces, $this->thresholdPyramid($hard, $paces)),
            'over_under'          => $this->hard($minutes, $paces, $this->overUnder($hard, $paces)),
            'wechseltempo'        => $this->hard($minutes, $paces, $this->floatTempo($hard, $paces)),
            'renntempo_bloecke'   => $this->hard($minutes, $paces, $this->raceBlocks($hard, $paces)),
            'vo2_400'             => $this->hard($minutes, $paces, $this->vo2Distance($hard, $paces, 400, 8, 16, 200)),
            'vo2_1200'            => $this->hard($minutes, $paces, $this->vo2Distance($hard, $paces, 1200, 3, 6)),
            'vo2_pyramide'        => $this->hard($minutes, $paces, $this->vo2Pyramid($hard, $paces)),
            'vo2_30_30'           => $this->hard($minutes, $paces, $this->thirtyThirty($hard, $paces)),
            'vo2_4x4'             => $this->hard($minutes, $paces, $this->fourByFour($hard, $paces)),
            'fahrtspiel_pyramide' => $this->hard($minutes, $paces, $this->fartlekPyramid($hard, $paces)),
            'fahrtspiel_1_1'      => $this->hard($minutes, $paces, $this->fartlekOneOne($hard, $paces)),
            'bergintervalle'      => $this->hard($minutes, $paces, $this->hillRepeats($hard)),
            'progressiv_km'       => $this->progressiveKm($minutes ?: 50, $paces),
            'endbeschleunigung'   => $this->strongFinish($minutes ?: 50, $hard, $paces),
            'test_30min'          => $this->hard($minutes, $paces, $this->test30($paces), 15),
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
                in_array($variant, ['locker', 'locker_steigerungen', 'locker_bergsprints', 'locker_lauf_abc', 'lang_locker', 'lang_steigerungen', 'steady'], true) => 'low',
                in_array($variant, ['lang_renntempo', 'lang_renntempo_bloecke', 'lang_progressiv', 'progressiv', 'progressiv_km', 'endbeschleunigung', 'sub_6min', 'sub_10min', 'sub_1000'], true) => 'medium',
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

    private function vo2Distance(int $hard, array $p, int $meters, int $min, int $max, int $rest = 400): array
    {
        $reps = $this->clamp((int) round($hard * 60 / ($meters / 1000 * $this->sec($p, 'interval'))), $min, $max);

        return [[
            $this->distanceStep('work', "{$meters} m Intervall", $meters, $p, 'interval', 5, $reps),
            $this->distanceStep('rest', "{$rest} m Traben", $rest, $p, 'easy', 1, $reps, false),
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


    // ── Erweiterung vom 29.10.2026 ───────────────────────────────────────

    /** Locker, am Ende 6 × (10 s Bergsprint + 1:30 zurückgehen). Ohne Pace: am Hang sagt sie nichts. */
    private function easyHillSprints(int $minutes, array $p): array
    {
        $steady = max(10, (int) round($minutes - 6 * (10 / 60 + 1.5)));

        return [[
            $this->step('work', 'Lockerer Lauf', $steady, $p['easy'] ?? null, 2),
            $this->step('work', 'Bergsprint', 10 / 60, null, 5, 6),
            $this->step('rest', 'Zurückgehen', 1.5, null, 1, 6),
        ], "{$steady} min locker + 6×10 s Bergsprint"];
    }

    private function easyDrills(int $minutes, array $p): array
    {
        $rest = max(10, $minutes - 20);

        return [[
            $this->step('warmup', 'Locker', 10, $p['easy'] ?? null, 2),
            $this->step('work', 'Lauf-ABC (Skippings, Anfersen, Hopserlauf)', 10, null, 2),
            $this->step('work', 'Lockerer Lauf', $rest, $p['easy'] ?? null, 2),
        ], "{$minutes} min locker mit Lauf-ABC"];
    }

    /** Die größte Pyramide, die in die Hartzeit passt. */
    private function thresholdPyramid(int $hard, array $p): array
    {
        $shapes = [[2, 4, 6, 4, 2], [3, 5, 7, 5, 3], [4, 6, 8, 6, 4], [5, 7, 9, 7, 5]];
        $shape  = $shapes[0];
        foreach ($shapes as $s) {
            if (array_sum($s) <= $hard) {
                $shape = $s;
            }
        }

        $steps = [];
        foreach ($shape as $i => $len) {
            $steps[] = $this->step('work', "{$len} min Schwelle", $len, $p['threshold'] ?? null, 4);
            if ($i < count($shape) - 1) {
                $steps[] = $this->step('rest', 'Traben', 2, null, 1);
            }
        }

        return [$steps, implode('-', $shape) . ' min Schwelle'];
    }

    /** 2 min knapp unter, 1 min knapp über der Schwelle, ohne Pause. */
    private function overUnder(int $hard, array $p): array
    {
        $reps = $this->clamp((int) round($hard / 3), 4, 10);
        $t    = $this->sec($p, 'threshold');

        return [[
            $this->step('work', 'Knapp unter der Schwelle', 2, isset($p['threshold']) ? PaceFormat::fromSeconds($t + 10) : null, 4, $reps),
            $this->step('work', 'Knapp über der Schwelle', 1, isset($p['threshold']) ? PaceFormat::target($t - 8) : null, 4, $reps),
        ], "{$reps}× (2 min unter / 1 min über der Schwelle)"];
    }

    /** 3 min Schwelle / 2 min Marathontempo, ohne Pause. */
    private function floatTempo(int $hard, array $p): array
    {
        $reps = $this->clamp((int) round($hard / 5), 3, 6);

        return [[
            $this->step('work', '3 min Schwelle', 3, $p['threshold'] ?? null, 4, $reps),
            $this->step('work', '2 min Marathontempo', 2, $p['marathon'] ?? null, 3, $reps),
        ], "{$reps}× (3 min Schwelle / 2 min Marathontempo)"];
    }

    private function raceBlocks(int $hard, array $p): array
    {
        $len  = $hard >= 30 ? 10 : 8;
        $reps = $this->clamp((int) round($hard / $len), 2, 4);
        $pace = $p['target_pace'] ?? $p['marathon'] ?? null;

        return [[
            $this->step('work', "{$len} min Zielrenntempo", $len, $pace, 4, $reps),
            $this->step('rest', 'Traben', 2, null, 1, $reps),
        ], "{$reps}×{$len} min Zielrenntempo"];
    }

    /** Die Pyramide, deren Belastung der Hartzeit am nächsten kommt. Traben: halbe Strecke. */
    private function vo2Pyramid(int $hard, array $p): array
    {
        $sec    = $this->sec($p, 'interval');
        $shapes = [[400, 800, 400], [400, 800, 1200, 800, 400], [400, 800, 1200, 1600, 1200, 800, 400]];
        $best   = $shapes[1];
        $diff   = PHP_FLOAT_MAX;
        foreach ($shapes as $s) {
            $d = abs(array_sum($s) / 1000 * $sec / 60 - $hard);
            if ($d < $diff) {
                [$best, $diff] = [$s, $d];
            }
        }

        $steps = [];
        foreach ($best as $i => $m) {
            $steps[] = $this->distanceStep('work', "{$m} m Intervall", $m, $p, 'interval', 5);
            if ($i < count($best) - 1) {
                $steps[] = $this->distanceStep('rest', ($m / 2) . ' m Traben', (int) ($m / 2), $p, 'easy', 1, 1, false);
            }
        }

        return [$steps, 'Pyramide ' . implode('-', $best) . ' m'];
    }

    private function thirtyThirty(int $hard, array $p): array
    {
        $reps = $this->clamp($hard, 12, 24);

        return [[
            $this->step('work', '30 s schnell', 0.5, $p['interval'] ?? null, 5, $reps),
            $this->step('rest', '30 s Traben', 0.5, null, 1, $reps),
        ], "{$reps}× 30/30"];
    }

    private function fourByFour(int $hard, array $p): array
    {
        $reps = $this->clamp((int) round($hard / 5), 3, 5);

        return [[
            $this->step('work', '4 min Intervall', 4, $p['interval'] ?? null, 5, $reps),
            $this->step('rest', 'Traben', 3, null, 1, $reps),
        ], "{$reps}×4 min"];
    }

    /** Kurze Abschnitte im Intervalltempo, ab drei Minuten an der Schwelle. */
    private function fartlekPyramid(int $hard, array $p): array
    {
        $shape = match (true) {
            $hard < 14  => [1, 2, 3, 2, 1],
            $hard >= 24 => [1, 2, 3, 4, 5, 4, 3, 2, 1],
            default     => [1, 2, 3, 4, 3, 2, 1],
        };

        $steps = [];
        foreach ($shape as $i => $len) {
            $steps[] = $len >= 3
                ? $this->step('work', "{$len} min zügig", $len, $p['threshold'] ?? null, 4)
                : $this->step('work', "{$len} min schnell", $len, $p['interval'] ?? null, 5);
            if ($i < count($shape) - 1) {
                $steps[] = $this->step('rest', 'Traben', $len / 2, null, 1);
            }
        }

        return [$steps, 'Fahrtspiel ' . implode('-', $shape) . ' min'];
    }

    private function fartlekOneOne(int $hard, array $p): array
    {
        $reps = $this->clamp((int) round($hard / 2), 8, 12);

        return [[
            $this->step('work', '1 min zügig', 1, $p['interval'] ?? null, 5, $reps),
            $this->step('rest', '1 min locker', 1, null, 1, $reps),
        ], "Fahrtspiel {$reps}× 1/1 min"];
    }

    /** Ohne Pace: bergauf zählt die Anstrengung, nicht die Uhr. */
    private function hillRepeats(int $hard): array
    {
        $reps = $this->clamp((int) round($hard / 3), 6, 10);

        return [[
            $this->step('work', '75 s bergauf', 1.25, null, 5, $reps),
            $this->step('rest', 'Locker zurück', 2, null, 1, $reps),
        ], "{$reps}×75 s bergauf"];
    }

    /** Jeder Kilometer etwas schneller, von locker bis zur Schwelle. */
    private function progressiveKm(int $minutes, array $p): array
    {
        $from = $this->sec($p, 'easy');
        $to   = $this->sec($p, 'threshold');
        $n    = $this->clamp((int) round($minutes * 60 / (($from + $to) / 2)), 5, 14);

        $steps = [];
        for ($i = 0; $i < $n; $i++) {
            $sec     = $from + ($to - $from) * $i / ($n - 1);
            $zone    = $i < $n / 3 ? 2 : ($i < 2 * $n / 3 ? 3 : 4);
            $pace    = isset($p['easy'], $p['threshold']) ? PaceFormat::fromSeconds($sec) : null;
            $steps[] = $this->distanceStep('work', 'Kilometer ' . ($i + 1), 1000, ['k' => $pace ?? PaceFormat::fromSeconds($sec)], 'k', $zone);
            if ($pace === null) {
                $steps[$i]['pace_target'] = null;
            }
        }

        return [$steps, "{$n} km, jeder etwas schneller"];
    }

    private function strongFinish(int $minutes, int $hard, array $p): array
    {
        $finish = $this->clamp($hard, 10, 15);
        $mp     = (int) ceil($finish / 2);
        $easy   = max(15, $minutes - $finish);

        return [[
            $this->step('warmup', 'Locker', $easy, $p['easy'] ?? null, 2),
            $this->step('work', 'Marathontempo', $mp, $p['marathon'] ?? null, 3),
            $this->step('work', 'Schwelle', $finish - $mp, $p['threshold'] ?? null, 4),
        ], "{$easy} min locker, {$finish} min Endbeschleunigung"];
    }

    private function test30(array $p): array
    {
        return [[
            $this->step('work', 'Steigerung', 20 / 60, $p['repetition'] ?? null, 5, 4),
            $this->step('rest', 'Traben', 1, null, 1, 4),
            $this->step('work', '30 min Test', 30, $p['threshold'] ?? null, 4),
        ], '30-min-Schwellentest'];
    }

    /** Die km des langen Laufs, wie in long(). */
    private function longKm(float $km, int $minutes, array $p): float
    {
        return $km > 0 ? $km : ($minutes > 0 ? round($minutes * 60 / $this->sec($p, 'long'), 1) : 15.0);
    }

    /** Locker, in der Mitte 6 × (20 s Steigerung + 1 min Traben). Die Steigerungen zählen nicht zur Strecke. */
    private function longStrides(float $km, int $minutes, array $p): array
    {
        $km    = $this->longKm($km, $minutes, $p);
        // Steigerungen und Traben laufen mit: ihre Meter gehen von den
        // lockeren Teilen ab, damit die Strecke der Leiter stimmt.
        $extra = 6 * (20 / $this->sec($p, 'repetition') + 60 / $this->sec($p, 'long'));
        $first = round(($km - $extra) / 2, 1);

        return [[
            $this->distanceStep('warmup', 'Locker', (int) round($first * 1000), $p, 'long', 2),
            $this->step('work', 'Steigerung', 20 / 60, $p['repetition'] ?? null, 5, 6),
            $this->step('rest', 'Traben', 1, null, 1, 6),
            $this->distanceStep('cooldown', 'Locker', (int) round(($km - $extra - $first) * 1000), $p, 'long', 2),
        ], $this->km($km) . ' km locker mit Steigerungen'];
    }

    /** Die Renntempo-km der Leiter in zwei bis drei Blöcken, 1 km locker dazwischen, 2 km locker zum Schluss. */
    private function longRaceBlocks(float $km, int $minutes, float $raceKm, array $p): array
    {
        $km     = $this->longKm($km, $minutes, $p);
        $blocks = $raceKm >= 6 ? 3 : 2;
        $raceKm = max(0.0, min($raceKm, $km - 3 - ($blocks - 1) - 2));

        if ($raceKm < 2) {
            return $this->long($km, $minutes, $raceKm, $p);
        }

        $block = (int) round($raceKm / $blocks * 1000);
        $key   = isset($p['target_pace']) ? 'target_pace' : 'marathon';
        $lead  = $km - $raceKm - ($blocks - 1) - 2;

        return [[
            $this->distanceStep('work', 'Locker', (int) round($lead * 1000), $p, 'long', 2),
            $this->distanceStep('work', 'Zielrenntempo', $block, $p, $key, 3, $blocks),
            $this->distanceStep('rest', '1 km locker', 1000, $p, 'long', 1, $blocks),
            $this->distanceStep('cooldown', 'Locker', 1000, $p, 'long', 1),
        ], $this->km($km) . " km, {$blocks} Blöcke im Zielrenntempo"];
    }

    /** Das letzte Stück (die Renntempo-km der Leiter) in zwei Stufen bis Marathontempo. */
    private function longProgressive(float $km, int $minutes, float $raceKm, array $p): array
    {
        $km     = $this->longKm($km, $minutes, $p);
        $finish = max(2.0, min($raceKm ?: $km / 3, $km - 3));
        $half   = (int) round($finish / 2 * 1000);
        $mid    = isset($p['long'], $p['marathon'])
            ? PaceFormat::fromSeconds(($this->sec($p, 'long') + $this->sec($p, 'marathon')) / 2)
            : null;

        return [[
            $this->distanceStep('work', 'Locker', (int) round(($km - $finish) * 1000), $p, 'long', 2),
            $this->distanceStep('work', 'Zügiger', $half, ['m' => $mid], 'm', 3, 1, $mid !== null),
            $this->distanceStep('work', 'Marathontempo', (int) round($finish * 1000) - $half, $p, 'marathon', 3),
        ], $this->km($km) . ' km, am Ende bis Marathontempo'];
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

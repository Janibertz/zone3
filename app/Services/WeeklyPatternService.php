<?php

namespace App\Services;

use App\Models\Event;
use App\Services\Bike\BikeSchedule;
use Carbon\CarbonImmutable;

/**
 * Baut das Wochengerüst eines Trainingsplans, bevor die KI gefragt wird.
 *
 * Bisher stand die Wochenstruktur nur als Bitte im Prompt („plane ein
 * Intervall pro Woche"). Durchgesetzt hat sie niemand, und bei jeder
 * Neugenerierung entschied das Modell neu. Hier werden die Pflichteinheiten
 * stattdessen auf konkrete Tage gelegt — die KI füllt danach nur noch
 * Beschreibung, Dauer und Pace aus, und {@see TrainingPlanValidator} prüft
 * das Ergebnis gegen dieses Gerüst.
 */
class WeeklyPatternService
{
    /** Mindestabstand zwischen zwei harten Einheiten. */
    private const QUALITY_GAP_DAYS = 2;

    /** Ab so viel Restzeit lohnt sich eine zweite, ergänzende Einheit. */
    private const SECOND_SLOT_MIN_MINUTES = 25;

    /**
     * Und so lang darf sie höchstens werden. Ohne Deckel bekam ein Sonntag
     * mit 180 Minuten Budget nach einem 90-Minuten-Longrun eine
     * 90-Minuten-Krafteinheit — gemeint war eine Ergänzung, keine zweite
     * Trainingseinheit.
     */
    private const SECOND_SLOT_MAX_MINUTES = 30;

    /** Eine eigene Krafteinheit an einem laufreien Tag. */
    private const STRENGTH_MAX_MINUTES = 45;

    /** Ein geplanter Lauf unter 20 Minuten ist kein sinnvoller Trainingsreiz. */
    public const MIN_USEFUL_RUN_MINUTES = 20;

    /** Unter so viele Läufe streicht das Umfangsbudget eine Woche nicht. */
    public const MIN_RUNS_PER_WEEK = 4;

    /** Alles, wofür Laufschuhe nötig sind — die Typen, die Wochenumfang kosten. */
    public const RUN_SLOT_TYPES = ['easy_run', 'tempo_run', 'interval', 'long_run', 'progressive_run', 'test_run'];

    /** Diese Typen zählen als harte Einheit — nie zwei davon an einem Tag. */
    public const HARD_TYPES = ['tempo_run', 'interval', 'test_run', 'progressive_run'];

    /**
     * Ein langer Lauf braucht Zeit. Am Ende des Planungsfensters bleibt oft
     * eine angebrochene Woche übrig, in der nur kurze Tage liegen — dort
     * landete der Longrun trotzdem, und im Plan stand „Langer Lauf, 45 min".
     * Passt er nicht, entfällt er; die nächste Neuberechnung plant ihn.
     */
    private const MIN_LONG_RUN_MINUTES = 70;

    /**
     * Wunschreihenfolge je Zieltyp. Reichen die verfügbaren Tage nicht,
     * fällt von hinten weg — deshalb steht vorn, was für das Ziel am
     * meisten zählt.
     *
     * Der Wunsch des Athleten (je 1× Easy, Tempo, Intervall) steckt in
     * jeder Liste; die Reihenfolge entscheidet nur, was bei einer knappen
     * Woche überlebt.
     */
    private const PRIORITIES = [
        '5km'            => ['interval', 'tempo_run', 'easy_run', 'long_run'],
        '10km'           => ['interval', 'tempo_run', 'long_run', 'easy_run'],
        'half_marathon'  => ['long_run', 'tempo_run', 'interval', 'easy_run'],
        'marathon'       => ['long_run', 'tempo_run', 'interval', 'easy_run'],
        'backyard_ultra' => ['long_run', 'easy_run', 'tempo_run', 'interval'],
    ];

    private const DEFAULT_PRIORITY = ['long_run', 'tempo_run', 'interval', 'easy_run'];

    /**
     * Wie viele harte Einheiten eine Woche verträgt. Beim Backyard bleibt es
     * bei einer — dort ist lockeres Volumen die Basis, und zwei harte
     * Einheiten würden das Wochenvolumen kaputtmachen.
     */
    private const MAX_HARD_PER_WEEK = ['backyard_ultra' => 1];

    /**
     * Ab dieser Distanz ersetzt ein Rennen den langen Lauf der Woche.
     *
     * Ohne diese Zuordnung plante das Geruest neben einem 24-km-Rennen noch
     * einen Longrun — zwei lange Belastungen in derselben Woche, acht Tage
     * vor dem Zielrennen.
     */
    private const RACE_COUNTS_AS_LONG_RUN_KM = 15.0;

    /**
     * Höchstens so viel der Laufzeit einer Woche ist hart (Seiler, 80/20).
     *
     * Gezählt werden die Hauptteile der harten Einheiten und die
     * Renntempo-Abschnitte des langen Laufs. Einlaufen und Auslaufen sind
     * locker, auch wenn sie in einem Intervalltag stecken. Daneben gilt
     * weiter die Regel nach Einheiten ({@see SECOND_HARD_FROM_SESSIONS}) —
     * Entscheidung vom 25.10.2026: beides.
     */
    public const HARD_SHARE = 0.20;

    /** So lang ist der Hauptteil einer harten Einheit, wenn die Woche Platz hat. */
    public const HARD_MAIN_MINUTES = [
        'tempo_run'       => 25,
        'interval'        => 20,
        'progressive_run' => 15,
        'test_run'        => 20,
    ];

    /** Darunter ist ein Hauptteil kein Reiz mehr — die Einheit wird locker. */
    public const MIN_HARD_MAIN_MINUTES = 10;

    /** Einlaufen und Auslaufen zusammen. */
    public const WARMUP_COOLDOWN_MINUTES = 20;

    /**
     * Norwegisches Modell (Entscheidung vom 25.10.2026): statt 80/20 höchstens
     * 25 % der Laufzeit knapp unter der Schwelle und höchstens 5 % schneller
     * als die Schwelle. Der Rest ist locker.
     */
    public const SUB_THRESHOLD_SHARE = 0.25;

    public const FAST_SHARE = 0.05;

    /** Hauptteil je Sub-Schwellen-Einheit, wenn die Woche Platz hat. */
    public const SUB_MAIN_MINUTES = ['am' => 30, 'pm' => 30, 'single' => 35];

    /** Darunter lohnt eine Sub-Schwellen-Einheit nicht — sie entfällt bzw. wird locker. */
    public const MIN_SUB_MAIN_MINUTES = 15;

    /** Die kurze schnelle Einheit am Wochenende: Richtwert und Untergrenze. */
    public const FAST_MAIN_MINUTES = 10;

    public const MIN_FAST_MAIN_MINUTES = 5;

    /**
     * Ein- und Auslaufen bei Sub-Schwelle. Kürzer als bei einer harten
     * Einheit — der Hauptteil ist kontrolliert, und abends bleibt oft nur
     * ein Fenster von 30 bis 45 Minuten.
     */
    public const SUB_WARMUP_MINUTES = 15;

    /** Ein Abendfenster unter dieser Länge trägt keine zweite Einheit. */
    public const MIN_SECOND_WINDOW_MINUTES = 30;

    /**
     * Was im norwegischen Modell bei einer knappen Woche wegfällt: zuerst
     * die schnelle Einheit, dann (unter Schutz des letzten lockeren Laufs)
     * die lockeren — die Schwelle trägt das Modell.
     */
    private const NORWEGIAN_PRIORITY = ['long_run', 'tempo_run', 'interval', 'easy_run'];

    public function __construct(
        private readonly PeriodizationService $periodization,
        private readonly BikeSchedule $bikeSchedule,
    ) {}

    private const ISO_TO_KEY = [
        1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday',
        5 => 'friday', 6 => 'saturday', 7 => 'sunday',
    ];

    /**
     * @param  array<string,array>  $weeklyAvailability   Wochenraster aus dem Athletenprofil
     * @param  array<string,array>  $overrides            Ausnahmen je Datum (Y-m-d)
     * @param  list<string>         $finalizedDates       Tage, die schon abgeschlossen sind
     *
     * @param  array|null           $comeback             Wiedereinstiegs-Stufe aus {@see ReturnToRunService::forPlan()}
     *
     * @return array{days: array<string,array>, weeks: array<string,array>, priority: list<string>, comeback: array|null}
     */
    public function build(
        Event $event,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?array $weeklyAvailability,
        array $overrides = [],
        array $finalizedDates = [],
        ?array $comeback = null,
        ?array $longRuns = null,
        ?array $volume = null,
        ?int $planningPaceSec = null,
        array $races = [],
        ?array $model = null,
        ?array $bike = null,
        ?array $strength = null,
    ): array {
        $days      = $this->availabilityPerDate($from, $to, $weeklyAvailability, $overrides, $finalizedDates, $races);
        $norwegian = ($model['name'] ?? 'polarized') === 'norwegian';
        $priority = self::PRIORITIES[$event->race_distance] ?? self::DEFAULT_PRIORITY;
        $maxHard  = self::MAX_HARD_PER_WEEK[$event->race_distance] ?? 2;
        $periods  = $this->periodization->forWindow($event, $from, $to);

        // Die Stufe wandert über die Wochen mit: sie zählt Trainingseinheiten,
        // nicht Kalendertage. Ist die Leiter durch, plant die nächste Woche
        // wieder normal.
        $step = $comeback['step'] ?? null;

        // Der letzte Comeback-Lauf wandert ebenfalls mit. Lag er am Sonntag,
        // begann die neue Woche ohne ihn — und Montag bekam Stufe 2 ohne den
        // Ruhetag dazwischen. Aufgefallen, weil die Tests nur sonntags
        // fielen; `plan:write-week` laeuft genau dann.
        $lastRun = null;

        $weeks = [];
        foreach ($this->groupByWeek($days) as $weekKey => $dates) {
            // Die Phase entscheidet, welche harten Einheiten die Woche
            // überhaupt bekommt — in der Grundlage keine Intervalle, in einer
            // Entlastungswoche nur eine.
            $period       = $periods[$weekKey] ?? null;
            $quality      = $period['quality'] ?? [];
            // Grundlage: die eine harte Einheit wechselt wochenweise zwischen
            // Schwelle und Intervall — dort nur Fahrtspiel und Bergintervalle,
            // keine Bahn (RunWorkouts::PHASES, Entscheidung von Jan 29.10.2026).
            $baseSwap     = ($period['phase'] ?? null) === 'base' && (int) substr($weekKey, -2) % 2 === 0;
            if (($period['phase'] ?? null) === 'base') {
                $quality[] = 'interval';
            }
            $weekPriority = $period
                ? array_values(array_filter(
                    $priority,
                    fn ($t) => ! in_array($t, self::HARD_TYPES, true) || in_array($t, $quality, true),
                ))
                : $priority;
            if ($baseSwap) {
                $weekPriority = $this->intervalFirst($weekPriority);
            }
            $weekMaxHard  = $period ? min($maxHard, $period['max_hard']) : $maxHard;

            $weeks[$weekKey] = match (true) {
                $step !== null && $step < ReturnToRunService::TOTAL_STEPS
                            => $this->planComebackWeek($days, $dates, $step, $lastRun, $priority, $maxHard),
                $norwegian  => $this->planNorwegianWeek($days, $dates, $period),
                default     => $this->planWeek($days, $dates, $weekPriority, $weekMaxHard),
            };

            // Was danach noch frei ist, wird HIER entschieden — nicht vom
            // Modell. Siehe assignFreeDays().
            $this->assignFreeDays($days, $dates);

            // Die Phase reist am Slot mit: sie entscheidet, welche Bausteine
            // der Katalog anbietet.
            foreach ($dates as $d) {
                foreach ($days[$d]['slots'] as $i => $slot) {
                    $days[$d]['slots'][$i]['phase'] = $period['phase'] ?? null;
                }
            }
        }

        // Der lange Lauf bekommt seine Zieldistanz aus der Leiter — sie ist
        // rückwärts vom Renntag gerechnet und nicht Sache des Modells.
        if ($longRuns) {
            $this->applyLongRuns($days, $longRuns, easyOnly: $norwegian);
        }

        // Und danach bekommt jede übrige Laufeinheit ihren Anteil am
        // Wochenumfang. Muss NACH der Leiter laufen: der lange Lauf geht vor,
        // der Rest teilt sich, was übrig bleibt.
        if ($volume && ! empty($volume['has_data']) && $planningPaceSec > 0) {
            $this->applyVolumeBudget($days, $volume, $planningPaceSec, $norwegian ? self::NORWEGIAN_PRIORITY : $priority, $periods);
        }

        if ($norwegian) {
            // Hat das Budget eine Hälfte eines Doppel-Tags gestrichen, ist
            // die andere eine gewöhnliche Einzel-Schwelle.
            $this->normalizeDoubles($days);

            // Statt 80/20: Sub-Schwelle und schnelle Minuten getrennt deckeln.
            $this->applyNorwegianShares($days);
        } else {
            // 80/20 nach Zeit — erst jetzt, weil es die Minuten aller
            // Einheiten der Woche braucht.
            $this->applyEightyTwenty($days, $priority);
        }

        // Kraft vor dem Rad: die Zahl der Krafttage ist eingestellt, das Rad
        // nur ein Höchstwert. Lief das Rad zuerst, belegte die zusätzliche
        // Fahrt den einzigen Tag mit Restzeit, und Jans Woche hatte gar
        // keine Kraft mehr. Kraft braucht die fertige Laufwoche — welche
        // Tage frei bleiben, wo der lange Lauf liegt, was übrig ist.
        if (! empty($strength['enabled'])) {
            $this->applyStrength($days, (int) ($strength['days_per_week'] ?? 2), $periods);
        }

        // Rad/Zwift zuletzt: der Lauf, den eine Radeinheit ersetzt, hat dann
        // seine Minuten und seinen Hauptteil — und seine Kilometer fallen mit
        // dem Tausch aus der Woche, statt auf andere Läufe verteilt zu werden.
        // Eine zusätzliche Fahrt nimmt, was neben Lauf und Kraft übrig ist.
        if ($bike) {
            $this->bikeSchedule->apply($days, $this->groupByWeek($days), $bike, $periods);
        }

        return [
            'days'      => $days,
            'weeks'     => $weeks,
            'priority'  => $priority,
            'comeback'  => $comeback,
            'long_runs' => $longRuns,
            'periods'   => $periods,
            'model'     => $norwegian ? ['name' => 'norwegian'] + ($model ?? []) : ['name' => 'polarized'],
        ];
    }

    /**
     * Verfügbarkeit je Datum auflösen. Eine Tages-Ausnahme schlägt das
     * Wochenraster; ohne jede Angabe gilt der Tag als verfügbar, damit ein
     * Athlet ohne gepflegtes Profil trotzdem einen Plan bekommt.
     */
    private function availabilityPerDate(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?array $weekly,
        array $overrides,
        array $finalizedDates,
        array $races = [],
    ): array {
        $finalized = array_flip($finalizedDates);
        $days      = [];

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $key = $date->format('Y-m-d');

            // budget_min = 0 heisst „keine bekannte Obergrenze", NICHT „gesperrt".
            // Gesperrt ist ein Tag nur, wenn es dazu eine ausdrueckliche Angabe
            // gibt. Andernfalls wuerde ein Profil ohne gepflegtes Wochenraster
            // ein leeres Geruest ergeben — und der Validator machte daraus
            // vierzehn Ruhetage.
            // Das Abendfenster kommt aus dem Wochenraster. Eine Tagesausnahme
            // kennt keins: wer für einen Tag eigens Zeit einträgt, hat damit
            // gesagt, wie viel er an dem Tag hat.
            $second = 0;

            if (isset($overrides[$key])) {
                $available = (bool) ($overrides[$key]['available'] ?? true);
                $budget    = (int) ($overrides[$key]['duration_min'] ?? 0);
            } elseif ($weekly !== null) {
                $day       = $weekly[self::ISO_TO_KEY[$date->isoWeekday()]] ?? null;
                $available = (bool) ($day['available'] ?? false);
                $budget    = (int) ($day['duration_min'] ?? 0);
                $second    = (int) ($day['second_min'] ?? 0);
            } else {
                $available = true;
                $budget    = 0;
            }

            // Fester Termin an diesem Wochentag (Laufclub, Vereinstraining).
            // Er wird nicht geplant, sondern ist gesetzt — das Gerüst legt
            // ihn als Erstes und plant nichts Zweites daneben.
            $fixed = ($weekly[self::ISO_TO_KEY[$date->isoWeekday()]]['fixed'] ?? null);
            if ($fixed && ! isset($overrides[$key])) {
                $fixed = [
                    'type'  => $fixed['type']  ?? 'interval',
                    'label' => $fixed['label'] ?? 'Fester Termin',
                ];
            } else {
                $fixed = null;
            }

            // Ein Rennen ist der festeste aller Termine.
            //
            // Bisher kannte nur der Prompt die anderen Rennen im Fenster
            // ("an diesen Tagen KEIN Training"). Das Modell hielt sich dran
            // und lieferte einen Ruhetag — und der Validator ersetzte ihn
            // durch die Einheit, die im Geruest stand. Am 5-km-Renntag stand
            // dann ein Tempolauf, am Tag des 24-km-Wattlaufs ein langer Lauf.
            // Zwei Wahrheiten ueber denselben Tag, und das Geruest gewann.
            //
            // Deshalb steht das Rennen jetzt IM Geruest. Es schlaegt das
            // Wochenraster (wer laeuft, hat an dem Tag Zeit) und einen
            // Vereinstermin (der faellt an dem Tag aus).
            if (isset($races[$key])) {
                $race      = $races[$key];
                $available = true;
                $raceKm    = (float) ($race['km'] ?? 0);

                $fixed = [
                    'type'        => 'race_prep',
                    'label'       => $race['name'],
                    'race'        => $race,
                    'substitutes' => $raceKm >= self::RACE_COUNTS_AS_LONG_RUN_KM ? 'long_run' : null,
                ];
            }

            $days[$key] = [
                'date'        => $key,
                'weekday'     => $date->isoWeekday(),
                'available'   => $available,
                'budget_min'  => $available ? $budget : 0,
                'second_min'  => $available && $second >= self::MIN_SECOND_WINDOW_MINUTES ? $second : 0,
                'finalized'   => isset($finalized[$key]),
                'week'        => $date->format('o-\WW'),
                'fixed'       => $available ? $fixed : null,
                'slots'       => [],
            ];
        }

        return $days;
    }

    /**
     * Die Zieldistanz der Leiter an den Longrun-Slot der jeweiligen Woche
     * hängen. Steht in einer Woche kein langer Lauf, gibt es auch nichts zu
     * setzen — die Leiter erzwingt keinen Slot, sie füllt einen.
     */
    private function applyLongRuns(array &$days, array $longRuns, bool $easyOnly = false): void
    {
        foreach ($days as $date => $day) {
            foreach ($day['slots'] as $i => $slot) {
                if ($slot['type'] !== 'long_run') {
                    continue;
                }

                $target = $longRuns['weeks'][$day['week']] ?? null;
                if (! $target) {
                    continue;
                }

                $km   = (float) $target['km'];
                $min   = (int) $target['min'];

                // Im norwegischen Modell bleibt der lange Lauf locker — die
                // Qualität steckt in der Sub-Schwelle, nicht im Renntempo am
                // Ende des Longruns.
                $mpKm  = $easyOnly ? 0.0 : (float) $target['mp_km'];

                // Die Leiter rechnet mit dem laengsten Tag der WOCHE. Das
                // Geruest legt den langen Lauf aber auf den laengsten FREIEN
                // Tag — ist der kuerzer, weil der Sonntag schon gelaufen oder
                // gesperrt ist, passt die Zieldistanz nicht hinein. Im Prompt
                // stand dann eine Vorgabe, die niemand erfuellen kann, und
                // der Validator musste sie jedes Mal kuerzen: in den Logs
                // vier Mal "long_run mit 177 min, erlaubt sind 120".
                $cap = (int) ($slot['max_min'] ?: $day['budget_min']);

                if ($cap > 0 && $min > $cap) {
                    $factor = $cap / $min;
                    $km     = round($km * $factor, 1);
                    $mpKm   = round($mpKm * $factor, 1);
                    $min    = $cap;

                    $days[$date]['slots'][$i]['shortened'] = true;
                }

                $days[$date]['slots'][$i]['target_km']  = $km;
                $days[$date]['slots'][$i]['target_min'] = $min;
                $days[$date]['slots'][$i]['race_km']    = $mpKm;
                $days[$date]['slots'][$i]['kind']       = $target['kind'];
            }
        }
    }

    /**
     * Den Wochenumfang auf die Laufeinheiten verteilen.
     *
     * Hier lag der folgenschwerste Widerspruch des ganzen Prompts. Das
     * Gerüst belegte die Tage nach VERFÜGBARKEIT und schrieb je Tag „max.
     * 120 min" — das Modell las das als Auftrag. Der Umfangsblock daneben
     * sagte gleichzeitig „der Wochenumfang darf 35,2 km nicht
     * überschreiten". Beides stand als bindend im selben Prompt.
     *
     * Für einen realen Fall hiess das: fünf Einheiten mit zusammen 383
     * Minuten Laufzeit — rund 71 km — gegen einen Deckel von 35,2 km. Es
     * gab keine Antwort, die beide Vorgaben erfüllt. Das Modell musste eine
     * brechen, und welche, entschied es jedes Mal neu. Genau das sieht der
     * Athlet als „schon wieder ein Fehler im Plan".
     *
     * Jetzt rechnet das Gerüst es aus:
     *   · Der lange Lauf steht (Leiter) und geht ab.
     *   · Was übrig bleibt, teilen sich die anderen Laufeinheiten.
     *   · Reicht der Anteil nicht für einen sinnvollen Lauf, fällt die
     *     unwichtigste Einheit weg — statt fünf Alibi-Läufe zu planen.
     *   · Die Verfügbarkeit bleibt Obergrenze, wird aber nie zum Ziel.
     *
     * @param  list<string>  $priority  Wunschreihenfolge; von hinten wird gestrichen.
     */
    private function applyVolumeBudget(array &$days, array $volume, int $paceSec, array $priority, array $periods = []): void
    {
        $budgetKm = (float) ($volume['next_week_max'] ?? 0);
        if ($budgetKm <= 0) {
            return;
        }

        // Der Deckel wächst über das Fenster mit — mit derselben Rate von
        // 8 %, die auch im Umfangsblock des Prompts steht. Bliebe er flach,
        // hungerte die zweite Woche aus: ihr langer Lauf ist laut Leiter
        // länger, und vom selben Deckel bliebe für den Rest der Woche
        // weniger übrig als in der ersten.
        //
        // Entlastung und Taper kommen als Faktor obendrauf. Sie stehen damit
        // im Gerüst und nicht als Bitte im Prompt („jede dritte bis vierte
        // Woche entlasten"), die nie jemand durchgesetzt hat.
        //
        // Gesteigert wird nur über Belastungswochen: nach einer Entlastung
        // geht es dort weiter, wo die Woche davor aufgehört hat, nicht mit
        // einem doppelten Sprung.
        $loadWeeks = 0;
        foreach ($this->groupByWeek($days) as $weekKey => $dates) {
            $this->budgetWeek(
                $days,
                $dates,
                $budgetKm
                    * (1 + WeeklyVolumeService::MAX_PROGRESSION_PCT / 100) ** $loadWeeks
                    * ($periods[$weekKey]['volume_factor'] ?? 1.0),
                $paceSec,
                $priority,
            );
            if (empty($periods[$weekKey]['recovery'])) {
                $loadWeeks++;
            }
        }
    }

    /**
     * 80/20 nach Zeit: höchstens 20 % der Laufminuten einer Woche sind hart.
     *
     * Die Regel nach Einheiten allein reichte nicht. Eine Woche mit vier
     * Läufen, davon ein Intervalltag und ein langer Lauf mit 8 km im
     * Renntempo, war nach Einheiten 75/25 in Ordnung — nach Zeit kam sie auf
     * fast ein Drittel hart.
     *
     * Jede harte Einheit bekommt hier `hard_min`: die Minuten ihres
     * Hauptteils. Was der Renntempo-Abschnitt des langen Laufs und ein fester
     * harter Termin verbrauchen, geht vorher ab. Reicht der Rest nicht für
     * einen Hauptteil mit Substanz, wird die unwichtigste harte Einheit
     * locker — eine halbe Intervalleinheit setzt keinen Reiz, ermüdet aber.
     *
     * Rennen zählen nicht mit: ein Marathon wäre allein 180 harte Minuten,
     * und er wird nicht geplant, sondern gelaufen.
     *
     * @param  list<string>  $priority  Wunschreihenfolge; von hinten wird gestrichen.
     */
    private function applyEightyTwenty(array &$days, array $priority): void
    {
        foreach ($this->groupByWeek($days) as $dates) {
            $total      = 0.0;
            $taken      = 0.0;
            $open       = [];
            $incomplete = false;

            foreach ($dates as $date) {
                foreach ($days[$date]['slots'] ?? [] as $i => $slot) {
                    if (! empty($slot['race']) || ! in_array($slot['type'], self::RUN_SLOT_TYPES, true)) {
                        continue;
                    }

                    $min = (int) ($slot['target_min'] ?? 0);
                    $total += $min;

                    if ($slot['type'] === 'long_run') {
                        $km = (float) ($slot['target_km'] ?? 0);
                        if ($km > 0 && ($slot['race_km'] ?? 0) > 0) {
                            $taken += (float) $slot['race_km'] * $min / $km;
                        }
                        continue;
                    }

                    if (! in_array($slot['type'], self::HARD_TYPES, true)) {
                        continue;
                    }

                    $want = self::HARD_MAIN_MINUTES[$slot['type']] ?? 20;
                    if ($min > 0) {
                        $want = min($want, max(0, $min - self::WARMUP_COOLDOWN_MINUTES));
                    } else {
                        // Ohne Wochenumfang (keine Laufdaten) gibt es keine
                        // Minuten, gegen die sich ein Anteil rechnen liesse.
                        // Dann bleibt es beim Richtwert; der Validator prüft
                        // gegen das, was das Modell tatsächlich plant.
                        $incomplete = true;
                    }

                    if (! empty($slot['fixed'])) {
                        $taken += $want;
                        continue;
                    }

                    $open[] = ['date' => $date, 'index' => $i, 'type' => $slot['type'], 'want' => $want];
                }
            }

            if ($open === []) {
                continue;
            }

            $cap = $incomplete ? INF : self::HARD_SHARE * $total - $taken;

            // Solange der Rest nicht für jeden Hauptteil die Mindestdauer
            // trägt, wird die unwichtigste harte Einheit locker.
            while ($open && array_sum(array_column($open, 'want')) > $cap
                && $cap / count($open) < self::MIN_HARD_MAIN_MINUTES) {
                $victim = $this->leastImportantHard($open, $priority);
                $o      = $open[$victim];

                $days[$o['date']]['slots'][$o['index']]['type']         = 'easy_run';
                $days[$o['date']]['slots'][$o['index']]['hard']         = false;
                $days[$o['date']]['slots'][$o['index']]['eighty_twenty'] = $o['type'];

                array_splice($open, $victim, 1);
            }

            $wanted = array_sum(array_column($open, 'want'));
            $scale  = $wanted > $cap && $wanted > 0 ? $cap / $wanted : 1.0;

            foreach ($open as $o) {
                $days[$o['date']]['slots'][$o['index']]['hard_min'] = (int) max(
                    min(self::MIN_HARD_MAIN_MINUTES, $o['want']),
                    floor($o['want'] * $scale),
                );
            }
        }
    }

    /** Die harte Einheit, die nach der Wunschreihenfolge am ehesten wegkann. */
    private function leastImportantHard(array $open, array $priority): int
    {
        $rank  = array_flip($priority);
        $worst = 0;

        foreach ($open as $i => $o) {
            if (($rank[$o['type']] ?? PHP_INT_MAX) >= ($rank[$open[$worst]['type']] ?? PHP_INT_MAX)) {
                $worst = $i;
            }
        }

        return $worst;
    }

    /**
     * Mindestdauern, damit eine Einheit ihren Zweck erfuellt.
     *
     * Eine Schwelleneinheit braucht Einlaufen, Hauptteil und Auslaufen —
     * unter 45 Minuten bleibt davon nichts uebrig. Ein lockerer Lauf unter
     * 30 Minuten ist ein Spaziergang mit Laufschuhen.
     */
    private const MIN_MINUTES_PER_TYPE = [
        'tempo_run'       => 45,
        'interval'        => 45,
        'progressive_run' => 40,
        'test_run'        => 40,
        'easy_run'        => 30,
    ];

    /** @param list<string> $dates */
    private function budgetWeek(array &$days, array $dates, float $budgetKm, int $paceSec, array $priority): void
    {
        $open    = [];
        $takenKm = 0.0;
        $fixed   = 0;

        foreach ($dates as $date) {
            foreach ($days[$date]['slots'] ?? [] as $i => $slot) {
                // Ein Rennen ist kein Trainingstyp, verbraucht aber
                // Wochenumfang wie kaum etwas sonst. Ohne diese Ausnahme
                // faenden 24,6 km in der Wochenbilanz nicht statt, und das
                // Geruest verteilte den vollen Deckel auf die uebrigen Tage.
                $isRace = ! empty($slot['race']);

                if (! $isRace && ! in_array($slot['type'], self::RUN_SLOT_TYPES, true)) {
                    continue;
                }

                // Eine Stufe des Wiedereinstiegs wird nicht gestrichen: die
                // Leiter zählt Einheiten, und eine gestrichene Stufe hätte
                // die nächste ohne Vorlauf gelassen. Sie verbraucht ihre
                // Höchstdauer. Vorher fielen Jans erste beiden Stufen dem
                // 20-km-Lauf zum Opfer, und Mo bis Mi standen leer.
                if (isset($slot['comeback']) && empty($slot['race'])) {
                    $minutes = (int) ($slot['max_min'] ?: $days[$date]['budget_min']);
                    $km      = $minutes * 60 / $paceSec;

                    $days[$date]['slots'][$i]['target_km']  = round($km, 1);
                    $days[$date]['slots'][$i]['target_min'] = $minutes;
                    $takenKm += $km;
                    $fixed++;
                    continue;
                }

                // Der lange Lauf steht schon (Leiter) und geht ab.
                if (isset($slot['target_km'])) {
                    $takenKm += (float) $slot['target_km'];
                    $fixed++;
                    continue;
                }

                // Ein fester Termin ist nicht verhandelbar: der Athlet geht
                // hin, ob er im Budget steht oder nicht. Er verbraucht die
                // Zeit, die er dauert.
                if (! empty($slot['fixed'])) {
                    // Bei einem Rennen steht die Distanz fest — sie wird
                    // nicht aus dem Zeitbudget geschaetzt. Andernfalls zaehlte
                    // ein 24,6-km-Rennen mit den 60 Minuten in die Woche, die
                    // zufaellig im Wochenraster stehen.
                    $raceKm = (float) ($slot['race']['km'] ?? 0);

                    if ($raceKm > 0) {
                        // Die eingetragene Zielzeit schlaegt jede Schaetzung.
                        // Ohne sie stand am 5-km-Renntag „27 min" — 5 km in
                        // der Zonenpace —, obwohl unter Events 20 Minuten
                        // stehen. Das Modell pacte den 5er daraufhin im
                        // Marathontempo.
                        $target  = (int) ($slot['race']['target_min'] ?? 0);
                        $minutes = $target > 0 ? $target : (int) round($raceKm * $paceSec / 60);
                        $km      = $raceKm;
                    } else {
                        $minutes = (int) ($slot['max_min'] ?: $days[$date]['budget_min']);
                        $km      = $minutes * 60 / $paceSec;
                    }

                    $days[$date]['slots'][$i]['target_km']  = round($km, 1);
                    $days[$date]['slots'][$i]['target_min'] = $minutes;
                    $takenKm += $km;
                    $fixed++;
                    continue;
                }

                $open[] = [
                    'date'  => $date,
                    'index' => $i,
                    'type'  => $slot['type'],
                    'cap'   => (int) ($slot['max_min'] ?: $days[$date]['budget_min']),
                    'pm'    => ($slot['double'] ?? null) === 'pm',
                ];
            }
        }

        if ($open === []) {
            return;
        }

        // Ab hier wird in Minuten gerechnet — das ist die Groesse, in der
        // der Athlet seine Woche plant, und die Verfuegbarkeit steht auch
        // darin.
        $budgetMin = max(0.0, ($budgetKm - $takenKm)) * $paceSec / 60;

        // Die Zahl der Läufe kommt aus der Verfügbarkeit (Jan, 30.10.2026):
        // ist der Umfang knapp, werden die lockeren Läufe kürzer, bis
        // hinunter auf MIN_USEFUL_RUN_MINUTES. Gestrichen wird erst, wenn
        // selbst das nicht passt, und nie unter MIN_RUNS_PER_WEEK Läufe.
        // Vorher fiel ein Lauf schon, sobald die vollen 30 Minuten nicht
        // passten — bei Jans 21 km blieben drei Läufe, und die freien Tage
        // füllten Kraft und Rad.
        while (count($open) > 1 && $fixed + count($open) > self::MIN_RUNS_PER_WEEK
            && $this->minutesNeeded($open, true) > $budgetMin) {
            $victim = $this->leastImportant($open, $priority);
            if ($victim === null) {
                break;
            }

            unset($days[$open[$victim]['date']]['slots'][$open[$victim]['index']]);
            array_splice($open, $victim, 1);
        }

        // Hat das Streichen die Woche unter SECOND_HARD_FROM_SESSIONS Läufe
        // gebracht, wird die zweite harte Einheit locker statt gestrichen —
        // so bleibt die Zahl der Läufe und 80/20 nach Einheiten trotzdem.
        // Die Sub-Schwelle des norwegischen Modells regelt ihre Anteile selbst.
        $hardInWeek = 0;
        foreach ($dates as $date) {
            foreach ($days[$date]['slots'] ?? [] as $slot) {
                if (! empty($slot['hard']) && empty($slot['controlled']) && empty($slot['race'])) {
                    $hardInWeek++;
                }
            }
        }
        while ($hardInWeek > $this->hardSessionsFor($fixed + count($open))) {
            $hardOpen = array_filter($open, fn ($o) => in_array($o['type'], self::HARD_TYPES, true)
                && empty($days[$o['date']]['slots'][$o['index']]['controlled']));
            if ($hardOpen === []) {
                break;
            }

            $keys   = array_keys($hardOpen);
            $victim = $keys[$this->leastImportantHard(array_values($hardOpen), $priority)];
            $o      = $open[$victim];

            $days[$o['date']]['slots'][$o['index']]['type'] = 'easy_run';
            $days[$o['date']]['slots'][$o['index']]['hard'] = false;
            $open[$victim]['type'] = 'easy_run';
            $hardInWeek--;
        }

        // Reicht es nicht einmal fuer die Mindestdauern, bleiben die Läufe
        // trotzdem stehen — sie werden dann eben kurz.
        $base = [];
        foreach ($open as $k => $slot) {
            $base[$k] = min($this->minMinutesFor($slot['type']), $slot['cap']);
        }

        // Fehlt Zeit für die vollen Mindestdauern, geben zuerst die lockeren
        // Läufe nach, gleichmäßig bis auf ihre Untergrenze. Eine harte
        // Einheit unter 45 Minuten hätte keinen Hauptteil mehr.
        $deficit = array_sum($base) - $budgetMin;
        if ($deficit > 0) {
            $slack = [];
            foreach ($open as $k => $slot) {
                if ($slot['type'] === 'easy_run') {
                    $slack[$k] = max(0, $base[$k] - min(self::MIN_USEFUL_RUN_MINUTES, $slot['cap']));
                }
            }
            $totalSlack = array_sum($slack);
            foreach ($slack as $k => $room) {
                if ($totalSlack > 0) {
                    $base[$k] -= min($room, $deficit * $room / $totalSlack);
                }
            }
        }

        // Was ueber die Mindestdauern hinaus uebrig ist, wird im Verhaeltnis
        // der Mindestdauern verteilt — die Schluesseleinheit waechst also
        // staerker als der lockere Lauf.
        $surplus = $budgetMin - array_sum($base);
        $weights = array_sum($base) > 0 ? $base : array_fill(0, count($open), 1);

        foreach ($open as $k => $slot) {
            $minutes = $base[$k];

            if ($surplus > 0) {
                $minutes += $surplus * ($weights[$k] / array_sum($weights));
            }

            $minutes = (int) round(min($minutes, $slot['cap']));
            $km      = $minutes * 60 / $paceSec;

            $days[$slot['date']]['slots'][$slot['index']]['target_km']  = round($km, 1);
            $days[$slot['date']]['slots'][$slot['index']]['target_min'] = $minutes;
        }

        // Aufgeräumt, damit die Indizes nach dem Streichen wieder passen.
        // Wer dabei seine einzige Einheit verloren hat, wird Ruhetag — sonst
        // fiele der Tag im Prompt zurück auf „frei: rest ODER lockere
        // Einheit", und genau diesen Münzwurf haben wir abgeschafft.
        foreach ($dates as $date) {
            if (! isset($days[$date]['slots'])) {
                continue;
            }

            $days[$date]['slots'] = array_values($days[$date]['slots']);

            if ($days[$date]['slots'] === [] && $days[$date]['available'] && empty($days[$date]['finalized'])) {
                $days[$date]['rest'] = true;
            }
        }

        $this->spreadRuns($days, $dates);
    }

    /** Mehr Tage ohne Lauf am Stück lässt das Gerüst nicht stehen, solange es anders geht. */
    private const MAX_DAYS_WITHOUT_RUN = 2;

    /**
     * Nach dem Streichen die übrigen Läufe über die Woche verteilen.
     *
     * Das Budget streicht nach Wichtigkeit, nicht nach Lage. Übrig blieb
     * bei Jan ein lockerer Lauf am Mittwoch direkt nach dem Laufclub — und
     * danach Do (gesperrt), Fr und Sa ohne Lauf, obwohl Fr und Sa je
     * 120 Minuten frei waren. Ein lockerer Lauf, der an einem anderen Lauf
     * klebt, wandert deshalb in die Mitte der längsten Lücke, wenn er dort
     * hineinpasst. Umfang und Zahl der Läufe bleiben, nur der Tag ändert sich.
     *
     * @param  list<string>  $dates
     */
    private function spreadRuns(array &$days, array $dates): void
    {
        $hasRun = fn (string $d) => collect($days[$d]['slots'] ?? [])
            ->contains(fn ($slot) => in_array($slot['type'], self::RUN_SLOT_TYPES, true) || ! empty($slot['race']));

        // Die längste Lücke der Woche suchen.
        $gap = [];
        $run = [];
        foreach ($dates as $d) {
            if ($hasRun($d)) {
                $run = [];
                continue;
            }
            $run[] = $d;
            if (count($run) > count($gap)) {
                $gap = $run;
            }
        }

        if (count($gap) <= self::MAX_DAYS_WITHOUT_RUN) {
            return;
        }

        // Ziel: ein freier, verfügbarer Tag möglichst in der Mitte der Lücke.
        $middle     = (count($gap) - 1) / 2;
        $candidates = collect($gap)
            ->filter(fn ($d) => $days[$d]['available'] && empty($days[$d]['finalized']) && empty($days[$d]['fixed']))
            ->sortBy(fn ($d) => abs(array_search($d, $gap, true) - $middle))
            ->values();

        // Quelle: ein lockerer Lauf, der direkt neben einem anderen Lauf liegt.
        foreach ($dates as $source) {
            foreach ($days[$source]['slots'] ?? [] as $i => $slot) {
                if ($slot['type'] !== 'easy_run' || ! empty($slot['fixed']) || isset($slot['comeback'])
                    || ! empty($slot['double']) || ! empty($slot['bike'])) {
                    continue;
                }

                $prev = CarbonImmutable::parse($source)->subDay()->format('Y-m-d');
                $next = CarbonImmutable::parse($source)->addDay()->format('Y-m-d');
                if (! (isset($days[$prev]) && $hasRun($prev)) && ! (isset($days[$next]) && $hasRun($next))) {
                    continue;
                }

                $minutes = (int) ($slot['target_min'] ?? self::MIN_MINUTES_PER_TYPE['easy_run']);

                foreach ($candidates as $target) {
                    $budget = (int) $days[$target]['budget_min'];
                    if ($budget > 0 && $budget < $minutes) {
                        continue;
                    }

                    $slot['max_min'] = $budget;
                    unset($days[$source]['slots'][$i]);
                    $days[$source]['slots'] = array_values($days[$source]['slots']);

                    // Was am Quelltag übrig bleibt (Kraft, Mobility), bleibt
                    // dort; ohne Lauf wird er Ruhetag.
                    if ($days[$source]['slots'] === []) {
                        $days[$source]['rest'] = true;
                    }

                    unset($days[$target]['rest']);
                    array_unshift($days[$target]['slots'], $slot);

                    return;
                }
            }
        }
    }

    /**
     * Ab wie vielen Einheiten eine Woche eine zweite harte vertraegt.
     *
     * Seiler: rund 80 % der Einheiten locker. Der lange Lauf zaehlt dabei
     * nicht als hart, obwohl er beim Halbmarathon ein Renntempo-Finish hat —
     * mit zwei Qualitaetseinheiten daneben waere eine Vier-Lauf-Woche zu drei
     * Vierteln Belastung.
     */
    private const SECOND_HARD_FROM_SESSIONS = 5;

    private function hardSessionsFor(int $sessions): int
    {
        return $sessions >= self::SECOND_HARD_FROM_SESSIONS ? 2 : 1;
    }

    /**
     * Wie viele Minuten die offenen Einheiten mindestens brauchen — mit
     * $shortEasy die lockeren Läufe nur mit ihrer Untergrenze.
     */
    private function minutesNeeded(array $open, bool $shortEasy = false): float
    {
        $sum = 0.0;
        foreach ($open as $slot) {
            $min = $shortEasy && $slot['type'] === 'easy_run'
                ? self::MIN_USEFUL_RUN_MINUTES
                : $this->minMinutesFor($slot['type']);
            $sum += min($min, $slot['cap']);
        }

        return $sum;
    }

    private function minMinutesFor(string $type): int
    {
        return self::MIN_MINUTES_PER_TYPE[$type] ?? self::MIN_USEFUL_RUN_MINUTES;
    }

    /**
     * Welche der offenen Einheiten am ehesten entbehrlich ist.
     *
     * Die Wunschreihenfolge des Zieltyps gilt rückwärts. Feste Termine
     * stehen hier gar nicht erst zur Wahl — sie sind vorher aus der Liste
     * heraus.
     *
     * Eine Ausnahme: der letzte lockere Lauf geht nicht, solange noch eine
     * harte Einheit offen ist. Beim Halbmarathon steht `easy_run` hinten in
     * der Reihenfolge, und eine knappe Woche bestand dann aus langem Lauf,
     * Tempolauf und Intervallen — das Gegenteil von 80/20.
     *
     * @param  list<array{date:string,index:int,type:string,cap:int}>  $open
     */
    private function leastImportant(array $open, array $priority): ?int
    {
        $rank = array_flip($priority);
        $worst = null;
        $worstRank = -1;

        $types    = array_column($open, 'type');
        $hasHard  = array_intersect($types, self::HARD_TYPES) !== [];
        $lastEasy = count(array_keys($types, 'easy_run', true)) === 1;

        foreach ($open as $i => $slot) {
            if ($slot['type'] === 'easy_run' && $lastEasy && $hasHard) {
                continue;
            }

            // Bei Gleichstand geht die Abend-Hälfte einer Doppel-Schwelle
            // zuerst — der Morgen bleibt als Einzel-Schwelle stehen.
            $r = $rank[$slot['type']] ?? count($priority);
            if ($r > $worstRank || ($r === $worstRank && ! empty($slot['pm']))) {
                $worstRank = $r;
                $worst = $i;
            }
        }

        return $worst;
    }

    /** @return array<string,list<string>> Wochenschlüssel → Daten der Woche */
    private function groupByWeek(array $days): array
    {
        $weeks = [];
        foreach ($days as $key => $day) {
            $weeks[$day['week']][] = $key;
        }

        return $weeks;
    }

    /**
     * Eine Woche belegen. Die harten Einheiten zuerst — sie brauchen Abstand
     * zueinander und den meisten Platz, alles andere ordnet sich darum an.
     *
     * @return array{planned: list<string>, dropped: list<string>, usable_days: int}
     */
    private function planWeek(array &$days, array $dates, array $priority, int $maxHard): array
    {
        $usable = array_values(array_filter(
            $dates,
            fn ($d) => $days[$d]['available']
                && ! $days[$d]['finalized']
                && $this->canFitUsefulRun($days[$d])
        ));

        if (! $usable) {
            return ['planned' => [], 'dropped' => $priority, 'usable_days' => 0];
        }

        // Mindestens ein Ruhetag pro Woche, sobald die Woche vollständig im
        // Fenster liegt — sonst plant das Gerüst den Athleten zu.
        $capacity = count($dates) >= 7 ? max(1, count($usable) - 1) : count($usable);

        $dropped = [];

        // 80/20 zaehlt in Einheiten. Bei bis zu vier Laeufen sind zwei harte
        // schon die Haelfte der Woche — dann bleibt es bei einer, und der
        // freie Platz geht an einen lockeren Lauf statt ans Intervall.
        $maxHard = min($maxHard, $this->hardSessionsFor($capacity));

        [$planned, $hard] = $this->placeFixed($days, $usable);

        foreach ($priority as $type) {
            // Schon durch einen festen Termin abgedeckt.
            if (in_array($type, $planned, true)) {
                continue;
            }

            if (count($planned) >= $capacity) {
                $dropped[] = $type;
                continue;
            }

            $isHard = in_array($type, self::HARD_TYPES, true);
            if ($isHard && $hard >= $maxHard) {
                $dropped[] = $type;
                continue;
            }

            $date = $this->pickDay($days, $usable, $type, $isHard);
            if ($date === null) {
                $dropped[] = $type;
                continue;
            }

            $days[$date]['slots'][] = [
                'type'    => $type,
                'hard'    => $isHard,
                'max_min' => $days[$date]['budget_min'],
            ];

            $planned[] = $type;
            if ($isHard) $hard++;
        }

        $this->fillSecondSlots($days, $usable);

        return ['planned' => $planned, 'dropped' => $dropped, 'usable_days' => count($usable)];
    }

    /**
     * Was schon steht, zählen — und die festen Termine legen.
     *
     * @return array{0: list<string>, 1: int}  geplante Typen, harte Einheiten
     */
    private function placeFixed(array &$days, array $usable): array
    {
        $planned = [];
        $hard    = 0;

        // Was an diesem Tag schon steht, zaehlt mit. Nach einem Wiedereinstieg
        // uebernimmt diese Methode eine angefangene Woche — ohne die Zaehlung
        // legte sie dieselbe Einheit ein zweites Mal daneben.
        foreach ($usable as $date) {
            foreach ($days[$date]['slots'] as $slot) {
                if (! empty($slot['optional'])) continue;

                $planned[] = $slot['type'];
                if ($slot['hard']) $hard++;
            }
        }

        // Feste Termine zuerst. Sie zaehlen als erfuellt — steht der
        // Laufclub am Dienstag, wird das Wochenintervall nicht ein zweites
        // Mal auf einen anderen Tag gelegt.
        foreach ($usable as $date) {
            $fixed = $days[$date]['fixed'] ?? null;
            if (! $fixed) continue;

            // Der Tag ist schon versorgt — der Termin steht bereits darin.
            if ($days[$date]['slots']) continue;

            // Ein Rennen ist immer eine harte Einheit — auch ein langes.
            // Ohne das legte die Woche neben zwei Rennen noch eine
            // Qualitaetseinheit.
            $isHard = ! empty($fixed['race']) || in_array($fixed['type'], self::HARD_TYPES, true);

            $slot = [
                'type'    => $fixed['type'],
                'hard'    => $isHard,
                'max_min' => $days[$date]['budget_min'],
                'fixed'   => true,
                'label'   => $fixed['label'],
            ];

            if (! empty($fixed['race'])) {
                $slot['race'] = $fixed['race'];
            }

            $days[$date]['slots'][] = $slot;

            $planned[] = $fixed['type'];

            // Ein langes Rennen IST der lange Lauf der Woche.
            if (! empty($fixed['substitutes'])) {
                $planned[] = $fixed['substitutes'];
            }

            if ($isHard) $hard++;
        }

        return [$planned, $hard];
    }

    /**
     * Eine Woche im norwegischen Modell (Entscheidung vom 25.10.2026).
     *
     *   · zwei Tage Doppel-Schwelle: morgens und abends je eine Einheit
     *     knapp unter der Schwelle (z. B. 5×6 min und 10×1000 m)
     *   · eine kurze schnelle Einheit, bevorzugt am Wochenende
     *   · ein lockerer langer Lauf, alles andere locker
     *
     * Doppelt wird nur an einem Tag mit Abendfenster
     * (`weekly_availability.*.second_min`). Fehlt es, bleibt eine
     * Einzel-Schwelle im Tagesfenster — besser als zwei Einheiten in eine
     * Mittagspause gepresst.
     *
     * Die Phase gilt auch hier: in der Grundlage und in einer
     * Entlastungswoche ein Schwellentag und keine schnelle Einheit, in der
     * Rennwoche nur eine kurze Einzel-Schwelle.
     *
     * Die Sub-Schwellen-Einheiten tragen `controlled`: sie zählen für den
     * Abstand wie harte Einheiten (nie zwei Schwellentage hintereinander),
     * verlangen aber keinen Ruhetag danach — der Tag nach einer
     * Doppel-Schwelle ist ein lockerer Lauf, so wie das Modell gedacht ist.
     *
     * @return array{planned: list<string>, dropped: list<string>, usable_days: int}
     */
    private function planNorwegianWeek(array &$days, array $dates, ?array $period): array
    {
        $usable = array_values(array_filter(
            $dates,
            fn ($d) => $days[$d]['available']
                && ! $days[$d]['finalized']
                && $this->canFitUsefulRun($days[$d])
        ));

        if (! $usable) {
            return ['planned' => [], 'dropped' => [], 'usable_days' => 0];
        }

        $capacity = count($dates) >= 7 ? max(1, count($usable) - 1) : count($usable);
        $dropped  = [];

        [$planned, $hard] = $this->placeFixed($days, $usable);

        $phase    = $period['phase'] ?? 'build';
        $recovery = (bool) ($period['recovery'] ?? false);

        $thresholdDays = match (true) {
            $phase === 'race_week'           => 0,
            $recovery || $phase === 'base'   => 1,
            default                          => 2,
        };

        // Ein fester harter Termin (Laufclub-Intervalle, ein Rennen) ist der
        // schnelle Reiz der Woche — eine zweite schnelle Einheit kommt nicht
        // dazu.
        $fast = in_array($phase, ['build', 'peak', 'taper'], true) && ! $recovery && $hard === 0;

        // Der lange Lauf zuerst: er braucht den längsten Tag.
        if (! in_array('long_run', $planned, true) && count($planned) < $capacity) {
            $date = $this->pickDay($days, $usable, 'long_run', false);
            if ($date !== null) {
                $days[$date]['slots'][] = ['type' => 'long_run', 'hard' => false, 'max_min' => $days[$date]['budget_min']];
                $planned[] = 'long_run';
            } else {
                $dropped[] = 'long_run';
            }
        }

        for ($n = 0; $n < $thresholdDays; $n++) {
            if (count($planned) >= $capacity) {
                $dropped[] = 'tempo_run';
                continue;
            }

            $date = $this->pickThresholdDay($days, $usable);
            if ($date === null) {
                $dropped[] = 'tempo_run';
                continue;
            }

            $second = (int) $days[$date]['second_min'];
            $am     = ['type' => 'tempo_run', 'hard' => true, 'controlled' => true, 'max_min' => $days[$date]['budget_min']];

            if ($second > 0) {
                $days[$date]['slots'][] = $am + ['double' => 'am'];
                $days[$date]['slots'][] = ['type' => 'tempo_run', 'hard' => true, 'controlled' => true, 'max_min' => $second, 'double' => 'pm'];
            } else {
                $days[$date]['slots'][] = $am;
            }

            $planned[] = 'tempo_run';
        }

        // Rennwoche: ein kurzer kontrollierter Reiz, keine Doppel-Schwelle.
        if ($phase === 'race_week' && count($planned) < $capacity && ! in_array('tempo_run', $planned, true)) {
            $date = $this->pickDay($days, $usable, 'tempo_run', true);
            if ($date !== null) {
                $days[$date]['slots'][] = ['type' => 'tempo_run', 'hard' => true, 'controlled' => true, 'max_min' => $days[$date]['budget_min']];
                $planned[] = 'tempo_run';
            }
        }

        if ($fast) {
            $date = count($planned) < $capacity ? $this->pickFastDay($days, $usable) : null;
            if ($date !== null) {
                $days[$date]['slots'][] = ['type' => 'interval', 'hard' => true, 'fast' => true, 'max_min' => $days[$date]['budget_min']];
                $planned[] = 'interval';
            } else {
                $dropped[] = 'interval';
            }
        }

        $this->fillSecondSlots($days, $usable);

        return ['planned' => $planned, 'dropped' => $dropped, 'usable_days' => count($usable)];
    }

    /**
     * Ein Tag für die Schwelle: mit Abstand zur nächsten harten Einheit,
     * bevorzugt mit Abendfenster, dann der längste.
     */
    private function pickThresholdDay(array $days, array $usable): ?string
    {
        $free = array_values(array_filter(
            $usable,
            fn ($d) => empty($days[$d]['slots']) && $this->hasQualityGap($days, $usable, $d),
        ));

        if (! $free) {
            return null;
        }

        usort($free, fn ($a, $b) => [$days[$b]['second_min'] > 0, $days[$b]['budget_min']]
            <=> [$days[$a]['second_min'] > 0, $days[$a]['budget_min']]);

        return $free[0];
    }

    /** Die schnelle Einheit: bevorzugt Samstag oder Sonntag, sonst irgendein Tag mit Abstand. */
    private function pickFastDay(array $days, array $usable): ?string
    {
        $free = array_values(array_filter(
            $usable,
            fn ($d) => empty($days[$d]['slots']) && $this->hasQualityGap($days, $usable, $d),
        ));

        if (! $free) {
            return null;
        }

        usort($free, fn ($a, $b) => [$days[$b]['weekday'] >= 6, $days[$b]['budget_min']]
            <=> [$days[$a]['weekday'] >= 6, $days[$a]['budget_min']]);

        return $free[0];
    }

    /** Ein Doppel-Tag mit nur noch einer Hälfte ist eine Einzel-Schwelle. */
    private function normalizeDoubles(array &$days): void
    {
        foreach ($days as $date => $day) {
            $halves = array_keys(array_filter($day['slots'] ?? [], fn ($s) => ! empty($s['double'])));

            if (count($halves) === 1) {
                unset($days[$date]['slots'][$halves[0]]['double']);
            }
        }
    }

    /**
     * Die Anteile des norwegischen Modells: höchstens 25 % der Laufzeit
     * Sub-Schwelle, höchstens 5 % schneller als die Schwelle.
     *
     * Wie bei 80/20 bekommt jede Einheit ihren Hauptteil als `hard_min`.
     * Reicht der Anteil nicht, entfällt zuerst die Abend-Hälfte eines
     * Doppel-Tags (der Morgen bleibt als Einzel-Schwelle), danach wird die
     * letzte Schwelleneinheit locker. Die schnelle Einheit wird locker, wenn
     * ihre 5 % keine fünf Minuten tragen.
     *
     * Der lange Lauf ist hier locker und zählt nicht; Rennen zählen nie.
     */
    private function applyNorwegianShares(array &$days): void
    {
        foreach ($this->groupByWeek($days) as $dates) {
            $total      = 0.0;
            $takenSub   = 0.0;
            $takenFast  = 0.0;
            $sub        = [];
            $fast       = [];
            $incomplete = false;

            foreach ($dates as $date) {
                foreach ($days[$date]['slots'] ?? [] as $i => $slot) {
                    if (! empty($slot['race']) || ! in_array($slot['type'], self::RUN_SLOT_TYPES, true)) {
                        continue;
                    }

                    $min    = (int) ($slot['target_min'] ?? 0);
                    $total += $min;

                    if (! in_array($slot['type'], self::HARD_TYPES, true)) {
                        continue;
                    }

                    if (! empty($slot['fixed'])) {
                        // Ein auswärtiger Termin ist meist schnell (Bahn,
                        // Intervalle), eine Schwelle nur, wenn er so heisst.
                        if ($slot['type'] === 'tempo_run') {
                            $takenSub += self::SUB_MAIN_MINUTES['single'];
                        } else {
                            $takenFast += self::HARD_MAIN_MINUTES[$slot['type']] ?? 20;
                        }
                        continue;
                    }

                    if ($min <= 0) {
                        $incomplete = true;
                    }

                    if (! empty($slot['fast'])) {
                        $want   = $min > 0 ? min(self::FAST_MAIN_MINUTES, max(0, $min - self::WARMUP_COOLDOWN_MINUTES)) : self::FAST_MAIN_MINUTES;
                        $fast[] = ['date' => $date, 'index' => $i, 'want' => $want];
                        continue;
                    }

                    $part  = $slot['double'] ?? 'single';
                    $want  = self::SUB_MAIN_MINUTES[$part];
                    if ($min > 0) {
                        $want = min($want, max(0, $min - self::SUB_WARMUP_MINUTES));
                    }
                    $sub[] = ['date' => $date, 'index' => $i, 'part' => $part, 'want' => $want];
                }
            }

            // ── Sub-Schwelle ────────────────────────────────────────────
            $cap    = $incomplete ? INF : self::SUB_THRESHOLD_SHARE * $total - $takenSub;
            $remove = [];

            while ($sub && array_sum(array_column($sub, 'want')) > $cap
                && $cap / count($sub) < self::MIN_SUB_MAIN_MINUTES) {
                $pm = array_key_last(array_filter($sub, fn ($o) => $o['part'] === 'pm'));

                if ($pm !== null) {
                    // Der Abend fällt weg, der Morgen bleibt als Einzel-Schwelle.
                    $o        = $sub[$pm];
                    $remove[] = $o;
                    foreach ($sub as $k => $other) {
                        if ($other['date'] === $o['date'] && $other['part'] === 'am') {
                            $sub[$k]['part'] = 'single';
                            unset($days[$o['date']]['slots'][$other['index']]['double']);
                        }
                    }
                    array_splice($sub, $pm, 1);
                    continue;
                }

                $o = array_pop($sub);
                $days[$o['date']]['slots'][$o['index']]['type']      = 'easy_run';
                $days[$o['date']]['slots'][$o['index']]['hard']      = false;
                $days[$o['date']]['slots'][$o['index']]['norwegian'] = 'tempo_run';
                unset($days[$o['date']]['slots'][$o['index']]['controlled'], $days[$o['date']]['slots'][$o['index']]['double']);
            }

            $wanted = array_sum(array_column($sub, 'want'));
            $scale  = $wanted > $cap && $wanted > 0 ? $cap / $wanted : 1.0;

            foreach ($sub as $o) {
                $days[$o['date']]['slots'][$o['index']]['hard_min'] = (int) max(
                    min(self::MIN_SUB_MAIN_MINUTES, $o['want']),
                    floor($o['want'] * $scale),
                );
            }

            foreach ($remove as $o) {
                unset($days[$o['date']]['slots'][$o['index']]);
                $days[$o['date']]['slots'] = array_values($days[$o['date']]['slots']);
            }

            // ── Schnell ─────────────────────────────────────────────────
            $fastCap = $incomplete ? INF : self::FAST_SHARE * $total - $takenFast;

            foreach ($fast as $o) {
                if ($fastCap < self::MIN_FAST_MAIN_MINUTES) {
                    $days[$o['date']]['slots'][$o['index']]['type']      = 'easy_run';
                    $days[$o['date']]['slots'][$o['index']]['hard']      = false;
                    $days[$o['date']]['slots'][$o['index']]['norwegian'] = 'interval';
                    unset($days[$o['date']]['slots'][$o['index']]['fast']);
                    continue;
                }

                $minutes = (int) min($o['want'], floor($fastCap));
                $days[$o['date']]['slots'][$o['index']]['hard_min'] = $minutes;
                $fastCap -= $minutes;
            }
        }
    }

    /**
     * Eine Woche im Wiedereinstieg. Hier gibt nicht das Ziel den Takt vor,
     * sondern die Stufenleiter aus {@see ReturnToRunService::STEPS}: locker,
     * kurz, und mit jeder absolvierten Einheit ein Stück mehr.
     *
     * Die Stufe zählt Einheiten, keine Tage — deshalb wandert sie als
     * Referenz durch alle Wochen des Fensters.
     */
    private function planComebackWeek(array &$days, array $dates, int &$step, ?string &$lastRun, array $priority, int $maxHard): array
    {
        $usable = array_values(array_filter(
            $dates,
            fn ($d) => $days[$d]['available']
                && ! $days[$d]['finalized']
                && $this->canFitUsefulRun($days[$d])
        ));

        if (! $usable) {
            return ['planned' => [], 'dropped' => [], 'usable_days' => 0];
        }

        $capacity = count($dates) >= 7 ? max(1, count($usable) - 1) : count($usable);
        $planned  = [];

        foreach ($usable as $date) {
            if (count($planned) >= $capacity) {
                break;
            }

            // In den ersten beiden Stufen liegt zwischen zwei Läufen ein
            // Ruhetag — das ist der Sinn eines vorsichtigen Wiedereinstiegs.
            // Folgt morgen ein fester Termin, bleibt heute frei: der Termin
            // nimmt die Stufe auf (entschärft), statt dass der Ruhetag nach
            // dem ersten Lauf ihn überschreibt. So verschwand Jans Laufclub
            // am Dienstag hinter einem Pflicht-Ruhetag.
            $tomorrow = CarbonImmutable::parse($date)->addDay()->format('Y-m-d');
            if ($step <= 2 && empty($days[$date]['fixed']) && ! empty($days[$tomorrow]['fixed'])
                && in_array($tomorrow, $usable, true)) {
                $days[$date]['rest'] = true;
                continue;
            }

            if ($lastRun !== null && $step <= 2
                && CarbonImmutable::parse($lastRun)->diffInDays(CarbonImmutable::parse($date)) < 2) {
                // Der Ruhetag zwischen den ersten beiden Comeback-Läufen
                // ist medizinisch begründet. Er darf später weder von
                // assignFreeDays noch vom Normalplan aufgefüllt werden.
                $days[$date]['rest'] = true;
                continue;
            }

            $ladder = ReturnToRunService::STEPS[min($step, ReturnToRunService::TOTAL_STEPS)];
            $fixed  = $days[$date]['fixed'] ?? null;
            $budget = $days[$date]['budget_min'];

            // Ein fester Termin (Laufclub) bleibt stehen — der Athlet geht
            // ohnehin hin. Sein Inhalt wird nur entschärft, solange die
            // Leiter noch keine harte Einheit erlaubt.
            // Ein Rennen behaelt seinen Typ auch im Wiedereinstieg. Die
            // Leiter kann eine Einheit entschaerfen, aber kein Rennen — der
            // Athlet startet oder er startet nicht, und das entscheidet er.
            $type = $fixed
                ? (empty($fixed['race'])
                    && in_array($fixed['type'], self::HARD_TYPES, true)
                    && $step < ReturnToRunService::TOTAL_STEPS
                        ? ($ladder['type'] ?? 'easy_run')
                        : $fixed['type'])
                : ($ladder['type'] ?? 'easy_run');

            $cap = $ladder['max_min'] ?? null;
            $max = $cap === null ? $budget : ($budget > 0 ? min($budget, $cap) : $cap);

            $slot = [
                'type'     => $type,
                'hard'     => in_array($type, self::HARD_TYPES, true),
                'max_min'  => $max,
                'comeback' => $step,
            ];
            if ($fixed) {
                $slot['fixed'] = true;
                $slot['label'] = $fixed['label'];

                if (! empty($fixed['race'])) {
                    $slot['race'] = $fixed['race'];
                    $slot['hard'] = true;
                }
            }

            $days[$date]['slots'][] = $slot;

            $planned[] = $type;

            if (! empty($fixed['substitutes'])) {
                $planned[] = $fixed['substitutes'];
            }
            $lastRun   = $date;
            $step++;

            // Leiter durch: ab hier gilt wieder der Normalbetrieb.
            if ($step >= ReturnToRunService::TOTAL_STEPS) {
                break;
            }
        }

        $this->fillSecondSlots($days, $usable);

        // Endet die Leiter mitten in der Woche, wird der Rest der Woche
        // normal geplant. Sonst stünde nach dem Wiedereinstieg eine halbe
        // Woche ohne jede Vorgabe da — und das Modell füllte sie nach
        // eigenem Ermessen.
        if ($step >= ReturnToRunService::TOTAL_STEPS) {
            // Die Comeback-Tage sind abgeschlossen: Der Normalplan darf nur
            // die RESTLICHEN Tage füllen. Mit der ganzen Woche legte er
            // rückwirkend z.B. ein Intervall auf den bewussten Ruhetag
            // zwischen Einheit 1 und 2.
            $remaining = $lastRun === null
                ? []
                : array_values(array_filter($dates, fn ($date) => $date > $lastRun));

            if ($remaining !== []) {
                $normal = $this->planWeek($days, $remaining, $priority, $maxHard);
                $planned = [...$planned, ...$normal['planned']];
            }
        }

        return ['planned' => $planned, 'dropped' => [], 'usable_days' => count($usable)];
    }

    /**
     * Den passenden Tag wählen: lange und harte Einheiten bekommen den Tag
     * mit dem größten Zeitbudget, harte zusätzlich Abstand zueinander.
     */
    private function pickDay(array $days, array $usable, string $type, bool $isHard): ?string
    {
        $free = array_values(array_filter($usable, fn ($d) => empty($days[$d]['slots'])));
        if (! $free) {
            return null;
        }

        if ($isHard) {
            // Kein Tag mit Abstand mehr frei? Dann entfällt die Einheit.
            // Vorher wurde sie „lieber eng als gar nicht" trotzdem gelegt —
            // in einer angebrochenen Woche standen so Tempolauf und Intervall
            // an zwei aufeinanderfolgenden Tagen.
            $free = array_values(array_filter(
                $free,
                fn ($d) => $this->hasQualityGap($days, $usable, $d)
            ));
            if (! $free) {
                return null;
            }
        }

        // Ein langer Lauf braucht ein Zeitbudget, das ihn trägt.
        if ($type === 'long_run') {
            $long = array_values(array_filter(
                $free,
                fn ($d) => $days[$d]['budget_min'] === 0 || $days[$d]['budget_min'] >= self::MIN_LONG_RUN_MINUTES
            ));
            if (! $long) {
                return null;
            }
            $free = $long;
        }

        if ($type === 'long_run' || $isHard) {
            usort($free, fn ($a, $b) => $days[$b]['budget_min'] <=> $days[$a]['budget_min']);
            return $free[0];
        }

        // Lockere Einheiten dürfen auf den kleinsten passenden Tag.
        usort($free, fn ($a, $b) => $days[$a]['budget_min'] <=> $days[$b]['budget_min']);

        return $free[0];
    }

    private function hasQualityGap(array $days, array $usable, string $candidate): bool
    {
        foreach ($usable as $other) {
            if ($other === $candidate) continue;

            $hasHard = collect($days[$other]['slots'])->contains(fn ($s) => $s['hard']);
            if (! $hasHard) continue;

            $gap = abs(CarbonImmutable::parse($candidate)->diffInDays(CarbonImmutable::parse($other)));
            if ($gap < self::QUALITY_GAP_DAYS) {
                return false;
            }
        }

        return true;
    }

    /**
     * Die freien Tage einer Woche festlegen: Ruhetag oder lockerer Lauf.
     *
     * Das war bis hierher offen. Das Gerüst schrieb für einen freien Tag
     * `type="rest" ODER lockere Einheit` in den Prompt und überließ die
     * Entscheidung dem Modell — bei jedem Durchlauf neu. Der Athlet sah
     * am Montag zwei Ruhetage in seiner Woche und am Dienstag keinen mehr,
     * ohne dass sich irgendetwas an seiner Lage geändert hätte. Ein
     * Sprachmodell ist nicht deterministisch; wer es zweimal fragt, bekommt
     * zweimal etwas anderes.
     *
     * Die Regel ist bewusst schlicht und nachvollziehbar:
     *   · Der Tag nach einer harten Einheit ist Erholung — Ruhetag.
     *   · Jede Woche hat mindestens einen Ruhetag. Fällt keiner an, wird
     *     der freie Tag mit dem kleinsten Zeitbudget dazu erklärt.
     *   · Alles Übrige wird ein lockerer Lauf in Zone 2.
     *
     * @param  list<string>  $dates  Die Tage dieser Woche
     */
    private function assignFreeDays(array &$days, array $dates): void
    {
        // Verfügbarkeit unter 20 Minuten ist kein Trainingsfenster. Ohne
        // diese Festlegung schrieb der Prompt „frei" und das Modell erfand
        // daraus Mini-Läufe wie „8 Minuten Zone 2".
        foreach ($dates as $date) {
            if ($days[$date]['available']
                && ! $days[$date]['finalized']
                && empty($days[$date]['slots'])
                && ! $this->canFitUsefulRun($days[$date])) {
                $days[$date]['rest'] = true;
            }
        }

        $free = array_values(array_filter(
            $dates,
            fn ($d) => $days[$d]['available']
                && ! $days[$d]['finalized']
                && empty($days[$d]['slots'])
                && empty($days[$d]['rest'])
                && $this->canFitUsefulRun($days[$d]),
        ));

        if ($free === []) {
            return;
        }

        $restDates = [];

        foreach ($free as $date) {
            $previous = CarbonImmutable::parse($date)->subDay()->format('Y-m-d');

            // Der Vortag kann außerhalb des Fensters liegen — dann ist über
            // ihn nichts bekannt und der Tag bleibt ein lockerer Lauf.
            // Kontrollierte Sub-Schwelle (norwegisches Modell) verlangt keinen
            // Ruhetag danach — dort folgt ein lockerer Lauf.
            $afterHard = isset($days[$previous]) && collect($days[$previous]['slots'] ?? [])
                ->contains(fn ($slot) => ($slot['hard'] ?? false) && empty($slot['controlled']));

            if ($afterHard) {
                $restDates[$date] = true;
            }
        }

        // Mindestens ein echter Ruhetag pro Woche.
        if ($restDates === []) {
            $lowest = $free[0];
            foreach ($free as $date) {
                if ($days[$date]['budget_min'] > 0
                    && ($days[$lowest]['budget_min'] === 0 || $days[$date]['budget_min'] < $days[$lowest]['budget_min'])) {
                    $lowest = $date;
                }
            }
            $restDates[$lowest] = true;
        }

        foreach ($free as $date) {
            if (isset($restDates[$date])) {
                $days[$date]['rest'] = true;
                continue;
            }

            $days[$date]['slots'][] = [
                'type'    => 'easy_run',
                'hard'    => false,
                'max_min' => $days[$date]['budget_min'],
                'filler'  => true,
            ];
        }
    }

    /**
     * Kraft so oft, wie der Athlet sie eingestellt hat — als feste Einheit.
     *
     * `strength_days_per_week` wurde bis zum 30.10.2026 nirgends gelesen.
     * Kraft entstand nur als optionale Zweiteinheit an einem Lauftag mit
     * Restzeit, nie an einem freien Tag, und das Modell durfte sie weglassen:
     * Jan wollte zweimal pro Woche und bekam in 14 Tagen eine, die dann fehlte.
     *
     * Reihenfolge (Entscheidung von Jan): zuerst freie verfügbare Tage, dann
     * neben einen lockeren Lauf, zuletzt hinter eine harte Einheit — harte
     * Tage hart. Nie am Tag vor dem langen Lauf oder einer harten Einheit,
     * nie an einem Renntag oder in den drei Tagen davor, nicht in der
     * Rennwoche und nicht in den ersten beiden Stufen eines Wiedereinstiegs.
     * Zwei Krafttage liegen nicht direkt nebeneinander, solange es anders geht.
     */
    private function applyStrength(array &$days, int $perWeek, array $periods): void
    {
        $perWeek = max(1, min(4, $perWeek));
        $dates   = array_keys($days);

        $hasRun = fn (array $day) => collect($day['slots'])
            ->contains(fn ($s) => in_array($s['type'], self::RUN_SLOT_TYPES, true) || ! empty($s['bike']) || ! empty($s['race']));
        $isStrength = fn (array $slot) => in_array($slot['type'], ['strength', 'core'], true);

        // Renntage im Fenster — auch die eines Nebenrennens.
        $raceDates = [];
        foreach ($days as $date => $day) {
            if (collect($day['slots'])->contains(fn ($s) => ! empty($s['race']) || $s['type'] === 'race_prep')) {
                $raceDates[] = $date;
            }
        }

        foreach ($this->groupByWeek($days) as $weekKey => $weekDates) {
            $phase = $periods[$weekKey]['phase'] ?? null;

            // Die optionalen Kraft-Ergänzungen aus fillSecondSlots weichen
            // der festen Zahl; Mobility neben harten Tagen bleibt.
            foreach ($weekDates as $d) {
                $days[$d]['slots'] = array_values(array_filter(
                    $days[$d]['slots'],
                    fn ($s) => ! ($isStrength($s) && ! empty($s['optional'])),
                ));
            }

            if ($phase === 'race_week') {
                continue;
            }
            if (collect($weekDates)->contains(fn ($d) => collect($days[$d]['slots'])->contains(fn ($s) => ($s['comeback'] ?? 99) < 3))) {
                continue;
            }

            $tiers = [[], [], []];
            foreach ($weekDates as $d) {
                $day = $days[$d];
                if (! $day['available'] || $day['finalized'] || ! empty($day['kept'])) {
                    continue;
                }

                $tomorrow = CarbonImmutable::parse($d)->addDay()->format('Y-m-d');
                $next     = $days[$tomorrow]['slots'] ?? [];
                if (collect($next)->contains(fn ($s) => $s['type'] === 'long_run' || ! empty($s['hard']))) {
                    continue;
                }
                if (collect($raceDates)->contains(fn ($r) => $r >= $d && CarbonImmutable::parse($d)->diffInDays(CarbonImmutable::parse($r)) <= 3)) {
                    continue;
                }

                $budget = $day['budget_min'];

                if (! $hasRun($day)) {
                    // Ein Tag ohne Zeitangabe bekommt eine Einheit normaler Länge.
                    $room = $budget > 0 ? $budget : self::STRENGTH_MAX_MINUTES;
                    if ($room >= self::SECOND_SLOT_MIN_MINUTES) {
                        $tiers[0][$d] = min($room, self::STRENGTH_MAX_MINUTES);
                    }
                    continue;
                }

                // Eine optionale Mobility-Ergänzung weicht der Kraft.
                $primary = array_values(array_filter($day['slots'], fn ($s) => empty($s['optional'])));
                if (count($primary) !== 1 || $budget <= 0) {
                    continue;
                }

                $slot = $primary[0];
                if ($slot['type'] === 'long_run' || ! empty($slot['race']) || ! empty($slot['double'])) {
                    continue;
                }

                $used = (int) ($slot['target_min'] ?? $this->estimateMinutes($slot['type'], $budget));
                $room = $budget - $used;
                if ($room < self::SECOND_SLOT_MIN_MINUTES) {
                    continue;
                }

                $tiers[! empty($slot['hard']) ? 2 : 1][$d] = min($room, self::SECOND_SLOT_MAX_MINUTES);
            }

            $chosen = [];
            foreach ([true, false] as $spaced) {
                foreach ($tiers as $tier) {
                    foreach ($tier as $d => $minutes) {
                        if (count($chosen) >= $perWeek || isset($chosen[$d])) {
                            continue;
                        }

                        $i = array_search($d, $dates, true);
                        $neighbours = [$dates[$i - 1] ?? null, $dates[$i + 1] ?? null];
                        if ($spaced && array_intersect($neighbours, array_keys($chosen))) {
                            continue;
                        }

                        $chosen[$d] = $minutes;
                    }
                }
            }

            foreach ($chosen as $d => $minutes) {
                unset($days[$d]['rest']);
                $days[$d]['slots']   = array_values(array_filter($days[$d]['slots'], fn ($s) => empty($s['optional'])));
                $days[$d]['slots'][] = [
                    'type'    => 'strength',
                    'hard'    => false,
                    'max_min' => $minutes,
                ];
            }
        }
    }

    /**
     * Zweite Einheit an Tagen, an denen nach der Pflichteinheit noch Zeit
     * übrig ist. Bewusst an die Restzeit gekoppelt und nicht an eine feste
     * Schwelle — wer 75 Minuten hat und 45 verbraucht, kann 30 Minuten Core
     * dranhängen.
     */
    private function fillSecondSlots(array &$days, array $usable): void
    {
        foreach ($usable as $date) {
            $slots = $days[$date]['slots'];
            if (count($slots) !== 1) {
                continue;
            }

            $primary = $slots[0];

            // Auf den ersten beiden Stufen eines Wiedereinstiegs bleibt es bei
            // der einen lockeren Einheit. Wer gerade 30 Minuten laufen darf,
            // braucht keine Krafteinheit daneben.
            if (($primary['comeback'] ?? 99) < 3) {
                continue;
            }
            $rest    = $days[$date]['budget_min'] - $this->estimateMinutes($primary['type'], $days[$date]['budget_min']);

            if ($rest < self::SECOND_SLOT_MIN_MINUTES) {
                continue;
            }

            $days[$date]['slots'][] = [
                'type'    => $primary['hard'] ? 'mobility' : 'strength',
                'hard'    => false,
                'max_min' => min($rest, self::SECOND_SLOT_MAX_MINUTES),
                'optional'=> true,
            ];
        }
    }

    /** Grobe Dauer einer Einheit — nur um die Restzeit abzuschätzen. */
    private function estimateMinutes(string $type, int $budget): int
    {
        return match ($type) {
            'long_run'  => $budget,
            'interval', 'tempo_run' => min($budget, 60),
            default     => min($budget, 45),
        };
    }

    /**
     * Eine Verfügbarkeit ist eine Obergrenze, kein Auftrag, jede Minute zu
     * füllen. Reichen weniger als 20 Minuten, bleibt der Tag Ruhetag statt
     * dass das Modell einen 8-Minuten-"Mini-Reset" erfindet.
     */
    private function canFitUsefulRun(array $day): bool
    {
        $budget = (int) ($day['budget_min'] ?? 0);

        return $budget === 0 || $budget >= self::MIN_USEFUL_RUN_MINUTES;
    }

    /** Intervall vor Schwelle, die übrige Reihenfolge bleibt. */
    private function intervalFirst(array $priority): array
    {
        $t = array_search('tempo_run', $priority, true);
        $i = array_search('interval', $priority, true);

        if ($t !== false && $i !== false && $i > $t) {
            [$priority[$t], $priority[$i]] = ['interval', 'tempo_run'];
        }

        return $priority;
    }

    /** Die Regeln des norwegischen Modells — ersetzen 80/20 und „ein Lauf pro Tag". */
    private function norwegianRules(array $model): string
    {
        $pace = ! empty($model['sub_pace'])
            ? "{$model['sub_pace']} min/km (Schwelle T = {$model['threshold']}, Sub-Schwelle = T + 5–15 s)"
            : 'T + 5–15 s/km';
        $hr   = ! empty($model['hr_max'])
            ? " und Puls hoechstens {$model['hr_max']} bpm (knapp unter der Schwellen-HF)"
            : '';

        return "- NORWEGISCHES MODELL (vom Athleten gewaehlt): Statt 80/20 gilt — hoechstens 25 % der Laufminuten Sub-Schwelle, hoechstens 5 % schneller als die Schwelle, alles andere locker (Zone 1–2). Einlaufen, Trabpausen und Auslaufen zaehlen als locker.\n"
            . "- SUB-SCHWELLE heisst kontrolliert knapp UNTER der Schwelle: Pace {$pace}{$hr}. Der Athlet hat kein Laktatmessgeraet — die Pace steht im Ablauf; nenne in der description den Pulsdeckel und sage, dass er langsamer wird, wenn der Puls darueber steigt. Die Einheit soll sich am Ende so anfuehlen, als ginge noch mehr; nie bis zur Erschoepfung.\n"
            . "- Die Minuten des Hauptteils (hard_min) stehen im Geruest; das System baut den Ablauf danach.\n"
            . "- DOPPEL-SCHWELLE: An Tagen mit \"part=am\" und \"part=pm\" stehen ZWEI Laufeinheiten — morgens und abends, beide type=\"tempo_run\". Gib jeder das Feld \"part\" mit (\"am\" bzw. \"pm\") und beginne den title mit \"Morgens:\" bzw. \"Abends:\". Die Abschnitte kommen aus dem Baustein (workout).\n"
            . "- Außer an Doppel-Schwellen-Tagen HÖCHSTENS EINE LAUFEINHEIT PRO TAG. Ein Tag bekommt genau so viele Einträge, wie oben Slots stehen — nie mehr. Ein weiterer Eintrag darf nur strength, core oder mobility sein.\n"
            . "- Der lange Lauf bleibt komplett locker — kein Renntempo-Abschnitt.\n";
    }

    /** „ — workout: \"a\" (…) | \"b\" (…)" fuer den Prompt. */
    private function workoutChoices(array $slot, string $date, array $skeleton): string
    {
        // Nur was in der Phase passt, einen Hang hat (sofern noetig) und in
        // den letzten drei Wochen nicht lief. Innerhalb des Fensters setzt
        // der Validator die Rotation durch.
        $variants = \App\Services\Run\RunWorkouts::fresh(
            \App\Services\Run\RunWorkouts::variantsFor($slot + ['hills' => $skeleton['hills'] ?? true]),
            $skeleton['workout_history'] ?? [],
            $date,
        );

        if ($variants === []) {
            return '';
        }

        // Die Beschreibung steht einmal in der Legende unter dem Gerüst,
        // nicht an jedem Tag — sie machte vorher die Hälfte des Prompts aus.
        foreach ($variants as $v) {
            $this->legend[$v] = \App\Services\Run\RunWorkouts::DESCRIPTIONS[$v] ?? $v;
        }

        $list = array_map(fn ($v) => "\"{$v}\"", $variants);

        return count($list) === 1
            ? " — workout={$list[0]}"
            : ' — workout waehlen: ' . implode(' | ', $list);
    }

    /**
     * Das Gerüst als Prompt-Abschnitt. Bewusst als Belegung formuliert und
     * nicht als Wunsch — die KI füllt aus, sie entscheidet nicht mehr.
     */
    /** @var array<string,string> Bausteine, die der gerade gebaute Prompt-Abschnitt nennt */
    private array $legend = [];

    public function toPromptSection(array $skeleton): string
    {
        $this->legend = [];

        $model     = $skeleton['model'] ?? ['name' => 'polarized'];
        $norwegian = ($model['name'] ?? null) === 'norwegian';

        $labels = [
            'easy_run'   => 'Lockerer Lauf (Zone 2)',
            'tempo_run'  => 'Tempolauf (Schwelle)',
            'interval'   => 'Intervalltraining',
            'long_run'   => 'Langer Lauf',
            'strength'   => 'Kraft (ergänzend)',
            'mobility'   => 'Mobility (ergänzend)',
        ];
        $weekdays = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

        $lines = [];
        foreach ($skeleton['days'] as $date => $day) {
            $wd = $weekdays[$day['weekday']];

            // Abgeschlossene Tage und solche, die unveraendert stehen
            // bleiben, gehoeren nicht in die Liste der zu planenden Tage.
            if ($day['finalized'] || ! empty($day['kept'])) {
                continue;
            }

            if (! $day['available']) {
                $lines[] = "- {$date} ({$wd}): NICHT VERFÜGBAR → type=\"rest\"";
                continue;
            }

            $cap = $day['budget_min'] > 0 ? "max. {$day['budget_min']} min" : 'ohne feste Obergrenze';

            // Ruhetage sind verbindlich wie jede andere Vorgabe. Vorher stand
            // hier "rest ODER lockere Einheit", und das Modell wuerfelte.
            if (! empty($day['rest'])) {
                $lines[] = "- {$date} ({$wd}): RUHETAG → type=\"rest\" PFLICHT";
                continue;
            }

            if (! $day['slots']) {
                $lines[] = "- {$date} ({$wd}): frei, {$cap} → type=\"rest\" ODER lockere Einheit";
                continue;
            }

            $parts = [];
            foreach ($day['slots'] as $slot) {
                $label = $labels[$slot['type']] ?? $slot['type'];

                // Der Deckel der Einheit steht getrennt vom Tagesbudget: bei
                // einem Wiedereinstieg ist er deutlich kleiner, und genau
                // daran hängt, ob die Einheit angemessen ist.
                $slotMax = (int) ($slot['max_min'] ?? 0);
                $slotCap = $slotMax > 0 && ($day['budget_min'] === 0 || $slotMax < $day['budget_min'])
                    ? ", max. {$slotMax} min"
                    : '';

                // Eine Radeinheit steht fest — sie kommt aus den Vorlagen in
                // % FTP und wird vom System eingesetzt, nicht vom Modell.
                if (! empty($slot['bike'])) {
                    $parts[] = ! empty($slot['extra'])
                        ? "dazu RADEINHEIT (Zwift, vom System gesetzt: \"{$slot['workout']['title']}\", {$slot['target_min']} min) — "
                            . 'gib dafuer keinen Eintrag zurueck; der Lauf dieses Tages bleibt'
                        : "RADEINHEIT (Zwift, vom System gesetzt: \"{$slot['workout']['title']}\", {$slot['target_min']} min) — "
                            . 'gib dafuer KEINEN Eintrag zurueck, auch keinen Lauf';
                    continue;
                }

                // Ein Rennen wird benannt, nicht als fester Termin getarnt.
                if (! empty($slot['race'])) {
                    $race = $slot['race'];
                    $rk   = $race['km'] ?? null;
                    $dist = $rk ? ", {$rk} km" : '';
                    $prio = ! empty($race['priority']) ? " (Prio {$race['priority']})" : '';

                    // Die Zielzeit des Athleten, nicht unsere Schaetzung —
                    // und daraus das Zieltempo. Genau daran hing, dass das
                    // Modell einen 20-Minuten-5er im Marathontempo plante.
                    // Der Zusatz gilt nur fuer Nebenrennen: bei einem
                    // 20-Minuten-5er ist das Marathon-Renntempo die falsche
                    // Vorgabe. Beim Zielrennen selbst waere derselbe Satz
                    // Unsinn — dort IST es das Renntempo.
                    $notMarathonPace = empty($race['is_goal'])
                        ? ' — daran richtet sich die Renn-Strategie aus, NICHT am Renntempo des Zielrennens.'
                        : '.';

                    $goal = ! empty($race['target_min'])
                        ? " ZIELZEIT: {$race['target_min']} min"
                            . (! empty($race['target_pace']) ? " ({$race['target_pace']} min/km)" : '')
                            . $notMarathonPace
                        : '';

                    $goalRace = ! empty($race['is_goal'])
                        ? ' Das ist das ZIELRENNEN dieses Plans.'
                        : '';

                    $parts[] = "type=\"race_prep\" — WETTKAMPF: {$race['name']}{$dist}{$prio}.{$goalRace}"
                        . " Das ist das Rennen selbst, KEIN Training. title = der Rennname,"
                        . " description = die Renn-Strategie fuer diesen Wettkampf.{$goal}";
                    continue;
                }

                if (! empty($slot['fixed'])) {
                    // Der Inhalt steht dort nicht fest, der Umfang zaehlt
                    // trotzdem in die Woche — deshalb ein Richtwert.
                    $rough = isset($slot['target_km'])
                        ? " (rechne mit rund {$slot['target_km']} km fuer die Wochenbilanz)"
                        : '';
                    $parts[] = "type=\"{$slot['type']}\" — FESTER TERMIN: {$slot['label']}{$slotCap}{$rough}"
                        . ' — den Inhalt gibt der Termin vor (jede Woche anders): kein workout, kein Ablauf, keine Zahlen in der Beschreibung';
                    continue;
                }

                // Welche Bausteine an diesem Slot erlaubt sind — das Modell
                // waehlt einen, den Ablauf baut der Validator (RunWorkouts).
                $workouts = $this->workoutChoices($slot, (string) $date, $skeleton);

                // Beim langen Lauf steht die Distanz fest, nicht nur der Typ.
                if ($slot['type'] === 'long_run' && isset($slot['target_km'])) {
                    $race     = ($slot['race_km'] ?? 0) > 0 ? ", davon {$slot['race_km']} km im Zielrenntempo" : '';
                    $shortened = ! empty($slot['shortened'])
                        ? ' — auf das Zeitbudget dieses Tages gekuerzt, die Leiter haette mehr vorgesehen'
                        : '';
                    $parts[] = "type=\"long_run\" — {$slot['target_km']} km (~{$slot['target_min']} min){$race}{$shortened}{$workouts}";
                    continue;
                }

                $optional = ! empty($slot['optional']) ? ' [optional, weglassen wenn unpassend]' : '';

                // 80/20 nach Zeit: der Hauptteil hat eine Obergrenze. Und
                // eine Einheit, die deswegen locker wurde, sagt es — sonst
                // schreibt das Modell den Tempolauf trotzdem hinein.
                $hard = isset($slot['hard_min'])
                    ? " — Hauptteil (Zone 4–5) hoechstens {$slot['hard_min']} min, hard_min={$slot['hard_min']}"
                    : '';
                if (! empty($slot['eighty_twenty'])) {
                    $optional .= " — statt {$slot['eighty_twenty']}: die Woche laege sonst ueber 20 % harter Laufzeit. Rein locker, keine Tempoabschnitte";
                }

                // Norwegisches Modell: die Schwelle heisst hier Sub-Schwelle
                // und hat andere Grenzen als ein harter Tempolauf.
                if (! empty($slot['controlled'])) {
                    $label = match ($slot['double'] ?? null) {
                        'am'    => 'DOPPEL-SCHWELLE MORGENS, part="am"',
                        'pm'    => 'DOPPEL-SCHWELLE ABENDS, part="pm"',
                        default => 'EINZEL-SCHWELLE',
                    } . ', knapp UNTER der Schwelle';
                    $hard = isset($slot['hard_min'])
                        ? " — Hauptteil Sub-Schwelle hoechstens {$slot['hard_min']} min, hard_min={$slot['hard_min']}"
                        : '';
                } elseif (! empty($slot['fast'])) {
                    $label = 'KURZ UND SCHNELL, schneller als die Schwelle';
                    $hard  = isset($slot['hard_min'])
                        ? " — schnelle Abschnitte zusammen hoechstens {$slot['hard_min']} min, hard_min={$slot['hard_min']}"
                        : '';
                }
                if (! empty($slot['norwegian'])) {
                    $optional .= " — statt {$slot['norwegian']}: der Anteil der Woche traegt keinen Hauptteil. Rein locker";
                }

                // Der Zielumfang kommt aus dem Wochenbudget. Ohne ihn stand
                // dort nur die Verfuegbarkeit — und das Modell las eine
                // Obergrenze von 120 Minuten als Auftrag, 120 Minuten zu
                // planen.
                if (isset($slot['target_km'])) {
                    $parts[] = "type=\"{$slot['type']}\" ({$label}) — Ziel {$slot['target_km']} km"
                        . " (~{$slot['target_min']} min){$slotCap}{$hard}{$optional}{$workouts}";
                    continue;
                }

                $parts[]  = "type=\"{$slot['type']}\" ({$label}{$slotCap}){$hard}{$optional}{$workouts}";
            }

            $lines[] = "- {$date} ({$wd}): hoechstens {$cap} → " . implode(' + ', $parts);
        }

        // Die Übersicht wird aus den TAGEN gebildet, nicht aus dem Ergebnis
        // der Wochenplanung. Letzteres entsteht, bevor das Umfangsbudget
        // Einheiten streicht — die Übersicht behauptete danach Einheiten,
        // die in der Tagesliste darunter gar nicht mehr standen.
        $byWeek  = [];
        $minutes = [];
        $bikes   = [];
        foreach ($skeleton['days'] as $day) {
            if ($day['finalized'] || ! empty($day['kept'])) {
                continue;
            }

            foreach ($day['slots'] ?? [] as $slot) {
                if (! empty($slot['bike'])) {
                    $kind = ! empty($slot['extra']) ? 'extra' : 'swap';
                    $bikes[$day['week']][$kind] = ($bikes[$day['week']][$kind] ?? 0) + 1;
                }

                if (in_array($slot['type'], self::RUN_SLOT_TYPES, true)) {
                    $byWeek[$day['week']][] = $slot['type'];

                    if (empty($slot['race'])) {
                        $minutes[$day['week']] = ($minutes[$day['week']] ?? 0) + (int) ($slot['target_min'] ?? 0);
                    }
                }
            }
        }

        $planned = [];
        foreach (array_keys($skeleton['weeks']) as $weekKey) {
            $list = ! empty($byWeek[$weekKey])
                ? implode(', ', $byWeek[$weekKey])
                : 'keine Laufeinheit';

            $share = match (true) {
                ($minutes[$weekKey] ?? 0) <= 0 => '',
                $norwegian => " — rund {$minutes[$weekKey]} min Laufzeit, davon hoechstens "
                    . (int) floor($minutes[$weekKey] * self::SUB_THRESHOLD_SHARE) . ' min Sub-Schwelle und '
                    . (int) floor($minutes[$weekKey] * self::FAST_SHARE) . ' min schneller als die Schwelle',
                default => " — rund {$minutes[$weekKey]} min Laufzeit, davon hoechstens " . (int) floor($minutes[$weekKey] * self::HARD_SHARE) . ' min hart',
            };

            // Rad zählt nicht in die Laufminuten — die Kilometer des
            // ersetzten Laufs sind aus der Woche heraus, nicht verteilt.
            $bike = implode('', array_filter([
                ! empty($bikes[$weekKey]['swap']) ? " (dazu {$bikes[$weekKey]['swap']}× Rad statt Laufen)" : '',
                ! empty($bikes[$weekKey]['extra']) ? " (dazu {$bikes[$weekKey]['extra']}× Rad zusaetzlich)" : '',
            ]));

            $planned[] = "- {$weekKey}: {$list}{$bike}{$share}";
        }

        $comeback = '';
        if (! empty($skeleton['comeback'])) {
            $c        = $skeleton['comeback'];
            $comeback = "\n\nDieses Gerüst ist bereits auf den Wiedereinstieg nach {$c['trigger_label']} zugeschnitten "
                . "(Stufe {$c['step']} von {$c['total_steps']}): Typ und Maximaldauer jeder Einheit setzen die Stufenregel um. "
                . "Halte dich daran und ergänze KEINE weitere Einheit, um die Regel zu erfüllen — sie ist damit erfüllt.";
        }

        $legend = $this->legend === [] ? '' : "\n\nBausteine:\n" . implode("\n", array_map(
            fn ($k, $v) => "- {$k}: {$v}",
            array_keys($this->legend),
            $this->legend,
        ));

        return "\n\n**VERBINDLICHES WOCHENGERÜST (vom System festgelegt, NICHT ändern):**\n"
            . implode("\n", $lines)
            . $legend
            . "\n\nWochenübersicht:\n" . implode("\n", $planned)
            . $comeback
            . "\n\nRegeln zum Gerüst:\n"
            . "- Der vorgegebene \"type\" jedes Tages ist BINDEND. Du füllst nur workout, title, description und intensity aus; distance_km und duration_min übernimmst du aus dem Gerüst. Den Ablauf, die Paces und die genaue Distanz setzt das System.\n"
            . ($norwegian ? $this->norwegianRules($model) : "- 80/20 NACH ZEIT: Hoechstens 20 % der Laufminuten einer Woche sind hart (Zone 4–5 und Renntempo-Abschnitte). Einlaufen, Trabpausen und Auslaufen zaehlen als locker. Die Minuten des Hauptteils (hard_min) stehen im Geruest; das System baut den Ablauf danach.\n"
            . "- HÖCHSTENS EINE LAUFEINHEIT PRO TAG. Ein Tag bekommt genau so viele Einträge, wie oben Slots stehen — nie mehr. Ein zweiter Eintrag am selben Tag darf ausschließlich strength, core oder mobility sein, niemals ein weiterer Lauf.\n")
            . "- Tage mit zwei Einträgen bekommen ZWEI Objekte mit demselben \"date\"; die Summe beider duration_min darf das Tages-Maximum nicht überschreiten.\n"
            . "- Steht bei einer Einheit \"max. N min\", ist das ihre Obergrenze — nicht die des Tages. Sie zu überschreiten oder die fehlende Zeit mit einer zweiten Einheit aufzufüllen, ist beides falsch.\n"
            . "- Als [optional] markierte Zweiteinheiten darfst du weglassen, wenn sie an dem Tag nicht sinnvoll sind — aber niemals durch eine harte Einheit ersetzen.\n"
            . "- Als RUHETAG markierte Tage sind Pflicht: type=\"rest\", keine Einheit, auch keine lockere. Der Athlet hat sie fest eingeplant und richtet seine Woche danach ein.\n"
            . "- Erfinde KEINE zusätzlichen harten Einheiten und verschiebe KEINE Termine.\n"
            . ($bikes !== [] ? "- RADEINHEITEN (Zwift) setzt das System selbst ein — gib keine Radeinheit zurueck. Ersetzt das Rad einen Lauf, gib an diesem Tag auch keinen Lauf zurueck; die Kilometer fehlen bewusst im Laufumfang: gleiche sie NICHT an anderen Tagen aus. Kommt das Rad zusaetzlich, schreibst du den Lauf des Tages wie sonst. Ein Radintervall ist eine harte Einheit — plane den Tag danach entsprechend locker.\n" : '')
            . "- FESTE TERMINE sind auswärtige Einheiten (Laufclub, Vereinstraining). Ihr Inhalt steht NICHT fest und wechselt wöchentlich. Schreibe dort KEINE erfundene Struktur hinein: kurzer title mit dem Namen des Termins, und eine description, die sagt, dass der Inhalt vor Ort vorgegeben wird. Setze pace_target=null. Plane an diesem Tag nichts Zusätzliches und ziehe die Einheit bei der Wochenbelastung mit ein.";
    }
}

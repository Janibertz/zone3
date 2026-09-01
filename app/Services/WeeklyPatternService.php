<?php

namespace App\Services;

use App\Models\Event;
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

    /** Ein geplanter Lauf unter 20 Minuten ist kein sinnvoller Trainingsreiz. */
    public const MIN_USEFUL_RUN_MINUTES = 20;

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
    ): array {
        $days     = $this->availabilityPerDate($from, $to, $weeklyAvailability, $overrides, $finalizedDates);
        $priority = self::PRIORITIES[$event->race_distance] ?? self::DEFAULT_PRIORITY;
        $maxHard  = self::MAX_HARD_PER_WEEK[$event->race_distance] ?? 2;

        // Die Stufe wandert über die Wochen mit: sie zählt Trainingseinheiten,
        // nicht Kalendertage. Ist die Leiter durch, plant die nächste Woche
        // wieder normal.
        $step = $comeback['step'] ?? null;

        $weeks = [];
        foreach ($this->groupByWeek($days) as $weekKey => $dates) {
            $weeks[$weekKey] = $step !== null && $step < ReturnToRunService::TOTAL_STEPS
                ? $this->planComebackWeek($days, $dates, $step, $priority, $maxHard)
                : $this->planWeek($days, $dates, $priority, $maxHard);

            // Was danach noch frei ist, wird HIER entschieden — nicht vom
            // Modell. Siehe assignFreeDays().
            $this->assignFreeDays($days, $dates);
        }

        // Der lange Lauf bekommt seine Zieldistanz aus der Leiter — sie ist
        // rückwärts vom Renntag gerechnet und nicht Sache des Modells.
        if ($longRuns) {
            $this->applyLongRuns($days, $longRuns);
        }

        // Und danach bekommt jede übrige Laufeinheit ihren Anteil am
        // Wochenumfang. Muss NACH der Leiter laufen: der lange Lauf geht vor,
        // der Rest teilt sich, was übrig bleibt.
        if ($volume && ! empty($volume['has_data']) && $planningPaceSec > 0) {
            $this->applyVolumeBudget($days, $volume, $planningPaceSec, $priority);
        }

        return [
            'days'      => $days,
            'weeks'     => $weeks,
            'priority'  => $priority,
            'comeback'  => $comeback,
            'long_runs' => $longRuns,
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
            if (isset($overrides[$key])) {
                $available = (bool) ($overrides[$key]['available'] ?? true);
                $budget    = (int) ($overrides[$key]['duration_min'] ?? 0);
            } elseif ($weekly !== null) {
                $day       = $weekly[self::ISO_TO_KEY[$date->isoWeekday()]] ?? null;
                $available = (bool) ($day['available'] ?? false);
                $budget    = (int) ($day['duration_min'] ?? 0);
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

            $days[$key] = [
                'date'        => $key,
                'weekday'     => $date->isoWeekday(),
                'available'   => $available,
                'budget_min'  => $available ? $budget : 0,
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
    private function applyLongRuns(array &$days, array $longRuns): void
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

                $days[$date]['slots'][$i]['target_km']  = $target['km'];
                $days[$date]['slots'][$i]['target_min'] = $target['min'];
                $days[$date]['slots'][$i]['race_km']    = $target['mp_km'];
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
    private function applyVolumeBudget(array &$days, array $volume, int $paceSec, array $priority): void
    {
        $budgetKm = (float) ($volume['next_week_max'] ?? 0);
        if ($budgetKm <= 0) {
            return;
        }

        // Der Deckel wächst über das Fenster mit — mit derselben Rate von
        // 10 %, die auch im Umfangsblock des Prompts steht. Bliebe er flach,
        // hungerte die zweite Woche aus: ihr langer Lauf ist laut Leiter
        // länger, und vom selben Deckel bliebe für den Rest der Woche
        // weniger übrig als in der ersten.
        $week = 0;
        foreach ($this->groupByWeek($days) as $dates) {
            $this->budgetWeek(
                $days,
                $dates,
                $budgetKm * (1 + WeeklyVolumeService::MAX_PROGRESSION_PCT / 100) ** $week,
                $paceSec,
                $priority,
            );
            $week++;
        }
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

        foreach ($dates as $date) {
            foreach ($days[$date]['slots'] ?? [] as $i => $slot) {
                if (! in_array($slot['type'], self::RUN_SLOT_TYPES, true)) {
                    continue;
                }

                // Der lange Lauf steht schon (Leiter) und geht ab.
                if (isset($slot['target_km'])) {
                    $takenKm += (float) $slot['target_km'];
                    continue;
                }

                // Ein fester Termin ist nicht verhandelbar: der Athlet geht
                // hin, ob er im Budget steht oder nicht. Er verbraucht die
                // Zeit, die er dauert.
                if (! empty($slot['fixed'])) {
                    $minutes = (int) ($slot['max_min'] ?: $days[$date]['budget_min']);
                    $km      = $minutes * 60 / $paceSec;

                    $days[$date]['slots'][$i]['target_km']  = round($km, 1);
                    $days[$date]['slots'][$i]['target_min'] = $minutes;
                    $takenKm += $km;
                    continue;
                }

                $open[] = [
                    'date'  => $date,
                    'index' => $i,
                    'type'  => $slot['type'],
                    'cap'   => (int) ($slot['max_min'] ?: $days[$date]['budget_min']),
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

        // Solange das Budget die Mindestdauern nicht traegt, faellt die
        // unwichtigste Einheit weg. Lieber drei Laeufe mit Substanz als
        // fuenf, die keinen Reiz setzen.
        while (count($open) > 1 && $this->minutesNeeded($open) > $budgetMin) {
            $victim = $this->leastImportant($open, $priority);
            if ($victim === null) {
                break;
            }

            unset($days[$open[$victim]['date']]['slots'][$open[$victim]['index']]);
            array_splice($open, $victim, 1);
        }

        // Reicht es nicht einmal fuer die letzte Einheit, bleibt sie
        // trotzdem stehen — eine Woche ohne jeden Lauf waere kein Plan.
        // Sie wird dann eben kurz.
        $base = [];
        foreach ($open as $k => $slot) {
            $base[$k] = min($this->minMinutesFor($slot['type']), $slot['cap']);
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
    }

    /** Wie viele Minuten die offenen Einheiten mindestens brauchen. */
    private function minutesNeeded(array $open): float
    {
        $sum = 0.0;
        foreach ($open as $slot) {
            $sum += min($this->minMinutesFor($slot['type']), $slot['cap']);
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
     * Die Wunschreihenfolge des Zieltyps gilt rückwärts: beim Marathon
     * fliegt der lockere Lauf vor dem Tempolauf. Feste Termine stehen hier
     * gar nicht erst zur Wahl — sie sind vorher aus der Liste heraus.
     *
     * @param  list<array{date:string,index:int,type:string,cap:int}>  $open
     */
    private function leastImportant(array $open, array $priority): ?int
    {
        $rank = array_flip($priority);
        $worst = null;
        $worstRank = -1;

        foreach ($open as $i => $slot) {
            $r = $rank[$slot['type']] ?? count($priority);
            if ($r > $worstRank) {
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

        $planned = [];
        $dropped = [];
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

            $isHard = in_array($fixed['type'], self::HARD_TYPES, true);

            $days[$date]['slots'][] = [
                'type'    => $fixed['type'],
                'hard'    => $isHard,
                'max_min' => $days[$date]['budget_min'],
                'fixed'   => true,
                'label'   => $fixed['label'],
            ];

            $planned[] = $fixed['type'];
            if ($isHard) $hard++;
        }

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
     * Eine Woche im Wiedereinstieg. Hier gibt nicht das Ziel den Takt vor,
     * sondern die Stufenleiter aus {@see ReturnToRunService::STEPS}: locker,
     * kurz, und mit jeder absolvierten Einheit ein Stück mehr.
     *
     * Die Stufe zählt Einheiten, keine Tage — deshalb wandert sie als
     * Referenz durch alle Wochen des Fensters.
     */
    private function planComebackWeek(array &$days, array $dates, int &$step, array $priority, int $maxHard): array
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
        $lastRun  = null;

        foreach ($usable as $date) {
            if (count($planned) >= $capacity) {
                break;
            }

            // In den ersten beiden Stufen liegt zwischen zwei Läufen ein
            // Ruhetag — das ist der Sinn eines vorsichtigen Wiedereinstiegs.
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
            $type = $fixed
                ? (in_array($fixed['type'], self::HARD_TYPES, true) && $step < ReturnToRunService::TOTAL_STEPS
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
            }

            $days[$date]['slots'][] = $slot;

            $planned[] = $type;
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
            $afterHard = isset($days[$previous]) && collect($days[$previous]['slots'] ?? [])
                ->contains(fn ($slot) => (bool) ($slot['hard'] ?? false));

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

    /**
     * Das Gerüst als Prompt-Abschnitt. Bewusst als Belegung formuliert und
     * nicht als Wunsch — die KI füllt aus, sie entscheidet nicht mehr.
     */
    public function toPromptSection(array $skeleton): string
    {
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

                if (! empty($slot['fixed'])) {
                    // Der Inhalt steht dort nicht fest, der Umfang zaehlt
                    // trotzdem in die Woche — deshalb ein Richtwert.
                    $rough = isset($slot['target_km'])
                        ? " (rechne mit rund {$slot['target_km']} km fuer die Wochenbilanz)"
                        : '';
                    $parts[] = "type=\"{$slot['type']}\" — FESTER TERMIN: {$slot['label']}{$slotCap}{$rough}";
                    continue;
                }

                // Beim langen Lauf steht die Distanz fest, nicht nur der Typ.
                if ($slot['type'] === 'long_run' && isset($slot['target_km'])) {
                    $race    = ($slot['race_km'] ?? 0) > 0 ? ", davon {$slot['race_km']} km im Zielrenntempo am Ende" : '';
                    $parts[] = "type=\"long_run\" — {$slot['target_km']} km (~{$slot['target_min']} min){$race}";
                    continue;
                }

                $optional = ! empty($slot['optional']) ? ' [optional, weglassen wenn unpassend]' : '';

                // Der Zielumfang kommt aus dem Wochenbudget. Ohne ihn stand
                // dort nur die Verfuegbarkeit — und das Modell las eine
                // Obergrenze von 120 Minuten als Auftrag, 120 Minuten zu
                // planen.
                if (isset($slot['target_km'])) {
                    $parts[] = "type=\"{$slot['type']}\" ({$label}) — Ziel {$slot['target_km']} km"
                        . " (~{$slot['target_min']} min){$slotCap}{$optional}";
                    continue;
                }

                $parts[]  = "type=\"{$slot['type']}\" ({$label}{$slotCap}){$optional}";
            }

            $lines[] = "- {$date} ({$wd}): hoechstens {$cap} → " . implode(' + ', $parts);
        }

        // Die Übersicht wird aus den TAGEN gebildet, nicht aus dem Ergebnis
        // der Wochenplanung. Letzteres entsteht, bevor das Umfangsbudget
        // Einheiten streicht — die Übersicht behauptete danach Einheiten,
        // die in der Tagesliste darunter gar nicht mehr standen.
        $byWeek = [];
        foreach ($skeleton['days'] as $day) {
            if ($day['finalized'] || ! empty($day['kept'])) {
                continue;
            }

            foreach ($day['slots'] ?? [] as $slot) {
                if (in_array($slot['type'], self::RUN_SLOT_TYPES, true)) {
                    $byWeek[$day['week']][] = $slot['type'];
                }
            }
        }

        $planned = [];
        foreach (array_keys($skeleton['weeks']) as $weekKey) {
            $list = ! empty($byWeek[$weekKey])
                ? implode(', ', $byWeek[$weekKey])
                : 'keine Laufeinheit';
            $planned[] = "- {$weekKey}: {$list}";
        }

        $comeback = '';
        if (! empty($skeleton['comeback'])) {
            $c        = $skeleton['comeback'];
            $comeback = "\n\nDieses Gerüst ist bereits auf den Wiedereinstieg nach {$c['trigger_label']} zugeschnitten "
                . "(Stufe {$c['step']} von {$c['total_steps']}): Typ und Maximaldauer jeder Einheit setzen die Stufenregel um. "
                . "Halte dich daran und ergänze KEINE weitere Einheit, um die Regel zu erfüllen — sie ist damit erfüllt.";
        }

        return "\n\n**VERBINDLICHES WOCHENGERÜST (vom System festgelegt, NICHT ändern):**\n"
            . implode("\n", $lines)
            . "\n\nWochenübersicht:\n" . implode("\n", $planned)
            . $comeback
            . "\n\nRegeln zum Gerüst:\n"
            . "- Der vorgegebene \"type\" jedes Tages ist BINDEND. Du füllst nur title, description, distance_km, duration_min, pace_target, zone und intensity aus.\n"
            . "- HÖCHSTENS EINE LAUFEINHEIT PRO TAG. Ein Tag bekommt genau so viele Einträge, wie oben Slots stehen — nie mehr. Ein zweiter Eintrag am selben Tag darf ausschließlich strength, core oder mobility sein, niemals ein weiterer Lauf.\n"
            . "- Tage mit zwei Einträgen bekommen ZWEI Objekte mit demselben \"date\"; die Summe beider duration_min darf das Tages-Maximum nicht überschreiten.\n"
            . "- Steht bei einer Einheit \"max. N min\", ist das ihre Obergrenze — nicht die des Tages. Sie zu überschreiten oder die fehlende Zeit mit einer zweiten Einheit aufzufüllen, ist beides falsch.\n"
            . "- Als [optional] markierte Zweiteinheiten darfst du weglassen, wenn sie an dem Tag nicht sinnvoll sind — aber niemals durch eine harte Einheit ersetzen.\n"
            . "- Als RUHETAG markierte Tage sind Pflicht: type=\"rest\", keine Einheit, auch keine lockere. Der Athlet hat sie fest eingeplant und richtet seine Woche danach ein.\n"
            . "- Erfinde KEINE zusätzlichen harten Einheiten und verschiebe KEINE Termine.\n"
            . "- FESTE TERMINE sind auswärtige Einheiten (Laufclub, Vereinstraining). Ihr Inhalt steht NICHT fest und wechselt wöchentlich. Schreibe dort KEINE erfundene Struktur hinein: kurzer title mit dem Namen des Termins, und eine description, die sagt, dass der Inhalt vor Ort vorgegeben wird. Setze pace_target=null. Plane an diesem Tag nichts Zusätzliches und ziehe die Einheit bei der Wochenbelastung mit ein.";
    }
}

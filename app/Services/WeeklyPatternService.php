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
        }

        return ['days' => $days, 'weeks' => $weeks, 'priority' => $priority, 'comeback' => $comeback];
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
            fn ($d) => $days[$d]['available'] && ! $days[$d]['finalized']
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
            fn ($d) => $days[$d]['available'] && ! $days[$d]['finalized']
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
            // planWeek zaehlt die schon belegten Tage mit — die Liste ist
            // danach vollstaendig und wird nicht ergaenzt, sondern ersetzt.
            $planned = $this->planWeek($days, $dates, $priority, $maxHard)['planned'];
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

            if ($day['finalized']) {
                continue;
            }

            if (! $day['available']) {
                $lines[] = "- {$date} ({$wd}): NICHT VERFÜGBAR → type=\"rest\"";
                continue;
            }

            $cap = $day['budget_min'] > 0 ? "max. {$day['budget_min']} min" : 'ohne feste Obergrenze';

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
                    $parts[] = "type=\"{$slot['type']}\" — FESTER TERMIN: {$slot['label']}{$slotCap}";
                    continue;
                }

                $optional = ! empty($slot['optional']) ? ' [optional, weglassen wenn unpassend]' : '';
                $parts[]  = "type=\"{$slot['type']}\" ({$label}{$slotCap}){$optional}";
            }

            $lines[] = "- {$date} ({$wd}): {$cap} gesamt → " . implode(' + ', $parts);
        }

        $planned = [];
        foreach ($skeleton['weeks'] as $weekKey => $week) {
            $list = $week['planned'] ? implode(', ', $week['planned']) : 'keine (zu wenig verfügbare Tage)';
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
            . "- Tage ohne Vorgabe: entweder type=\"rest\" oder eine lockere Ergänzung, niemals eine harte Einheit.\n"
            . "- Erfinde KEINE zusätzlichen harten Einheiten und verschiebe KEINE Termine.\n"
            . "- FESTE TERMINE sind auswärtige Einheiten (Laufclub, Vereinstraining). Ihr Inhalt steht NICHT fest und wechselt wöchentlich. Schreibe dort KEINE erfundene Struktur hinein: kurzer title mit dem Namen des Termins, und eine description, die sagt, dass der Inhalt vor Ort vorgegeben wird. Setze pace_target=null. Plane an diesem Tag nichts Zusätzliches und ziehe die Einheit bei der Wochenbelastung mit ein.";
    }
}

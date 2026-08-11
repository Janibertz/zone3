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

    /** Diese Typen zählen als harte Einheit — nie zwei davon an einem Tag. */
    public const HARD_TYPES = ['tempo_run', 'interval', 'test_run', 'progressive_run'];

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
     * @return array{days: array<string,array>, weeks: array<string,array>, priority: list<string>}
     */
    public function build(
        Event $event,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?array $weeklyAvailability,
        array $overrides = [],
        array $finalizedDates = [],
    ): array {
        $days     = $this->availabilityPerDate($from, $to, $weeklyAvailability, $overrides, $finalizedDates);
        $priority = self::PRIORITIES[$event->race_distance] ?? self::DEFAULT_PRIORITY;
        $maxHard  = self::MAX_HARD_PER_WEEK[$event->race_distance] ?? 2;

        $weeks = [];
        foreach ($this->groupByWeek($days) as $weekKey => $dates) {
            $weeks[$weekKey] = $this->planWeek($days, $dates, $priority, $maxHard);
        }

        return ['days' => $days, 'weeks' => $weeks, 'priority' => $priority];
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

            $days[$key] = [
                'date'        => $key,
                'weekday'     => $date->isoWeekday(),
                'available'   => $available,
                'budget_min'  => $available ? $budget : 0,
                'finalized'   => isset($finalized[$key]),
                'week'        => $date->format('o-\WW'),
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

        foreach ($priority as $type) {
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
            $spaced = array_values(array_filter(
                $free,
                fn ($d) => $this->hasQualityGap($days, $usable, $d)
            ));
            // Lieber eng als gar nicht: reicht die Woche nicht für den
            // Abstand, wird trotzdem geplant — der Validator sieht das.
            $free = $spaced ?: $free;
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
            $rest    = $days[$date]['budget_min'] - $this->estimateMinutes($primary['type'], $days[$date]['budget_min']);

            if ($rest < self::SECOND_SLOT_MIN_MINUTES) {
                continue;
            }

            $days[$date]['slots'][] = [
                'type'    => $primary['hard'] ? 'mobility' : 'strength',
                'hard'    => false,
                'max_min' => $rest,
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
                $label    = $labels[$slot['type']] ?? $slot['type'];
                $optional = ! empty($slot['optional']) ? ' [optional, weglassen wenn unpassend]' : '';
                $parts[]  = "type=\"{$slot['type']}\" ({$label}){$optional}";
            }

            $lines[] = "- {$date} ({$wd}): {$cap} gesamt → " . implode(' + ', $parts);
        }

        $planned = [];
        foreach ($skeleton['weeks'] as $weekKey => $week) {
            $list = $week['planned'] ? implode(', ', $week['planned']) : 'keine (zu wenig verfügbare Tage)';
            $planned[] = "- {$weekKey}: {$list}";
        }

        return "\n\n**VERBINDLICHES WOCHENGERÜST (vom System festgelegt, NICHT ändern):**\n"
            . implode("\n", $lines)
            . "\n\nWochenübersicht:\n" . implode("\n", $planned)
            . "\n\nRegeln zum Gerüst:\n"
            . "- Der vorgegebene \"type\" jedes Tages ist BINDEND. Du füllst nur title, description, distance_km, duration_min, pace_target, zone und intensity aus.\n"
            . "- Tage mit zwei Einträgen bekommen ZWEI Objekte mit demselben \"date\"; die Summe beider duration_min darf das Tages-Maximum nicht überschreiten.\n"
            . "- Als [optional] markierte Zweiteinheiten darfst du weglassen, wenn sie an dem Tag nicht sinnvoll sind — aber niemals durch eine harte Einheit ersetzen.\n"
            . "- Tage ohne Vorgabe: entweder type=\"rest\" oder eine lockere Ergänzung, niemals eine harte Einheit.\n"
            . "- Erfinde KEINE zusätzlichen harten Einheiten und verschiebe KEINE Termine.";
    }
}

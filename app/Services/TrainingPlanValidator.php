<?php

namespace App\Services;

/**
 * Prüft die KI-Antwort gegen das Wochengerüst und repariert sie.
 *
 * Bis hierher war der Prompt die einzige Durchsetzung: Regeln wie „an nicht
 * verfügbaren Tagen IMMER rest" oder „die Tagessumme darf das Maximum
 * NIEMALS überschreiten" standen in Großbuchstaben im Text und wurden von
 * nichts überprüft. Ein Plan, der sie verletzte, landete unverändert in der
 * Datenbank.
 *
 * Der Validator ändert nur, was nachweislich falsch ist, und protokolliert
 * jeden Eingriff — so bleibt sichtbar, wie zuverlässig das Modell arbeitet.
 */
class TrainingPlanValidator
{
    /** Alles, was die Oberfläche kennt. Andere Typen dürfen nicht in die DB. */
    public const KNOWN_TYPES = [
        'rest', 'easy_run', 'tempo_run', 'interval', 'long_run', 'race_prep',
        'progressive_run', 'test_run', 'back_to_back_long', 'time_on_feet',
        'yard_simulation', 'night_run', 'strength', 'core', 'mobility',
    ];

    private array $report = [];

    /**
     * @param  list<array>  $sessions  Rohe KI-Einheiten
     * @param  array        $skeleton  Ergebnis von {@see WeeklyPatternService::build()}
     *
     * @return array{sessions: list<array>, report: list<string>}
     */
    public function validate(array $sessions, array $skeleton, ?string $raceDate = null): array
    {
        $this->report = [];
        $days = $skeleton['days'] ?? [];

        $sessions = $this->dropUnknownDates($sessions, $days, $raceDate);
        $sessions = $this->fixTypes($sessions, $days);
        $sessions = $this->enforceAvailability($sessions, $days);
        $sessions = $this->enforceOneHardPerDay($sessions);
        $sessions = $this->enforceDailyBudget($sessions, $days);
        $sessions = $this->restoreMissingSlots($sessions, $days);
        $sessions = $this->fillMissingDays($sessions, $days);

        usort($sessions, fn ($a, $b) => [$a['date'], $b['_hard'] ?? false] <=> [$b['date'], $a['_hard'] ?? false]);
        foreach ($sessions as &$s) {
            unset($s['_hard']);
        }

        return ['sessions' => array_values($sessions), 'report' => $this->report];
    }

    /** Tage außerhalb des Fensters und hinter dem Renntag fliegen raus. */
    private function dropUnknownDates(array $sessions, array $days, ?string $raceDate): array
    {
        return array_values(array_filter($sessions, function ($s) use ($days, $raceDate) {
            $date = $s['date'] ?? null;

            if (! $date || ! isset($days[$date])) {
                $this->note("Tag {$date} liegt außerhalb des Planungsfensters — verworfen");
                return false;
            }

            if ($raceDate && $date > $raceDate) {
                $this->note("Tag {$date} liegt hinter dem Renntag — verworfen");
                return false;
            }

            // Abgeschlossene Tage gehören dem Athleten, nicht dem Modell.
            if (! empty($days[$date]['finalized'])) {
                return false;
            }

            return true;
        }));
    }

    /** Erfundene Typen auf das Gerüst zurückführen. */
    private function fixTypes(array $sessions, array $days): array
    {
        foreach ($sessions as &$s) {
            $type = $s['type'] ?? null;

            if (in_array($type, self::KNOWN_TYPES, true)) {
                continue;
            }

            $fallback = $days[$s['date']]['slots'][0]['type'] ?? 'easy_run';
            $this->note("Unbekannter Typ \"{$type}\" am {$s['date']} → {$fallback}");
            $s['type'] = $fallback;
        }

        return $sessions;
    }

    /** An nicht verfügbaren Tagen darf nichts stehen als Ruhe. */
    private function enforceAvailability(array $sessions, array $days): array
    {
        foreach ($sessions as &$s) {
            if ($days[$s['date']]['available'] || ($s['type'] ?? '') === 'rest') {
                continue;
            }

            $this->note("{$s['date']}: nicht verfügbar, aber \"{$s['type']}\" geplant → rest");
            $s = $this->restEntry($s['date']);
        }

        // Doppelte Ruhetage am selben Datum zusammenfassen.
        $seenRest = [];
        return array_values(array_filter($sessions, function ($s) use (&$seenRest) {
            if (($s['type'] ?? '') !== 'rest') return true;
            if (isset($seenRest[$s['date']])) return false;
            $seenRest[$s['date']] = true;
            return true;
        }));
    }

    /** Zwei harte Einheiten an einem Tag: die zweite wird locker. */
    private function enforceOneHardPerDay(array $sessions): array
    {
        $hardSeen = [];

        foreach ($sessions as &$s) {
            $isHard = in_array($s['type'] ?? '', WeeklyPatternService::HARD_TYPES, true);
            $s['_hard'] = $isHard;

            if (! $isHard) continue;

            if (isset($hardSeen[$s['date']])) {
                $this->note("{$s['date']}: zweite harte Einheit (\"{$s['type']}\") → easy_run");
                $s['type']      = 'easy_run';
                $s['intensity'] = 'low';
                $s['zone']      = 2;
                $s['_hard']     = false;
                continue;
            }

            $hardSeen[$s['date']] = true;
        }

        return $sessions;
    }

    /**
     * Tagesbudget einhalten. Gekürzt wird die weiche Einheit zuerst — die
     * Pflichteinheit des Tages bleibt vollständig.
     */
    private function enforceDailyBudget(array $sessions, array $days): array
    {
        $byDate = [];
        foreach ($sessions as $i => $s) {
            $byDate[$s['date']][] = $i;
        }

        foreach ($byDate as $date => $indexes) {
            $budget = (int) ($days[$date]['budget_min'] ?? 0);
            if ($budget <= 0) continue;

            $total = array_sum(array_map(fn ($i) => (int) ($sessions[$i]['duration_min'] ?? 0), $indexes));
            if ($total <= $budget) continue;

            $this->note("{$date}: {$total} min geplant, nur {$budget} min verfügbar → gekürzt");

            // Weiche Einheiten zuerst opfern, danach anteilig kürzen.
            usort($indexes, fn ($a, $b) => ($sessions[$a]['_hard'] ?? false) <=> ($sessions[$b]['_hard'] ?? false));

            foreach ($indexes as $i) {
                if ($total <= $budget) break;

                $dur = (int) ($sessions[$i]['duration_min'] ?? 0);
                if ($dur <= 0) continue;

                $over    = $total - $budget;
                $newDur  = max(15, $dur - $over);
                $total  -= ($dur - $newDur);

                $sessions[$i]['duration_min'] = $newDur;
                if (! empty($sessions[$i]['distance_km']) && $dur > 0) {
                    $sessions[$i]['distance_km'] = round($sessions[$i]['distance_km'] * $newDur / $dur, 1);
                }
            }
        }

        return $sessions;
    }

    /**
     * Fehlt eine im Gerüst vorgesehene Pflichteinheit, wird sie eingesetzt.
     * Das ist der eigentliche Zweck des Gerüsts: das Wochenmuster gilt auch
     * dann, wenn das Modell es ignoriert hat.
     */
    private function restoreMissingSlots(array $sessions, array $days): array
    {
        foreach ($days as $date => $day) {
            if ($day['finalized'] || ! $day['available'] || ! $day['slots']) {
                continue;
            }

            foreach ($day['slots'] as $slot) {
                if (! empty($slot['optional'])) {
                    continue;
                }

                $present = collect($sessions)->contains(
                    fn ($s) => $s['date'] === $date && ($s['type'] ?? '') === $slot['type']
                );
                if ($present) continue;

                // Ein Ruhetag an einem belegten Tag wird ersetzt, sonst ergänzt.
                $restIndex = collect($sessions)->search(
                    fn ($s) => $s['date'] === $date && ($s['type'] ?? '') === 'rest'
                );

                $entry = $this->placeholder($date, $slot);

                if ($restIndex !== false) {
                    $this->note("{$date}: {$slot['type']} fehlte (stand als Ruhetag) → eingesetzt");
                    $sessions[$restIndex] = $entry;
                } else {
                    $this->note("{$date}: {$slot['type']} aus dem Gerüst fehlte → ergänzt");
                    $sessions[] = $entry;
                }
            }
        }

        return $sessions;
    }

    /** Jeder Tag im Fenster braucht einen Eintrag, sonst klafft die Liste. */
    private function fillMissingDays(array $sessions, array $days): array
    {
        $covered = collect($sessions)->pluck('date')->flip();

        foreach ($days as $date => $day) {
            if ($day['finalized'] || isset($covered[$date])) {
                continue;
            }

            $this->note("{$date}: kein Eintrag geliefert → Ruhetag");
            $sessions[] = $this->restEntry($date);
        }

        return $sessions;
    }

    private function restEntry(string $date): array
    {
        return [
            'date'         => $date,
            'type'         => 'rest',
            'title'        => 'Ruhetag',
            'description'  => 'Erholung — kein Training geplant.',
            'distance_km'  => 0,
            'duration_min' => 0,
            'pace_target'  => null,
            'zone'         => null,
            'intensity'    => 'rest',
            '_hard'        => false,
        ];
    }

    /**
     * Notnagel für eine fehlende Pflichteinheit. Bewusst schmucklos: der
     * Athlet soll erkennen, dass hier das System eingesprungen ist.
     */
    private function placeholder(string $date, array $slot): array
    {
        $typical = match ($slot['type']) {
            'long_run'              => 90,
            'interval', 'tempo_run' => 60,
            default                 => 45,
        };

        // max_min = 0 heisst „keine bekannte Obergrenze" — dann gilt die
        // uebliche Dauer, nicht null.
        $cap     = (int) $slot['max_min'];
        $minutes = $cap > 0 ? max(20, min($cap, $typical)) : $typical;

        [$title, $desc, $zone, $intensity] = match ($slot['type']) {
            'interval'  => ['Intervalltraining', 'Nach dem Einlaufen 5–6 harte Abschnitte mit lockerer Trabpause dazwischen, danach auslaufen.', 4, 'high'],
            'tempo_run' => ['Tempolauf', 'Nach dem Einlaufen zügig an der Schwelle laufen, danach auslaufen.', 3, 'medium'],
            'long_run'  => ['Langer Lauf', 'Gleichmäßig locker, Tempo bewusst zurückhalten. Trinken und essen mitnehmen.', 2, 'medium'],
            'easy_run'  => ['Lockerer Lauf', 'Ruhiges Grundlagentempo in Zone 2 — unterhalten können muss möglich sein.', 2, 'low'],
            default     => ['Ergänzende Einheit', 'Lockere Ergänzung zum Lauftraining.', 2, 'low'],
        };

        return [
            'date'         => $date,
            'type'         => $slot['type'],
            'title'        => $title,
            'description'  => $desc,
            'distance_km'  => 0,
            'duration_min' => $minutes,
            'pace_target'  => null,
            'zone'         => $zone,
            'intensity'    => $intensity,
            '_hard'        => in_array($slot['type'], WeeklyPatternService::HARD_TYPES, true),
        ];
    }

    private function note(string $message): void
    {
        $this->report[] = $message;
    }
}

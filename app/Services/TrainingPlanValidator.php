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
    /** Kein geplanter Lauf unter dieser Dauer — dann ist Erholung sinnvoller. */
    private const MIN_USEFUL_RUN_MINUTES = WeeklyPatternService::MIN_USEFUL_RUN_MINUTES;

    /** Alles, was die Oberfläche kennt. Andere Typen dürfen nicht in die DB. */
    public const KNOWN_TYPES = [
        'rest', 'easy_run', 'tempo_run', 'interval', 'long_run', 'race_prep',
        'cross_training',
        'progressive_run', 'test_run', 'back_to_back_long', 'time_on_feet',
        'yard_simulation', 'night_run', 'strength', 'core', 'mobility',
    ];

    /**
     * Alles, wofür man Laufschuhe anzieht. Der Athlet will davon höchstens
     * eines pro Tag — eine zweite Einheit darf nur Kraft, Core oder Mobility
     * sein.
     */
    public const RUN_TYPES = [
        'easy_run', 'tempo_run', 'interval', 'long_run', 'progressive_run',
        'test_run', 'back_to_back_long', 'time_on_feet', 'yard_simulation',
        'night_run', 'race_prep',
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
        $sessions = $this->enforceOneRunPerDay($sessions, $days);
        $sessions = $this->enforceLongRunTargets($sessions, $days);
        $sessions = $this->enforceSlotCaps($sessions, $days);
        $sessions = $this->enforceDailyBudget($sessions, $days);
        $sessions = $this->restoreMissingSlots($sessions, $days);
        // Ein eingesetzter Notnagel muss ebenfalls noch ins Tagesbudget
        // passen; erst danach entscheiden wir, ob ein kurzer Lauf überhaupt
        // einen Trainingsreiz ergibt.
        $sessions = $this->enforceDailyBudget($sessions, $days);
        $sessions = $this->enforceMinimumUsefulRunDuration($sessions);
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
            // Erhaltene Tage ebenso — sie stehen bereits in der Datenbank
            // und wurden dem Modell nur als Kontext genannt.
            if (! empty($days[$date]['finalized']) || ! empty($days[$date]['kept'])) {
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

    /**
     * An nicht verfügbaren Tagen darf nichts stehen als Ruhe — und an
     * Ruhetagen, die das Gerüst festgelegt hat, ebenso wenig.
     *
     * Der zweite Fall ist neu. Freie Tage waren im Prompt als „rest ODER
     * lockere Einheit" ausgeschrieben, die Entscheidung lag beim Modell und
     * fiel bei jedem Durchlauf anders aus. Jetzt legt das Gerüst sie fest,
     * und was festgelegt ist, muss auch durchgesetzt werden — ein Hinweis im
     * Prompt allein hat sich noch nie als Durchsetzung bewährt.
     */
    private function enforceAvailability(array $sessions, array $days): array
    {
        foreach ($sessions as &$s) {
            $day = $days[$s['date']];

            if (($s['type'] ?? '') === 'rest') {
                continue;
            }

            if (! empty($day['rest'])) {
                $this->note("{$s['date']}: Ruhetag im Gerüst, aber \"{$s['type']}\" geplant → rest");
                $s = $this->restEntry($s['date']);
                continue;
            }

            if ($day['available']) {
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
     * Ein Lauftraining pro Tag — mehr nicht.
     *
     * Zwei harte Einheiten hat der Validator schon immer erkannt; hart plus
     * locker lief durch. Genau das entstand, wenn zwei Vorgaben im Prompt
     * sich widersprachen (Gerüst: Tempolauf, Sicherheitsregel: 30 Minuten
     * locker): das Modell legte beides auf denselben Tag und erfüllte damit
     * formal beide. Was danebenpasst, ist Kraft oder Mobility.
     */
    private function enforceOneRunPerDay(array $sessions, array $days): array
    {
        $byDate = [];
        foreach ($sessions as $i => $s) {
            if (in_array($s['type'] ?? '', self::RUN_TYPES, true)) {
                $byDate[$s['date']][] = $i;
            }
        }

        $drop = [];
        foreach ($byDate as $date => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $slotTypes = array_column($days[$date]['slots'] ?? [], 'type');

            // Bleiben darf die Einheit, die das Gerüst vorsieht; sonst die
            // härtere, bei Gleichstand die längere.
            usort($indexes, function ($a, $b) use ($sessions, $slotTypes) {
                $rank = fn ($i) => [
                    in_array($sessions[$i]['type'], $slotTypes, true) ? 1 : 0,
                    ! empty($sessions[$i]['_hard']) ? 1 : 0,
                    (int) ($sessions[$i]['duration_min'] ?? 0),
                ];

                return $rank($b) <=> $rank($a);
            });

            foreach (array_slice($indexes, 1) as $i) {
                $this->note("{$date}: zweite Laufeinheit (\"{$sessions[$i]['type']}\") → entfernt, ein Lauf pro Tag");
                $drop[$i] = true;
            }
        }

        return array_values(array_filter(
            $sessions,
            fn ($i) => ! isset($drop[$i]),
            ARRAY_FILTER_USE_KEY
        ));
    }

    /**
     * Der lange Lauf hat eine Zieldistanz, keine Empfehlung.
     *
     * Sie kommt aus einer Leiter, die vom Renntag rückwärts gerechnet ist —
     * genau das macht sie zum Rückgrat einer Marathonvorbereitung. Ein
     * Modell, das daraus „so um die 18 km" macht, oder das sich vom
     * Zeitbudget des Tages verleiten lässt, bricht die Leiter.
     *
     * Toleriert wird eine kleine Abweichung: runde Zahlen im Plan sind
     * angenehmer als 23,4 km, und für die Wirkung ist es einerlei.
     */
    private function enforceLongRunTargets(array $sessions, array $days): array
    {
        foreach ($days as $date => $day) {
            $slot = collect($day['slots'] ?? [])->first(fn ($s) => isset($s['target_km']));
            if (! $slot) {
                continue;
            }

            foreach ($sessions as &$s) {
                if ($s['date'] !== $date || ($s['type'] ?? '') !== 'long_run') {
                    continue;
                }

                $km        = (float) ($s['distance_km'] ?? 0);
                $tolerance = max(1.5, $slot['target_km'] * 0.1);

                if ($km > 0 && abs($km - $slot['target_km']) <= $tolerance) {
                    continue;
                }

                $this->note("{$date}: langer Lauf mit {$km} km statt {$slot['target_km']} km aus der Leiter → korrigiert");
                $s['distance_km']  = $slot['target_km'];
                $s['duration_min'] = $slot['target_min'];
            }
            unset($s);
        }

        return $sessions;
    }

    /**
     * Die Obergrenze der einzelnen Einheit einhalten.
     *
     * Das Tagesbudget allein reicht nicht: beim Wiedereinstieg nach einer
     * Krankheit darf die erste Einheit 30 Minuten dauern, auch wenn der Tag
     * 120 hergibt. Diese Grenze steht im Gerüst und wurde bisher von nichts
     * geprüft.
     */
    private function enforceSlotCaps(array $sessions, array $days): array
    {
        foreach ($days as $date => $day) {
            foreach ($day['slots'] ?? [] as $slot) {
                $cap = (int) ($slot['max_min'] ?? 0);
                if ($cap <= 0) {
                    continue;
                }

                foreach ($sessions as &$s) {
                    if ($s['date'] !== $date || ($s['type'] ?? '') !== $slot['type']) {
                        continue;
                    }

                    $dur = (int) ($s['duration_min'] ?? 0);
                    if ($dur <= $cap) {
                        continue;
                    }

                    $this->note("{$date}: {$s['type']} mit {$dur} min, erlaubt sind {$cap} → gekürzt");
                    $s['duration_min'] = $cap;
                    if (! empty($s['distance_km'])) {
                        $s['distance_km'] = round($s['distance_km'] * $cap / $dur, 1);
                    }
                }
                unset($s);
            }
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
     * Eine Lauf-Einheit mit 8 oder 15 Minuten erzeugt nur Planrauschen.
     * Sie wird nicht künstlich auf 20 Minuten verlängert — die angegebene
     * Verfügbarkeit bleibt verbindlich — sondern zu einem Ruhetag.
     */
    private function enforceMinimumUsefulRunDuration(array $sessions): array
    {
        $seenRest = [];

        foreach ($sessions as &$s) {
            if (! in_array($s['type'] ?? '', self::RUN_TYPES, true)) {
                continue;
            }

            $duration = (int) ($s['duration_min'] ?? 0);
            if ($duration >= self::MIN_USEFUL_RUN_MINUTES) {
                continue;
            }

            $this->note("{$s['date']}: {$duration} min {$s['type']} sind kein sinnvoller Lauf → Ruhetag");
            $s = $this->restEntry($s['date']);
        }
        unset($s);

        return array_values(array_filter($sessions, function ($s) use (&$seenRest) {
            if (($s['type'] ?? '') !== 'rest') {
                return true;
            }

            if (isset($seenRest[$s['date']])) {
                return false;
            }

            $seenRest[$s['date']] = true;
            return true;
        }));
    }

    /**
     * Fehlt eine im Gerüst vorgesehene Pflichteinheit, wird sie eingesetzt.
     * Das ist der eigentliche Zweck des Gerüsts: das Wochenmuster gilt auch
     * dann, wenn das Modell es ignoriert hat.
     */
    private function restoreMissingSlots(array $sessions, array $days): array
    {
        foreach ($days as $date => $day) {
            if ($day['finalized'] || ! empty($day['kept']) || ! $day['available'] || ! $day['slots']) {
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

                // Steht dort schon ein Lauf — nur eben der falsche —, wird er
                // ersetzt und nicht ergänzt. Sonst hätte der Tag am Ende zwei.
                if (in_array($slot['type'], self::RUN_TYPES, true)) {
                    $runIndex = collect($sessions)->search(
                        fn ($s) => $s['date'] === $date && in_array($s['type'] ?? '', self::RUN_TYPES, true)
                    );

                    if ($runIndex !== false) {
                        $this->note("{$date}: {$sessions[$runIndex]['type']} statt {$slot['type']} aus dem Gerüst → ersetzt");
                        $sessions[$runIndex] = $entry;
                        continue;
                    }
                }

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
            if ($day['finalized'] || ! empty($day['kept']) || isset($covered[$date])) {
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

        // Feste Termine sind auswaertige Einheiten — ihr Inhalt steht nicht
        // fest. Hier wird deshalb kein Workout erfunden, sondern nur der
        // Termin gesetzt.
        if (! empty($slot['fixed'])) {
            return [
                'date'         => $date,
                'type'         => $slot['type'],
                'title'        => $slot['label'] ?? 'Fester Termin',
                'description'  => 'Auswärtige Einheit — der Inhalt wird vor Ort vorgegeben.',
                'distance_km'  => 0,
                'duration_min' => $minutes,
                'pace_target'  => null,
                'zone'         => null,
                'intensity'    => in_array($slot['type'], WeeklyPatternService::HARD_TYPES, true) ? 'high' : 'medium',
                '_hard'        => in_array($slot['type'], WeeklyPatternService::HARD_TYPES, true),
            ];
        }

        // Kommt der lange Lauf aus der Leiter, gilt deren Distanz und Dauer.
        if (isset($slot['target_km'])) {
            $race = $slot['race_km'] > 0
                ? " Die letzten {$slot['race_km']} km im Zielrenntempo."
                : '';

            return [
                'date'         => $date,
                'type'         => 'long_run',
                'title'        => 'Langer Lauf',
                'description'  => "Gleichmäßig locker über {$slot['target_km']} km, Tempo bewusst zurückhalten.{$race} Trinken und essen mitnehmen.",
                'distance_km'  => $slot['target_km'],
                'duration_min' => $slot['target_min'],
                'pace_target'  => null,
                'zone'         => 2,
                'intensity'    => 'medium',
                '_hard'        => false,
            ];
        }

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

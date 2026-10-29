<?php

namespace App\Services;

use App\Services\Run\RunWorkouts;

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
        $sessions = $this->clearBikeDays($sessions, $days);
        $sessions = $this->fixTypes($sessions, $days);
        $sessions = $this->enforceAvailability($sessions, $days);
        $sessions = $this->assignParts($sessions, $days);
        $sessions = $this->enforceOneHardPerDay($sessions, $days);
        $sessions = $this->enforceOneRunPerDay($sessions, $days);
        $sessions = $this->enforceLongRunTargets($sessions, $days);
        $sessions = $this->enforceSlotCaps($sessions, $days);
        $sessions = $this->enforceDailyBudget($sessions, $days);
        $sessions = $this->restoreMissingSlots($sessions, $days);
        $sessions = $this->insertBikeSessions($sessions, $days);
        // Ein eingesetzter Notnagel muss ebenfalls noch ins Tagesbudget
        // passen; erst danach entscheiden wir, ob ein kurzer Lauf überhaupt
        // einen Trainingsreiz ergibt.
        $sessions = $this->enforceDailyBudget($sessions, $days);
        // 80/20 nach Zeit erst, wenn alle Dauern feststehen — jede Kürzung
        // davor verschiebt den Anteil.
        $sessions = ($skeleton['model']['name'] ?? 'polarized') === 'norwegian'
            ? $this->enforceNorwegianShares($sessions, $days)
            : $this->enforceEightyTwenty($sessions, $days);
        $sessions = $this->enforceMinimumUsefulRunDuration($sessions);
        $sessions = $this->fillMissingDays($sessions, $days);
        // Zuletzt der Ablauf: aus den Zahlen, die jetzt feststehen.
        $sessions = $this->buildStructures($sessions, $days, $skeleton['paces'] ?? null);

        // Morgens vor abends, sonst die harte Einheit zuerst. Die Reihenfolge
        // wird als sort_order gespeichert.
        // Kraft und Mobility hinter die Haupteinheit — auch hinter eine
        // Radeinheit, die erst am Ende eingesetzt wird.
        $evening = fn ($s) => ($s['part'] ?? null) === 'pm' ? 1 : 0;
        $extra   = fn ($s) => in_array($s['type'] ?? '', ['strength', 'core', 'mobility'], true) ? 1 : 0;
        usort($sessions, fn ($a, $b) => [$a['date'], $evening($a), $extra($a), $b['_hard'] ?? false] <=> [$b['date'], $evening($b), $extra($b), $a['_hard'] ?? false]);
        foreach ($sessions as &$s) {
            unset($s['_hard'], $s['_locked']);
        }

        return ['sessions' => array_values($sessions), 'report' => $this->report];
    }

    /**
     * Jede Laufeinheit bekommt ihren Ablauf aus dem Katalog
     * ({@see RunWorkouts}) — Schritte, Dauer, Distanz und Pace aus einer
     * Rechnung. Vorher schrieb das Modell die Struktur als Text, und ein
     * zweiter Aufruf las sie beim Öffnen der Details heraus.
     *
     * Ausgenommen: Rennen, feste Termine (der Inhalt wird vor Ort
     * vorgegeben), Rad und die Yard-Simulation (eigener Baustein).
     */
    private function buildStructures(array $sessions, array $days, ?array $paces): array
    {
        $catalog = app(RunWorkouts::class);

        foreach ($sessions as &$s) {
            $type = $s['type'] ?? '';

            if (! in_array($type, self::RUN_TYPES, true) || in_array($type, ['race_prep', 'yard_simulation'], true)
                || ! empty($s['steps']) || ! empty($s['sport_type'])) {
                continue;
            }

            $slot = $this->slotFor($days[$s['date'] ?? ''] ?? [], $s);
            if (! empty($slot['fixed']) || ! empty($slot['race'])) {
                continue;
            }

            $built = $catalog->forSlot($s, $slot, $paces);
            if ($built === null) {
                continue;
            }

            $s['workout']      = $built['workout'];
            $s['steps']        = $built['steps'];
            $s['duration_min'] = $built['duration_min'];
            $s['distance_km']  = $built['distance_km'] ?? ($s['distance_km'] ?? null);
            $s['pace_target']  = $built['pace_target'] ?? ($s['pace_target'] ?? null);
            $s['zone']         = $built['zone'];
            $s['intensity']    = $built['intensity'];
        }
        unset($s);

        return $sessions;
    }

    /**
     * An einem Radtag schreibt das Modell nichts — die Radeinheit steht fest
     * und kommt aus den Vorlagen. Was es trotzdem liefert (meist den Lauf,
     * den das Rad ersetzt), fliegt raus. Kraft und Mobility daneben bleiben.
     */
    private function clearBikeDays(array $sessions, array $days): array
    {
        return array_values(array_filter($sessions, function ($s) use ($days) {
            $isBikeDay = collect($days[$s['date']]['slots'] ?? [])->contains(fn ($slot) => ! empty($slot['bike']));

            if (! $isBikeDay || in_array($s['type'] ?? '', ['strength', 'core', 'mobility'], true)) {
                return true;
            }

            if (in_array($s['type'] ?? '', self::RUN_TYPES, true)) {
                $this->note("{$s['date']}: {$s['type']} an einem Radtag → durch die Radeinheit ersetzt");
            }

            return false;
        }));
    }

    /**
     * Die Radeinheiten aus dem Gerüst einsetzen, fertig mit Schritten in
     * % FTP. `_locked` schützt sie vor dem Kürzen aufs Tagesbudget — die
     * Schritte passen nur zu genau dieser Dauer.
     */
    private function insertBikeSessions(array $sessions, array $days): array
    {
        foreach ($days as $date => $day) {
            if ($day['finalized'] || ! empty($day['kept'])) {
                continue;
            }

            foreach ($day['slots'] ?? [] as $slot) {
                if (empty($slot['bike'])) {
                    continue;
                }

                $w = $slot['workout'];

                $sessions[] = [
                    'date'         => $date,
                    'type'         => 'cross_training',
                    'sport_type'   => $slot['sport'] ?? 'VirtualRide',
                    'title'        => $w['title'],
                    'description'  => $w['description'],
                    'distance_km'  => 0,
                    'duration_min' => $w['duration_min'],
                    'pace_target'  => null,
                    'zone'         => null,
                    'intensity'    => $w['intensity'],
                    'steps'        => $w['steps'],
                    '_hard'        => ! empty($slot['hard']),
                    '_locked'      => true,
                ];
            }
        }

        return $sessions;
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

    /**
     * Doppel-Tage (norwegisches Modell): welche Einheit ist morgens, welche
     * abends? Das Modell sagt es mit `part`; fehlt die Angabe, gilt die
     * Reihenfolge der Antwort. An allen anderen Tagen gibt es kein `part`.
     */
    private function assignParts(array $sessions, array $days): array
    {
        $byDate = [];
        foreach ($sessions as $i => $s) {
            $byDate[$s['date']][] = $i;
        }

        foreach ($byDate as $date => $indexes) {
            $isDouble = collect($days[$date]['slots'] ?? [])->contains(fn ($slot) => ! empty($slot['double']));

            if (! $isDouble) {
                foreach ($indexes as $i) {
                    unset($sessions[$i]['part']);
                }
                continue;
            }

            $taken = [];
            $open  = [];
            foreach ($indexes as $i) {
                if (($sessions[$i]['type'] ?? '') !== 'tempo_run') {
                    unset($sessions[$i]['part']);
                    continue;
                }

                $part = $sessions[$i]['part'] ?? null;
                if (in_array($part, ['am', 'pm'], true) && ! isset($taken[$part])) {
                    $taken[$part] = true;
                } else {
                    $open[] = $i;
                }
            }

            foreach ($open as $i) {
                $part = isset($taken['am']) ? (isset($taken['pm']) ? null : 'pm') : 'am';
                if ($part === null) {
                    unset($sessions[$i]['part']);
                    continue;
                }
                $sessions[$i]['part'] = $part;
                $taken[$part]         = true;
            }
        }

        return $sessions;
    }

    /** Die Gerüst-Einheit, zu der eine Einheit gehört — an Doppel-Tagen nach Morgen/Abend. */
    private function slotFor(array $day, array $session): array
    {
        return collect($day['slots'] ?? [])->first(
            fn ($slot) => $slot['type'] === ($session['type'] ?? '')
                && (empty($slot['double']) || $slot['double'] === ($session['part'] ?? null))
        ) ?? [];
    }

    /** Gehört die Einheit zu diesem Slot? Ohne Doppel-Tag reicht der Typ. */
    private function belongsTo(array $session, array $slot): bool
    {
        return ($session['type'] ?? '') === $slot['type']
            && (empty($slot['double']) || ($session['part'] ?? null) === $slot['double']);
    }

    /**
     * Wie viele Laufeinheiten das Gerüst an einem Tag vorsieht — eine, an
     * einem Doppel-Tag des norwegischen Modells zwei.
     */
    private function runsAllowed(array $day): int
    {
        $doubles = collect($day['slots'] ?? [])->filter(fn ($slot) => ! empty($slot['double']))->count();

        return max(1, $doubles);
    }

    /** Zwei harte Einheiten an einem Tag: die zweite wird locker — außer an einem Doppel-Tag. */
    private function enforceOneHardPerDay(array $sessions, array $days): array
    {
        $hardSeen = [];

        foreach ($sessions as &$s) {
            $isHard = in_array($s['type'] ?? '', WeeklyPatternService::HARD_TYPES, true);
            $s['_hard'] = $isHard;

            if (! $isHard) continue;

            $hardSeen[$s['date']] = ($hardSeen[$s['date']] ?? 0) + 1;

            if ($hardSeen[$s['date']] > $this->runsAllowed($days[$s['date']] ?? [])) {
                $this->note("{$s['date']}: zweite harte Einheit (\"{$s['type']}\") → easy_run");
                $s['type']      = 'easy_run';
                $s['intensity'] = 'low';
                $s['zone']      = 2;
                $s['_hard']     = false;
                unset($s['part']);
                continue;
            }
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
            $allowed = $this->runsAllowed($days[$date] ?? []);

            if (count($indexes) <= $allowed) {
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

            foreach (array_slice($indexes, $allowed) as $i) {
                $this->note($allowed > 1
                    ? "{$date}: dritte Laufeinheit (\"{$sessions[$i]['type']}\") → entfernt, höchstens Doppel-Schwelle"
                    : "{$date}: zweite Laufeinheit (\"{$sessions[$i]['type']}\") → entfernt, ein Lauf pro Tag");
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
            foreach ($day['slots'] ?? [] as $slot) {
                if (! isset($slot['target_km'], $slot['target_min'])) {
                    continue;
                }

                // Ein fester Termin traegt nur einen Richtwert fuer die
                // Wochenbilanz. Was dort tatsaechlich gelaufen wird,
                // entscheidet der Laufclub, nicht der Plan.
                if (! empty($slot['fixed'])) {
                    continue;
                }

                // Der lange Lauf kommt aus der Leiter und wird eng gefuehrt.
                // Alle anderen Zielwerte sind eine Aufteilung des
                // Wochenumfangs — dort darf das Modell mehr abweichen,
                // solange es nicht die ganze Verfuegbarkeit ausschoepft.
                $isLongRun = $slot['type'] === 'long_run';
                $tolerance = $isLongRun ? 0.10 : 0.30;

                foreach ($sessions as &$s) {
                    if ($s['date'] !== $date || ! $this->belongsTo($s, $slot)) {
                        continue;
                    }

                    if ($isLongRun) {
                        $km    = (float) ($s['distance_km'] ?? 0);
                        $allow = max(1.5, $slot['target_km'] * $tolerance);

                        if ($km > 0 && abs($km - $slot['target_km']) <= $allow) {
                            continue;
                        }

                        $this->note("{$date}: langer Lauf mit {$km} km statt {$slot['target_km']} km aus der Leiter → korrigiert");
                    } else {
                        $min   = (int) ($s['duration_min'] ?? 0);
                        $allow = max(10, (int) round($slot['target_min'] * $tolerance));

                        if ($min > 0 && abs($min - $slot['target_min']) <= $allow) {
                            continue;
                        }

                        $this->note("{$date}: {$slot['type']} mit {$min} min statt {$slot['target_min']} min aus dem Wochenumfang → korrigiert");
                    }

                    $s['distance_km']  = $slot['target_km'];
                    $s['duration_min'] = $slot['target_min'];
                }
                unset($s);
            }
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
                // Ein Rennen hat die Laenge, die es hat. Das Zeitbudget im
                // Wochenraster ist eine Aussage ueber Trainingstage — am
                // Renntag laeuft der Athlet die Distanz, die ausgeschrieben
                // ist. Ohne diese Ausnahme wurde aus dem Berlin Marathon
                // „33,8 km in 180 min", weil sonntags 180 Minuten im Profil
                // stehen.
                if (! empty($slot['race'])) {
                    continue;
                }

                $cap = (int) ($slot['max_min'] ?? 0);
                if ($cap <= 0) {
                    continue;
                }

                foreach ($sessions as &$s) {
                    if ($s['date'] !== $date || ! $this->belongsTo($s, $slot)) {
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

            // Ein Doppel-Tag hat zwei Fenster: der Abend kommt dazu.
            if (collect($days[$date]['slots'] ?? [])->contains(fn ($slot) => ! empty($slot['double']))) {
                $budget += (int) ($days[$date]['second_min'] ?? 0);
            }

            // An einem Renntag gilt das Tagesbudget nicht — siehe
            // enforceSlotCaps(). Sonst kuerzt diese Schleife den Marathon
            // anteilig mit, sobald noch eine Mobility-Einheit danebensteht.
            $isRaceDay = collect($indexes)->contains(
                fn ($i) => ($sessions[$i]['type'] ?? '') === 'race_prep'
            );
            if ($isRaceDay) continue;

            $total = array_sum(array_map(fn ($i) => (int) ($sessions[$i]['duration_min'] ?? 0), $indexes));
            if ($total <= $budget) continue;

            $this->note("{$date}: {$total} min geplant, nur {$budget} min verfügbar → gekürzt");

            // Weiche Einheiten zuerst opfern, danach anteilig kürzen.
            // Eine Radeinheit wird nie gekürzt — ihre Schritte passen nur zu
            // genau dieser Dauer.
            $firm = fn ($i) => [! empty($sessions[$i]['_locked']), $sessions[$i]['_hard'] ?? false];
            usort($indexes, fn ($a, $b) => $firm($a) <=> $firm($b));

            foreach ($indexes as $i) {
                if ($total <= $budget) break;
                if (! empty($sessions[$i]['_locked'])) continue;

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

    /** Rundungsspielraum, bevor eine Abweichung als Verstoß gilt. */
    private const HARD_TOLERANCE_MIN = 2;

    /**
     * 80/20 nach Zeit: höchstens 20 % der Laufminuten einer Woche sind hart.
     *
     * Zwei Stufen. Zuerst die einzelne Einheit: das Gerüst gibt jedem
     * Hauptteil eine Obergrenze (`hard_min`), und wer darüber plant, wird
     * darauf zurückgesetzt. Danach die Woche: liegen die harten Minuten
     * insgesamt über 20 % — etwa weil das Modell die lockeren Läufe kürzer
     * geplant hat als vorgesehen —, werden die Hauptteile anteilig gekürzt.
     * Bleibt dabei weniger als ein Reiz übrig, wird die Einheit locker.
     *
     * Hart heißt: der Hauptteil der harten Einheiten und der
     * Renntempo-Abschnitt des langen Laufs. Rennen zählen nicht, ein fester
     * Termin zählt mit, wird aber nicht angefasst.
     *
     * Gekürzt wird nur `hard_min`, nicht die Dauer: die Minuten gehen ins
     * Ein- und Auslaufen. Die Beschreibung des Modells kann danach noch
     * „3×10 min" sagen — deshalb hängt der Validator den Satz dazu, der gilt.
     */
    private function enforceEightyTwenty(array $sessions, array $days): array
    {
        $weeks = [];

        foreach ($sessions as $i => $s) {
            $date = $s['date'] ?? null;
            $type = $s['type'] ?? '';
            $day  = $days[$date] ?? null;

            if (! $day || $type === 'race_prep' || ! in_array($type, self::RUN_TYPES, true)) {
                continue;
            }

            $week = $day['week'] ?? substr($date, 0, 7);
            $dur  = (int) ($s['duration_min'] ?? 0);
            $slot = $this->slotFor($day, $s);

            $weeks[$week]['total'] = ($weeks[$week]['total'] ?? 0) + $dur;
            $weeks[$week]['taken'] ??= 0.0;
            $weeks[$week]['open']  ??= [];

            if ($type === 'long_run') {
                $km = (float) ($s['distance_km'] ?? 0);
                if ($km > 0 && ($slot['race_km'] ?? 0) > 0) {
                    $weeks[$week]['taken'] += min($km, (float) $slot['race_km']) * $dur / $km;
                }
                continue;
            }

            if (! in_array($type, WeeklyPatternService::HARD_TYPES, true)) {
                continue;
            }

            $planned  = isset($slot['hard_min']) ? (int) $slot['hard_min'] : null;
            $estimate = min(
                WeeklyPatternService::HARD_MAIN_MINUTES[$type] ?? 20,
                max(0, $dur - WeeklyPatternService::WARMUP_COOLDOWN_MINUTES),
            );

            // Ohne Angabe gehen wir davon aus, dass das Modell dem Gerüst
            // gefolgt ist — sonst würde eine fehlende Zahl als Verstoß gelten.
            $hard = (int) ($s['hard_min'] ?? 0) > 0 ? (int) $s['hard_min'] : ($planned ?? $estimate);

            if ($planned !== null && empty($slot['fixed']) && $hard > $planned + self::HARD_TOLERANCE_MIN) {
                $this->note("{$date}: {$type} mit {$hard} min Hauptteil, das Gerüst erlaubt {$planned} (80/20) → gekürzt");
                $hard = $planned;
                $sessions[$i]['description'] = $this->withMainSetNote($s['description'] ?? '', $hard);
            }

            $sessions[$i]['hard_min'] = $hard;

            if (! empty($slot['fixed'])) {
                $weeks[$week]['taken'] += $hard;
                continue;
            }

            $weeks[$week]['open'][] = $i;
        }

        foreach ($weeks as $week => $w) {
            $adjustable = array_sum(array_map(fn ($i) => $sessions[$i]['hard_min'], $w['open']));
            $hard       = $w['taken'] + $adjustable;
            $cap        = floor($w['total'] * WeeklyPatternService::HARD_SHARE);

            if ($adjustable <= 0 || $hard <= $cap + self::HARD_TOLERANCE_MIN) {
                continue;
            }

            $pct = (int) round($hard / max(1, $w['total']) * 100);
            $this->note("{$week}: " . (int) round($hard) . " von {$w['total']} min hart ({$pct} %), erlaubt sind {$cap} (80/20) → Hauptteile gekürzt");

            $scale = max(0.0, $cap - $w['taken']) / $adjustable;

            foreach ($w['open'] as $i) {
                $new = (int) floor($sessions[$i]['hard_min'] * $scale);

                if ($new >= WeeklyPatternService::MIN_HARD_MAIN_MINUTES) {
                    $sessions[$i]['hard_min']    = $new;
                    $sessions[$i]['description'] = $this->withMainSetNote($sessions[$i]['description'] ?? '', $new);
                    continue;
                }

                // Ein Hauptteil unter zehn Minuten ist kein Reiz mehr.
                $this->note("{$sessions[$i]['date']}: {$sessions[$i]['type']} wird lockerer Lauf — für einen harten Hauptteil reicht der 20-%-Anteil der Woche nicht");

                $sessions[$i] = array_merge($sessions[$i], [
                    'type'        => 'easy_run',
                    'title'       => 'Lockerer Lauf',
                    'description' => 'Ruhiges Grundlagentempo in Zone 2. Ursprünglich eine harte Einheit — die Woche läge damit über 20 % harter Laufzeit.',
                    'pace_target' => null,
                    'zone'        => 2,
                    'intensity'   => 'low',
                    'hard_min'    => 0,
                    '_hard'       => false,
                ]);
            }
        }

        return $sessions;
    }

    /**
     * Die Anteile des norwegischen Modells statt 80/20: höchstens 25 % der
     * Laufminuten Sub-Schwelle, höchstens 5 % schneller als die Schwelle.
     *
     * Dieselbe Mechanik wie {@see enforceEightyTwenty()}: zuerst die
     * einzelne Einheit gegen ihr `hard_min` aus dem Gerüst, dann die Woche.
     * Zu wenig übrig für einen Reiz → lockerer Lauf.
     */
    private function enforceNorwegianShares(array $sessions, array $days): array
    {
        $rules = [
            'sub'  => ['share' => WeeklyPatternService::SUB_THRESHOLD_SHARE, 'min' => WeeklyPatternService::MIN_SUB_MAIN_MINUTES, 'label' => 'Schwellenanteil 25 %', 'name' => 'Sub-Schwelle'],
            'fast' => ['share' => WeeklyPatternService::FAST_SHARE,          'min' => WeeklyPatternService::MIN_FAST_MAIN_MINUTES, 'label' => 'schnell höchstens 5 %', 'name' => 'schnell'],
        ];

        $weeks = [];

        foreach ($sessions as $i => $s) {
            $date = $s['date'] ?? null;
            $type = $s['type'] ?? '';
            $day  = $days[$date] ?? null;

            if (! $day || $type === 'race_prep' || ! in_array($type, self::RUN_TYPES, true)) {
                continue;
            }

            $week = $day['week'] ?? substr($date, 0, 7);
            $dur  = (int) ($s['duration_min'] ?? 0);

            $weeks[$week]['total'] = ($weeks[$week]['total'] ?? 0) + $dur;

            if (! in_array($type, WeeklyPatternService::HARD_TYPES, true)) {
                continue;
            }

            $slot = $this->slotFor($day, $s);
            // Schneller als die Schwelle ist nur der kurze schnelle Reiz —
            // im Gerüst eine Intervalleinheit. Alles andere ist Sub-Schwelle.
            $kind = ! empty($slot['fast']) || $type === 'interval' ? 'fast' : 'sub';

            $planned  = isset($slot['hard_min']) ? (int) $slot['hard_min'] : null;
            $estimate = $kind === 'fast'
                ? min(WeeklyPatternService::FAST_MAIN_MINUTES, max(0, $dur - WeeklyPatternService::WARMUP_COOLDOWN_MINUTES))
                : min(WeeklyPatternService::SUB_MAIN_MINUTES['single'], max(0, $dur - WeeklyPatternService::SUB_WARMUP_MINUTES));

            $hard = (int) ($s['hard_min'] ?? 0) > 0 ? (int) $s['hard_min'] : ($planned ?? $estimate);

            if ($planned !== null && empty($slot['fixed']) && $hard > $planned + self::HARD_TOLERANCE_MIN) {
                $this->note("{$date}: {$type} mit {$hard} min Hauptteil, das Gerüst erlaubt {$planned} ({$rules[$kind]['label']}) → gekürzt");
                $hard = $planned;
                $sessions[$i]['description'] = $this->withMainSetNote($s['description'] ?? '', $hard, $rules[$kind]['label']);
            }

            $sessions[$i]['hard_min'] = $hard;

            $weeks[$week][$kind]['taken'] ??= 0;
            $weeks[$week][$kind]['open']  ??= [];

            if (! empty($slot['fixed'])) {
                $weeks[$week][$kind]['taken'] += $hard;
                continue;
            }

            $weeks[$week][$kind]['open'][] = $i;
        }

        foreach ($weeks as $week => $w) {
            foreach ($rules as $kind => $rule) {
                $open  = $w[$kind]['open'] ?? [];
                $taken = $w[$kind]['taken'] ?? 0;

                $adjustable = array_sum(array_map(fn ($i) => $sessions[$i]['hard_min'], $open));
                $cap        = floor($w['total'] * $rule['share']);

                if ($adjustable <= 0 || $taken + $adjustable <= $cap + self::HARD_TOLERANCE_MIN) {
                    continue;
                }

                $this->note("{$week}: " . (int) round($taken + $adjustable) . " von {$w['total']} min {$rule['name']}, erlaubt sind {$cap} ({$rule['label']}) → Hauptteile gekürzt");

                $scale = max(0.0, $cap - $taken) / $adjustable;

                foreach ($open as $i) {
                    $new = (int) floor($sessions[$i]['hard_min'] * $scale);

                    if ($new >= $rule['min']) {
                        $sessions[$i]['hard_min']    = $new;
                        $sessions[$i]['description'] = $this->withMainSetNote($sessions[$i]['description'] ?? '', $new, $rule['label']);
                        continue;
                    }

                    $this->note("{$sessions[$i]['date']}: {$sessions[$i]['type']} wird lockerer Lauf — der Anteil der Woche ({$rule['label']}) trägt keinen Hauptteil");

                    $sessions[$i] = array_merge($sessions[$i], [
                        'type'        => 'easy_run',
                        'title'       => 'Lockerer Lauf',
                        'description' => 'Ruhiges Grundlagentempo in Zone 2. Ursprünglich eine Qualitätseinheit — die Woche läge damit über ihrem Anteil.',
                        'pace_target' => null,
                        'zone'        => 2,
                        'intensity'   => 'low',
                        'hard_min'    => 0,
                        '_hard'       => false,
                    ]);
                }
            }
        }

        return $sessions;
    }

    private function withMainSetNote(string $description, int $minutes, string $rule = '80/20'): string
    {
        // Wird ein Hauptteil zweimal gekürzt (Einheit, dann Woche), gilt nur
        // der letzte Satz.
        $description = preg_replace('/\s*Hauptteil höchstens \d+ min \([^)]*\)[^.]*\./u', '', $description);

        return trim($description . " Hauptteil höchstens {$minutes} min ({$rule}) — den Rest locker ein- und auslaufen.");
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
                // Radeinheiten setzt insertBikeSessions() ein.
                if (! empty($slot['optional']) || ! empty($slot['bike'])) {
                    continue;
                }

                $present = collect($sessions)->contains(
                    fn ($s) => $s['date'] === $date && $this->belongsTo($s, $slot)
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
                    // An einem Doppel-Tag wird nur ein Lauf ersetzt, der zu
                    // keiner Hälfte gehört — die andere Hälfte bleibt stehen.
                    $runIndex = collect($sessions)->search(
                        fn ($s) => $s['date'] === $date && in_array($s['type'] ?? '', self::RUN_TYPES, true)
                            && (empty($slot['double']) || empty($s['part']))
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

        // Ein Renntag bekommt das Rennen, keine Ersatzeinheit. Genau hier
        // wurde bisher aus dem Ruhetag des Modells ein Tempolauf: das
        // Geruest verlangte eine Einheit, und der Notnagel erfand sie.
        if (! empty($slot['race'])) {
            $race = $slot['race'];
            $km   = (float) ($race['km'] ?? 0);

            // Zielzeit vor Slot-Schaetzung vor Standarddauer.
            $duration = (int) ($race['target_min'] ?? 0)
                ?: (int) ($slot['target_min'] ?? $minutes);

            return [
                'date'         => $date,
                'type'         => 'race_prep',
                'title'        => $race['name'],
                'description'  => 'Wettkampftag — heute wird gelaufen, nicht trainiert.',
                'distance_km'  => $km > 0 ? round($km, 1) : null,
                'duration_min' => $duration,
                'pace_target'  => $race['target_pace'] ?? null,
                'zone'         => null,
                'intensity'    => 'high',
                '_hard'        => true,
            ];
        }

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
        // Die Typpruefung ist noetig, seit JEDER Laufslot einen Zielumfang
        // traegt — ohne sie wurde aus einem fehlenden lockeren Lauf ein
        // langer.
        if ($slot['type'] === 'long_run' && isset($slot['target_km'])) {
            $race = ($slot['race_km'] ?? 0) > 0
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

        [$title, $desc, $zone, $intensity] = match (true) {
            ($slot['double'] ?? null) === 'am' => ['Morgens: Sub-Schwelle', 'Einlaufen, dann 5×6 min knapp unter der Schwelle (T + 5–15 s/km) mit 1 min Trabpause, auslaufen. Kontrolliert — du solltest danach noch mehr können.', 3, 'medium'],
            ($slot['double'] ?? null) === 'pm' => ['Abends: Sub-Schwelle', 'Einlaufen, dann 10×1000 m knapp unter der Schwelle (T + 5–15 s/km) mit 1 min Trabpause, auslaufen.', 3, 'medium'],
            ! empty($slot['controlled'])       => ['Sub-Schwelle', 'Einlaufen, dann 5×6 min knapp unter der Schwelle (T + 5–15 s/km) mit 1 min Trabpause, auslaufen.', 3, 'medium'],
            ! empty($slot['fast'])             => ['Kurz und schnell', 'Einlaufen, dann 10×200 m zügig (schneller als die Schwelle) mit Gehpause, auslaufen.', 5, 'high'],
            default                            => $this->typicalContent($slot['type']),
        };

        $entry = [
            'date'         => $date,
            'type'         => $slot['type'],
            'title'        => $title,
            'description'  => $desc,
            'zone'         => $zone,
            'intensity'    => $intensity,
        ];

        if (! empty($slot['double'])) {
            $entry['part'] = $slot['double'];
        }

        return $this->withTargets($entry, $slot, $minutes);
    }

    /** @return array{0: string, 1: string, 2: int, 3: string} */
    private function typicalContent(string $type): array
    {
        return match ($type) {
            'interval'  => ['Intervalltraining', 'Nach dem Einlaufen 5–6 harte Abschnitte mit lockerer Trabpause dazwischen, danach auslaufen.', 4, 'high'],
            'tempo_run' => ['Tempolauf', 'Nach dem Einlaufen zügig an der Schwelle laufen, danach auslaufen.', 3, 'medium'],
            'long_run'  => ['Langer Lauf', 'Gleichmäßig locker, Tempo bewusst zurückhalten. Trinken und essen mitnehmen.', 2, 'medium'],
            'easy_run'  => ['Lockerer Lauf', 'Ruhiges Grundlagentempo in Zone 2 — unterhalten können muss möglich sein.', 2, 'low'],
            default     => ['Ergänzende Einheit', 'Lockere Ergänzung zum Lauftraining.', 2, 'low'],
        };
    }

    private function withTargets(array $entry, array $slot, int $minutes): array
    {
        // Steht ein Zielumfang aus dem Wochenbudget bereit, gilt er auch fuer
        // den Notnagel — sonst faellt die Wochenbilanz auseinander, sobald
        // eine Einheit nachgesetzt werden muss.
        if (isset($slot['target_km'], $slot['target_min'])) {
            $minutes = (int) $slot['target_min'];
        }

        return $entry + [
            'distance_km'  => (float) ($slot['target_km'] ?? 0),
            'duration_min' => $minutes,
            'pace_target'  => null,
            'hard_min'     => (int) ($slot['hard_min'] ?? 0),
            '_hard'        => in_array($slot['type'], WeeklyPatternService::HARD_TYPES, true),
        ];
    }

    private function note(string $message): void
    {
        $this->report[] = $message;
    }
}

<?php

namespace App\Services\AI;

use App\Services\PlanContext;
use App\Services\WeeklyPatternService;
use Illuminate\Support\Facades\Log;

/**
 * Erzeugt Trainingsplaene. Das Wochengeruest kommt aus dem PlanContext und
 * ist bindend — hier wird es nur noch inhaltlich ausgefuellt.
 */
class TrainingPlanGenerator
{
    use TalksToOpenAI;

    /**
     * Generate a structured 10-day training plan for a specific race event.
     *
     * Returns an array of exactly 10 session objects, or null on failure.
     * Each session: date, type, title, description, distance_km, duration_min,
     *               pace_target, zone, intensity.
     */
    public function generateEventTrainingPlan(PlanContext $c): ?array
    {
        // Die lange Parameterliste ist einem benannten Kontext gewichen. Die
        // lokalen Namen bleiben, damit der Prompt darunter unveraendert ist.
        $event                 = $c->event;
        $profile               = $c->profile;
        $recentActivities      = $c->recentActivities;
        $wellbeingData         = $c->wellbeing;
        $sessionRatings        = $c->sessionRatings;
        $weeklyAvailability    = $c->weeklyAvailability;
        $availabilityOverrides = $c->availabilityOverrides;
        $trainingLoad          = $c->trainingLoad;
        $pastPlanResults       = $c->pastPlanResults;
        $otherEvents           = $c->otherEvents;
        $finalizedSessions     = $c->finalizedSessions;
        $followUpGoal          = $c->followUpGoal;
        $skeleton              = $c->skeleton;
        $garminText            = $c->garminText;

        // Was der Athlet dem Coach erzählt hat — Antworten auf dessen
        // Rückfragen nach einer Einheit, im Chat gemerkte Vorlieben und
        // Einschränkungen. Das floss bisher nur zurück in den Chat. Der
        // Planer sah es nie, obwohl genau dort die Konsequenz hingehört:
        // Wer schreibt, dass sein Knie zwickt, will nicht am nächsten Tag
        // ein Intervalltraining im Plan finden.
        $notes          = trim((string) $c->coachNotes);
        $coachNotesText = $notes === '' ? '' : "\n\n**Was du über diesen Athleten weißt (aus seinen eigenen Angaben):**\n"
            . "{$notes}\n"
            . "Beziehe das in die Planung ein. Wenn eine Angabe einer Einheit im Gerüst widerspricht "
            . "(Beschwerden, Belastung, Vorlieben), passe Inhalt und Intensität an — der Slot bleibt.";

        // Das Fenster beginnt beim Planstart, nicht zwingend heute — wer den
        // Plan später starten lässt, bekommt davor keinen Tag.
        $today         = $c->windowFrom->format('Y-m-d');
        $startsToday   = $c->windowFrom->isToday();
        $fromLabel     = $startsToday ? "heute ({$today})" : "dem Planstart ({$today})";
        $eventDate     = $event->event_date->format('Y-m-d');
        $daysUntil     = $event->days_until;
        $daysFromStart = (int) $c->windowFrom->diffInDays(\Carbon\CarbonImmutable::parse($eventDate), false);
        $targetH      = $event->target_time_hours;
        $targetM      = $event->target_time_minutes;
        $targetTime   = $targetH > 0 ? sprintf('%d:%02d Std', $targetH, $targetM) : "{$targetM} Min";
        $distLabel    = $event->distance_label;
        $priority     = $event->priority;
        $priorityText = match ($priority) {
            'A' => 'A-Event (Hauptrennen — höchste Priorität)',
            'B' => 'B-Event (wichtiges Rennen)',
            'C' => 'C-Event (Trainingsrennen)',
        };

        // Profile section
        $profileText = $profile
            ? "Athletenprofil:\n- Schwellenpace: {$profile['threshold_pace']} min/km\n- LTHR: {$profile['threshold_hr']} bpm\n- Max HR: {$profile['max_hr']} bpm"
            : "Athletenprofil: nicht hinterlegt — plane konservativ.";

        // Activities summary (last 4 weeks, max 10)
        $actLines = [];
        foreach (array_slice($recentActivities, 0, 10) as $a) {
            $hr      = $a['avg_hr'] ? " | HF: {$a['avg_hr']} bpm" : '';
            $pace    = $a['pace'] ? " | Pace: {$a['pace']} min/km" : '';
            $actLines[] = "- [{$a['date']}] {$a['name']}: {$a['distance_km']} km, {$a['duration_min']} min{$pace}{$hr}";
        }
        $activitiesText = empty($actLines)
            ? 'Keine Läufe in den letzten 4 Wochen.'
            : implode("\n", $actLines);

        // Anderes als Laufen — zählt für die Erholung, nicht für die Form.
        $crossText = '';
        if ($c->crossTraining) {
            $labels = ['Ride' => 'Radfahren', 'VirtualRide' => 'Radfahren (Rolle)', 'Walk' => 'Spazieren',
                       'Hike' => 'Wandern', 'Swim' => 'Schwimmen', 'WeightTraining' => 'Krafttraining',
                       'Workout' => 'Workout', 'Yoga' => 'Yoga'];
            $parts  = [];
            foreach ($c->crossTraining as $type => $row) {
                $label   = $labels[$type] ?? $type;
                $parts[] = "{$label} {$row['count']}× ({$row['minutes']} min)";
            }
            $crossText = "\n\n**Sonstige Aktivitäten (4 Wochen, kein Lauftraining — kostet Zeit und Erholung, ersetzt aber keine Laufeinheit):** "
                . implode(', ', $parts);
        }

        // Tempi und Wochenumfang: die beiden Größen, aus denen ein Plan
        // gebaut wird. Sie standen bisher in keinem Prompt.
        $paceText = $c->paces
            ? app(\App\Services\TrainingPaceService::class)->toPromptSection($c->paces, $c->longRuns)
            : '';

        // Die Leiter der langen Läufe. Sie steht vor dem Wochenumfang, weil
        // sie ihn mitbestimmt: der lange Lauf kommt zuerst, der Rest der
        // Woche füllt auf.
        $longRunText  = $c->longRuns
            ? app(\App\Services\LongRunPlanService::class)->toPromptSection($c->longRuns)
            : '';
        $ladderWeeks  = $c->longRuns['weeks'] ?? [];
        $nextLongRun  = $ladderWeeks ? array_values($ladderWeeks)[0]['km'] : null;

        $volumeText = $c->volume
            ? app(\App\Services\WeeklyVolumeService::class)->toPromptSection(
                $c->volume,
                $event,
                $nextLongRun,
                (float) (array_values($c->skeleton['periods'] ?? [])[0]['volume_factor'] ?? 1.0),
            )
            : '';

        // Die Phase je Woche kommt aus dem Gerüst — dieselbe Rechnung, nach
        // der es Umfang, Entlastung und harte Einheiten verteilt hat. Vorher
        // stand hier die Phase von HEUTE, für ein Fenster von zwei Wochen,
        // das über eine Phasengrenze reichen kann.
        $weeksLeft = $event->weeks_until;
        $phaseText = app(\App\Services\PeriodizationService::class)
            ->toPromptSection($c->skeleton['periods'] ?? [], $weeksLeft);

        // Ob die lockeren Läufe auch locker gelaufen werden. Steht bei der
        // Periodisierung, weil es dieselbe Frage von der anderen Seite ist:
        // dort was geplant ist, hier was passiert.
        $intensityText = app(\App\Services\IntensityDistributionService::class)->toPromptSection($c->intensity);

        // Was die Zieldistanz im Kern verlangt. Der Backyard-Prompt war
        // darin ausführlich, der Standard-Prompt behandelte 5 km und
        // Marathon gleich — und beschrieb für beide nur „Volumen + Tempo".
        $focusText = "\n\n**Was für diese Distanz zählt ({$distLabel}):**\n" . match ($event->race_distance) {
            '5km' => "- Schlüsselreiz sind kurze, harte Intervalle (VO2max): 4–6×3 min oder 8–10×400 m, Trabpause etwa so lang wie die Belastung.\n"
                . "- Dazu ein kürzerer Schwellenblock pro Woche und Steigerungsläufe für die Laufökonomie.\n"
                . "- Der lange Lauf bleibt moderat — er sichert die Grundlage, ist aber nicht der Hebel.",
            '10km' => "- Schlüsselreiz ist die Schwelle: Cruise-Intervalle wie 5×1000 m oder 3×8 min an der Schwellenpace.\n"
                . "- Ergänzend VO2-Intervalle alle ein bis zwei Wochen, dazu Abschnitte im Renntempo.\n"
                . "- Der lange Lauf hält die Grundlage, wächst aber nicht über das Nötige hinaus.",
            'half_marathon' => "- Schlüsselreiz ist die Schwelle in längeren Blöcken: 2×15 min, 4×8 min oder ein Dauerlauf mit 20–30 min an der Schwelle.\n"
                . "- Der lange Lauf bekommt in der spezifischen Phase Abschnitte im Renntempo (z.B. die letzten 5–8 km).\n"
                . "- Verpflegung im Rennen ist überschaubar — Trinken üben reicht.",
            'marathon' => "- Die wichtigste Einheit ist der lange Lauf mit Abschnitten im Zielrenntempo: die letzten 6–10 km, oder bei längeren Läufen zwei bis drei Blöcke à 5 km. Solche Einheiten entscheiden über den Marathon, nicht die Intervalle.\n"
                . "- Der lange Lauf wächst schrittweise Richtung 30–32 km bzw. 2:30–3:00 Std und wird ab drei Wochen vor dem Rennen wieder kürzer. Wie schnell er wachsen darf, steht in den Umfangsregeln oben — die haben Vorrang vor diesem Richtwert. Ist der Athlet noch weit davon entfernt, ist das kein Grund für einen Sprung, sondern für ehrliche Worte in der description.\n"
                . "- Eine Schwelleneinheit pro Woche hält das Tempo. Harte VO2-Intervalle sind hier zweitrangig und kosten Erholung, die der lange Lauf braucht.\n"
                . "- VERPFLEGUNG: Ab 90 Minuten Laufzeit gehört Essen und Trinken in die description — dieselben Gels/Getränke wie im Rennen, etwa 60–90 g Kohlenhydrate pro Stunde. Der Magen muss das mittrainieren.",
            'backyard_ultra' => "- Time on feet und Pacing-Disziplin schlagen jede Tempoarbeit.",
            default => "- Baue lockeres Volumen als Grundlage auf und setze eine Qualitätseinheit pro Woche, die dem Renntempo entspricht.",
        };

        // Wellbeing summary
        if (! empty($wellbeingData)) {
            $avgEnergy   = round(array_sum(array_column($wellbeingData, 'energy')) / count($wellbeingData), 1);
            $avgSleep    = round(array_sum(array_column($wellbeingData, 'sleep')) / count($wellbeingData), 1);
            $avgSoreness = round(array_sum(array_column($wellbeingData, 'soreness')) / count($wellbeingData), 1);
            $avgStress   = round(array_sum(array_column($wellbeingData, 'stress')) / count($wellbeingData), 1);
            $sickCount   = count(array_filter($wellbeingData, fn ($w) => $w['is_sick']));
            $injuredCount= count(array_filter($wellbeingData, fn ($w) => $w['is_injured']));
            $wellbeingText = "Tagesform (Selbsteinschätzung, Ø letzte 14 Tage):\n- Energie: {$avgEnergy}/10 | Schlaf: {$avgSleep}/10 | Muskelkater: {$avgSoreness}/10 | Stress: {$avgStress}/10";
            if ($sickCount > 0) $wellbeingText .= "\n- ⚠️ Krank an {$sickCount} Tagen";
            if ($injuredCount > 0) $wellbeingText .= "\n- ⚠️ Verletzt an {$injuredCount} Tagen";
        } else {
            $wellbeingText = 'Tagesform: keine Daten vorhanden.';
        }

        // Session ratings summary for AI learning
        $ratingsText = 'Keine Bewertungsdaten vorhanden.';
        if (! empty($sessionRatings)) {
            $ratingLines = [];
            foreach ($sessionRatings as $r) {
                $stars  = $r['rating'] ? str_repeat('⭐', $r['rating']) : '–';
                $rpe    = $r['effort_perceived'] ? "RPE {$r['effort_perceived']}/10" : '';
                $note   = $r['feeling_notes'] ? " | \"{$r['feeling_notes']}\"" : '';
                $ratingLines[] = "- [{$r['date']}] {$r['type']} {$r['distance_km']}km — {$stars} {$rpe}{$note}";
            }
            // Compute avg rating and RPE for coaching context
            $avgRating = round(array_sum(array_column($sessionRatings, 'rating')) / count($sessionRatings), 1);
            $rpeValues = array_filter(array_column($sessionRatings, 'effort_perceived'));
            $avgRpe    = $rpeValues ? round(array_sum($rpeValues) / count($rpeValues), 1) : null;
            $ratingsText = "Ø Bewertung: {$avgRating}/5" . ($avgRpe ? " | Ø RPE: {$avgRpe}/10" : '') . "\n" . implode("\n", array_slice($ratingLines, 0, 10));
        }

        // Availability text
        $dayNames = [
            'monday' => 'Montag', 'tuesday' => 'Dienstag', 'wednesday' => 'Mittwoch',
            'thursday' => 'Donnerstag', 'friday' => 'Freitag', 'saturday' => 'Samstag', 'sunday' => 'Sonntag',
        ];
        if ($weeklyAvailability) {
            $avLines = [];
            foreach ($dayNames as $key => $label) {
                $day = $weeklyAvailability[$key] ?? null;
                if (!$day) { $avLines[] = "- {$label}: nicht verfügbar"; continue; }
                if (! ($day['available'] ?? false)) { $avLines[] = "- {$label}: nicht verfügbar"; }
                else { $avLines[] = "- {$label}: verfügbar, max. {$day['duration_min']} Minuten"; }
            }
            $availabilityText = "Wöchentliche Verfügbarkeit des Athleten:\n" . implode("\n", $avLines);
        } else {
            $availabilityText = 'Wöchentliche Verfügbarkeit: keine Angabe — verteile Training gleichmäßig.';
        }

        // Pre-compute per-date availability for each day in the plan window
        $isoToWeekday = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
        $perDateLines = [];
        $planWindowDays = min(\App\Models\Event::PLAN_HORIZON_DAYS, $daysFromStart + 1); // rolling window
        for ($i = 0; $i < $planWindowDays; $i++) {
            $date    = $c->windowFrom->addDays($i);
            $dateStr = $date->format('Y-m-d');
            if ($dateStr > $eventDate) break;
            $dayKey    = $isoToWeekday[$date->isoWeekday()];
            $dayLabel  = $dayNames[$dayKey];
            // Override takes precedence over weekly default
            if (isset($availabilityOverrides[$dateStr])) {
                $ov = $availabilityOverrides[$dateStr];
                if (! ($ov['available'] ?? true)) {
                    $perDateLines[] = "- {$dateStr} ({$dayLabel}): ❌ NICHT VERFÜGBAR → type=\"rest\" PFLICHT";
                } else {
                    $max = (int) ($ov['duration_min'] ?? 0);
                    $perDateLines[] = "- {$dateStr} ({$dayLabel}): ✅ verfügbar, max. {$max} min";
                }
            } elseif ($weeklyAvailability) {
                $dayAvail = $weeklyAvailability[$dayKey] ?? null;
                if (! $dayAvail || ! ($dayAvail['available'] ?? false)) {
                    $perDateLines[] = "- {$dateStr} ({$dayLabel}): ❌ NICHT VERFÜGBAR → type=\"rest\" PFLICHT";
                } else {
                    $max = (int) ($dayAvail['duration_min'] ?? 0);
                    $perDateLines[] = "- {$dateStr} ({$dayLabel}): ✅ verfügbar, max. {$max} min";
                }
            }
        }
        // Ein Lauf pro Tag — außer an den Doppel-Schwellen-Tagen des
        // norwegischen Modells. Die Ausnahme steht hier und im Gerüst, sonst
        // widerspräche die allgemeine Regel dem Gerüst im selben Prompt.
        $doubleDays = ($skeleton['model']['name'] ?? null) === 'norwegian';

        $oneRunRule = $doubleDays
            ? '- EIN LAUFTRAINING PRO TAG — einzige Ausnahme sind die DOPPEL-SCHWELLEN-Tage im Wochengerüst (part="am" und part="pm"): dort stehen genau ZWEI tempo_run-Einträge, morgens und abends. An allen anderen Tagen hoechstens EINE Laufeinheit; eine weitere lockere Einheit danebenzustellen ist immer falsch.'
            : '- EIN LAUFTRAINING PRO TAG: An einem Tag steht hoechstens EINE Laufeinheit (easy_run, tempo_run, interval, long_run, progressive_run, test_run, race_prep). Zwei Laeufe am selben Tag sind immer falsch — auch dann, wenn der zweite locker waere, und auch dann, wenn eine andere Regel eine zusaetzliche lockere Einheit nahezulegen scheint. Passt eine Vorgabe nicht zur Einheit des Tages, aendere die Einheit, statt eine zweite danebenzustellen.';

        $secondEntryRule = $doubleDays
            ? 'Ein zweiter Eintrag mit demselben "date" ist nur erlaubt, wenn das Wochengerüst dort eine Doppel-Schwelle (part="pm") oder eine strength-, core- oder mobility-Einheit vorsieht.'
            : 'Ein zweiter Eintrag mit demselben "date" ist nur erlaubt, wenn das Wochengerüst dort eine strength-, core- oder mobility-Einheit vorsieht — niemals ein zweiter Lauf.';

        // Den Ablauf baut das System aus dem Katalog (RunWorkouts) — das
        // Modell waehlt nur den Baustein. Vorher schrieb es die Struktur als
        // Text, und ein zweiter Aufruf las sie beim Oeffnen der Details
        // wieder heraus; dabei gingen Minuten verloren (aus 12:30 wurden 12).
        $workoutRule = '- ABLAUF: Den Ablauf jeder Laufeinheit (Einlaufen, Hauptteil, Pausen, Auslaufen, Paces, Distanz) baut das System aus dem Baustein, den du in "workout" waehlst — die erlaubten Bausteine stehen im Gerüst bei jedem Tag. Waehle abwechslungsreich und passend zur Phase; im selben Plan denselben Baustein nicht zweimal (ausser locker und lang_locker). '
            . 'Die description sagt Zweck und Gefühl der Einheit, aber KEINE Minuten, Wiederholungen, Distanzen oder Paces: der Athlet sieht den Ablauf direkt darunter, und eine zweite Zahl wuerde ihm widersprechen. Der title darf den Baustein benennen ("Schwelle in Blöcken"), aber ohne Zahlen. '
            . 'Ab 90 Minuten Laufzeit gehört ein Satz zur Verpflegung in die description.';

        // Liegt ein Wochengerüst vor, ersetzt es die reine Verfügbarkeitsliste:
        // es enthält dieselbe Information und zusätzlich die feste Belegung.
        if ($skeleton) {
            $perDateAvailText = app(WeeklyPatternService::class)->toPromptSection($skeleton);
        } else {
            $perDateAvailText = ! empty($perDateLines)
                ? "\n\n**BINDENDE Verfügbarkeit je Datum (Vorrang vor allen anderen Regeln):**\n"
                    . implode("\n", $perDateLines)
                    . "\nAlle Daten mit ❌ MÜSSEN type=\"rest\" erhalten — keine Ausnahmen!"
                : '';
        }

        // Gemessene Erholungswerte der Uhr. Stehen bewusst direkt neben dem
        // selbst eingetragenen Wellbeing — die beiden widersprechen sich
        // regelmäßig, und das Modell soll den Unterschied sehen.
        $garminBlock = $garminText ? "\n\n**{$garminText}**" : '';

        // Past race results (for learning from previous plan cycles)
        $pastResultsText = 'Keine vergangenen Rennergebnisse vorhanden.';
        if (! empty($pastPlanResults)) {
            $lines = [];
            foreach ($pastPlanResults as $r) {
                $stars  = $r['overall_rating'] ? str_repeat('⭐', $r['overall_rating']) : '–';
                $actual = $r['actual_time'] ?? 'nicht eingetragen';
                $diff   = '';

                // Ohne gesetzte Zielzeit gibt es nichts zu vergleichen. Der
                // Backyard Ultra hat gar keine — er stand trotzdem als
                // „Ziel 0:00 → Ergebnis 10:00 ❌ verfehlt (+600 Min)" im
                // Prompt, bei fünf Sternen und der Notiz „überragend". Das
                // Modell lernte daraus, ein gelungenes Rennen sei misslungen.
                $hasTarget = $r['target_time'] && $r['target_time'] !== '0:00';

                if ($r['actual_time'] && $hasTarget) {
                    [$th, $tm] = explode(':', $r['target_time']) + [0, 0];
                    [$ah, $am] = explode(':', $r['actual_time']) + [0, 0];
                    $targetSec = ((int)$th * 60 + (int)$tm) * 60;
                    $actualSec = ((int)$ah * 60 + (int)$am) * 60;
                    $deltaSec  = $actualSec - $targetSec;
                    if ($deltaSec <= 0) {
                        $diff = ' ✅ Ziel erreicht (' . abs((int)($deltaSec / 60)) . ' Min schneller)';
                    } else {
                        $diff = ' ❌ Ziel verfehlt (+' . (int)($deltaSec / 60) . ' Min langsamer)';
                    }
                }
                $note   = $r['result_notes'] ? " | Notiz: \"{$r['result_notes']}\"" : '';
                $target = $hasTarget ? "Ziel {$r['target_time']} → " : 'ohne Zielzeit — ';
                $lines[] = "- {$r['event_name']} ({$r['race_distance']}): {$target}Ergebnis {$actual}{$diff} | Plan-Bewertung: {$stars}{$note}";
            }
            $pastResultsText = "Vergangene Rennergebnisse des Athleten:\n" . implode("\n", $lines);
        }

        // Training load context (CTL / ATL / TSB)
        $loadText = 'Trainingsbelastung: keine Daten vorhanden.';
        if ($trainingLoad && ($trainingLoad['ctl'] > 0 || $trainingLoad['atl'] > 0)) {
            $tsb       = $trainingLoad['tsb'];
            $tsbSign   = $tsb >= 0 ? "+{$tsb}" : "{$tsb}";
            $formLabel = $trainingLoad['form_label'];
            $loadText  = "Aktuelle Trainingsbelastung:\n"
                . "- CTL (Fitness, 42-Tage-EMA): {$trainingLoad['ctl']}\n"
                . "- ATL (Ermüdung, 7-Tage-EMA): {$trainingLoad['atl']}\n"
                . "- TSB (Form = CTL−ATL): {$tsbSign} → Status: {$formLabel}\n"
                // Der TSB beschreibt den Erholungszustand, NICHT die
                // Trainingsphase. Der alte Text sagte bei TSB > +5
                // „Tapering aktiv" — bei 38 Tagen bis zum Rennen las das
                // Modell daraus, es solle jetzt reduzieren, obwohl der
                // Athlet schlicht ausgeruht war und Umfang vertragen hätte.
                . "So ist der TSB zu lesen (er sagt etwas über die Erholung, nicht über die Trainingsphase — die steht weiter unten):\n"
                . "- unter −30 (übermüdet): Umfang und Intensität zurücknehmen, mehr Ruhe.\n"
                . "- −30 bis −10 (belastet): der normale Zustand in einem Trainingsblock, so weiterplanen.\n"
                . "- −10 bis +5 (optimal): gut belastbar.\n"
                . "- über +5 (frisch/ausgeruht): der Athlet verträgt mehr. Im Trainingsblock heißt das Umfang oder Qualität erhöhen — NICHT reduzieren. Nur in Taper und Rennwoche ist ein hoher TSB das Ziel und wird gehalten.";
        }

        // Other events in the plan window
        $otherEventsText = '';
        if (! empty($otherEvents)) {
            $lines = [];
            foreach ($otherEvents as $e) {
                $lines[] = "- {$e['date']}: {$e['name']} ({$e['distance']}, Priorität {$e['priority']})";
            }
            // Frueher stand hier "an diesen Tagen KEIN Training — type=rest".
            // Das Modell hielt sich dran, und der Validator ersetzte den
            // Ruhetag durch die Einheit aus dem Geruest — am 5-km-Renntag
            // stand ein Tempolauf. Jetzt gilt fuer diese Rennen dieselbe
            // Konvention wie fuer das Zielrennen: der Tag IST das Rennen.
            $otherEventsText = "\n\n**Weitere Wettkaempfe im Planungszeitraum — diese Tage SIND das Rennen"
                . " (type=\"race_prep\", title = der Rennname, description = die Renn-Strategie)."
                . " KEIN Training, aber auch KEIN blosser Ruhetag:**\n" . implode("\n", $lines);
        }

        // Follow-up goal: the next A/B race AFTER this event. Used to shape how much speed
        // a Backyard/Ultra block must preserve (e.g. a marathon afterwards → keep threshold).
        $followUpGoalText = '';
        if (! empty($followUpGoal)) {
            $fg = $followUpGoal;
            $followUpGoalText = "\n\n**Anschlussziel nach diesem Event:** {$fg['name']} am {$fg['date']} ({$fg['distance']}, Priorität {$fg['priority']}).\n"
                . "Der Athlet bereitet sich danach spezifisch auf dieses Ziel vor — die Schnelligkeit/Tempofähigkeit darf jetzt NICHT verloren gehen. "
                . "Richte die wöchentliche Qualitätseinheit an den Anforderungen dieses Ziels aus (Marathon → Marathon-/Schwellentempo; Halbmarathon/10k → schärfere Schwelle bzw. VO2-Intervalle).";
        }

        // Finalized sessions (skipped / completed) — give the coach context, PHP will not overwrite them
        $finalizedText = '';
        if (! empty($finalizedSessions)) {
            $lines = [];
            foreach ($finalizedSessions as $s) {
                $statusLabel = $s['status'] === 'skipped' ? 'Übersprungen' : 'Absolviert';
                $reason      = ! empty($s['skip_reason']) ? " (Grund: {$s['skip_reason']})" : '';
                $lines[]     = "- {$s['date']}: {$s['type']} — {$statusLabel}{$reason}";
            }
            $finalizedText = "\n\n**Bereits abgeschlossene Einheiten (nur als Kontext — du musst diese Tage NICHT im Array zurückgeben):**\n"
                . implode("\n", $lines)
                . "\nPasse die Folgetage entsprechend an (z.B. Erschöpfung → morgen leichter planen).";
        }

        // Vom Athleten selbst gesetzte Einheiten. Fuer das Geruest sind ihre
        // Tage gesperrt; der Prompt muss den Grund kennen, sonst haelt das
        // Modell sie fuer eine Luecke und plant sie zu.
        // Tage, die unveraendert stehen bleiben.
        //
        // Ihr Geruest-Slot hat sich nicht geaendert, also gibt es dort nichts
        // neu zu erfinden. Sie stehen bereits in der Datenbank und werden nur
        // genannt, damit das Modell die Woche als Ganzes sieht und die
        // offenen Tage dazu passend fuellt.
        $keptText = '';
        if ($c->keptSessions) {
            $lines = [];
            foreach ($c->keptSessions as $ks) {
                $umfang = trim(($ks['distance_km'] ? "{$ks['distance_km']} km" : '')
                    . ($ks['duration_min'] ? ($ks['distance_km'] ? ', ' : '') . "{$ks['duration_min']} min" : ''));
                $lines[] = "- {$ks['date']}: {$ks['title']} ({$ks['type']}" . ($umfang ? ", {$umfang}" : '') . ')';
            }

            $keptText = "\n\n**Steht bereits und bleibt unveraendert:**\n"
                . implode("\n", $lines)
                . "\nDiese Tage NICHT im Array zurueckgeben. Sie sind Teil der Woche und zaehlen"
                . " in die Wochenbelastung mit — plane die offenen Tage passend dazu.";
        }

        $pinnedText = '';
        if ($c->pinnedSessions) {
            $lines = [];
            foreach ($c->pinnedSessions as $ps) {
                $umfang = trim(($ps['distance_km'] ? "{$ps['distance_km']} km" : '')
                    . ($ps['duration_min'] ? ($ps['distance_km'] ? ', ' : '') . "{$ps['duration_min']} min" : ''));
                $lines[] = "- {$ps['date']}: {$ps['title']} ({$ps['type']}" . ($umfang ? ", {$umfang}" : '') . ')';
            }

            $pinnedText = "

**Vom Athleten selbst gesetzt — unveränderlich:**
"
                . implode("
", $lines)
                . "
Diese Tage NICHT im Array zurückgeben und nichts daneben planen. Der Athlet hat sie ausdrücklich so bestellt; sie wiegen schwerer als das Wochengerüst und als die Leiter der langen Läufe."
                . "
Plane die übrigen Tage darum herum: rechne ihren Umfang in die Wochensumme mit ein, und lege den Tag davor und danach entsprechend leichter an.";
        }

        // ── Wiedereinstieg nach Krankheit, Verletzung oder Erschöpfung ───────
        // Die Erkennung stand früher hier: dieselbe Auswertung von Krank-Flags
        // und Absagegründen, die auch das Dashboard macht — nur in einer
        // zweiten Kopie und dem Wochengerüst unbekannt. Das Ergebnis war ein
        // Prompt mit zwei bindenden, unvereinbaren Vorgaben: das Gerüst legte
        // einen Tempolauf auf denselben Tag, an dem diese Regel eine lockere
        // halbe Stunde verlangte. Das Modell legte daraufhin beides an einen
        // Tag. Jetzt kommt die Stufe aus dem Kontext, und das Gerüst darüber
        // setzt sie bereits um — der Text erklärt sie nur noch.
        $recoveryWarning = '';
        if ($comeback = $c->comeback) {
            $label     = $comeback['trigger_label'];
            $step      = $comeback['step'];
            $total     = $comeback['total_steps'];
            $rule      = $comeback['rule'];
            $maxMin    = $comeback['max_min'] ? "max. {$comeback['max_min']} min" : 'ohne feste Obergrenze';
            $zone      = $comeback['zone'] ? "Zone {$comeback['zone']}" : 'lockeres Tempo';
            $detailStr = empty($comeback['details']) ? '' : "\nErkannte Signale: " . implode(', ', $comeback['details']);

            $recoveryWarning = <<<WARN

⚠️ **PFLICHT-SICHERHEITSREGEL — Wiederaufnahme nach {$label}:**{$detailStr}

MEDIZINISCHE WARNUNG: Nach Infekten, Verletzungen und starker Erschöpfung besteht erhöhtes Risiko einer Herzmuskelentzündung (Myokarditis) bei zu früher intensiver Belastung.

Der Athlet steht auf **Stufe {$step} von {$total}**. Die Stufe zählt TRAININGSEINHEITEN, nicht Kalendertage — Ruhetage zählen nicht mit.
- Für die nächste Einheit gilt: {$rule}
- Umfang: {$maxMin}, Intensität: {$zone}.

Das Wochengerüst unten ist bereits nach dieser Leiter gebaut: Typ und Maximaldauer jeder Einheit setzen die Stufenregel um, und mit jeder absolvierten Einheit rückt sie eine Stufe weiter.
Deine Aufgabe ist, die vorgegebenen Einheiten entsprechend zu beschreiben — NICHT, eine zusätzliche Einheit einzuplanen, um die Regel zu erfüllen. Ein zusätzlicher „lockerer Wiedereinstieg" neben der Einheit des Tages wäre ein zweites Lauftraining am selben Tag und damit falsch.
Coach-Ton: Empathisch, fürsorglich, motivierend — Erholung ist Training.

WARN;
        }

        // ── Rolling planning window ──────────────────────────────────────────
        // Only plan the next N days instead of all the way to race day. The plan is
        // regenerated regularly anyway, so far-future detail just wastes tokens. The
        // race day + taper are added once the race falls inside the window.
        $horizon     = \App\Models\Event::PLAN_HORIZON_DAYS;
        $totalDays   = min($horizon, $daysFromStart + 1);        // number of day-entries to produce
        $reachesRace = ($daysFromStart + 1) <= $horizon;        // window includes race day?
        $planEndDate = $reachesRace ? $eventDate : $c->windowFrom->addDays($totalDays - 1)->format('Y-m-d');

        // Welche Tage das Modell ueberhaupt schreiben soll.
        //
        // Die Schluss-Anweisung nannte bisher das ganze Fenster ("ein Eintrag
        // pro Tag von heute bis X") — und sie ist das Letzte, was das Modell
        // liest. Bei einer Teil-Neuberechnung stand oben "diese Tage NICHT
        // zurueckgeben" und unten "einen Eintrag pro Tag". An echten Daten
        // gemessen: das Geruest enthielt EINEN Tag, das Modell lieferte NEUN.
        // Es hat nicht getroedelt, es hat der letzten Anweisung gehorcht.
        $openDates = [];
        foreach ($skeleton['days'] ?? [] as $date => $day) {
            if (empty($day['finalized']) && empty($day['kept'])) {
                $openDates[] = $date;
            }
        }

        $openList = $openDates === []
            ? "von {$fromLabel} bis {$planEndDate}"
            : (count($openDates) > 6
                ? 'fuer diese ' . count($openDates) . ' Tage: ' . implode(', ', $openDates)
                : 'fuer GENAU diese Tage: ' . implode(', ', $openDates));


        // Window-end rule wording (race day only included when the race is inside the window)
        $endRuleBackyard = $reachesRace
            ? "Am Renntag ({$eventDate}): type=\"race_prep\", title=\"{$event->name}\", beschreibe die Renn-Strategie (langsames, konstantes Rundentempo, Verpflegung pro Runde, Pausen-Management)."
            : "Das Rennen ist noch {$daysUntil} Tage entfernt — plane NUR bis {$planEndDate}, KEIN Renntag-Eintrag, KEIN race_prep. Der Plan wird später automatisch verlängert. Setze den aktuellen Trainingsblock fort (Volumen/Back-to-Back/Time-on-Feet).";
        $endRuleStandard = $reachesRace
            ? "Am Renntag ({$eventDate}): type=\"race_prep\", title=\"{$event->name}\", beschreibe das Rennen selbst."
            : "Das Rennen ist noch {$daysUntil} Tage entfernt — plane NUR bis {$planEndDate}, KEIN Renntag-Eintrag, KEIN race_prep. Der Plan wird später automatisch verlängert. Plane den dem Zeitraum entsprechenden Trainingsblock.";

        // ── Strength & core context + rules (only when the athlete enabled it) ──
        $strength = $profile['strength'] ?? null;
        if ($strength && ! empty($strength['enabled'])) {
            $equipMap = [
                'kettlebell' => 'Kettlebell', 'dumbbells' => 'Kurzhanteln',
                'gym' => 'Gym (Langhantel/Maschinen)', 'bodyweight' => 'Körpergewicht', 'band' => 'Widerstandsband',
            ];
            $equipList = ! empty($strength['equipment'])
                ? implode(', ', array_map(fn ($e) => $equipMap[$e] ?? $e, $strength['equipment']))
                : 'Körpergewicht';
            $expLabel = match ($strength['experience'] ?? null) {
                'beginner' => 'Anfänger', 'advanced' => 'Fortgeschritten', default => 'Mittel',
            };
            $strengthDays = (int) ($strength['days_per_week'] ?? 2);
            $strengthBlock = "\n\n**Kraft & Core (vom Athleten gewünscht):**\n"
                . "- Die Krafttage ({$strengthDays}× pro Woche) setzt das Wochengerüst — dort ist strength PFLICHT, mit konkreten Übungen. Plane Kraft an keinem anderen Tag.\n"
                . "- Verfügbares Equipment — NUR dieses verwenden: {$equipList}. Level: {$expLabel}.\n"
                . "- Übungen passend zum Equipment wählen (z.B. Kettlebell: Swing, Goblet Squat, Turkish Get-up, Clean&Press, Romanian Deadlift; Gym: Kniebeuge, Kreuzheben, Ausfallschritte, Wadenheben; Körpergewicht: Liegestütz, Ausfallschritte, Plank, Side-Plank, Bird-Dog, Glute Bridge).\n"
                . "- Neben einer harten Einheit oder am Tag danach: Oberkörper und Rumpf statt schwerer Beinkraft.\n"
                . "- Für strength/core/mobility: distance_km=0, duration_min gesetzt, pace_target=null, zone=null UND ein \"exercises\"-Array mit konkreten Übungen ({name, sets, reps, load, note}). load-Beispiele: \"16 kg\", \"2×20 kg\", \"Körpergewicht\", \"RPE 7\".";
        } else {
            $strengthBlock = "\n\n**Kraft & Core:** Nicht gewünscht — plane KEINE strength/core/mobility-Einheiten.";
        }

        if ($event->isBackyard()) {
            $targetYards  = (int) $event->target_yards;
            $targetDistKm = number_format($event->target_distance_km, 1, ',', '.');
            $lapKm        = number_format(\App\Models\Event::BACKYARD_LAP_KM, 3, ',', '.');

            $prompt = <<<PROMPT
Du bist ein erfahrener Ultra- und Backyard-Coach. Erstelle einen Trainingsplan von heute bis zum Renntag für einen **Backyard Ultra** (Last-One-Standing-Format).

**Format-Erklärung (wichtig für die Planung):**
- Eine Runde („Yard") = {$lapKm} km, die zu jeder vollen Stunde gestartet wird. Wer die Runde innerhalb der Stunde schafft, darf in die nächste Stunde — die Restzeit ist Pause.
- Es gibt KEINE Zielzeit und KEIN Tempo-Rennen. Wer zu schnell läuft, verbrennt unnötig Körner. Ziel ist: möglichst viele Stunden durchhalten und am Ende übrig bleiben.
- Erfolg = Ausdauer, Pacing-Disziplin (langsam genug für Pause), Verpflegung, Magen-Training, mentale Stärke und Umgang mit Müdigkeit/Dunkelheit.

**Event:**
- Name: {$event->name}
- Datum: {$eventDate} (in {$daysUntil} Tagen)
- Format: Backyard Ultra
- Ziel: {$targetYards} Yards / Stunden (≈ {$targetDistKm} km)
- Priorität: {$priorityText}

**{$profileText}**{$paceText}

**Letzte Läufe (4 Wochen):**
{$activitiesText}{$crossText}{$volumeText}{$longRunText}

**{$wellbeingText}**{$garminBlock}{$coachNotesText}

**{$loadText}**

**{$pastResultsText}**

**Bisherige Einheitsbewertungen (Athleten-Feedback):**
{$ratingsText}{$phaseText}{$intensityText}

**{$availabilityText}**{$otherEventsText}{$followUpGoalText}{$finalizedText}{$pinnedText}{$keptText}{$recoveryWarning}{$perDateAvailText}{$strengthBlock}

**Wenn sich Signale widersprechen, gilt diese Rangfolge — von oben nach unten:**
1. Gesundheit: Krankheit, Verletzung, Wiedereinstieg. Schlägt alles andere, auch das Renndatum.
2. Gemessene Erholung (Garmin: HRV, Ruhepuls, Schlaf). Objektiv und wichtiger als die Selbsteinschätzung.
3. Trainingsbelastung (TSB) und Selbsteinschätzung (Tagesform).
4. Der Zielehrgeiz. Er bestimmt, WAS trainiert wird, aber nie gegen die drei Punkte darüber, WIE VIEL.

**Planungsregeln (Backyard-spezifisch):**
- Starte den Plan ab {$fromLabel}
- Plane GENAU jeden Tag von {$today} bis {$planEndDate} — das sind {$totalDays} Tage. KEIN Tag nach {$planEndDate}.
- {$endRuleBackyard}
- HAUPTFOKUS: hohes, lockeres aerobes Volumen (Zone 1–2) ist die Basis und macht den Großteil des Umfangs aus (ca. 80 %).
- TEMPOERHALT (wichtig): Plane GENAU EINE Qualitätseinheit pro Woche zum Erhalt der Schwellenpace/Schnelligkeit — entweder tempo_run (Schwelle, z.B. 3×10 min) ODER interval (z.B. 6×1000 m bzw. längere Marathon-Intervalle). NIEMALS zwei harte Einheiten in derselben Woche. Die Qualitätseinheit NICHT direkt vor/nach einer yard_simulation oder einem back_to_back_long legen (Ermüdung). Bei schlechter Tagesform oder hoher Trainingsbelastung entfällt sie zugunsten von Ruhe.
- LONGRUNS: wöchentlich mind. ein langer, lockerer Lauf, schrittweise verlängert (time_on_feet zählt mehr als Tempo).
- BACK-TO-BACK (back_to_back_long): an aufeinanderfolgenden Tagen (z.B. Sa+So) zwei längere Läufe — trainiert das Laufen auf müden Beinen, zentral fürs Format. Mind. alle 1–2 Wochen in Build/Peak.
- TIME ON FEET (time_on_feet): lange, sehr lockere Einheiten mit bewusst niedrigem Tempo, ggf. Geh-Pausen — Dauer wichtiger als Distanz.
- YARD-SIMULATION (yard_simulation): mehrere {$lapKm}-km-Runden im echten Stundenrhythmus (Runde laufen, Rest der Stunde Pause, dann wieder los). Übt Rhythmus, Verpflegung und Pausen-Management. Etwa alle 2–3 Wochen in Build/Peak, NIE in den letzten 10 Tagen. Beschreibe Anzahl der Runden im description.
- NACHTLAUF (night_run): mind. ein Lauf in Dunkelheit/Abend zur Vorbereitung auf Schlafentzug und Nachtstunden — in der Build/Peak-Phase.
- VERPFLEGUNG: weise bei langen Einheiten ausdrücklich auf Ess-/Trink-Training (Magen-Training) hin.
- TAPER: in den letzten 10–14 Tagen Volumen deutlich reduzieren, aber etwas Time-on-Feet halten — ausgeruht und ohne Müdigkeit an den Start. Die Qualitätseinheit in dieser Phase auf kurze, lockere Schärfe-Reize (z.B. ein paar Steigerungen) reduzieren — keine harten Intervalle mehr.
- Berücksichtige Tagesform & Trainingsbelastung: schlechter Schlaf/hoher Stress oder TSB < −30 → leichtere Einheiten / mehr Ruhe.
- Mindestens ein Ruhetag pro Woche.
- VERFÜGBARKEIT: Plane Training AUSSCHLIESSLICH an verfügbaren Tagen. An nicht verfügbaren Tagen IMMER type="rest". Die GESAMTE Trainingsdauer eines Tages (bei zwei Einheiten die Summe) darf die angegebene Maximalzeit NIEMALS überschreiten. Tages-Ausnahmen haben Vorrang.
{$workoutRule}
{$oneRunRule}
- ZWEITE EINHEIT: Nur strength, core oder mobility duerfen als zweiter Eintrag zu einem Tag dazukommen, und nur wenn das Wochengeruest sie dort vorsieht. Bei zwei Eintraegen am selben "date": Tageszeit im title kennzeichnen ("Morgens: ...", "Abends: ..."), Laufeinheit zuerst, Summe beider duration_min <= Tages-Maximum.
- ANDERE RENNEVENTS: An Tagen mit anderen Rennevents im Planungszeitraum IMMER type="rest".

**Antworte ausschließlich mit einem JSON-Array — EIN Eintrag {$openList}. Kein Eintrag für einen anderen Tag; alles andere steht bereits und wurde oben genannt. {$secondEntryRule} Ist einer dieser Tage ein Ruhetag, gehört er als Eintrag mit type="rest" dazu.**
[
  {
    "date": "YYYY-MM-DD",
    "type": "rest|easy_run|tempo_run|interval|long_run|back_to_back_long|time_on_feet|yard_simulation|night_run|progressive_run|strength|core|mobility|race_prep",
    "workout": "Baustein aus dem Gerüst, z. B. lang_locker — nur bei Laufeinheiten",
    "title": "Kurzer Titel (max 40 Zeichen)",
    "description": "Zweck und Gefühl der Einheit (1-2 Sätze, KEINE Minuten, Wiederholungen oder Paces; Verpflegungshinweis bei langen Läufen)",
    "distance_km": 0,
    "duration_min": 0,
    "intensity": "rest|low|medium|high",
    "part": null,
    "exercises": [{ "name": "Kettlebell Swing", "sets": 4, "reps": "15", "load": "16 kg", "note": "explosiv" }]
  }
]
Für Ruhetage: distance_km=0, duration_min=0.
Nur bei strength/core/mobility das "exercises"-Array füllen; bei allen Lauf-Einheiten weglassen oder leer lassen.
PROMPT;
        } else {
        // Der Standard-Prompt ist seit dem 30.10.2026 kurz (vorher ~7.000
        // Token). Das Modell waehlt nur noch Baustein, Titel und
        // Beschreibung; Umfang, Leiter, Phase, 80/20, Paces und Ablauf setzt
        // der Code durch. Jeder Abschnitt, der eine dieser Zahlen ein
        // zweites Mal nannte, war eine zweite Wahrheit — in Jans Prompt
        // stand „kein Long Run" (Wiedereinstieg) neben 20 km (Leiter), „TSB
        // frisch: Umfang erhoehen" neben der Pause, und drei Zielzeiten fuer
        // dasselbe Rennen. Gestrichen: Tempotabelle, Laufliste, Umfang,
        // Leiter, Belastung, Rennergebnisse, Bewertungen, Distanzfokus,
        // Verfuegbarkeit (steckt im Geruest), alte Rennregel.
        $goalLine = $this->goalLine($c->paces, $targetTime);
        $notesBlock = $this->recentNotes($c->coachNotes, $c->windowFrom);
        $easyHint = $this->easyHint($c->intensity);
        // Ohne Kraftwunsch sagte der Prompt „plane KEINE Mobility", während
        // das Gerüst optionale Mobility-Slots trug. Jetzt gilt das Gerüst.
        $strengthRule = ($strength && ! empty($strength['enabled']))
            ? $strengthBlock . "\n- Kraft, Core und Mobility nur dort, wo das Wochengerüst sie vorsieht."
            : "\n\n**Kraft & Core:** Kein eigenes Programm gewünscht. Optionale Zweiteinheiten aus dem Gerüst nur als kurze, leichte Mobility — oder weglassen.";

        $prompt = <<<PROMPT
Du bist ein professioneller Lauf-Coach. Das System hat den Plan fuer {$totalDays} Tage ab {$today} schon gebaut: welcher Tag welche Einheit bekommt, Umfang, Distanz, Paces und den Ablauf jeder Laufeinheit. Deine Aufgabe ist nur: je Tag den Baustein aus den erlaubten waehlen, einen Titel und eine kurze Beschreibung schreiben.

**Rennen:** {$event->name}, {$eventDate} (noch {$daysUntil} Tage), {$distLabel}, Zielzeit {$targetTime}.
Es gilt diese Zielzeit — auch wenn eine Notiz unten eine andere nennt.{$goalLine}{$phaseText}

**{$wellbeingText}**{$garminBlock}{$notesBlock}{$finalizedText}{$pinnedText}{$keptText}{$recoveryWarning}{$perDateAvailText}{$strengthRule}

**Regeln:**
- {$endRuleStandard}
{$workoutRule}
- JEDE EINHEIT BRAUCHT EINEN ZWECK: ein Halbsatz in der description, worauf sie hinarbeitet (z.B. „hält die Schwelle für das Renntempo").
- Wähle den Baustein passend zur Phase und zu dem, was der Athlet über sich gesagt hat (Beschwerden → die schonendere Wahl). Im Wiedereinstieg schreibst du einfühlsam: Erholung ist Training.{$easyHint}
{$oneRunRule}
- ZWEITE EINHEIT: Bei zwei Einträgen am selben "date" Tageszeit im title ("Morgens: ...", "Abends: ..."), Laufeinheit zuerst.

**Antworte ausschließlich mit einem JSON-Array — EIN Eintrag {$openList}. Kein Eintrag für einen anderen Tag. {$secondEntryRule} Ist einer dieser Tage ein Ruhetag, gehört er als Eintrag mit type="rest" dazu.**
[
  {
    "date": "YYYY-MM-DD",
    "type": "rest|easy_run|tempo_run|interval|long_run|progressive_run|test_run|strength|core|mobility|race_prep",
    "workout": "Baustein aus dem Gerüst, z. B. schwelle_bloecke — nur bei Laufeinheiten",
    "title": "Kurzer Titel (max 40 Zeichen)",
    "description": "Zweck und Gefühl der Einheit (1-2 Sätze, KEINE Minuten, Wiederholungen oder Paces)",
    "distance_km": 0,
    "duration_min": 0,
    "intensity": "rest|low|medium|high",
    "part": null,
    "exercises": [{ "name": "Goblet Squat", "sets": 3, "reps": "10", "load": "20 kg", "note": "" }]
  }
]
Für Ruhetage: distance_km=0, duration_min=0. distance_km und duration_min übernimmst du aus dem Gerüst.
Nur bei strength/core/mobility das "exercises"-Array füllen; bei allen Lauf-Einheiten weglassen oder leer lassen.
PROMPT;
        }

        // gpt-5.5 is a reasoning model: internal reasoning tokens eat into the budget,
        // so a full multi-week plan needs a high cap or the completion comes back empty
        // (finish_reason: length). Runs in a queued job, so the longer timeout is fine.
        $text = $this->ai->chat('event_plan', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Antworte ausschließlich mit einem validen JSON-Array ohne zusätzlichen Text.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.6, 16000, 240, background: true);

        $sessions = $this->ai->jsonArray($text);

        if ($sessions) {
            Log::info('Event training plan generated', ['event_id' => $event->id, 'sessions' => count($sessions)]);

            return $sessions;
        }

        Log::warning('Event training plan parse failed', ['text' => $text]);

        return null;
    }

    /** Ziel gegen Form in einem Satz — die einzige Stelle, an der der Prompt über Tempo spricht. */
    private function goalLine(?array $paces, string $targetTime): string
    {
        if (! $paces || empty($paces['predicted_time']) || empty($paces['target_pace'])) {
            return '';
        }

        $delta = (int) ($paces['delta_sec'] ?? 0);
        $gap   = $delta > 0
            ? "Das Ziel ({$paces['target_pace']} min/km) liegt {$delta} s/km vor der heutigen Form ({$paces['predicted_time']}, {$paces['predicted_pace']} min/km). Die Renntempo-Abschnitte im Plan wandern deshalb über die Wochen von der Form zum Ziel — das System setzt sie."
            : "Die heutige Form ({$paces['predicted_time']}) trägt das Ziel bereits.";

        return "\n{$gap}";
    }

    /**
     * Die Notizen, die heute noch etwas sagen: höchstens acht Wochen alt,
     * höchstens zehn, ohne Wiederholung. Vorher standen alle 25 im Prompt —
     * Rückmeldungen aus dem August, die OP zweimal, drei Zielzeiten.
     */
    private function recentNotes(?string $notes, \Carbon\CarbonImmutable $from): string
    {
        $cutoff = \Carbon\CarbonImmutable::today()->subWeeks(8);
        $seen   = [];
        $keep   = [];

        foreach (preg_split('/\R/', (string) $notes, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            if (preg_match('/\[(\d{2}\.\d{2}\.\d{4})\]/', $line, $m)) {
                $date = \Carbon\CarbonImmutable::createFromFormat('d.m.Y', $m[1])->startOfDay();
                if ($date->lt($cutoff)) {
                    continue;
                }
            }

            $key = mb_strtolower(trim(preg_replace('/\[[^\]]*\]/', '', $line)));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $keep[]     = trim($line);
        }

        $keep = array_slice($keep, -10);
        if ($keep === []) {
            return '';
        }

        return "\n\n**Was der Athlet zuletzt über sich gesagt hat:**\n" . implode("\n", $keep);
    }

    /** Ein Satz, wenn die lockeren Läufe zuletzt zu schnell waren — ohne Pace, die steht im Ablauf. */
    private function easyHint(?array $intensity): string
    {
        if (! $intensity || ($intensity['hard_pct'] ?? 0) <= ($intensity['limit_pct'] ?? \App\Services\IntensityDistributionService::TOO_HARD_PCT)) {
            return '';
        }

        return "\n- Die lockeren Läufe waren zuletzt zu schnell ({$intensity['hard_pct']} % hart): sage bei jedem lockeren Lauf, dass er langsam sein muss.";
    }

}

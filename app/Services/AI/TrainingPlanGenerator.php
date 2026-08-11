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

        $today        = now()->format('Y-m-d');
        $eventDate    = $event->event_date->format('Y-m-d');
        $daysUntil    = $event->days_until;
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
            ? 'Keine Aktivitäten in den letzten 4 Wochen.'
            : implode("\n", $actLines);

        // Wellbeing summary
        if (! empty($wellbeingData)) {
            $avgEnergy   = round(array_sum(array_column($wellbeingData, 'energy')) / count($wellbeingData), 1);
            $avgSleep    = round(array_sum(array_column($wellbeingData, 'sleep')) / count($wellbeingData), 1);
            $avgSoreness = round(array_sum(array_column($wellbeingData, 'soreness')) / count($wellbeingData), 1);
            $avgStress   = round(array_sum(array_column($wellbeingData, 'stress')) / count($wellbeingData), 1);
            $sickCount   = count(array_filter($wellbeingData, fn ($w) => $w['is_sick']));
            $injuredCount= count(array_filter($wellbeingData, fn ($w) => $w['is_injured']));
            $wellbeingText = "Wellbeing (Ø letzte 14 Tage):\n- Energie: {$avgEnergy}/10 | Schlaf: {$avgSleep}/10 | Muskelkater: {$avgSoreness}/10 | Stress: {$avgStress}/10";
            if ($sickCount > 0) $wellbeingText .= "\n- ⚠️ Krank an {$sickCount} Tagen";
            if ($injuredCount > 0) $wellbeingText .= "\n- ⚠️ Verletzt an {$injuredCount} Tagen";
        } else {
            $wellbeingText = 'Wellbeing: keine Daten vorhanden.';
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
        $planWindowDays = min(\App\Models\Event::PLAN_HORIZON_DAYS, $daysUntil + 1); // rolling window
        for ($i = 0; $i < $planWindowDays; $i++) {
            $date    = now()->addDays($i);
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
                // Simple time comparison for feedback
                if ($r['actual_time'] && $r['target_time']) {
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
                $note  = $r['result_notes'] ? " | Notiz: \"{$r['result_notes']}\"" : '';
                $lines[] = "- {$r['event_name']} ({$r['race_distance']}): Ziel {$r['target_time']} → Ergebnis {$actual}{$diff} | Plan-Bewertung: {$stars}{$note}";
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
                . "Übermüdet (<−30): Nur leichte Einheiten / Ruhe. Belastet (−30 bis −10): Normaler Trainingsblock. Optimal (−10 bis +5): Wettkampfbereit. Frisch (+5 bis +25): Tapering aktiv. Ausgeruht (>+25): Volumen erhöhen.";
        }

        // Other events in the plan window
        $otherEventsText = '';
        if (! empty($otherEvents)) {
            $lines = [];
            foreach ($otherEvents as $e) {
                $lines[] = "- {$e['date']}: {$e['name']} ({$e['distance']}, Priorität {$e['priority']})";
            }
            $otherEventsText = "\n\n**Weitere Rennevents im Planungszeitraum (an diesen Tagen KEIN Training — type=\"rest\"):**\n" . implode("\n", $lines);
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

        // Recovery detection: illness / injury / exhaustion / poor wellbeing in the last 7 days
        $recoveryWarning = '';
        $recoveryTrigger = null; // 'sick' | 'injured' | 'exhausted' | 'poor_wellbeing'
        $recoveryDetails = [];

        // Check wellbeing entries (last 7 days)
        $last7Wellbeing = array_slice($wellbeingData, 0, 7);
        foreach ($last7Wellbeing as $w) {
            if (! empty($w['is_sick'])) {
                $recoveryTrigger = 'sick';
                $recoveryDetails[] = "krank am {$w['date']}";
                break;
            }
            if (! empty($w['is_injured'])) {
                $recoveryTrigger = 'injured';
                $recoveryDetails[] = "verletzt am {$w['date']}";
                break;
            }
        }

        // Check skipped sessions (last 7 days) for illness/injury/exhaustion reasons
        if ($recoveryTrigger === null) {
            $sevenDaysAgo = now()->subDays(7)->format('Y-m-d');
            foreach ($finalizedSessions as $s) {
                if (($s['status'] ?? '') !== 'skipped') continue;
                if (($s['date'] ?? '') < $sevenDaysAgo) continue;
                $reason = mb_strtolower($s['skip_reason'] ?? '');
                if (str_contains($reason, 'krank') || str_contains($reason, 'sick')) {
                    $recoveryTrigger = 'sick';
                    $recoveryDetails[] = "Krank-Skip am {$s['date']}";
                    break;
                }
                if (str_contains($reason, 'verletzt') || str_contains($reason, 'injur')) {
                    $recoveryTrigger = 'injured';
                    $recoveryDetails[] = "Verletzt-Skip am {$s['date']}";
                    break;
                }
                if (str_contains($reason, 'erschöpft') || str_contains($reason, 'erschopft') || str_contains($reason, 'exhausted')) {
                    $recoveryTrigger = 'exhausted';
                    $recoveryDetails[] = "Erschöpft-Skip am {$s['date']}";
                    break;
                }
            }
        }

        // Check sustained poor wellbeing over last 7 days (even without sick/injured flag)
        if ($recoveryTrigger === null && count($last7Wellbeing) >= 3) {
            $avgEnergy   = array_sum(array_column($last7Wellbeing, 'energy'))   / count($last7Wellbeing);
            $avgSoreness = array_sum(array_column($last7Wellbeing, 'soreness')) / count($last7Wellbeing);
            $avgSleep    = array_sum(array_column($last7Wellbeing, 'sleep'))    / count($last7Wellbeing);
            $avgStress   = array_sum(array_column($last7Wellbeing, 'stress'))   / count($last7Wellbeing);

            if ($avgEnergy < 4) {
                $recoveryTrigger = 'poor_wellbeing';
                $recoveryDetails[] = sprintf('Ø Energie %.1f/10 (letzte 7 Tage)', $avgEnergy);
            }
            if ($avgSoreness > 7) {
                $recoveryTrigger = 'poor_wellbeing';
                $recoveryDetails[] = sprintf('Ø Muskelkater %.1f/10 (letzte 7 Tage)', $avgSoreness);
            }
            if ($avgSleep < 4) {
                $recoveryDetails[] = sprintf('Ø Schlaf %.1f/10 (letzte 7 Tage)', $avgSleep);
                if ($recoveryTrigger === null) $recoveryTrigger = 'poor_wellbeing';
            }
            if ($avgStress > 7) {
                $recoveryDetails[] = sprintf('Ø Stress %.1f/10 (letzte 7 Tage)', $avgStress);
                if ($recoveryTrigger === null) $recoveryTrigger = 'poor_wellbeing';
            }
        }

        if ($recoveryTrigger !== null) {
            $triggerLabel = match($recoveryTrigger) {
                'sick'          => 'Krankheit',
                'injured'       => 'Verletzung',
                'exhausted'     => 'starker Erschöpfung',
                'poor_wellbeing'=> 'anhaltend schlechtem Wellbeing',
            };
            $detailStr = empty($recoveryDetails) ? '' : "\nErkannte Signale: " . implode(', ', $recoveryDetails);

            $recoveryWarning = <<<WARN

⚠️ **PFLICHT-SICHERHEITSREGEL — Wiederaufnahme nach {$triggerLabel} (letzte 7 Tage):**{$detailStr}

MEDIZINISCHE WARNUNG: Nach Infekten, Verletzungen und starker Erschöpfung besteht erhöhtes Risiko einer Herzmuskelentzündung (Myokarditis) bei zu früher intensiver Belastung.

VERPFLICHTENDE STUFENREGEL — zähle TRAININGSEINHEITEN, nicht Kalendertage (Ruhetage zählen nicht):

Einheit 1 (erste Trainingseinheit nach der Pause):
- type="easy_run", Zone 1–2, max. 30 min, sehr lockeres Tempo
- intensity="low", KEIN Tempolauf, KEIN Intervall

Einheit 2 (zweite Trainingseinheit):
- type="easy_run", Zone 2, max. 40 min, lockeres Tempo
- intensity="low"

Ab Einheit 3 (dritte Trainingseinheit und danach):
- Schrittweise Steigerung erlaubt — z.B. tempo_run mit reduziertem Umfang
- Keine Intervalle oder Long Runs vor Einheit 4

Ab Einheit 5:
- Normale Intensität möglich

Ruhetage zwischen den Einheiten zählen NICHT — es geht um Trainingsbelastungen, nicht um Kalendertage.
Coach-Ton: Empathisch, fürsorglich, motivierend — Erholung ist Training.

WARN;
        }

        // ── Rolling planning window ──────────────────────────────────────────
        // Only plan the next N days instead of all the way to race day. The plan is
        // regenerated regularly anyway, so far-future detail just wastes tokens. The
        // race day + taper are added once the race falls inside the window.
        $horizon     = \App\Models\Event::PLAN_HORIZON_DAYS;
        $totalDays   = min($horizon, $daysUntil + 1);            // number of day-entries to produce
        $reachesRace = ($daysUntil + 1) <= $horizon;            // window includes race day?
        $planEndDate = $reachesRace ? $eventDate : now()->addDays($totalDays - 1)->format('Y-m-d');

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
                . "- Frequenz: {$strengthDays}× pro Woche eine strength- oder core-Einheit (zusätzlich zum Laufen).\n"
                . "- Verfügbares Equipment — NUR dieses verwenden: {$equipList}. Level: {$expLabel}.\n"
                . "- Übungen passend zum Equipment wählen (z.B. Kettlebell: Swing, Goblet Squat, Turkish Get-up, Clean&Press, Romanian Deadlift; Gym: Kniebeuge, Kreuzheben, Ausfallschritte, Wadenheben; Körpergewicht: Liegestütz, Ausfallschritte, Plank, Side-Plank, Bird-Dog, Glute Bridge).\n"
                . "- INTERFERENZ vermeiden: KEINE Kraft am Tag VOR einem Long Run oder Schlüssel-Workout; schwere Beinkraft nicht direkt vor/nach Tempo-/Intervalltagen. Lege Kraft eher nach einem easy_run oder als eigenständige Einheit. Mind. 1 Tag Abstand zwischen schwerer Beinkraft und Qualitätsläufen.\n"
                . "- TAPER: in der Race Week (<7 Tage bis Rennen) nur noch leichtes core/mobility; in den letzten 3 Tagen KEINE Kraft.\n"
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

**{$profileText}**

**Letzte Aktivitäten (4 Wochen):**
{$activitiesText}

**{$wellbeingText}**{$garminBlock}

**{$loadText}**

**{$pastResultsText}**

**Bisherige Einheitsbewertungen (Athleten-Feedback):**
{$ratingsText}

**{$availabilityText}**{$otherEventsText}{$followUpGoalText}{$finalizedText}{$recoveryWarning}{$perDateAvailText}{$strengthBlock}

**Planungsregeln (Backyard-spezifisch):**
- Starte den Plan ab heute ({$today})
- Plane GENAU jeden Tag von {$today} bis {$planEndDate} — das sind {$totalDays} Tage. KEIN Tag nach {$planEndDate}.
- {$endRuleBackyard}
- HAUPTFOKUS: hohes, lockeres aerobes Volumen (Zone 1–2) ist die Basis und macht den Großteil des Umfangs aus (ca. 80 %).
- TEMPOERHALT (wichtig): Plane GENAU EINE Qualitätseinheit pro Woche zum Erhalt der Schwellenpace/Schnelligkeit — entweder tempo_run (Schwelle, z.B. 3×10 min) ODER interval (z.B. 6×1000 m bzw. längere Marathon-Intervalle). NIEMALS zwei harte Einheiten in derselben Woche. Die Qualitätseinheit NICHT direkt vor/nach einer yard_simulation oder einem back_to_back_long legen (Ermüdung). Bei schlechtem Wellbeing oder hoher Trainingsbelastung entfällt sie zugunsten von Ruhe.
- LONGRUNS: wöchentlich mind. ein langer, lockerer Lauf, schrittweise verlängert (time_on_feet zählt mehr als Tempo).
- BACK-TO-BACK (back_to_back_long): an aufeinanderfolgenden Tagen (z.B. Sa+So) zwei längere Läufe — trainiert das Laufen auf müden Beinen, zentral fürs Format. Mind. alle 1–2 Wochen in Build/Peak.
- TIME ON FEET (time_on_feet): lange, sehr lockere Einheiten mit bewusst niedrigem Tempo, ggf. Geh-Pausen — Dauer wichtiger als Distanz.
- YARD-SIMULATION (yard_simulation): mehrere {$lapKm}-km-Runden im echten Stundenrhythmus (Runde laufen, Rest der Stunde Pause, dann wieder los). Übt Rhythmus, Verpflegung und Pausen-Management. Etwa alle 2–3 Wochen in Build/Peak, NIE in den letzten 10 Tagen. Beschreibe Anzahl der Runden im description.
- NACHTLAUF (night_run): mind. ein Lauf in Dunkelheit/Abend zur Vorbereitung auf Schlafentzug und Nachtstunden — in der Build/Peak-Phase.
- VERPFLEGUNG: weise bei langen Einheiten ausdrücklich auf Ess-/Trink-Training (Magen-Training) hin.
- TAPER: in den letzten 10–14 Tagen Volumen deutlich reduzieren, aber etwas Time-on-Feet halten — ausgeruht und ohne Müdigkeit an den Start. Die Qualitätseinheit in dieser Phase auf kurze, lockere Schärfe-Reize (z.B. ein paar Steigerungen) reduzieren — keine harten Intervalle mehr.
- Berücksichtige Wellbeing & Trainingsbelastung: schlechter Schlaf/hoher Stress oder TSB < −30 → leichtere Einheiten / mehr Ruhe.
- Mindestens ein Ruhetag pro Woche.
- VERFÜGBARKEIT: Plane Training AUSSCHLIESSLICH an verfügbaren Tagen. An nicht verfügbaren Tagen IMMER type="rest". Die GESAMTE Trainingsdauer eines Tages (bei zwei Einheiten die Summe) darf die angegebene Maximalzeit NIEMALS überschreiten. Tages-Ausnahmen haben Vorrang.
- DOPPEL-EINHEITEN: Welche Tage zwei Einheiten bekommen, steht im Wochengeruest oben. Erfinde keine zusaetzlichen. Bei zwei Eintraegen am selben "date": Tageszeit im title kennzeichnen ("Morgens: ...", "Abends: ..."), Morgen-Einheit zuerst, Summe beider duration_min <= Tages-Maximum, und niemals zwei harte Einheiten am selben Tag.
- ANDERE RENNEVENTS: An Tagen mit anderen Rennevents im Planungszeitraum IMMER type="rest".

**Antworte ausschließlich mit einem JSON-Array — in der Regel EIN Eintrag pro offenem Tag von heute ({$today}) bis {$planEndDate}. An Tagen mit viel Zeit sind ausnahmsweise ZWEI Einträge mit demselben "date" erlaubt (siehe Doppel-Einheiten-Regel). Bereits abgeschlossene Tage (siehe oben) NICHT zurückgeben. Ruhetage MÜSSEN als Eintrag mit type="rest" enthalten sein.**
[
  {
    "date": "YYYY-MM-DD",
    "type": "rest|easy_run|tempo_run|interval|long_run|back_to_back_long|time_on_feet|yard_simulation|night_run|progressive_run|strength|core|mobility|race_prep",
    "title": "Kurzer Titel (max 40 Zeichen)",
    "description": "Beschreibung der Einheit (2-3 Sätze, konkrete Anweisungen inkl. Verpflegungshinweis bei langen Läufen)",
    "distance_km": 0,
    "duration_min": 0,
    "pace_target": "6:30-7:30 oder null bei Ruhetag",
    "zone": 2,
    "intensity": "rest|low|medium|high",
    "exercises": [{ "name": "Kettlebell Swing", "sets": 4, "reps": "15", "load": "16 kg", "note": "explosiv" }]
  }
]
Für Ruhetage: distance_km=0, duration_min=0, pace_target=null, zone=null.
Nur bei strength/core/mobility das "exercises"-Array füllen; bei allen Lauf-Einheiten weglassen oder leer lassen.
PROMPT;
        } else {
        $prompt = <<<PROMPT
Du bist ein professioneller Lauf-Coach. Erstelle einen Trainingsplan von heute bis zum Renntag.

**Event:**
- Name: {$event->name}
- Datum: {$eventDate} (in {$daysUntil} Tagen)
- Distanz: {$distLabel}
- Priorität: {$priorityText}
- Zielzeit: {$targetTime}

**{$profileText}**

**Letzte Aktivitäten (4 Wochen):**
{$activitiesText}

**{$wellbeingText}**{$garminBlock}

**{$loadText}**

**{$pastResultsText}**

**Bisherige Einheitsbewertungen (Athleten-Feedback):**
{$ratingsText}

**{$availabilityText}**{$otherEventsText}{$finalizedText}{$recoveryWarning}{$perDateAvailText}{$strengthBlock}

**Planungsregeln:**
- Starte den Plan ab heute ({$today})
- Plane GENAU jeden Tag von {$today} bis {$planEndDate} — das sind {$totalDays} Tage. Kein Tag nach {$planEndDate}.
- {$endRuleStandard}
- Passe die Intensität an den Zeitraum bis zum Rennen an: {$daysUntil} Tage
- Bei >30 Tagen: normaler Aufbau (Volumen + Tempo)
- Bei 10-30 Tagen: Tapering einleiten (Volumen reduzieren, Qualität halten)
- Bei <10 Tagen: starkes Tapering, nur leichte Läufe und Ruhetage
- Berücksichtige Wellbeing-Daten: schlechter Schlaf/hoher Stress → leichtere Einheiten
- Berücksichtige die Trainingsbelastung: TSB < −30 (Übermüdet) → Volumen stark reduzieren, mehr Ruhetage; TSB > +15 (zu frisch) → Volumen erhöhen
- Mindestens ein Ruhetag pro Woche
- WOCHENMUSTER: Die Wochenstruktur ist im Gerüst oben festgelegt und nicht verhandelbar. Deine Aufgabe ist, jede vorgegebene Einheit inhaltlich auszugestalten: beim easy_run konsequent Zone 2 (Unterhaltungstempo, kein „flotter" Dauerlauf), beim tempo_run einen klaren Schwellenabschnitt mit konkreten Minutenangaben, beim interval konkrete Wiederholungen mit Länge und Trabpause (z.B. „5×1000 m mit 400 m Trabpause"). Schreibe diese Angaben ausdrücklich in die description — daraus wird später die Schritteliste gebaut.
- A-Events: max. Leistungsoptimierung; C-Events: Trainingsrennen, moderate Belastung
- WICHTIG: Plane nur Tage von heute ({$today}) bis {$planEndDate}. Kein Tag nach {$planEndDate}.
- Lerne aus den Athleten-Bewertungen: niedrige Bewertungen (1-2⭐) oder hohe RPE (≥8) bei bestimmten Typen → weniger davon oder leichter planen; hohe Bewertungen (4-5⭐) → mehr davon
- Lerne aus vergangenen Rennergebnissen: Ziel verfehlt → mehr spezifisches Tempotraining für diese Distanz; Ziel erreicht/übertroffen → Plan funktioniert, ähnliche Struktur beibehalten
- ANDERE RENNEVENTS: An Tagen mit anderen Rennevents im Planungszeitraum IMMER type="rest" — der Athlet läuft ein Rennen, kein zusätzliches Training.
- VERFÜGBARKEIT: Plane Training AUSSCHLIESSLICH an verfügbaren Tagen. An nicht verfügbaren Tagen IMMER type="rest". Die GESAMTE Trainingsdauer eines Tages (bei zwei Einheiten die Summe) darf die angegebene Maximalzeit NIEMALS überschreiten. Tages-Ausnahmen haben Vorrang.
- ZIELORIENTIERUNG (wichtig): Plane ehrgeizig und zielgerichtet auf die Zielzeit {$targetTime} hin — mit klarer Progression aus Umfang und spezifischem Tempo an der Zielpace. Sei nicht unnötig konservativ, solange Wellbeing und Trainingsbelastung es zulassen. Zeigt der aktuelle Leistungsstand (Aktivitäten, Schwellenpace), dass die Zielzeit deutlich zu leicht ODER unrealistisch ist, spiegle das in der description der Schlüssel-Einheiten wider (was nötig ist, um das Ziel zu erreichen bzw. dass ein ehrgeizigeres Ziel drin wäre).
- DOPPEL-EINHEITEN: Welche Tage zwei Einheiten bekommen, steht im Wochengeruest oben. Erfinde keine zusaetzlichen. Bei zwei Eintraegen am selben "date": Tageszeit im title kennzeichnen ("Morgens: ...", "Abends: ..."), Morgen-Einheit zuerst, Summe beider duration_min <= Tages-Maximum, und niemals zwei harte Einheiten am selben Tag.
- PROGRESSIVE LÄUFE (progressive_run): Lauf beginnt in Zone 1–2 und steigert sich Kilometer für Kilometer bis Zone 3–4 gegen Ende. Ideal für Tempoaufbau ohne volle Belastung. Max. 1× pro Woche, nur in Build- und Peak-Phase, nicht im Tapering.
- TESTLÄUFE (test_run): 5k oder 10k Zeitversuch bei maximalem persönlichen Effort (Zone 4–5) — so schnell wie möglich über die gesamte Distanz. Zweck: objektive Fortschrittsmessung und automatische Neukalibrierung der Schwellenpace. Plane exakt alle 4–6 Wochen — niemals in den letzten 14 Tagen vor dem A-Event. Nach einem test_run folgt IMMER ein easy_run als Regeneration. Kündige den Testlauf im title-Feld deutlich an, z.B. "5k Zeitversuch".

**Antworte ausschließlich mit einem JSON-Array — in der Regel EIN Eintrag pro offenem Tag von heute ({$today}) bis {$planEndDate}. An Tagen mit viel Zeit sind ausnahmsweise ZWEI Einträge mit demselben "date" erlaubt (siehe Doppel-Einheiten-Regel). Bereits abgeschlossene Tage (siehe oben) NICHT zurückgeben. Ruhetage MÜSSEN als Eintrag mit type="rest" enthalten sein.**
[
  {
    "date": "YYYY-MM-DD",
    "type": "rest|easy_run|tempo_run|interval|long_run|progressive_run|test_run|strength|core|mobility|race_prep",
    "title": "Kurzer Titel (max 40 Zeichen)",
    "description": "Beschreibung der Einheit (2-3 Sätze, konkrete Anweisungen)",
    "distance_km": 0,
    "duration_min": 0,
    "pace_target": "5:30-6:00 oder null bei Ruhetag",
    "zone": 2,
    "intensity": "rest|low|medium|high",
    "exercises": [{ "name": "Goblet Squat", "sets": 3, "reps": "10", "load": "20 kg", "note": "" }]
  }
]
Für Ruhetage: distance_km=0, duration_min=0, pace_target=null, zone=null.
Nur bei strength/core/mobility das "exercises"-Array füllen; bei allen Lauf-Einheiten weglassen oder leer lassen.
PROMPT;
        }

        // gpt-5.5 is a reasoning model: internal reasoning tokens eat into the budget,
        // so a full multi-week plan needs a high cap or the completion comes back empty
        // (finish_reason: length). Runs in a queued job, so the longer timeout is fine.
        $text = $this->ai->chat('event_plan', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Antworte ausschließlich mit einem validen JSON-Array ohne zusätzlichen Text.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.6, 16000, 240);

        $sessions = $this->ai->jsonArray($text);

        if ($sessions) {
            Log::info('Event training plan generated', ['event_id' => $event->id, 'sessions' => count($sessions)]);

            return $sessions;
        }

        Log::warning('Event training plan parse failed', ['text' => $text]);

        return null;
    }

}

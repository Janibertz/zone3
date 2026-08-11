<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * Alle erzeugten Texte rund ums Training: Tagesempfehlung, Tagesnachricht,
 * Wochenrueckblick, Rennprognose, Renn-Strategie und -Analyse, Jahresrueckblick.
 */
class CoachingTextService
{
    use TalksToOpenAI;

    /**
     * Generate AI-powered training analysis for a goal
     *
     * @param array $goalData
     * @param array $progressData
     * @param array $recentActivities
     * @param mixed $wellbeingData
     * @return string
     */
    public function analyzeTraining(array $goalData, array $progressData, array $recentActivities, $wellbeingData = null): string
    {
        $prompt = $this->buildAnalysisPrompt($goalData, $progressData, $recentActivities, $wellbeingData);

        $content = $this->ai->chat('goal_analysis', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Gib kurze, ermutigende und actionable Trainingsanalysen auf Deutsch. Sei präzise und praktisch. Verwende Emojis für bessere Readability. Beachte die Wellbeing-Daten des Athleten und passe deine Empfehlungen entsprechend an.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.7, 900, 30, $this->ai->mini());

        return $content ?? 'KI-Analyse konnte nicht geladen werden.';
    }

    /**
     * Format status for display
     */
    protected function formatStatus(string $status): string
    {
        $statusMap = [
            'completed' => '✅ Abgeschlossen',
            'on_track_ahead' => '🚀 Voraus im Plan',
            'on_track' => '🎯 Im Plan',
            'behind' => '⚠️ Hinter dem Plan',
            'missed' => '❌ Verpasst',
        ];

        return $statusMap[$status] ?? $status;
    }

    /**
     * Build a German weather context block for coaching prompts.
     * Returns '' when no weather is available so prompts stay clean.
     */
    private function weatherContext(?array $weather, bool $coachingHint = true): string
    {
        if (! $weather || ! isset($weather['temp_c'])) {
            return '';
        }

        $parts = ["{$weather['description']}, {$weather['temp_c']}°C"];
        if (($weather['apparent_c'] ?? null) !== null && $weather['apparent_c'] !== $weather['temp_c']) {
            $parts[] = "gefühlt {$weather['apparent_c']}°C";
        }
        if (($weather['precip_prob'] ?? null) !== null) {
            $parts[] = "Regenwahrscheinlichkeit {$weather['precip_prob']}%";
        }
        if (($weather['wind_kmh'] ?? null) !== null) {
            $parts[] = "Wind {$weather['wind_kmh']} km/h";
        }
        $line = implode(', ', $parts);

        $hint = $coachingHint
            ? ' Berücksichtige das in der Empfehlung (bei Hitze >25°C langsamere Pace, Hydration, frühere Tageszeit; bei Kälte <2°C längeres Aufwärmen/Kleidung; bei Regen/Sturm/Gewitter Vorsicht oder Indoor-Alternative).'
            : '';

        return "Wetter heute am Trainingsort: {$line}.{$hint}\n";
    }

    public function generateTodayRecommendation(?array $runnerProfile, ?array $yesterdayActivity, ?array $wellbeingEntry, ?array $goal, array $progress, array $upcomingEvents = [], ?array $todayAvailability = null, ?array $weather = null, ?array $returnToRun = null): ?array
    {
        $profileText = $runnerProfile ? "Runner Profile:\n- LTHR: {$runnerProfile['threshold_heart_rate']} bpm\n- Max HR: {$runnerProfile['max_heart_rate']} bpm\n- Schwellenpace: {$runnerProfile['threshold_speed']} min/km\n" : "Kein Runner Profile vorhanden.\n";
        $activityText = $yesterdayActivity ? "Letzte Aktivität (gestern):\n- " . round($yesterdayActivity['distance']/1000,2) . " km in " . PaceFormat::hms($yesterdayActivity['moving_time']) . " · Pace: " . ($yesterdayActivity['average_speed'] ? PaceFormat::fromSpeed($yesterdayActivity['average_speed']) : '—') . "\n" : "Keine Aktivität von gestern.\n";
        $wellbeingText = $wellbeingEntry ? "Wellbeing heute: Energie {$wellbeingEntry['energy_level']}/10, Schlaf {$wellbeingEntry['sleep_quality']}/10, Muskelkater {$wellbeingEntry['muscle_soreness']}/10, Stress {$wellbeingEntry['stress_level']}/10\n" : "Kein Wellbeing.\n";
        $goalText = $goal ? "Ziel: {$goal['name']} (bis {$goal['end_date']})\n" : "Kein aktives Ziel.\n";

        // Upcoming events / taper logic
        $eventsText = '';
        $taperWarning = '';
        if (!empty($upcomingEvents)) {
            $lines = array_map(
                fn ($e) => "- {$e['name']} ({$e['distance_label']}) am {$e['event_date']} (in {$e['days_until']} Tagen)",
                $upcomingEvents
            );
            $eventsText = "Kommende Wettkämpfe:\n" . implode("\n", $lines) . "\n";

            $nearest = $upcomingEvents[0];
            $daysUntil = $nearest['days_until'];
            if ($daysUntil <= 1) {
                $taperWarning = "WICHTIG: Wettkampf morgen oder heute! Kein Training — nur lockeres Einlaufen erlaubt. Empfehle rest oder sehr kurzes easy_run.\n";
            } elseif ($daysUntil <= 3) {
                $taperWarning = "WICHTIG: Wettkampf in {$daysUntil} Tagen! Nur leichtes Regenerationstraining, kein intensiver Reiz. Maximal easy_run, Zone 1–2.\n";
            } elseif ($daysUntil <= 7) {
                $taperWarning = "WICHTIG: Wettkampf in {$daysUntil} Tagen — Tapering-Phase! Kein hartes Training (kein interval, kein tempo_run, kein progressive_run, kein test_run). Nur easy_run oder rest.\n";
            }
        } else {
            $eventsText = "Keine kommenden Wettkämpfe.\n";
        }

        // Today's availability
        $availabilityText = '';
        if ($todayAvailability !== null) {
            if ($todayAvailability['available'] ?? false) {
                $maxMin = $todayAvailability['duration_min'] ?? null;
                $availabilityText = $maxMin
                    ? "Heutige Verfügbarkeit: maximal {$maxMin} Minuten. Passe duration_min entsprechend an.\n"
                    : "Heute verfügbar für Training.\n";
            } else {
                $availabilityText = "Heute laut Profil kein Training geplant. Empfehle rest.\n";
            }
        }

        $weatherText = $this->weatherContext($weather);

        // Return-to-run build-up: today's session must respect the current step.
        $returnToRunText = '';
        if ($returnToRun && isset($returnToRun['step'])) {
            $label = $returnToRun['trigger_label'] ?? 'Pause';
            $step  = (int) $returnToRun['step'];
            $total = (int) ($returnToRun['total_steps'] ?? 5);
            if ($step >= $total) {
                $returnToRunText = "WIEDEREINSTIEG (nach {$label}): Stufe {$step}/{$total} — der Athlet ist zurück im Normalbetrieb, normale Intensität ist wieder möglich.\n";
            } else {
                $c = $returnToRun['current'] ?? [];
                $returnToRunText = "WICHTIG — WIEDEREINSTIEG nach {$label} (Stufe {$step} von {$total}): Der Athlet baut nach einer Pause behutsam wieder auf. "
                    . "Die heutige Empfehlung MUSS dieser Stufe entsprechen: type=\"" . ($c['type'] ?? 'easy_run') . "\", Zone " . ($c['zone'] ?? '1–2') . ", maximal " . ($c['max_min'] ?? 30) . " Minuten. "
                    . ($c['rule'] ?? '') . " Diese Vorgabe hat Vorrang vor anderen Überlegungen (außer ein Ruhetag ist nötig).\n";
            }
        }

        $prompt = <<<PROMPT
Du bist ein präziser Lauf-Coach. Erstelle eine Trainingsempfehlung für heute als JSON-Objekt.

{$profileText}
{$activityText}
{$wellbeingText}
{$goalText}
{$eventsText}
{$taperWarning}
{$availabilityText}
{$returnToRunText}
{$weatherText}
Antworte NUR mit einem JSON-Objekt (kein Markdown, kein Text davor/danach):
{
  "type": "easy_run|tempo_run|interval|long_run|progressive_run|test_run|rest",
  "title": "Kurzer Titel der Einheit",
  "description": "2-3 Sätze Erklärung warum und wie. Nur auf bevorstehende Wettkämpfe eingehen, keine vergangenen Ereignisse erwähnen.",
  "distance_km": 8.0,
  "duration_min": 50,
  "pace_target": "5:30",
  "zone": 2,
  "intensity": "low|medium|high"
}
PROMPT;

        $content = $this->ai->chat('recommendation', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Antworte ausschließlich mit dem angeforderten JSON-Objekt.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.4, 1000, 30, $this->ai->mini());

        if (!$content) return null;

        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);
        $parsed  = json_decode(trim($content), true);

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Adjust an existing structured recommendation harder or softer.
     * Returns adjusted recommendation array or null on failure.
     */
    public function adjustTodayRecommendation(array $current, string $direction, ?array $runnerProfile, ?array $wellbeingEntry): ?array
    {
        $directionText = $direction === 'harder'
            ? 'Mache die Einheit HÄRTER: mehr Distanz (+15-25%), schnellere Pace, höhere Zone, oder Wechsel zu einem intensiveren Typ (z.B. easy_run → tempo_run).'
            : 'Mache die Einheit SOFTER: weniger Distanz (-15-25%), langsamere Pace, niedrigere Zone, oder Wechsel zu einem ruhigeren Typ (z.B. tempo_run → easy_run).';

        $wellbeingText = $wellbeingEntry ? "Wellbeing: Energie {$wellbeingEntry['energy_level']}/10, Muskelkater {$wellbeingEntry['muscle_soreness']}/10\n" : '';
        $profileText = $runnerProfile ? "Schwellenpace: {$runnerProfile['threshold_speed']} min/km\n" : '';

        $currentJson = json_encode($current, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Aktuelle Einheit:
{$currentJson}

{$profileText}{$wellbeingText}
Aufgabe: {$directionText}

Antworte NUR mit dem angepassten JSON-Objekt (gleiche Felder wie die Eingabe):
PROMPT;

        $content = $this->ai->chat('adjust_recommendation', [
            ['role' => 'system', 'content' => 'Du bist ein Lauf-Coach. Antworte ausschließlich mit dem angeforderten JSON-Objekt.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.3, 1000, 30, $this->ai->mini());

        if (!$content) return null;

        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);
        $parsed  = json_decode(trim($content), true);

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Generate a short daily motivational message from the coach.
     * Considers today's session type, upcoming events, and coach personality.
     * Returns a plain string (1–2 sentences) or null on failure.
     */
    public function generateDailyMessage(?string $sessionType, ?string $sessionTitle, ?array $upcomingEvents, ?array $weather = null): ?string
    {
        $sessionText = $sessionType
            ? "Heutige geplante Einheit: {$sessionTitle} (Typ: {$sessionType}).\n"
            : "Heute kein spezifisches Training geplant.\n";

        $eventsText = '';
        if (!empty($upcomingEvents)) {
            $nearest = $upcomingEvents[0];
            $eventsText = "Nächster Wettkampf: {$nearest['name']} in {$nearest['days_until']} Tagen.\n";
        }

        $weatherText = $this->weatherContext($weather, false);

        $prompt = <<<PROMPT
{$sessionText}{$eventsText}{$weatherText}
Schreib eine kurze, motivierende Botschaft (1–2 Sätze) für den Läufer für heute. Sprich ihn direkt an (du). Beziehe das Wetter nur ein, wenn es bemerkenswert ist (sehr warm, kalt, Regen, Sturm). Kein Emoji, keine Anführungszeichen. Nur den reinen Text.
PROMPT;

        $content = $this->ai->chat('daily_message', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Du bist ein ermutigender Lauf-Coach. Antworte nur mit dem reinen Motivationstext.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.8, 700, 30, $this->ai->mini());

        return $content ? trim($content) : null;
    }

    /**
     * Generate a weekly review for the athlete (runs every Monday, cached in DB).
     */
    public function generateWeeklyReview(\App\Models\User $user, string $weekStart, string $weekEnd): ?string
    {
        // Strava activities this week (ground truth – one entry per actual workout)
        $activities = $user->activities()
            ->whereBetween(\DB::raw('DATE(start_date)'), [$weekStart, $weekEnd])
            ->orderBy('start_date')
            ->get();

        // Build a map: strava activity_id → linked training session (for rating/RPE)
        $linkedActivityIds = $activities->pluck('id')->filter()->all();
        $sessionsByActivity = \App\Models\TrainingSession::where('user_id', $user->id)
            ->whereIn('activity_id', $linkedActivityIds)
            ->get()
            ->keyBy('activity_id');

        // Skipped plan sessions (no Strava activity for that day)
        $skipped = \App\Models\TrainingSession::where('user_id', $user->id)
            ->where('status', 'skipped')
            ->whereBetween('planned_date', [$weekStart, $weekEnd])
            ->count();

        // Wellbeing this week
        $wellbeing = $user->wellbeingEntries()
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->get();

        if ($activities->isEmpty() && $wellbeing->isEmpty()) {
            return null; // Nothing to review
        }

        // Build session summary from Strava activities (correct names, no duplicates)
        $totalKm  = $activities->sum(fn ($a) => ($a->distance ?? 0) / 1000);
        $totalMin = $activities->sum(fn ($a) => round(($a->moving_time ?? 0) / 60));

        $sessionLines = $activities->map(function ($a) use ($sessionsByActivity) {
            $session = $sessionsByActivity[$a->id] ?? null;
            $km  = number_format(($a->distance ?? 0) / 1000, 1);
            $min = round(($a->moving_time ?? 0) / 60);
            $day = $a->start_date->format('D');
            $extra = '';
            if ($session) {
                if ($session->rating)           $extra .= ", Bewertung: {$session->rating}/5⭐";
                if ($session->effort_perceived) $extra .= ", RPE {$session->effort_perceived}/10";
            }
            return "- [{$day}] {$a->name}: {$km} km, {$min} min{$extra}";
        })->implode("\n");

        $wellbeingLines = '';
        if ($wellbeing->isNotEmpty()) {
            $avgE = round($wellbeing->avg('energy_level'), 1);
            $avgS = round($wellbeing->avg('sleep_quality'), 1);
            $avgM = round($wellbeing->avg('muscle_soreness'), 1);
            $sick = $wellbeing->where('is_sick', true)->count();
            $wellbeingLines = "Wellbeing Ø: Energie {$avgE}/10 | Schlaf {$avgS}/10 | Muskelkater {$avgM}/10" . ($sick > 0 ? " | {$sick} Krankheitstage" : '');
        }

        $totalKmFormatted = number_format($totalKm, 1);

        $prompt = <<<PROMPT
Du bist Lauf-Coach. Schreibe einen kurzen, motivierenden Wochenrückblick für deinen Athleten. Sei direkt, konkret und ehrlich — weder überschwänglich noch demotivierend.

**Trainingswoche {$weekStart} – {$weekEnd}:**
{$sessionLines}
Übersprungene Einheiten: {$skipped}
Gesamt: {$totalKmFormatted} km / {$totalMin} min

{$wellbeingLines}

**Dein Review (max. 150 Wörter):**
- Was lief gut diese Woche?
- Was fiel auf (Belastung, Wellbeing, Konstanz)?
- Eine konkrete Empfehlung für die kommende Woche

Schreibe fließend, kein JSON, kein Markdown mit #-Überschriften. Direkte Ansprache (du).
PROMPT;

        $this->forUser($user->id);

        $text = $this->ai->chat('weekly_review', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Antworte auf Deutsch, kurz und präzise.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.7, 1000, 60, $this->ai->mini());

        return ($text && trim($text) !== '') ? trim($text) : null;
    }

    /**
     * Generate a short celebratory PR message from the coach.
     */
    public function generatePrMessage(\App\Models\Activity $activity): ?string
    {
        $km      = number_format(($activity->distance ?? 0) / 1000, 2);
        $min     = round(($activity->moving_time ?? 0) / 60);
        $h       = (int) ($min / 60);
        $m       = $min % 60;
        $timeStr = $h > 0 ? "{$h}h {$m}min" : "{$m}min";
        $name    = $activity->name;

        $prompt = "Der Athlet hat gerade einen neuen persönlichen Rekord aufgestellt: \"{$name}\" – {$km} km in {$timeStr}. " .
                  "Schreib eine kurze, enthusiastische Glückwunschbotschaft als Coach (2–3 Sätze, direkte Anrede, auf Deutsch, mit passendem Emoji).";

        return $this->ai->chat('coach_pr', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Du bist ein begeisterter Lauf-Coach. Feiere echte Leistungen deines Athleten.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.9, 700, 30, $this->ai->mini());
    }

    /**
     * Generate a short AI coaching recommendation based on the race prediction.
     *
     * @param array $predictionData  Output from PredictFinishTimeService::predict()
     * @param array $eventData       ['name', 'race_distance', 'target_time_formatted', 'days_until']
     * @param array $recentSessions  Last 5 completed/planned sessions [{type, distance_km, status}]
     * @return string|null
     */
    public function generateRacePredictionText(
        array  $predictionData,
        array  $eventData,
        array  $recentSessions = [],
    ): ?string {
        $predicted = $predictionData['predicted_finish_time'];
        $pace      = $predictionData['predicted_pace'];
        $trend     = $predictionData['prediction_trend'];
        $deltaSec  = $predictionData['prediction_target_delta_sec'] ?? null;
        $runCount  = $predictionData['prediction_run_count'];

        $trendText = match ($trend) {
            'improving' => 'Der Athlet verbessert sich – aktuelle Läufe sind schneller als ältere.',
            'declining' => 'Die Leistung sinkt leicht – aktuelle Läufe sind langsamer als ältere.',
            default     => 'Die Leistung ist stabil.',
        };

        $deltaText = '';
        if ($deltaSec !== null) {
            $absSec    = abs($deltaSec);
            $h         = (int)($absSec / 3600);
            $m         = (int)(($absSec % 3600) / 60);
            $s         = $absSec % 60;
            $formatted = $h > 0
                ? sprintf('%d:%02d:%02d', $h, $m, $s)
                : sprintf('%d:%02d', $m, $s);
            $deltaText = $deltaSec >= 0
                ? "Die Prognose liegt {$formatted} unter der Zielzeit – der Athlet ist auf Kurs."
                : "Der Athlet liegt {$formatted} hinter der Zielzeit zurück.";
        }

        $sessionsText = '';
        if (! empty($recentSessions)) {
            $lines = array_map(fn ($s) => "- {$s['type']} ({$s['distance_km']} km, Status: {$s['status']})", $recentSessions);
            $sessionsText = "\nLetzte Trainingseinheiten:\n" . implode("\n", $lines);
        }

        $prompt = <<<PROMPT
Du bist ein persönlicher Lauf-Coach. Schreibe eine kurze, motivierende Empfehlung (2–3 Sätze) auf Deutsch.

Event: {$eventData['name']} ({$eventData['race_distance']}) in {$eventData['days_until']} Tagen
Zielzeit: {$eventData['target_time_formatted']}
Prognostizierte Finishzeit: {$predicted} (Pace: {$pace}/km)
Basiert auf: {$runCount} Läufen der letzten 90 Tage
Trend: {$trendText}
{$deltaText}{$sessionsText}

Schreibe die Empfehlung direkt (kein "Hallo", keine Einleitung). Maximal 3 Sätze. Sei konkret: Nenne welche Trainingsart hilft und warum. Keine Emojis.
PROMPT;

        return $this->ai->chat('race_prediction_text', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Du bist ein erfahrener Lauf-Coach. Antworte immer auf Deutsch. Sei direkt, sachlich und motivierend. Keine Emojis. Max. 3 Sätze.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.6, 800, 45, $this->ai->mini());
    }

    /**
     * Race-day pacing & fueling strategy text.
     *
     * @param array       $eventData ['name', 'race_distance', 'target_time_formatted', 'days_until']
     * @param string      $goalPace  e.g. "5:00"
     * @param array|null  $weather   Output of WeatherService::forUser(), optional
     */
    public function generateRaceStrategy(array $eventData, string $goalPace, ?array $weather = null): ?string
    {
        $weatherText = '';
        if ($weather && isset($weather['temp_c'])) {
            $weatherText = "\nWetter am Ort: {$weather['description']}, {$weather['temp_c']}°C"
                . (($weather['precip_prob'] ?? null) !== null ? ", Regen {$weather['precip_prob']}%" : '')
                . (($weather['wind_kmh'] ?? null) !== null ? ", Wind {$weather['wind_kmh']} km/h" : '')
                . '.';
        }

        $prompt = <<<PROMPT
Du bist ein persönlicher Lauf-Coach. Schreibe eine kompakte Renntag-Strategie (3–5 Sätze) auf Deutsch.

Wettkampf: {$eventData['name']} ({$eventData['race_distance']}) in {$eventData['days_until']} Tagen
Zielzeit: {$eventData['target_time_formatted']}
Zielpace: {$goalPace}/km{$weatherText}

Gehe konkret ein auf: (1) Pacing — gleichmäßig oder leicht negativer Split, erste Kilometer bewusst kontrolliert; (2) Verpflegung passend zur Distanz (bei Halbmarathon/Marathon Gels/Kohlenhydrate + Trinken, bei 5–10 km kaum nötig); (3) ein mentaler Cue für die harte Phase. Falls Wetter genannt und relevant ist (Hitze/Kälte/Regen/Wind), gib einen kurzen Hinweis. Direkt, ohne Einleitung, keine Emojis.
PROMPT;

        return $this->ai->chat('race_strategy', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Du bist ein erfahrener Lauf-Coach. Antworte auf Deutsch, direkt und konkret. Keine Emojis. Max. 5 Sätze.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.6, 900, 45, $this->ai->mini());
    }

    /**
     * Post-race analysis text comparing target vs. actual and pacing.
     *
     * @param array       $eventData  ['name', 'race_distance']
     * @param string|null $targetTime formatted target time (or null)
     * @param array       $actual     ['time', 'pace', 'distance_km', 'splits_text'?]
     */
    public function generateRaceAnalysis(array $eventData, ?string $targetTime, array $actual): ?string
    {
        $targetText = $targetTime ? "Zielzeit: {$targetTime}" : 'Keine Zielzeit gesetzt';
        $splitsText = ! empty($actual['splits_text']) ? "\nSplits (km-weise):\n{$actual['splits_text']}" : '';

        $prompt = <<<PROMPT
Du bist ein persönlicher Lauf-Coach. Schreibe eine Renn-Auswertung (4–6 Sätze) auf Deutsch.

Wettkampf: {$eventData['name']} ({$eventData['race_distance']})
{$targetText}
Tatsächliche Zeit: {$actual['time']} (Pace {$actual['pace']}/km, {$actual['distance_km']} km){$splitsText}

Werte konkret aus: (1) Ziel vs. Ist — Ziel erreicht? Wie groß die Abweichung; (2) Pacing-Konsistenz — gleichmäßig gelaufen oder am Ende eingebrochen (nutze die Splits, falls vorhanden); (3) was gut lief; (4) 1–2 konkrete Learnings fürs nächste Rennen. Direkt, ehrlich und motivierend. Keine Emojis.
PROMPT;

        return $this->ai->chat('race_analysis', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Du bist ein erfahrener Lauf-Coach. Antworte auf Deutsch, ehrlich und konstruktiv. Keine Emojis. 4–6 Sätze.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.6, 1000, 45, $this->ai->mini());
    }

    /**
     * Personal "Wrapped" retrospective text for a period.
     *
     * @param array  $stats       Output of WrappedService::generate()
     * @param string $periodLabel e.g. "2026" or "Juni 2026"
     */
    public function generateWrappedReview(array $stats, string $periodLabel): ?string
    {
        $t = $stats['totals'] ?? [];
        $lines = [
            "Zeitraum: {$periodLabel}",
            'Läufe: ' . ($t['runs'] ?? 0) . ', Distanz: ' . ($t['km'] ?? 0) . ' km, Zeit: ' . ($t['hours'] ?? 0)
                . ' h, Höhenmeter: ' . ($t['elevation'] ?? 0) . ' m, aktive Tage: ' . ($t['active_days'] ?? 0),
        ];
        if (! empty($stats['longest_run']))      $lines[] = "Längster Lauf: {$stats['longest_run']['km']} km";
        if (! empty($stats['fastest_run']))      $lines[] = "Schnellster Lauf: Pace {$stats['fastest_run']['pace']}/km über {$stats['fastest_run']['km']} km";
        if (! empty($stats['favorite_weekday'])) $lines[] = "Lieblings-Wochentag: {$stats['favorite_weekday']['label']}";
        if (! empty($stats['longest_streak']))   $lines[] = "Längste Serie: {$stats['longest_streak']} Tage in Folge";
        if (! empty($stats['prs']['count']))     $lines[] = "Neue persönliche Rekorde: {$stats['prs']['count']}";
        if (! empty($stats['vs_previous'])) {
            $d = $stats['vs_previous']['delta_pct'];
            $lines[] = "Vergleich zu {$stats['vs_previous']['prev_label']}: " . ($d >= 0 ? "+{$d}" : $d) . '% km';
        }
        $data = implode("\n", $lines);

        $prompt = <<<PROMPT
Daten des Athleten für den Rückblick:
{$data}

Schreibe einen kurzen, persönlichen und motivierenden Rückblick (3–5 Sätze) auf Deutsch. Sprich den Athleten direkt an (du). Hebe 1–2 Highlights hervor und schließe ermutigend ab. Keine Aufzählung, kein Markdown, nur Fließtext.
PROMPT;

        return $this->ai->chat('wrapped_review', [
            ['role' => 'system', 'content' => $this->ai->systemPrompt('Du bist ein motivierender Lauf-Coach. Antworte auf Deutsch, warm und persönlich. Nur Fließtext.')],
            ['role' => 'user',   'content' => $prompt],
        ], 0.8, 900, 45, $this->ai->mini());
    }

    /**
     * Generate a German plain-language summary of a GitHub push for the admin wiki changelog.
     */
    public function generateChangelogSummary(array $commits, array $filesChanged): ?string
    {
        $commitLines = implode("\n", array_map(
            fn ($c) => "- [{$c['id']}] {$c['message']} (von {$c['author']})",
            array_slice($commits, 0, 10)
        ));
        $fileLines = implode(', ', array_slice($filesChanged, 0, 15));
        if (count($filesChanged) > 15) {
            $fileLines .= ' … (' . count($filesChanged) . ' Dateien gesamt)';
        }

        $prompt = <<<PROMPT
Du bist der technische Dokumentations-Bot für Zone3, eine Laravel/Vue.js Lauf-Trainingsplattform.

Fasse diese GitHub-Push-Zusammenfassung auf Deutsch zusammen:

Commits:
{$commitLines}

Geänderte Dateien: {$fileLines}

Schreibe eine kompakte, klare Zusammenfassung (3-5 Sätze) die erklärt:
1. Was wurde geändert (technisch, aber verständlich)
2. Was bedeutet das für den Nutzer / die Plattform
3. Welche Bereiche des Systems sind betroffen

Schreibe auf Deutsch, direkt und ohne Floskeln. Keine Überschrift, nur der Text.
PROMPT;

        return $this->ai->chat('changelog_summary', [
            ['role' => 'system', 'content' => 'Du bist ein technischer Dokumentations-Assistent. Antworte nur mit der reinen Zusammenfassung auf Deutsch.'],
            ['role' => 'user',   'content' => $prompt],
        ], 0.4, 1200, 30, $this->ai->mini());
    }

    /**
     * Build the prompt for training analysis
     */
    protected function buildAnalysisPrompt(array $goalData, array $progressData, array $recentActivities, $wellbeingData = null): string
    {
        $activitiesSummary = $this->summarizeActivities($recentActivities);
        $wellbeing = $this->formatWellbeingData($wellbeingData);

        return <<<PROMPT
Analysiere bitte das folgende Trainingsfortschritt und gib eine ermutigende, actionable Analyse:

**Ziel:** {$goalData['name']}
- Zielstrecke: {$progressData['target_distance_km']} km
- Aktueller Fortschritt: {$progressData['completed_distance_km']} km ({$progressData['progress_percentage']}%)
- Status: {$this->formatStatus($progressData['status'])}
- Restzeit: {$progressData['days_remaining']} Tage
- Trainingseinheiten: {$progressData['activities_count']}

**Letzte Aktivitäten:**
{$activitiesSummary}

**Wellbeing Status:**
{$wellbeing}

Bitte gib eine kurze (max 3-4 Sätze), ermutigende Analyse mit:
1. Bewertung des aktuellen Fortschritts (beachte Wellbeing-Status)
2. Einen praktischen Tipp für die nächsten Tage (angepasst an Energie/Verletzung)
3. Motivierende Worte

Benutze Emojis und halte es prägnant!
PROMPT;
    }

    /**
     * Format wellbeing data for prompt
     */
    protected function formatWellbeingData($wellbeingData): string
    {
        if (!$wellbeingData) {
            return 'Keine Wellbeing-Daten erfasst.';
        }

        $status = $wellbeingData->getStatus();
        $score = $wellbeingData->getWellbeingScore();
        
        $data = "- Status: $status (Score: $score/10)\n";
        $data .= "- Energielevel: {$wellbeingData->energy_level}/10\n";
        $data .= "- Stimmung: {$wellbeingData->mood}/10\n";
        $data .= "- Schlafqualität: {$wellbeingData->sleep_quality}/10\n";
        $data .= "- Muskelkater: {$wellbeingData->muscle_soreness}/10\n";
        $data .= "- Stress-Level: {$wellbeingData->stress_level}/10\n";
        
        if ($wellbeingData->is_sick) {
            $data .= "- ⚠️ Athlet ist krank\n";
        }
        if ($wellbeingData->is_injured) {
            $data .= "- ⚠️ Athlet ist verletzt\n";
        }
        if ($wellbeingData->notes) {
            $data .= "- Notizen: {$wellbeingData->notes}\n";
        }

        return $data;
    }

    /**
     * Summarize recent activities
     */
    protected function summarizeActivities(array $activities): string
    {
        if (empty($activities)) {
            return 'Keine Aktivitäten vorhanden.';
        }

        $summary = [];
        foreach (array_slice($activities, 0, 3) as $activity) {
            $distance = $activity['distance'] / 1000; // meters to km
            $pace = PaceFormat::fromSpeed($activity['average_speed']);
            $summary[] = sprintf("- %s: %.2f km, Pace: %s", $activity['name'], $distance, $pace);
        }

        return implode("\n", $summary);
    }
}

<?php

namespace App\Jobs;

use App\Models\CoachMessage;
use App\Models\TrainingSession;
use App\Services\AI\SessionContentService;
use App\Services\TrainingLoadService;
use App\Services\WeatherService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use App\Services\PaceFormat;
use App\Services\Review\SessionHighlights;

/**
 * Generate the coach's post-session review from the real data of a completed
 * session: actual metrics vs. the athlete's usual baseline, aerobic decoupling
 * (HR drift), wellbeing, training load and weather. The coach highlights
 * anything unusual and — when a deviation has several plausible causes — asks a
 * short follow-up question (answered later, stored to coach_notes).
 *
 * Runs off the request/webhook cycle (OpenAI + weather HTTP). Idempotent:
 * exits immediately if the session was already reviewed.
 */
class GenerateSessionReviewJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 240; // Flex: doppeltes Zeitlimit plus ein Versuch auf Standard

    public function __construct(
        public readonly int $sessionId,
    ) {}

    public function handle(
        SessionContentService $sessions,
        WeatherService $weather,
        TrainingLoadService $loadService,
    ): void {
        $session = TrainingSession::with(['user.coach', 'activity'])->find($this->sessionId);
        if (! $session) return;

        // Only completed real sessions get a review; skip rest days and re-runs.
        if ($session->status !== 'completed' || $session->type === 'rest') return;
        if ($session->reviewed_at !== null) return;

        $user = $session->user;
        if (! $user) return;

        $activity = $session->activity;

        // Persist the weather snapshot at training time once (used here + shown in UI).
        if ($activity && empty($activity->weather)) {
            $snapshot = $weather->forActivity($activity);
            if ($snapshot) {
                $activity->update(['weather' => $snapshot]);
            }
        }

        // Spaziergang, Yoga, kurzes Wandern: Alltag, kein Training. Ein
        // Trainer schreibt dazu nichts (Jan, 12.11.2026).
        $minutes = $activity && $activity->moving_time > 0 ? (int) round($activity->moving_time / 60) : null;
        if (SessionHighlights::silent($session->sport_type ?? $activity?->type, $minutes)) {
            $session->update(['reviewed_at' => now()]);

            return;
        }

        $result = $this->draft($session, $sessions, $loadService);

        if (! $result) {
            Log::warning('Session review generation returned null', ['session_id' => $session->id]);
            return;
        }

        $session->update([
            'coach_review'    => $result['review'],
            'review_question' => $result['question'],
            'review_options'  => ! empty($result['options']) ? $result['options'] : null,
            'reviewed_at'     => now(),
        ]);

        // Surface in the coach chat as an assistant message (natural dialogue).
        // Die Rückfrage trägt ihre Einheit mit, damit Telegram die Antworten
        // als Knöpfe zeigen kann ({@see \App\Observers\CoachMessageObserver}).
        $chatContent = $result['review'];
        if ($result['question']) {
            $chatContent .= "\n\n" . $result['question'];
        }
        CoachMessage::create([
            'user_id' => $user->id,
            'role'    => 'assistant',
            'content' => $chatContent,
            'actions' => $result['question']
                ? [['type' => CoachMessage::ACTION_REVIEW_QUESTION, 'session_id' => $session->id]]
                : null,
        ]);

        // Die Benachrichtigung haengt am Anlegen der Chat-Nachricht
        // (CoachMessageObserver) — hier waere sie die zweite.
    }

    /**
     * Die Nachricht, ohne sie zu speichern — für den Job und für
     * `review:compare`, der alt und neu nebeneinanderlegt.
     *
     * @return array{review:string, question:?string, options:array<string>, highlight:string}|null
     */
    public function draft(TrainingSession $session, SessionContentService $sessions, TrainingLoadService $loadService): ?array
    {
        $user      = $session->user;
        $facts     = $this->buildFacts($session, $loadService);
        $label     = $this->sessionLabel($session);
        $highlight = SessionHighlights::pick($this->signals($session));
        $context   = $this->coachContext($session);

        $result = $sessions
            ->withCoach($user->coach?->personality_prompt)
            ->forUser($user->id)
            ->generateSessionReview($user, $label, $highlight, $context, $facts);

        return $result ? [...$result, 'highlight' => $highlight['key']] : null;
    }

    /**
     * Kurzbezeichnung der Einheit, z.B. "Langer Lauf – 19,6 km".
     *
     * Bei einer Fremdsportart steht die Sportart vorn statt des
     * Trainingstyps. Der Typ ist dort ohnehin nur ein Platzhalter aus dem
     * Import ("easy_run"), und genau der landete bisher im Etikett: der
     * Coach begruesste eine Schwimmeinheit als "Lockerer Lauf".
     */
    private function sessionLabel(TrainingSession $s): string
    {
        $bits = [$s->isRun() ? ($this->typeLabels()[$s->type] ?? $s->type) : $s->sportLabel()];

        if ($s->title) {
            $bits[] = $s->title;
        }

        return implode(' – ', array_unique($bits));
    }

    /**
     * Assemble the data block the coach reasons over: planned target, actual
     * metrics, baseline comparison for this session type, HR drift, wellbeing,
     * training load, weather and the athlete's own rating.
     */
    private function buildFacts(TrainingSession $s, TrainingLoadService $loadService): string
    {
        $lines = [];
        $typeLabel = $this->typeLabels()[$s->type] ?? $s->type;

        // ── Die Sportart ─────────────────────────────────────────────────
        // Alles, was nicht Laufen ist, kam aus dem Import mit dem Platzhalter
        // "easy_run" herein. Ohne diese Zeile hielt der Coach jede
        // Schwimmeinheit fuer einen Lauf und fragte entsprechend.
        if (! $s->isRun()) {
            // Nur Fakten, keine Anweisungen: was hier steht, liest das
            // Modell gern vor (12.11.2026). Die Regeln stehen im Prompt.
            $lines[] = 'Sportart: ' . $s->sportLabel() . ' (kein Lauf; Pace und Laufzonen sagen hier nichts)';
            $typeLabel = $s->sportLabel();
        }

        // ── Plan oder nicht ──────────────────────────────────────────────
        // Die Felder distance_km/duration_min/pace_target tragen nach dem
        // Strava-Import die TATSAECHLICHEN Werte. Was geplant war, steht im
        // Schnappschuss, den der Import vorher anlegt. Ohne ihn stand hier
        // zweimal dieselbe Zahl, und eine Abweichung war unsichtbar.
        $snapshot = $s->planned_snapshot;

        if ($s->isCrossTraining()) {
            // Fremdsport steht nie im Laufplan — ihn als "ungeplant" zu
            // fuehren, liest sich wie eine Planabweichung. Das war er nicht.
            $lines[] = 'Plan: Ergänzung zum Laufen, steht nicht im Laufplan (das ist normal)';

            if (($snapshot['type'] ?? null) === 'rest') {
                $lines[] = 'Plan: an diesem Tag war ein Ruhetag vorgesehen';
            }
        } elseif ($s->was_unplanned) {
            $wasRest = ($snapshot['type'] ?? null) === 'rest';
            $lines[] = $wasRest
                ? 'Plan: ungeplant, an diesem Tag war ein Ruhetag vorgesehen'
                : 'Plan: ungeplant, an diesem Tag stand nichts im Plan';
        } elseif ($snapshot) {
            $plannedType = $this->typeLabels()[$snapshot['type'] ?? ''] ?? ($snapshot['type'] ?? $typeLabel);

            $planned = [];
            if (! empty($snapshot['distance_km']))  $planned[] = "{$snapshot['distance_km']} km";
            if (! empty($snapshot['duration_min'])) $planned[] = "{$snapshot['duration_min']} min";
            if (! empty($snapshot['pace_target']))  $planned[] = "Pace {$snapshot['pace_target']}";
            if (! empty($snapshot['zone']))         $planned[] = "Zone {$snapshot['zone']}";

            $lines[] = 'Plan: geplante Einheit';
            $lines[] = 'Geplant war: ' . $plannedType . (empty($planned) ? '' : ' (' . implode(', ', $planned) . ')');
        } else {
            // Aeltere Einheiten, die vor Einfuehrung des Schnappschusses
            // importiert wurden. Lieber offen sagen als etwas behaupten.
            $lines[] = 'Plan: geplante Einheit, die ursprünglichen Planwerte liegen nicht mehr vor';
        }

        $activity = $s->activity;
        if ($activity) {
            $km   = $activity->distance > 0 ? round($activity->distance / 1000, 2) : null;
            $min  = $activity->moving_time > 0 ? (int) round($activity->moving_time / 60) : null;
            // Eine Pace in min/km ist beim Schwimmen und Radfahren keine
            // Groesse, die der Coach einordnen kann.
            $pace = $s->isRun() ? $this->paceFromSpeed((float) $activity->average_speed) : null;
            $act  = [];
            // Schwimmer denken in Metern. "1.35 km" liest sich wie eine
            // Laufdistanz und lud frueher dazu ein, sie als solche zu werten.
            if ($km) {
                $act[] = in_array($s->sport_type, ['Swim', 'OpenWaterSwim'], true)
                    ? round($km * 1000) . ' m'
                    : "{$km} km";
            }
            if ($min) $act[] = "{$min} min";
            if ($pace) $act[] = "Ø-Pace {$pace} min/km";
            if ($activity->average_heartrate) $act[] = 'Ø-Puls ' . (int) $activity->average_heartrate . ' bpm';
            if ($activity->max_heartrate)     $act[] = 'Max-Puls ' . (int) $activity->max_heartrate . ' bpm';
            if ($activity->total_elevation_gain) $act[] = round($activity->total_elevation_gain) . ' hm';
            $lines[] = 'Absolviert: ' . (empty($act) ? 'keine Detaildaten' : implode(', ', $act));

            // Die Abweichung wird ausgerechnet, nicht geschaetzt. Ein
            // Sprachmodell, das "12,4 km" und "11 km" gegenueberstellt,
            // verrechnet sich zuverlaessig irgendwann.
            if (! $s->was_unplanned && $snapshot) {
                $deltas = $this->planDeltas($snapshot, $km, $min, (float) $activity->average_speed);
                $lines[] = $deltas
                    ? 'Gegen den Plan: ' . implode(', ', $deltas)
                    : 'Gegen den Plan: wie geplant umgesetzt';
            }

            // Baseline: same session type over the last 90 days (excluding this one).
            $baseline = $this->baselineFor($s);
            if ($baseline) {
                $b = [];
                if ($baseline['avg_hr'] && $activity->average_heartrate) {
                    $delta = (int) round($activity->average_heartrate - $baseline['avg_hr']);
                    $sign  = $delta > 0 ? "+{$delta}" : (string) $delta;
                    $b[] = "Ø-Puls sonst {$baseline['avg_hr']} bpm (heute {$sign} bpm)";
                }
                if ($baseline['avg_pace'] && $pace) {
                    $b[] = "Ø-Pace sonst {$baseline['avg_pace']} min/km";
                }
                if ($b) {
                    $lines[] = "Dein Normalwert bei {$typeLabel} (letzte {$baseline['count']} Einheiten): " . implode(', ', $b);
                }
            }

            // Aerobic decoupling / HR drift across the run (first vs. second half).
            $drift = $this->hrDrift($activity->laps ?? []);
            if ($drift) {
                $lines[] = "Puls-Verlauf: 1. Hälfte Ø {$drift['first']} bpm → 2. Hälfte Ø {$drift['second']} bpm "
                    . "(Drift {$drift['delta_signed']} bpm)";
            }

            if (! empty($activity->weather)) {
                $w = $activity->weather;
                $wl = ($w['emoji'] ?? '') . ' ' . ($w['description'] ?? '') . ', ' . ($w['temp_c'] ?? '?') . '°C';
                if (isset($w['apparent_c'])) $wl .= " (gefühlt {$w['apparent_c']}°C)";
                if (! empty($w['wind_kmh'])) $wl .= ", Wind {$w['wind_kmh']} km/h";
                if (isset($w['precip_mm']) && $w['precip_mm'] > 0) $wl .= ", Niederschlag {$w['precip_mm']} mm";
                $lines[] = 'Wetter: ' . trim($wl);
            }
        } else {
            $lines[] = 'Absolviert: manuell abgehakt, keine Aktivitätsdaten (Puls/Pace nicht verfügbar).';
        }

        // ── Die Wochen davor ────────────────────────────────────
        // Das Review sah bisher genau eine Einheit und einen 90-Tage-
        // Mittelwert. Damit lässt sich sagen, ob der Lauf gut war, aber
        // nicht, ob es aufwärts geht — und das ist die einzige Frage, die
        // einen Athleten über Wochen wirklich interessiert.
        foreach ($this->weeklyContext($s) as $line) {
            $lines[] = $line;
        }

        // Wellbeing on the training day.
        $wb = $s->user->wellbeingEntries()->whereDate('date', $s->planned_date->toDateString())->first();
        if ($wb) {
            $wbBits = [
                "Schlaf {$wb->sleep_quality}/10",
                "Energie {$wb->energy_level}/10",
                "Stress {$wb->stress_level}/10",
                "Muskelkater {$wb->muscle_soreness}/10",
            ];
            if ($wb->is_sick)    $wbBits[] = 'krank';
            if ($wb->is_injured) $wbBits[] = 'verletzt';
            $lines[] = 'Tagesform an dem Tag: ' . implode(', ', $wbBits);
        }

        // Training load / form.
        try {
            $load = $loadService->calculate($s->user_id);
            if (($load['ctl'] ?? 0) > 0 || ($load['atl'] ?? 0) > 0) {
                $tsb = $load['tsb'];
                $lines[] = "Form (TSB): " . ($tsb >= 0 ? "+{$tsb}" : $tsb) . " → {$load['form_label']}";
            }
        } catch (\Throwable $e) {
            // load is best-effort context only
        }

        // Athlete's own feedback, if entered.
        $own = [];
        if ($s->rating)           $own[] = "{$s->rating}/5 Sterne";
        if ($s->effort_perceived) $own[] = "RPE {$s->effort_perceived}/10";
        if ($s->feeling_notes)    $own[] = "Notiz: \"{$s->feeling_notes}\"";
        if ($own) {
            $lines[] = 'Selbsteinschätzung des Athleten: ' . implode(', ', $own);
        }

        return implode("\n", array_map(fn ($l) => "- {$l}", $lines));
    }

    /**
     * Die Rohsignale für {@see SessionHighlights::pick()} — alles gerechnet,
     * nichts geschätzt.
     */
    private function signals(TrainingSession $s): array
    {
        $activity = $s->activity;
        $snapshot = $s->planned_snapshot ?? [];
        $run      = $s->isRun();

        $out = [
            'run'          => $run,
            'type'         => $s->type,
            'sport'        => $s->sport_type,
            'unplanned'    => (bool) $s->was_unplanned,
            'rest_ignored' => ($snapshot['type'] ?? null) === 'rest',
        ];

        if ($s->type === 'race_prep' || ($snapshot['type'] ?? null) === 'race_prep') {
            $out['race'] = $s->title ?: 'Rennen';
        }

        if (! $activity) {
            return $out;
        }

        $km  = $activity->distance > 0 ? $activity->distance / 1000 : null;
        $min = $activity->moving_time > 0 ? (int) round($activity->moving_time / 60) : null;
        $out['minutes'] = $min;

        if ($run) {
            $pb = app(\App\Services\BestEffortService::class)->celebration($s->user_id, $activity->id);
            if ($pb) {
                $out['pb'] = [
                    'distance' => $pb['distance'],
                    'time'     => $this->clock((int) $pb['seconds']),
                    'previous' => $this->clock((int) $pb['previous_seconds']),
                ];
            }

            $previous = \App\Models\Activity::where('user_id', $s->user_id)
                ->whereIn('type', TrainingSession::RUN_SPORTS)
                ->where('id', '!=', $activity->id)
                ->where('start_date', '<', $activity->start_date)
                ->max('start_date');
            if ($previous) {
                $out['comeback_days'] = (int) \Carbon\Carbon::parse($previous)->startOfDay()
                    ->diffInDays(\Carbon\Carbon::parse($activity->start_date)->startOfDay());
            }

            $pausedUntil = $s->user->runnerProfile?->training_paused_until;
            $out['pause_ended'] = $pausedUntil
                && $pausedUntil->lt($s->planned_date)
                && $pausedUntil->gte($s->planned_date->copy()->subDays(14))
                && ($out['comeback_days'] ?? 99) >= 5;
        }

        if ($snapshot && ! $s->was_unplanned) {
            $target = $this->paceRangeSeconds($snapshot['pace_target'] ?? null);
            if ($run && $target && $activity->average_speed > 0) {
                $actual = 1000 / $activity->average_speed;
                if ($actual < $target['min'] - 5) {
                    $out['pace_off_s'] = (int) round($actual - $target['min']);
                } elseif ($actual > $target['max'] + 5) {
                    $out['pace_off_s'] = (int) round($actual - $target['max']);
                }
            }
            if (($snapshot['distance_km'] ?? 0) > 0 && $km) {
                $out['km_off_pct'] = (int) round(($km - $snapshot['distance_km']) / $snapshot['distance_km'] * 100);
            } elseif (($snapshot['duration_min'] ?? 0) > 0 && $min) {
                $out['min_off_pct'] = (int) round(($min - $snapshot['duration_min']) / $snapshot['duration_min'] * 100);
            }
            $out['as_planned'] = ! $this->planDeltas($snapshot, $km ? round($km, 2) : null, $min, (float) $activity->average_speed);
        }

        if ($w = $this->trendWindows($s)) {
            $out['trend_pace_s'] = (int) round(1000 / $w['then']['speed'] - 1000 / $w['now']['speed']);
            if ($w['now']['hr'] && $w['then']['hr']) {
                $out['trend_hr'] = (int) round($w['now']['hr'] - $w['then']['hr']);
            }
        }

        if ($drift = $this->hrDrift($activity->laps ?? [])) {
            $out['drift'] = $drift['second'] - $drift['first'];
        }

        $weather = $activity->weather ?? [];
        $out['temp']     = isset($weather['temp_c']) ? (float) $weather['temp_c'] : null;
        $out['apparent'] = isset($weather['apparent_c']) ? (float) $weather['apparent_c'] : null;
        $out['wind']     = ! empty($weather['wind_kmh']) ? (float) $weather['wind_kmh'] : null;

        return $out;
    }

    /** 46:22 oder 1:28:40 — wie eine Uhr, nicht „00:46:22". */
    private function clock(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $sec = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
    }

    /** Pause an diesem Tag oder ein Plan, der erst später beginnt. */
    private function breakOrLaterStart(TrainingSession $s): bool
    {
        $profile = $s->user->runnerProfile;
        if ($profile?->isPausedOn($s->planned_date)) {
            return true;
        }
        $until = $profile?->training_paused_until;
        if ($until && $until->gte($s->planned_date->copy()->subWeeks(4))) {
            return true;
        }

        return $s->user->planStartsLater() !== null;
    }

    /**
     * Was ein Trainer über seinen Athleten im Kopf hat: Name, Ziel, Pause,
     * die nächste Einheit — und was er zuletzt geschrieben hat, damit er
     * nicht jedes Mal gleich anfängt. Bis 12.11.2026 fehlte all das; der
     * Coach las Jans OP-Pause als „1 von 6 Läufen".
     */
    private function coachContext(TrainingSession $s): string
    {
        $user    = $s->user;
        $profile = $user->runnerProfile;
        $lines   = [];

        if ($first = trim(strtok((string) $user->name, ' ') ?: '')) {
            $lines[] = "Vorname: {$first}";
        }

        $event = $user->activeTrainingPlan?->event
            ?? \App\Models\Event::where('user_id', $user->id)->races()
                ->whereDate('event_date', '>=', $s->planned_date)->orderBy('event_date')->first();

        if ($event) {
            if ($event->isOpen()) {
                $lines[] = 'Ziel ohne Rennen: ' . $event->openFocusLabel();
            } else {
                $goal = "Ziel: {$event->name}, {$event->distance_label}";
                if ($event->target_time_formatted) $goal .= ", Zielzeit {$event->target_time_formatted}";
                $goal .= ', in ' . $event->weeks_until . ' Wochen';
                $lines[] = $goal;
            }
            try {
                $lines[] = 'Phase: ' . app(\App\Services\PeriodizationService::class)
                    ->phaseOn($event, \Carbon\CarbonImmutable::parse($s->planned_date->toDateString()))['label'];
            } catch (\Throwable $e) {
                // Phase ist nur Beiwerk.
            }
        }

        if ($profile?->isPausedOn($s->planned_date)) {
            $lines[] = 'Trainingspause bis ' . $profile->training_paused_until->format('d.m.')
                . ($profile->training_pause_reason ? " ({$profile->training_pause_reason})" : '');
        } elseif ($profile?->training_paused_until && $profile->training_paused_until->gte($s->planned_date->copy()->subWeeks(4))) {
            $lines[] = 'Trainingspause beendet am ' . $profile->training_paused_until->format('d.m.')
                . ($profile->training_pause_reason ? " ({$profile->training_pause_reason})" : '');
        }

        if ($later = $user->planStartsLater()) {
            $lines[] = "Der Plan startet erst am {$later['date']}; bis dahin geht es ums Formhalten";
        }

        $next = TrainingSession::where('user_id', $user->id)
            ->where('status', 'planned')
            ->whereNotIn('type', ['rest'])
            ->whereDate('planned_date', '>', $s->planned_date)
            ->orderBy('planned_date')->orderBy('sort_order')
            ->first();
        if ($next) {
            $label = $next->isRun() ? (TrainingSession::TYPE_LABELS[$next->type] ?? $next->type) : $next->sportLabel();
            $lines[] = 'Nächste Einheit: ' . $next->planned_date->locale('de')->isoFormat('dddd')
                . ", {$label}" . ($next->title && $next->title !== $label ? " ({$next->title})" : '');
        }

        $recent = CoachMessage::where('user_id', $user->id)
            ->where('role', 'assistant')
            ->latest('id')->limit(3)->pluck('content')
            ->map(fn ($c) => '„' . mb_strimwidth(preg_replace('/\s+/', ' ', $c), 0, 140, '…') . '"')
            ->all();
        if ($recent) {
            $lines[] = 'Deine letzten Nachrichten (nicht wiederholen, anders anfangen): ' . implode(' · ', $recent);
        }

        return $lines ? implode("\n", array_map(fn ($l) => "- {$l}", $lines)) : '- (nichts weiter bekannt)';
    }

    /**
     * Abweichungen zwischen Plan und Wirklichkeit — in Worten, die im Prompt
     * stehen koennen. Leer, wenn alles innerhalb der Toleranz liegt.
     *
     * Toleranzen bewusst grosszuegig: eine Runde mehr oder ein paar Sekunden
     * Pace sind kein Abweichen vom Plan, sondern normales Laufen.
     *
     * @return list<string>
     */
    private function planDeltas(array $snapshot, ?float $actualKm, ?int $actualMin, float $actualSpeed): array
    {
        $out = [];

        $planKm = $snapshot['distance_km'] ?? null;
        if ($planKm > 0 && $actualKm) {
            $diff = $actualKm - $planKm;
            $pct  = ($diff / $planKm) * 100;
            if (abs($pct) >= 10) {
                $out[] = sprintf('%s km (%+.1f km, %+d %%)', $actualKm, $diff, (int) round($pct));
            }
        }

        $planMin = $snapshot['duration_min'] ?? null;
        if ($planMin > 0 && $actualMin) {
            $diff = $actualMin - $planMin;
            $pct  = ($diff / $planMin) * 100;
            if (abs($pct) >= 10) {
                $out[] = sprintf('%d min (%+d min, %+d %%)', $actualMin, $diff, (int) round($pct));
            }
        }

        // Pace-Ziele stehen als "5:30" oder als Spanne "5:30-6:00" im Plan.
        $target = $this->paceRangeSeconds($snapshot['pace_target'] ?? null);
        if ($target && $actualSpeed > 0) {
            $actualSec = 1000 / $actualSpeed;

            if ($actualSec < $target['min'] - 10) {
                $out[] = sprintf('%s min/km — %d s/km schneller als vorgesehen',
                    $this->secondsToPace($actualSec), (int) round($target['min'] - $actualSec));
            } elseif ($actualSec > $target['max'] + 10) {
                $out[] = sprintf('%s min/km — %d s/km langsamer als vorgesehen',
                    $this->secondsToPace($actualSec), (int) round($actualSec - $target['max']));
            }
        }

        return $out;
    }

    /**
     * "5:30" oder "5:30-6:00" in Sekunden je Kilometer.
     *
     * @return array{min: float, max: float}|null
     */
    private function paceRangeSeconds(?string $pace): ?array
    {
        if (! $pace || $pace === 'null') return null;

        preg_match_all('/(\d{1,2}):(\d{2})/', $pace, $m, PREG_SET_ORDER);
        if (! $m) return null;

        $values = array_map(fn ($p) => (int) $p[1] * 60 + (int) $p[2], $m);

        return ['min' => (float) min($values), 'max' => (float) max($values)];
    }

    /**
     * Erst runden, dann teilen.
     *
     * Andersherum lief die Minute daneben, sobald der Wert knapp unter einer
     * vollen lag: 359,97 s/km ergaben Minute 5 (abgeschnitten) und Sekunde 0
     * (aufgerundet) — also "5:00" fuer eine Sechs-Minuten-Pace. Sichtbar wurde
     * es erst, als der Vergleich zweier Zeitraeume beide Zahlen nebeneinander
     * stellte und die Differenz nicht mehr zu ihnen passte.
     */
    private function secondsToPace(float $seconds): string
    {
        return PaceFormat::fromSeconds($seconds);
    }

    /**
     * Average HR / pace over recent completed sessions of the same type (with a
     * matched Strava activity), to detect deviations. Null if too little history.
     *
     * @return array{avg_hr:?int, avg_pace:?string, count:int}|null
     */
    /**
     * Der Verlauf der letzten Wochen: Umfang, Verlässlichkeit, Entwicklung.
     *
     * Alles hier ist gerechnet und nicht geschätzt. Ein Sprachmodell, dem
     * man Wochenlisten hinlegt und "gibt es einen Trend?" fragt, findet
     * zuverlässig einen — auch wenn keiner da ist.
     *
     * @return list<string>
     */
    private function weeklyContext(TrainingSession $s): array
    {
        $lines = [];
        $end   = \Carbon\CarbonImmutable::parse($s->planned_date)->endOfWeek();

        // Wochenumfang: vier abgeschlossene Wochen plus die laufende.
        $weeks = [];
        for ($i = 4; $i >= 0; $i--) {
            $from = $end->subWeeks($i)->startOfWeek();
            $to   = $from->endOfWeek();

            $runs = \App\Models\Activity::where('user_id', $s->user_id)
                ->where('type', 'Run')
                ->whereBetween('start_date', [$from->startOfDay(), $to->endOfDay()])
                ->get(['distance']);

            $weeks[] = [
                'label' => $from->format('d.m.'),
                'km'    => round($runs->sum('distance') / 1000, 1),
                'runs'  => $runs->count(),
            ];
        }

        if (array_sum(array_column($weeks, 'runs')) > 0) {
            $lines[] = 'Wochenumfang (ab KW-Beginn, letzte 5 Wochen): ' . implode(', ', array_map(
                fn ($w) => "{$w['label']} {$w['km']} km in {$w['runs']} " . ($w['runs'] === 1 ? 'Lauf' : 'Läufen'),
                $weeks,
            ));

            // Zwei Wochen gegen die zwei davor. Einzelne Wochen schwanken zu
            // stark, um daraus etwas abzuleiten; die laufende Woche ist noch
            // unvollständig und bleibt draußen.
            $recentKm = array_sum(array_column(array_slice($weeks, 2, 2), 'km'));
            $beforeKm = array_sum(array_column(array_slice($weeks, 0, 2), 'km'));

            if ($beforeKm > 0) {
                $pct = (int) round((($recentKm - $beforeKm) / $beforeKm) * 100);
                $lines[] = 'Umfang-Entwicklung: ' . match (true) {
                    $pct >=  15 => "deutlich mehr ({$pct} % gegenüber den zwei Wochen davor)",
                    $pct <= -15 => "deutlich weniger ({$pct} % gegenüber den zwei Wochen davor)",
                    default     => "stabil ({$pct} % gegenüber den zwei Wochen davor)",
                };
            }
        }

        // Verlässlichkeit: was war geplant, was ist daraus geworden.
        $since = $end->subWeeks(4)->startOfWeek()->toDateString();
        $past  = TrainingSession::where('user_id', $s->user_id)
            ->whereNotIn('type', ['rest', 'cross_training'])
            ->where('planned_date', '>=', $since)
            ->where('planned_date', '<=', $s->planned_date->toDateString())
            ->get(['status', 'was_unplanned']);

        $planned   = $past->where('was_unplanned', false)->count();
        $done      = $past->where('was_unplanned', false)->where('status', 'completed')->count();
        $skipped   = $past->where('status', 'skipped')->count();
        $unplanned = $past->where('was_unplanned', true)->count();

        // Fremdsport getrennt zaehlen. Als "zusaetzlich ungeplant gelaufen"
        // waere eine Schwimmeinheit schlicht falsch beschrieben.
        $crossCount = TrainingSession::where('user_id', $s->user_id)
            ->where('type', 'cross_training')
            ->where('status', 'completed')
            ->where('planned_date', '>=', $since)
            ->where('planned_date', '<=', $s->planned_date->toDateString())
            ->count();

        // In einer Pause oder vor dem Planstart ist die Quote kein Zeichen
        // von Unzuverlässigkeit — so las der Coach sie aber (12.11.2026,
        // „1 von 6 geplanten Laufeinheiten" mitten in Jans OP-Pause).
        if ($planned > 0 && ! $this->breakOrLaterStart($s)) {
            $quote = (int) round(($done / $planned) * 100);
            $line  = "Umsetzung der letzten 4 Wochen: {$done} von {$planned} geplanten Laufeinheiten absolviert ({$quote} %)";
            if ($skipped > 0)   $line .= ", {$skipped} ausgelassen";
            if ($unplanned > 0) $line .= ", {$unplanned} zusätzlich ungeplant gelaufen";
            $lines[] = $line;
        }

        if ($crossCount > 0) {
            $lines[] = "Rad, Schwimmen und Ähnliches im selben Zeitraum: {$crossCount} Einheiten";
        }

        if ($trend = $this->typeTrend($s)) {
            $lines[] = $trend;
        }

        if (! $s->isRun()) {
            $lines[] = 'Die Wochenkilometer oben zählen nur Läufe; diese Einheit ('
                . $s->sportLabel() . ') ist dort nicht dabei.';
        }

        return $lines;
    }

    /**
     * Derselbe Einheitentyp jetzt gegen früher: die letzten vier Wochen
     * gegen die acht Wochen davor.
     *
     * Der aussagekräftigste Satz eines Coaches ist "gleicher Puls, aber
     * schneller". Dafür braucht es beide Werte aus beiden Zeiträumen —
     * die Pace allein sagt nichts, weil sie auch Tagesform sein kann.
     */
    private function trendWindows(TrainingSession $s): ?array
    {
        // Eine Schwimmeinheit traegt den Platzhalter-Typ "easy_run" aus dem
        // Import. Ohne diese Sperre wuerde sie gegen echte lockere Laeufe
        // verglichen und deren Schnitt verfaelschen.
        if (! $s->isRun()) {
            return null;
        }

        $window = function (int $fromDaysAgo, int $toDaysAgo) use ($s) {
            $rows = \App\Models\Activity::query()
                ->whereIn('id', function ($q) use ($s, $fromDaysAgo, $toDaysAgo) {
                    $q->select('activity_id')
                        ->from('training_sessions')
                        ->where('user_id', $s->user_id)
                        ->where('type', $s->type)
                        ->where('status', 'completed')
                        ->where('id', '!=', $s->id)
                        ->where(fn ($q2) => $q2->whereNull('sport_type')
                            ->orWhereIn('sport_type', TrainingSession::RUN_SPORTS))
                        ->whereNotNull('activity_id')
                        ->where('planned_date', '>=', now()->subDays($fromDaysAgo)->toDateString())
                        ->where('planned_date', '<',  now()->subDays($toDaysAgo)->toDateString());
                })
                ->get(['average_heartrate', 'average_speed']);

            $speeds = $rows->pluck('average_speed')->filter(fn ($v) => $v > 0);
            $hrs    = $rows->pluck('average_heartrate')->filter();

            return [
                'count' => $rows->count(),
                'speed' => $speeds->count() ? (float) $speeds->avg() : null,
                'hr'    => $hrs->count()    ? (float) $hrs->avg()    : null,
            ];
        };

        $now  = $window(28, 0);
        $then = $window(84, 28);

        if ($now['count'] < 2 || $then['count'] < 2 || ! $now['speed'] || ! $then['speed']) {
            return null;
        }

        return ['now' => $now, 'then' => $then];
    }

    private function typeTrend(TrainingSession $s): ?string
    {
        $w = $this->trendWindows($s);
        if (! $w) {
            return null;
        }
        ['now' => $now, 'then' => $then] = $w;

        $secNow  = 1000 / $now['speed'];
        $secThen = 1000 / $then['speed'];
        $deltaS  = (int) round($secThen - $secNow);   // positiv = schneller geworden

        $typeLabel = $this->typeLabels()[$s->type] ?? $s->type;
        $paceText  = match (true) {
            $deltaS >=  5 => "{$deltaS} s/km schneller",
            $deltaS <= -5 => abs($deltaS) . ' s/km langsamer',
            default       => 'gleich schnell',
        };

        $line = "Entwicklung bei {$typeLabel}: letzte 4 Wochen ({$now['count']} Einheiten) "
            . "{$this->secondsToPace($secNow)} min/km gegenüber {$this->secondsToPace($secThen)} min/km "
            . "in den 8 Wochen davor ({$then['count']} Einheiten) — {$paceText}";

        if ($now['hr'] && $then['hr']) {
            $hrDelta = (int) round($now['hr'] - $then['hr']);
            $line .= '. Ø-Puls dabei ' . match (true) {
                $hrDelta >=  3 => "+{$hrDelta} bpm höher",
                $hrDelta <= -3 => "{$hrDelta} bpm niedriger",
                default        => 'praktisch unverändert',
            };
        }

        return $line;
    }

    private function baselineFor(TrainingSession $s): ?array
    {
        if (! $s->isRun()) {
            return null;
        }

        $activities = \App\Models\Activity::query()
            ->whereIn('id', function ($q) use ($s) {
                $q->select('activity_id')
                    ->from('training_sessions')
                    ->where('user_id', $s->user_id)
                    ->where('type', $s->type)
                    ->where('status', 'completed')
                    ->where('id', '!=', $s->id)
                    ->whereNotNull('activity_id')
                    // Nur Laufeinheiten — sonst zoege eine importierte
                    // Radfahrt mit demselben Platzhalter-Typ den Schnitt.
                    ->where(fn ($q2) => $q2->whereNull('sport_type')
                        ->orWhereIn('sport_type', TrainingSession::RUN_SPORTS))
                    ->where('planned_date', '>=', now()->subDays(90)->toDateString());
            })
            ->get(['average_heartrate', 'average_speed']);

        if ($activities->count() < 2) return null;

        $hrValues = $activities->pluck('average_heartrate')->filter()->values();
        $avgHr    = $hrValues->count() ? (int) round($hrValues->avg()) : null;

        $speedValues = $activities->pluck('average_speed')->filter(fn ($v) => $v > 0)->values();
        $avgPace     = $speedValues->count() ? $this->paceFromSpeed((float) $speedValues->avg()) : null;

        if (! $avgHr && ! $avgPace) return null;

        return ['avg_hr' => $avgHr, 'avg_pace' => $avgPace, 'count' => $activities->count()];
    }

    /**
     * HR drift between the first and second half of a run (by moving time),
     * a proxy for aerobic decoupling. Null when laps lack usable HR.
     *
     * @return array{first:int, second:int, delta_signed:string}|null
     */
    private function hrDrift(array $laps): ?array
    {
        $usable = array_values(array_filter($laps, fn ($l) => ! empty($l['average_heartrate']) && ! empty($l['moving_time'])));
        if (count($usable) < 2) return null;

        $totalTime = array_sum(array_column($usable, 'moving_time'));
        if ($totalTime <= 0) return null;

        $half = $totalTime / 2;
        $acc = 0.0;
        $firstHrTime = 0.0; $firstTime = 0.0;
        $secondHrTime = 0.0; $secondTime = 0.0;

        foreach ($usable as $lap) {
            $t  = (float) $lap['moving_time'];
            $hr = (float) $lap['average_heartrate'];
            // Assign each lap to the half its midpoint falls into.
            $mid = $acc + $t / 2;
            if ($mid <= $half) {
                $firstHrTime += $hr * $t; $firstTime += $t;
            } else {
                $secondHrTime += $hr * $t; $secondTime += $t;
            }
            $acc += $t;
        }

        if ($firstTime <= 0 || $secondTime <= 0) return null;

        $first  = (int) round($firstHrTime / $firstTime);
        $second = (int) round($secondHrTime / $secondTime);
        $delta  = $second - $first;

        return [
            'first'        => $first,
            'second'       => $second,
            'delta_signed' => $delta > 0 ? "+{$delta}" : (string) $delta,
        ];
    }

    private function paceFromSpeed(float $mps): ?string
    {
        $pace = PaceFormat::fromSpeed($mps);

        return $pace === PaceFormat::NONE ? null : $pace;
    }

    /** @return array<string,string> */
    private function typeLabels(): array
    {
        return \App\Models\TrainingSession::TYPE_LABELS;
    }
}

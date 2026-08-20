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
    public int $timeout = 90;

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

        $facts = $this->buildFacts($session, $loadService);
        $label = $this->sessionLabel($session);

        $result = $sessions
            ->withCoach($user->coach?->personality_prompt)
            ->forUser($user->id)
            ->generateSessionReview($user, $label, $facts);

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
        $chatContent = $result['review'];
        if ($result['question']) {
            $chatContent .= "\n\n" . $result['question'];
        }
        CoachMessage::create([
            'user_id' => $user->id,
            'role'    => 'assistant',
            'content' => $chatContent,
        ]);

        // Die Benachrichtigung haengt am Anlegen der Chat-Nachricht
        // (CoachMessageObserver) — hier waere sie die zweite.
    }

    /** Short label of the completed session, e.g. "Long Run – 19.6 km". */
    private function sessionLabel(TrainingSession $s): string
    {
        $typeLabel = $this->typeLabels()[$s->type] ?? $s->type;
        $bits = [$typeLabel];
        if ($s->title) $bits[] = $s->title;
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

        // ── Plan oder nicht ──────────────────────────────────────────────
        // Die Felder distance_km/duration_min/pace_target tragen nach dem
        // Strava-Import die TATSAECHLICHEN Werte. Was geplant war, steht im
        // Schnappschuss, den der Import vorher anlegt. Ohne ihn stand hier
        // zweimal dieselbe Zahl, und eine Abweichung war unsichtbar.
        $snapshot = $s->planned_snapshot;

        if ($s->was_unplanned) {
            $wasRest = ($snapshot['type'] ?? null) === 'rest';
            $lines[] = $wasRest
                ? 'EINORDNUNG: Ungeplant — fuer diesen Tag war ein RUHETAG vorgesehen.'
                : 'EINORDNUNG: Ungeplant — fuer diesen Tag stand keine Einheit im Plan.';
        } elseif ($snapshot) {
            $plannedType = $this->typeLabels()[$snapshot['type'] ?? ''] ?? ($snapshot['type'] ?? $typeLabel);

            $planned = [];
            if (! empty($snapshot['distance_km']))  $planned[] = "{$snapshot['distance_km']} km";
            if (! empty($snapshot['duration_min'])) $planned[] = "{$snapshot['duration_min']} min";
            if (! empty($snapshot['pace_target']))  $planned[] = "Pace {$snapshot['pace_target']}";
            if (! empty($snapshot['zone']))         $planned[] = "Zone {$snapshot['zone']}";

            $lines[] = 'EINORDNUNG: Geplante Einheit.';
            $lines[] = 'Geplant war: ' . $plannedType . (empty($planned) ? '' : ' (' . implode(', ', $planned) . ')');
        } else {
            // Aeltere Einheiten, die vor Einfuehrung des Schnappschusses
            // importiert wurden. Lieber offen sagen als etwas behaupten.
            $lines[] = 'EINORDNUNG: Geplante Einheit — die urspruenglichen Planwerte liegen nicht mehr vor.';
        }

        $activity = $s->activity;
        if ($activity) {
            $km   = $activity->distance > 0 ? round($activity->distance / 1000, 2) : null;
            $min  = $activity->moving_time > 0 ? (int) round($activity->moving_time / 60) : null;
            $pace = $this->paceFromSpeed((float) $activity->average_speed);
            $act  = [];
            if ($km)  $act[] = "{$km} km";
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
                    ? 'Abweichung vom Plan: ' . implode(', ', $deltas)
                    : 'Abweichung vom Plan: wie geplant umgesetzt.';
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
                $lines[] = 'Wetter beim Lauf: ' . trim($wl);
            }
        } else {
            $lines[] = 'Absolviert: manuell abgehakt, keine Aktivitätsdaten (Puls/Pace nicht verfügbar).';
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
            $lines[] = 'Wellbeing an dem Tag: ' . implode(', ', $wbBits);
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

    private function secondsToPace(float $seconds): string
    {
        return sprintf('%d:%02d', (int) ($seconds / 60), (int) round($seconds) % 60);
    }

    /**
     * Average HR / pace over recent completed sessions of the same type (with a
     * matched Strava activity), to detect deviations. Null if too little history.
     *
     * @return array{avg_hr:?int, avg_pace:?string, count:int}|null
     */
    private function baselineFor(TrainingSession $s): ?array
    {
        $activities = \App\Models\Activity::query()
            ->whereIn('id', function ($q) use ($s) {
                $q->select('activity_id')
                    ->from('training_sessions')
                    ->where('user_id', $s->user_id)
                    ->where('type', $s->type)
                    ->where('status', 'completed')
                    ->where('id', '!=', $s->id)
                    ->whereNotNull('activity_id')
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
        if ($mps <= 0) return null;
        $secPerKm = 1000 / $mps;
        return sprintf('%d:%02d', (int) ($secPerKm / 60), (int) ($secPerKm % 60));
    }

    /** @return array<string,string> */
    private function typeLabels(): array
    {
        return [
            'easy_run'          => 'Lockerer Lauf',
            'tempo_run'         => 'Tempolauf',
            'interval'          => 'Intervalltraining',
            'long_run'          => 'Langer Lauf',
            'progressive_run'   => 'Progressiver Lauf',
            'test_run'          => 'Testlauf',
            'race_prep'         => 'Rennvorbereitung',
            'back_to_back_long' => 'Back-to-Back Longrun',
            'time_on_feet'      => 'Time on Feet',
            'night_run'         => 'Nachtlauf',
            'yard_simulation'   => 'Yard-Simulation',
            'strength'          => 'Krafttraining',
            'core'              => 'Core-Training',
            'mobility'          => 'Mobility',
        ];
    }
}

<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\CoachingTextService;
use App\Services\PredictFinishTimeService;
use App\Services\RacePredictionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateRacePredictionJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    // Die Planseite startet den Job bei jedem Aufruf, solange Text und
    // Prognose nicht zusammenpassen — mehrmals Neuladen soll nicht mehrmals
    // das Modell rufen.
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return (string) $this->planId;
    }

    public int $tries   = 1;
    public int $timeout = 180; // Flex: doppeltes Zeitlimit plus ein Versuch auf Standard

    public function __construct(
        public readonly int $planId,
    ) {}

    public function handle(
        PredictFinishTimeService $predictor,
        CoachingTextService $text,
        RacePredictionService $prediction,
    ): void
    {
        $plan = TrainingPlan::with('event')->find($this->planId);
        if (! $plan || ! $plan->event) return;

        $event = $plan->event;
        if ($event->days_until < 0) return;

        $user = User::find($plan->user_id);
        if (! $user) return;

        // ── Die Zahl, die der Athlet sieht ────────────────────────────────────
        //
        // Mit VDOT kommt alles daraus: Zeit, Pace, Abstand zum Ziel, die
        // Grundlage und der Trend (aus dem Verlauf der gemessenen Schwelle).
        // Vorher stand im Prompt die VDOT-Zeit neben Trend und Laufanzahl
        // aus der Riegel-Hochrechnung — „27 Läufe, leicht sinkend“, obwohl
        // die Zahl aus der Schwelle kam, und die Riegel-Mittelwerte zählten
        // jeden lockeren Lauf mit. Riegel bleibt nur ohne VDOT.
        $profile   = $user->runnerProfile;
        $threshold = ($profile?->vdot() && ! $event->isBackyard())
            ? $prediction->forVdot($profile->vdot(), $event->race_distance, $event->distance_km)
            : null;

        if ($threshold) {
            $targetSec = ($event->target_time_hours * 3600) + ($event->target_time_minutes * 60);

            $data = [
                'predicted_finish_time'       => $threshold['time'],
                'predicted_pace'              => $threshold['pace'],
                'prediction_target_delta_sec' => $targetSec > 0 ? $targetSec - $threshold['total_sec'] : null,
                'prediction_trend'            => self::thresholdTrend($profile->threshold_pace_history ?? []),
                'prediction_run_count'        => null,
                'prediction_basis'            => $profile->vdotInfo()['label'] ?? null,
            ];
        } else {
            $data = $predictor->predict($plan->user_id, $event);
            if (! $data) {
                Log::info('GenerateRacePredictionJob: insufficient runs', ['plan_id' => $this->planId]);
                return;
            }
        }

        // ── Generate AI recommendation text ───────────────────────────────────
        $recentSessions = TrainingSession::where('training_plan_id', $plan->id)
            ->whereIn('status', ['completed', 'planned'])
            ->orderByDesc('planned_date')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'type'        => $s->type,
                'distance_km' => $s->distance_km,
                'status'      => $s->status,
            ])
            ->toArray();

        $eventData = [
            'name'                  => $event->name,
            'race_distance'         => $event->distance_label,
            'target_time_formatted' => $event->target_time_formatted ?? 'nicht angegeben',
            'days_until'            => $event->days_until,
        ];

        $text->withCoach($user->coach?->personality_prompt)->forUser($user->id);
        $text = $text->generateRacePredictionText($data, $eventData, $recentSessions);

        // ── Persist to plan ────────────────────────────────────────────────────
        $plan->update([
            'predicted_finish_time'       => $data['predicted_finish_time'],
            'predicted_pace'              => $data['predicted_pace'],
            'prediction_trend'            => $data['prediction_trend'],
            'prediction_target_delta_sec' => $data['prediction_target_delta_sec'],
            'prediction_run_count'        => $data['prediction_run_count'],
            'prediction_text'             => $text,
            'prediction_updated_at'       => now(),
        ]);

        Log::info('GenerateRacePredictionJob: done', [
            'plan_id'   => $this->planId,
            'predicted' => $data['predicted_finish_time'],
        ]);
    }

    /** Schwelle mindestens so viel schneller/langsamer, damit es ein Trend ist. */
    public const TREND_MIN_SEC = 3;

    /** Zeitraum, über den der Schwellenverlauf verglichen wird. */
    public const TREND_DAYS = 42;

    /**
     * Trend aus dem Verlauf der gemessenen Schwelle: der neueste Wert gegen
     * den ältesten der letzten sechs Wochen. Null, wenn es dafür keine zwei
     * Messungen gibt — dann sagt der Prompt nichts über einen Trend.
     *
     * @param array<int, array{date?: string, pace?: float}> $history
     */
    public static function thresholdTrend(array $history): ?string
    {
        $since  = now()->subDays(self::TREND_DAYS)->startOfDay();
        $points = collect($history)
            ->map(function ($h) {
                try {
                    $date = \Carbon\Carbon::createFromFormat('d.m.Y', (string) ($h['date'] ?? ''))->startOfDay();
                } catch (\Throwable) {
                    return null;
                }
                return isset($h['pace']) ? ['date' => $date, 'sec' => (float) $h['pace'] * 60] : null;
            })
            ->filter()
            ->filter(fn ($p) => $p['date']->gte($since))
            ->sortBy(fn ($p) => $p['date']->timestamp)
            ->values();

        if ($points->count() < 2) return null;

        $diff = $points->first()['sec'] - $points->last()['sec']; // positiv = schneller geworden
        if ($diff >= self::TREND_MIN_SEC)  return 'improving';
        if ($diff <= -self::TREND_MIN_SEC) return 'declining';
        return 'stable';
    }
}

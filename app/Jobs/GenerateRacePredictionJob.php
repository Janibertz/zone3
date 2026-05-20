<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\OpenAIService;
use App\Services\PredictFinishTimeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateRacePredictionJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 60;

    public function __construct(
        public readonly int $planId,
    ) {}

    public function handle(PredictFinishTimeService $predictor, OpenAIService $openAI): void
    {
        $plan = TrainingPlan::with('event')->find($this->planId);
        if (! $plan || ! $plan->event) return;

        $event = $plan->event;
        if ($event->days_until < 0) return;

        $user = User::find($plan->user_id);
        if (! $user) return;

        // ── Compute Riegel prediction ──────────────────────────────────────────
        $data = $predictor->predict($plan->user_id, $event);
        if (! $data) {
            Log::info('GenerateRacePredictionJob: insufficient runs', ['plan_id' => $this->planId]);
            return;
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

        $openAI->withCoach($user->coach?->personality_prompt)->forUser($user->id);
        $text = $openAI->generateRacePredictionText($data, $eventData, $recentSessions);

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
}

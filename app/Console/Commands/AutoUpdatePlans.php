<?php

namespace App\Console\Commands;

use App\Jobs\RegeneratePlanJob;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use Illuminate\Console\Command;

class AutoUpdatePlans extends Command
{
    protected $signature   = 'plan:auto-update {--user=* : Specific user IDs}';
    protected $description = 'Detect plan gaps and queue regeneration for all athletes with upcoming races';

    public function handle(): void
    {
        $today   = now()->startOfDay();
        $todayStr = $today->toDateString();
        $userIds = $this->option('user');

        $horizon = Event::PLAN_HORIZON_DAYS;
        $refresh = Event::PLAN_REFRESH_DAYS;

        // All active plans for upcoming races (no upper date bound — the rolling
        // window is slid forward over time, even for races months away).
        $query = TrainingPlan::where('is_active', true)
            ->whereHas('event', fn ($q) => $q->where('event_date', '>=', $todayStr))
            ->with(['event', 'user']);

        if (! empty($userIds)) {
            $query->whereIn('user_id', $userIds);
        }

        $query->chunk(50, function ($plans) use ($today, $todayStr, $horizon, $refresh) {
            foreach ($plans as $plan) {
                if (! $plan->event || ! $plan->user) continue;

                $eventDate = $plan->event->event_date->copy()->startOfDay();
                $daysUntil = (int) $today->diffInDays($eventDate, false);
                if ($daysUntil < 0) continue;

                // Window the plan should currently cover: today … min(race, today+horizon-1)
                $windowDays = min($horizon, $daysUntil + 1);
                $windowEnd  = $today->copy()->addDays($windowDays - 1);
                $expected   = $windowDays; // one entry per day (incl. rest days)

                // Distinct days actually covered within the window (any status)
                $coveredInWindow = TrainingSession::where('training_plan_id', $plan->id)
                    ->whereDate('planned_date', '>=', $todayStr)
                    ->whereDate('planned_date', '<=', $windowEnd->toDateString())
                    ->distinct()
                    ->count('planned_date');

                // How many days ahead the plan reaches at all (for sliding the window)
                $lastCovered  = TrainingSession::where('training_plan_id', $plan->id)->max('planned_date');
                $reachesRace  = $lastCovered !== null && $lastCovered >= $eventDate->toDateString();
                $coverageAhead = $lastCovered !== null
                    ? (int) $today->diffInDays(\Illuminate\Support\Carbon::parse($lastCovered)->startOfDay(), false)
                    : -1;

                $hasGap        = $coveredInWindow < $expected;          // missing day(s) inside the window
                $windowLow     = ! $reachesRace && $coverageAhead < $refresh; // window running out, slide it

                if ($hasGap || $windowLow) {
                    $reason = $hasGap ? "gap {$coveredInWindow}/{$expected}" : "window low ({$coverageAhead}d ahead)";
                    $this->line("↻ Regen for user #{$plan->user_id} ({$plan->event->name}): {$reason}");

                    // Skip if plan was regenerated very recently (avoid hammering OpenAI)
                    if ($plan->updated_at->gt(now()->subHours(12))) {
                        $this->line("  ⏭ Skipped — regenerated within last 12h");
                        continue;
                    }

                    $plan->update(['needs_plan_update' => true]);
                    RegeneratePlanJob::dispatch($plan->user_id, RegeneratePlanJob::REASON_GAP);
                } else {
                    $this->line("✓ Plan ok for user #{$plan->user_id} ({$coverageAhead}d ahead, window {$coveredInWindow}/{$expected})");
                }
            }
        });
    }
}

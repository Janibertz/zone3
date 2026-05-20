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
        $today = now()->toDateString();
        $userIds = $this->option('user');

        // All active plans for events within the next 21 days
        $query = TrainingPlan::where('is_active', true)
            ->whereHas('event', fn ($q) => $q
                ->where('event_date', '>=', $today)
                ->where('event_date', '<=', now()->addDays(21)->toDateString())
            )
            ->with(['event', 'user']);

        if (! empty($userIds)) {
            $query->whereIn('user_id', $userIds);
        }

        $query->chunk(50, function ($plans) use ($today) {
            foreach ($plans as $plan) {
                if (! $plan->event || ! $plan->user) continue;

                $eventDate = $plan->event->event_date->format('Y-m-d');
                $daysUntil = now()->diffInDays($plan->event->event_date, false);

                if ($daysUntil < 0) continue;

                // Count planned sessions from today until race day
                $plannedDays = TrainingSession::where('training_plan_id', $plan->id)
                    ->where('status', 'planned')
                    ->whereDate('planned_date', '>=', $today)
                    ->whereDate('planned_date', '<=', $eventDate)
                    ->count();

                // Expected: one entry per day (including rest days)
                $expectedDays = $daysUntil + 1;

                if ($plannedDays < $expectedDays) {
                    $this->line("↻ Gap detected for user #{$plan->user_id} (event: {$plan->event->name}): {$plannedDays}/{$expectedDays} days covered");

                    // Skip if plan was regenerated very recently (avoid hammering OpenAI)
                    if ($plan->updated_at->gt(now()->subHours(12))) {
                        $this->line("  ⏭ Skipped — regenerated within last 12h");
                        continue;
                    }

                    $plan->update(['needs_plan_update' => true]);
                    RegeneratePlanJob::dispatch($plan->user_id);
                } else {
                    $this->line("✓ Plan complete for user #{$plan->user_id} ({$plannedDays} days covered)");
                }
            }
        });
    }
}

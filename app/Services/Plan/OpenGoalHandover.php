<?php

namespace App\Services\Plan;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ein Rennen löst ein Ziel ohne Rennen ab (Jan, 10.11.2026): ab dem
 * nächsten Montag gilt der Rennplan. Die laufende Woche bleibt, wie sie
 * ist — als Einheiten ohne Plan, gepinnt, damit keine Neuberechnung sie
 * wegräumt. Was danach geplant war, fällt weg; das offene Ziel endet am
 * Sonntag und rollt nicht weiter.
 */
class OpenGoalHandover
{
    /** Der aktive offene Plan des Athleten, falls einer läuft. */
    public static function activeOpenPlan(int $userId, ?int $exceptEventId = null): ?TrainingPlan
    {
        return TrainingPlan::where('user_id', $userId)
            ->where('is_active', true)
            ->when($exceptEventId, fn ($q) => $q->where('event_id', '!=', $exceptEventId))
            ->whereHas('event', fn ($q) => $q->where('goal_type', 'open'))
            ->with('event')
            ->first();
    }

    public function handOver(TrainingPlan $openPlan, Event $race, ?CarbonImmutable $today = null): CarbonImmutable
    {
        $today  = $today ?? CarbonImmutable::parse(now()->toDateString());
        $monday = $today->startOfWeek()->addWeek();
        $sunday = $monday->subDay();

        DB::transaction(function () use ($openPlan, $race, $monday, $sunday) {
            // Ab Montag gehört der Kalender dem Rennplan.
            TrainingSession::where('training_plan_id', $openPlan->id)
                ->where('status', 'planned')
                ->whereDate('planned_date', '>=', $monday->toDateString())
                ->delete();

            // Die laufende Woche bleibt stehen, ohne Plan und gepinnt.
            TrainingSession::where('training_plan_id', $openPlan->id)
                ->where('status', 'planned')
                ->whereDate('planned_date', '<=', $sunday->toDateString())
                ->update([
                    'training_plan_id' => null,
                    'event_id'         => null,
                    'pinned_at'        => now(),
                ]);

            $openPlan->update(['is_active' => false]);

            $open = $openPlan->event;
            if ($open && $open->event_date->gt($sunday)) {
                $open->forceFill([
                    'event_date' => $sunday->toDateString(),
                    'open_test'  => false,
                ])->save();
            }

            $start = $race->plan_start_date
                ? CarbonImmutable::parse($race->plan_start_date->toDateString())
                : null;
            if (! $start || $start->lt($monday)) {
                $race->forceFill(['plan_start_date' => $monday->toDateString()])->save();
            }
        });

        return $monday;
    }
}

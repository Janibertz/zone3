<?php

use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use Illuminate\Database\Migrations\Migration;

/**
 * Einmalige Korrektur (Jan, 24.11.2026): der Coach hatte statt des heutigen
 * Laufclubs den vom 01.12. gestrichen (inzwischen zurückgenommen). Heute
 * fällt der Laufclub weg (eingeplante Einheit #5247 und fester Termin), dafür
 * steht Schwimmen. Greift nur genau in diesem Zustand, sonst nichts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $date = '2026-11-24';
        $club = TrainingSession::where('id', 5247)->where('user_id', 1)
            ->whereDate('planned_date', $date)->where('title', 'KRAFT Laufclub')
            ->where('status', 'planned')->first();
        if (! $club) {
            return;
        }

        $planId = TrainingPlan::where('user_id', 1)->where('is_active', true)->value('id');
        $club->delete();

        if (! TrainingSession::where('user_id', 1)->whereDate('planned_date', $date)->where('sport_type', 'Swim')->exists()) {
            $swim = new TrainingSession([
                'title' => 'Schwimmen locker', 'description' => 'Alternativtraining im Wasser statt Laufclub — locker, ohne Druck.',
                'type' => 'cross_training', 'sport_type' => 'Swim', 'duration_min' => 45, 'intensity' => 'low',
                'status' => 'planned', 'pinned_at' => now(),
            ]);
            $swim->user_id = 1;
            $swim->training_plan_id = $planId;
            $swim->planned_date = $date;
            $swim->sort_order = 2;
            $swim->save();
        }

        if ($plan = TrainingPlan::find($planId)) {
            $overrides = $plan->availability_overrides ?? [];
            $overrides[$date] = array_merge($overrides[$date] ?? [], ['fixed_off' => true]);
            $plan->availability_overrides = $overrides;
            $plan->save();
        }

        $profile = \App\Models\RunnerProfile::where('user_id', 1)->first();
        if ($profile && (string) $profile->recommendation_date === $date) {
            $profile->forceFill(['today_recommendation' => null, 'recommendation_date' => null])->save();
        }
    }

    public function down(): void {}
};

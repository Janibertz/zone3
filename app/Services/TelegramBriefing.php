<?php

namespace App\Services;

use App\Models\TrainingSession;
use App\Models\User;
use App\Observers\SessionRecommendationObserver;

/**
 * Was heute ansteht, als Nachricht — ohne KI-Aufruf.
 *
 * Das Morgen-Briefing und der Befehl /heute zeigen dasselbe. Die Zahlen
 * stehen schon in der Einheit; ein Modell, das sie umformuliert, kostet
 * Geld und kann sich verrechnen.
 */
class TelegramBriefing
{
    public function today(User $user, bool $morning = false): string
    {
        $plan = $user->activeTrainingPlan;

        $sessions = $plan
            ? TrainingSession::where('training_plan_id', $plan->id)
                ->whereDate('planned_date', now()->toDateString())
                ->where('status', '!=', 'skipped')
                ->orderBy('sort_order')
                ->get()
            : collect();

        $greeting = $morning ? 'Guten Morgen' . ($user->name ? ', ' . strtok($user->name, ' ') : '') . "!\n\n" : '';

        $profile = $user->runnerProfile;
        if ($profile?->isPausedOn()) {
            $why = $profile->training_pause_reason ? " ({$profile->training_pause_reason})" : '';

            return $greeting . '**Trainingspause bis ' . $profile->training_paused_until->format('d.m.') . "{$why}.** Erhol dich gut.";
        }

        if ($sessions->isEmpty()) {
            return $greeting . ($plan ? 'Heute steht nichts im Plan.' : 'Du hast noch keinen aktiven Trainingsplan.');
        }

        if ($sessions->every(fn ($s) => $s->type === 'rest')) {
            return $greeting . '**Heute: Ruhetag.** Erholung ist Teil des Trainings.';
        }

        $lines = [];
        foreach ($sessions->where('type', '!=', 'rest') as $s) {
            $lines[] = '**Heute: ' . SessionRecommendationObserver::describe($s->only(['title', 'type', 'distance_km', 'duration_min', 'pace_target'])) . '**';

            $description = trim(strtok((string) $s->description, "\n"));
            if ($description !== '') {
                $lines[] = $description;
            }
        }

        if ($morning) {
            $lines[] = '';
            $lines[] = 'Wie fühlst du dich? Trag kurz deine Tagesform ein: ' . url('/dashboard');
        }

        return $greeting . implode("\n", $lines);
    }
}

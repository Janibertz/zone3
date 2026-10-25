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

        // Doppel-Schwelle: beide Läufe standen schon in der Nachricht, aber
        // zweimal als „Heute:" — welcher morgens und welcher abends gelaufen
        // wird, sagte sie nicht.
        $runs  = $sessions->filter(fn ($s) => in_array($s->type, TrainingPlanValidator::RUN_TYPES, true))->values();
        $label = function (TrainingSession $s) use ($runs): string {
            if ($runs->count() < 2 || ! $runs->contains('id', $s->id)) {
                return 'Heute';
            }

            return $runs->first()->id === $s->id ? 'Morgens' : 'Abends';
        };

        $lines = [];
        foreach ($sessions->where('type', '!=', 'rest') as $s) {
            $lines[] = '**' . $label($s) . ': ' . SessionRecommendationObserver::describe($s->only(['title', 'type', 'distance_km', 'duration_min', 'pace_target'])) . '**';

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

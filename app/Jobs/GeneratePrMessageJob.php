<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\User;
use App\Services\OpenAIService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generate (and cache) the coach's PR celebration message off the request
 * cycle, so the dashboard render never blocks on an OpenAI call.
 *
 * Idempotent: if a message was already cached by an earlier run, it exits
 * immediately, so duplicate dispatches from rapid reloads are harmless.
 */
class GeneratePrMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 60;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function handle(OpenAIService $openAI): void
    {
        $user = User::find($this->userId);
        if (! $user) return;

        $profile = $user->runnerProfile;
        if (! $profile || ! $profile->pending_pr_activity_id) return;

        // Already generated (possibly by a concurrent run) — nothing to do.
        if ($profile->pending_pr_message) return;

        $prActivity = Activity::find($profile->pending_pr_activity_id);
        if (! $prActivity) {
            $profile->update(['pending_pr_activity_id' => null]);
            return;
        }

        $message = $openAI
            ->withCoach($user->coach?->personality_prompt)
            ->forUser($user->id)
            ->generatePrMessage($prActivity);

        if ($message) {
            $profile->update(['pending_pr_message' => $message]);
        }
    }
}

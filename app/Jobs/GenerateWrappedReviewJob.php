<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AI\CoachingTextService;
use App\Services\WrappedService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Der Coach-Text im Rückblick, außerhalb des Requests.
 *
 * Vorher rief `GET /api/wrapped/review` OpenAI direkt auf: ein GET, der Geld
 * kostet, und ein Aufruf, der den Einzelprozess-Webserver aufhält. Jetzt
 * startet ein POST diesen Job, und die Seite fragt nach, bis der Text im
 * Cache liegt.
 */
class GenerateWrappedReviewJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 120;

    public function __construct(
        public readonly int $userId,
        public readonly string $period,
        public readonly ?int $year,
        public readonly ?int $month,
        public readonly string $cacheKey,
    ) {}

    public function handle(WrappedService $wrapped, CoachingTextService $text): void
    {
        try {
            $user = User::find($this->userId);
            if (! $user || Cache::has($this->cacheKey)) return;

            $stats = $wrapped->generate($user, $this->period, $this->year, $this->month);
            if (empty($stats['has_data'])) return;

            $text->withCoach($user->coach?->personality_prompt)->forUser($user->id);
            $review = $text->generateWrappedReview($stats, $stats['period_label']);

            // Auch ein leerer Text wird gemerkt — sonst fragte die Seite
            // bei jedem Öffnen erneut das Modell.
            Cache::put($this->cacheKey, $review ?? '', now()->addDays(14));
        } finally {
            Cache::forget($this->cacheKey . ':pending');
        }
    }
}

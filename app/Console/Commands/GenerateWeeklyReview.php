<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WeeklyReview;
use App\Services\OpenAIService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateWeeklyReview extends Command
{
    protected $signature   = 'ai:weekly-review {--user=* : Specific user IDs to process}';
    protected $description = 'Generate AI weekly review for all athletes (runs every Monday)';

    public function handle(OpenAIService $openAI): void
    {
        // Last Monday (start of the past week Mon–Sun)
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY)->subWeek()->toDateString();
        $weekEnd   = Carbon::now()->startOfWeek(Carbon::MONDAY)->subDay()->toDateString();  // last Sunday

        $userIds = $this->option('user');
        $query   = User::query();
        if (! empty($userIds)) {
            $query->whereIn('id', $userIds);
        }

        $query->chunk(50, function ($users) use ($openAI, $weekStart, $weekEnd) {
            foreach ($users as $user) {
                // Skip if already generated for this week
                if (WeeklyReview::where('user_id', $user->id)->where('week_start', $weekStart)->exists()) {
                    continue;
                }

                $content = $openAI->generateWeeklyReview($user, $weekStart, $weekEnd);

                if ($content) {
                    WeeklyReview::create([
                        'user_id'    => $user->id,
                        'week_start' => $weekStart,
                        'content'    => $content,
                    ]);
                    $this->line("✓ Weekly review generated for user #{$user->id}");
                } else {
                    $this->line("– Skipped user #{$user->id} (no data)");
                }
            }
        });
    }
}

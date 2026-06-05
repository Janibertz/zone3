<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\StravaAccount;
use App\Services\BestEffortService;
use App\Services\StravaService;
use Illuminate\Console\Command;

class BackfillBestEfforts extends Command
{
    protected $signature = 'strava:backfill-best-efforts
        {--user= : Restrict to a single user id}
        {--limit=80 : Max activities to process this run (Strava rate limit ~100/15min)}';

    protected $description = 'Fetch Strava best efforts for already-imported runs and store personal records';

    public function handle(StravaService $strava, BestEffortService $bestEfforts): int
    {
        $limit     = max(1, (int) $this->option('limit'));
        $processed = 0;

        $accountQuery = StravaAccount::query();
        if ($userId = $this->option('user')) {
            $accountQuery->where('user_id', (int) $userId);
        }

        foreach ($accountQuery->get() as $account) {
            $pending = Activity::where('user_id', $account->user_id)
                ->where('type', 'Run')
                ->whereNull('best_efforts_synced_at')
                ->orderByDesc('start_date')
                ->get();

            foreach ($pending as $activity) {
                if ($processed >= $limit) {
                    $this->warn("Reached limit of {$limit} — re-run to continue.");
                    $this->info("Processed {$processed} activities.");
                    return self::SUCCESS;
                }

                $detail = $strava->fetchActivity($account, (int) $activity->strava_id);
                if (! $detail) {
                    // Likely rate-limited or deleted on Strava — stop to be safe.
                    $this->warn("Detail fetch failed for activity {$activity->id}; stopping.");
                    $this->info("Processed {$processed} activities.");
                    return self::SUCCESS;
                }

                $bestEfforts->syncFromActivityData($activity, $detail);
                $processed++;

                // Gentle pacing to stay well under the rate limit.
                usleep(300_000); // 0.3s
            }
        }

        $this->info("Done. Processed {$processed} activities.");
        return self::SUCCESS;
    }
}

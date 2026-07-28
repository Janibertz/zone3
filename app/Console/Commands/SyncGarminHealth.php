<?php

namespace App\Console\Commands;

use App\Jobs\SyncGarminHealthJob;
use App\Models\User;
use Illuminate\Console\Command;

class SyncGarminHealth extends Command
{
    protected $signature   = 'garmin:sync-health {--days=7 : How many days back to pull} {--user= : Limit to a single user id}';
    protected $description = 'Queue a read-only pull of Garmin recovery data (HRV, sleep, RHR, Body Battery, stress, readiness) for connected users.';

    public function handle(): void
    {
        $days = (int) $this->option('days');

        $query = User::whereNotNull('garmin_session');
        if ($userId = $this->option('user')) {
            $query->where('id', $userId);
        }

        $count = 0;
        $query->each(function (User $user) use ($days, &$count) {
            SyncGarminHealthJob::dispatch($user->id, $days);
            $count++;
        });

        $this->info("Queued Garmin health sync for {$count} user(s) ({$days} days).");
    }
}

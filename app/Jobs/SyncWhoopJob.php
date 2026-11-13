<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Whoop\WhoopActivityImporter;
use App\Services\Whoop\WhoopHealthImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Abruf bei WHOOP: nach dem Verbinden (30 Tage Erholung, 42 Tage Trainings)
 * und nach einem Webhook für Schlaf oder Recovery (nur `health`).
 */
class SyncWhoopJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $userId, public readonly bool $healthOnly = false) {}

    public function uniqueId(): string
    {
        return $this->userId . ($this->healthOnly ? ':health' : '');
    }

    public function handle(WhoopHealthImporter $health, WhoopActivityImporter $activities): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $health->sync($user);
        if (! $this->healthOnly) {
            $activities->sync($user);
        }
    }
}

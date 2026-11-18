<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Rouvy\RouvyActivityImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Nach dem Verbinden einmal die letzten Wochen von ROUVY holen. */
class SyncRouvyActivitiesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly int $userId) {}

    public function handle(RouvyActivityImporter $importer): void
    {
        $user = User::find($this->userId);

        if ($user) {
            $importer->sync($user);
        }
    }
}

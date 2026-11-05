<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Coros\CorosWorkoutSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Den COROS-Kalender eines Athleten abgleichen — einmal pro Athlet in der
 * Queue, mit Verzoegerung, wie `SyncGarminWorkoutsJob`.
 */
class SyncCorosWorkoutsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public const DELAY_SECONDS = 60;

    public int $tries = 2;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(CorosWorkoutSync $sync): void
    {
        $user = User::find($this->userId);

        if ($user) {
            $sync->sync($user);
        }
    }
}

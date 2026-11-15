<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Intervals\IntervalsWorkoutSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Den intervals-Kalender eines Athleten abgleichen — einmal pro Athlet in der
 * Queue, mit Verzögerung, wie bei Garmin und COROS.
 */
class SyncIntervalsWorkoutsJob implements ShouldQueue, ShouldBeUnique
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

    public function handle(IntervalsWorkoutSync $sync): void
    {
        $user = User::find($this->userId);

        if ($user) {
            $sync->sync($user);
        }
    }
}

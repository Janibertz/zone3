<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Garmin\GarminWorkoutSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Den Garmin-Kalender eines Athleten abgleichen.
 *
 * Einmal pro Athlet in der Queue: eine Neuberechnung legt vierzehn Einheiten
 * an, und jede meldet sich. Ein Abgleich am Ende reicht — darum einmalig
 * und mit Verzoegerung, damit der Plan-Job fertig ist, wenn er liest.
 */
class SyncGarminWorkoutsJob implements ShouldQueue, ShouldBeUnique
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

    public function handle(GarminWorkoutSync $sync): void
    {
        $user = User::find($this->userId);

        if ($user) {
            $sync->sync($user);
        }
    }
}

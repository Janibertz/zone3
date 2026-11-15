<?php

namespace App\Services\Intervals;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * intervals.icu trennen — ein Weg für Profil und Konto löschen.
 *
 * Erst die bevorstehenden Radeinheiten aus dem intervals-Kalender nehmen
 * (sonst stünden sie weiter in Zwift), dann bei intervals.icu widerrufen und
 * den Zugang löschen. Übernommene Fahrten bleiben, wie bei Garmin: sie sind
 * gefahren und stehen im Trainingsverlauf.
 */
class IntervalsDisconnector
{
    public function __construct(
        private readonly IntervalsClient $client,
        private readonly IntervalsWorkoutSync $workouts,
    ) {}

    public function disconnect(User $user): void
    {
        $connection = $user->intervalsConnection;

        $this->workouts->removeAll($user);

        if ($connection) {
            try {
                $this->client->revoke($connection);
            } catch (Throwable $e) {
                Log::warning('intervals.icu-Widerruf fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        $connection?->delete();
        $user->unsetRelation('intervalsConnection');
    }
}

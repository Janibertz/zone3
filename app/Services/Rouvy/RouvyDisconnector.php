<?php

namespace App\Services\Rouvy;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ROUVY trennen — ein Weg für Profil, Konto löschen und den
 * `disconnect`-Webhook.
 *
 * Erst die bevorstehenden Workouts aus ROUVY nehmen, dann den Refresh-Token
 * widerrufen und den Zugang löschen. Übernommene Fahrten bleiben, wie bei
 * intervals.icu und Garmin: sie sind gefahren.
 *
 * Hat der Athlet im ROUVY Riders Portal getrennt (`$remote`), ist der Zugang
 * schon tot — dann wird nur hier aufgeräumt.
 */
class RouvyDisconnector
{
    public function __construct(
        private readonly RouvyClient $client,
        private readonly RouvyWorkoutSync $workouts,
    ) {}

    public function disconnect(User $user, bool $remote = false): void
    {
        $connection = $user->rouvyConnection;

        if ($remote) {
            \App\Models\RouvyScheduledWorkout::where('user_id', $user->id)->delete();
        } else {
            $this->workouts->removeAll($user);

            if ($connection) {
                try {
                    $this->client->revoke($connection);
                } catch (Throwable $e) {
                    Log::warning('ROUVY-Widerruf fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $connection?->delete();
        $user->unsetRelation('rouvyConnection');
    }
}

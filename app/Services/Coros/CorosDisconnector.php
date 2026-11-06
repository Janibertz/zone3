<?php

namespace App\Services\Coros;

use App\Models\CorosScheduledWorkout;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * COROS trennen — ein Weg fuer Profil und Konto loeschen.
 *
 * Geloescht werden Zugang, Kalenderplaetze, die Erholungswerte und die
 * Aktivitaeten, die von COROS kamen (Entscheidung von Jan, 06.11.2026 —
 * anders als bei Garmin) (COROS verlangt laut Partner-Vertrag das Loeschen binnen
 * 24 Stunden).
 */
class CorosDisconnector
{
    public function __construct(
        private readonly CorosClient $client,
        private readonly CorosWorkoutSync $workouts,
        private readonly CorosHealthImporter $health,
        private readonly CorosActivityImporter $activities,
    ) {}

    public function disconnect(User $user): void
    {
        $connection = $user->corosConnection;

        if ($connection) {
            // Bevorstehende Workouts absagen, solange der Token noch gilt.
            $this->workouts->cancelAll($user);

            try {
                $this->client->revoke($connection);
            } catch (Throwable $e) {
                Log::warning('COROS-Token nicht widerrufen', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        CorosScheduledWorkout::where('user_id', $user->id)->delete();
        $this->health->forget($user);
        $this->activities->forget($user);
        $connection?->delete();
    }
}

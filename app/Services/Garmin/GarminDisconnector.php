<?php

namespace App\Services\Garmin;

use App\Models\GarminDailyMetric;
use App\Models\GarminScheduledWorkout;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Garmin trennen — ein Weg fuer alle Ausloeser.
 *
 * Profil, Konto loeschen und der Deregistrierungs-Webhook muessen dasselbe
 * tun, und /privacy sagt, was das ist: Zugang und Erholungswerte gehen,
 * Aktivitaeten bleiben (an ihnen haengen erledigte Einheiten und
 * Bewertungen). Der alte Login ueber den Fit-Service wird gleich mit
 * entfernt, damit "Trennen" nicht die Haelfte stehen laesst.
 */
class GarminDisconnector
{
    public function __construct(
        private readonly GarminClient $client,
        private readonly GarminWorkoutSync $workouts,
    ) {}

    /**
     * @param bool $atGarmin  false, wenn Garmin die Trennung selbst gemeldet
     *                        hat — dann gibt es dort nichts mehr aufzuheben.
     */
    public function disconnect(User $user, bool $atGarmin = true): void
    {
        $connection = $user->garminConnection;

        if ($connection && $atGarmin) {
            // Bevorstehende Workouts aus dem Garmin-Kalender nehmen, solange
            // der Token noch gilt — danach kommt Zone3 dort nicht mehr hin.
            $this->workouts->removeAll($user);

            try {
                $this->client->deregister($connection);
            } catch (Throwable $e) {
                // Lokal wird trotzdem getrennt: der Athlet will raus, und ein
                // Token, den Garmin nicht mehr annimmt, ist ohnehin wertlos.
                Log::warning('Garmin-Deregistrierung fehlgeschlagen', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        GarminScheduledWorkout::where('user_id', $user->id)->delete();
        $connection?->delete();
        $user->update(['garmin_email' => null, 'garmin_session' => null]);
        GarminDailyMetric::where('user_id', $user->id)->delete();
    }
}

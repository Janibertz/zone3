<?php

namespace App\Services\Whoop;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WHOOP trennen — ein Weg für Profil und Konto löschen.
 *
 * Bei WHOOP widerrufen (`DELETE /v2/user/access`), dann Zugang, alle
 * Trainings und Erholungswerte löschen, die von WHOOP kamen. WHOOP verbietet
 * dauerhafte Kopien (API Terms 4.2.2) — mit dem Trennen geht alles.
 */
class WhoopDisconnector
{
    public function __construct(
        private readonly WhoopClient $client,
        private readonly WhoopActivityImporter $activities,
        private readonly WhoopHealthImporter $health,
    ) {}

    public function disconnect(User $user): void
    {
        $connection = $user->whoopConnection;

        if ($connection) {
            try {
                $this->client->revoke($connection);
            } catch (Throwable $e) {
                Log::warning('WHOOP-Widerruf fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        $this->activities->forget($user);
        $this->health->forget($user);
        $connection?->delete();
        $user->unsetRelation('whoopConnection');
    }
}

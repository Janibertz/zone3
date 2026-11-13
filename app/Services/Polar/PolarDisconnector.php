<?php

namespace App\Services\Polar;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polar trennen — ein Weg für Profil und Konto löschen.
 *
 * Bei Polar abmelden (widerruft den Token, Polar-Vertrag §3.3), dann Token,
 * alle Trainings und Erholungswerte löschen, die von Polar kamen. Polar verlangt nur das
 * Löschen des Tokens; die Daten gehen trotzdem, so steht es in der
 * Beschreibung im Polar-Admin und in /privacy.
 */
class PolarDisconnector
{
    public function __construct(
        private readonly PolarClient $client,
        private readonly PolarActivityImporter $activities,
        private readonly PolarHealthImporter $health,
    ) {}

    public function disconnect(User $user): void
    {
        $connection = $user->polarConnection;

        if ($connection) {
            try {
                $this->client->deregister($connection);
            } catch (Throwable $e) {
                Log::warning('Polar-Abmeldung fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        $this->activities->forget($user);
        $this->health->forget($user);
        $connection?->delete();
        $user->unsetRelation('polarConnection');
    }
}

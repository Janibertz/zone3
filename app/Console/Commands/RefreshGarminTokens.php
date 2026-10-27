<?php

namespace App\Console\Commands;

use App\Models\GarminConnection;
use App\Services\Garmin\GarminClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Jede Garmin-Verbindung einmal am Tag erneuern.
 *
 * Ein Refresh-Token verfaellt nach rund 90 Tagen ohne Benutzung. Ein Athlet,
 * dessen Uhr drei Monate im Schrank lag, kaeme sonst ohne neues Verbinden
 * nicht mehr zurueck. Ein Fehlschlag steht an der Verbindung
 * (`refresh_error`) und haelt die anderen nicht auf.
 */
class RefreshGarminTokens extends Command
{
    protected $signature   = 'garmin:refresh-tokens {--user= : Nur diesen Nutzer}';
    protected $description = 'Erneuert die Tokens aller offiziellen Garmin-Verbindungen.';

    public function handle(GarminClient $client): int
    {
        $query = GarminConnection::query();
        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
        }

        $ok = $failed = 0;

        $query->each(function (GarminConnection $connection) use ($client, &$ok, &$failed) {
            try {
                $client->refresh($connection, force: true);
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn("Nutzer {$connection->user_id}: {$e->getMessage()}");
            }
        });

        $this->info("Garmin-Tokens erneuert: {$ok}, fehlgeschlagen: {$failed}.");

        return self::SUCCESS;
    }
}

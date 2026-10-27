<?php

namespace App\Jobs;

use App\Services\Garmin\GarminActivityImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ein Activity-Push von Garmin, ausserhalb des Requests verarbeitet.
 *
 * Anders als beim Strava-Webhook, der im Request importiert: Garmin
 * verlangt die 200 vor der Verarbeitung (30 s Timeout), und die Daten
 * stehen schon im Body — kein Abruf, der im Job scheitern koennte. Der
 * Import selbst ist wiederholbar (Suche ueber die Garmin-ID), ein zweiter
 * Versuch legt nichts doppelt an.
 */
class ImportGarminActivitiesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly array $payload) {}

    public function handle(GarminActivityImporter $importer): void
    {
        $outcomes = $importer->import($this->payload);

        if ($outcomes !== []) {
            Log::info('Garmin-Aktivitaeten empfangen', $outcomes);
        }
    }
}

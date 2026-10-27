<?php

namespace App\Jobs;

use App\Services\Garmin\GarminHealthImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ein Health-Push von Garmin, ausserhalb des Requests verarbeitet.
 *
 * Garmin wartet hoechstens 30 Sekunden und verlangt die 200 vor der
 * Verarbeitung. Die Arbeit selbst ist klein (keine Aufrufe nach aussen),
 * kann aber hinter einem Plan-Job in derselben Queue warten — fuer
 * Erholungswerte vom Vortag ist das unkritisch.
 */
class ImportGarminHealthJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly array $payload) {}

    public function handle(GarminHealthImporter $importer): void
    {
        $written = $importer->import($this->payload);

        if ($written !== []) {
            Log::info('Garmin-Erholungswerte empfangen', $written);
        }
    }
}

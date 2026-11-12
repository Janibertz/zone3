<?php

namespace App\Console\Commands;

use App\Services\LiveActivity\LiveActivityService;
use Illuminate\Console\Command;

/** Live Activity „Heute steht an“: starten, aktualisieren, beenden. */
class TickLiveActivities extends Command
{
    protected $signature = 'live-activities:tick';

    protected $description = 'Startet, aktualisiert und beendet die Live Activities per APNs';

    public function handle(LiveActivityService $service): int
    {
        $stats = $service->tick();

        if (array_sum($stats) > 0) {
            $this->info(sprintf('gestartet %d, aktualisiert %d, beendet %d', $stats['started'], $stats['updated'], $stats['ended']));
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Observability\Watchdog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sieht nach, ob etwas kippt — und sagt Bescheid.
 *
 * Laeuft alle 15 Minuten, im selben Takt wie `strava:sync`. `--dry` prueft
 * ohne zu benachrichtigen; damit laesst sich der Zustand ansehen, ohne dass
 * die Abklingzeit verbraucht wird.
 */
class WatchPerformance extends Command
{
    protected $signature = 'perf:watch {--dry : Nur pruefen, nicht benachrichtigen}';

    protected $description = 'Prueft Queues, Fehler und Hintergrundarbeit und benachrichtigt die Admins';

    public function handle(Watchdog $watchdog): int
    {
        $findings = $watchdog->run(notify: ! $this->option('dry'));

        if ($findings === []) {
            $this->info('Keine Auffaelligkeiten.');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->warn(($finding['notified'] ? '[gemeldet] ' : '[still]    ') . $finding['message']);
        }

        // Auch ins Log, damit der Verlauf spaeter in /admin/system/logs
        // nachlesbar ist — die Push-Nachricht ist weg, sobald man sie
        // weggewischt hat.
        Log::warning('Watchdog: Auffaelligkeiten', [
            'count'    => count($findings),
            'findings' => array_column($findings, 'message'),
        ]);

        return self::SUCCESS;
    }
}

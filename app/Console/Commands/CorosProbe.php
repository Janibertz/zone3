<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Coros\CorosClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Einmal-Abruf der Rohantworten von COROS MCP, als Vorlage fuer den Import.
 *
 * MCP antwortet nur mit Fliesstext, dessen Aufbau fuer Aktivitaeten und
 * Gesundheitswerte erst eine echte Uhr zeigt. Das Kommando fragt die
 * Werkzeuge ab, die der Import brauchen wird, und legt die Antworten unter
 * `storage/app/private/coros-probe/` ab (Admin: /admin/coros-probe).
 * Nur mit Einverstaendnis des Athleten ausfuehren; der Container ist
 * fluechtig, ein Deploy loescht die Dateien.
 */
class CorosProbe extends Command
{
    public const DIR = 'coros-probe';

    protected $signature = 'coros:probe {user : ID des Athleten} {--days=14 : Zeitraum}';

    protected $description = 'Speichert Rohantworten von COROS MCP (Aktivitaeten, Gesundheit) als Vorlage fuer den Import';

    public function handle(CorosClient $coros): int
    {
        $user       = User::findOrFail($this->argument('user'));
        $connection = $user->corosConnection;

        if (! $connection) {
            $this->error('Keine COROS-Verbindung.');

            return self::FAILURE;
        }

        $days  = max(1, (int) $this->option('days'));
        $start = now()->subDays($days - 1)->format('Ymd');
        $end   = now()->format('Ymd');
        $stamp = now()->format('Y-m-d_His');
        $base  = self::DIR . "/user{$user->id}_{$stamp}";
        $out   = [];

        $call = function (string $tool, array $args = []) use ($coros, $connection, &$out): ?string {
            try {
                $text = $coros->callTool($connection, $tool, $args);
            } catch (Throwable $e) {
                $text = null;
                $out[] = "=== {$tool} " . json_encode($args) . "\nFEHLER: {$e->getMessage()}\n";
                $this->warn("{$tool}: {$e->getMessage()}");

                return null;
            }
            $out[] = "=== {$tool} " . json_encode($args) . "\n{$text}\n";
            $this->line("{$tool}: " . mb_strlen($text) . ' Zeichen');

            return $text;
        };

        $records = $call('querySportRecords', ['startDate' => $start, 'endDate' => $end]);

        // labelId und sportType aus dem Text — Aufbau unbekannt, darum grosszuegig.
        $labelId = null;
        $sport   = null;
        if ($records && preg_match('/label\s*_?id\W{0,5}([A-Za-z0-9_-]{6,})/i', $records, $m)) {
            $labelId = $m[1];
            if (preg_match('/sport\s*_?type(?:\s*code)?\W{0,5}(\d{2,5})/i', $records, $s)) {
                $sport = (int) $s[1];
            }
        }

        if ($labelId && $sport) {
            $call('getActivityDetail', ['labelId' => $labelId, 'sportType' => $sport]);
            $call('queryActivityLapData', ['labelId' => $labelId, 'sportType' => $sport]);
            $urls = $call('queryActivityFitFileDownloadUrls', ['labelId' => $labelId, 'sportType' => $sport]);

            if ($urls && preg_match('#https://\S+#', $urls, $u)) {
                try {
                    $fit = Http::timeout(60)->get(rtrim($u[0], '.,)"\''))->throw()->body();
                    Storage::disk('local')->put("{$base}.fit", $fit);
                    $this->line('FIT: ' . strlen($fit) . ' Bytes');
                } catch (Throwable $e) {
                    $out[] = "=== FIT-Download\nFEHLER: {$e->getMessage()}\n";
                    $this->warn("FIT: {$e->getMessage()}");
                }
            }
        } else {
            $out[] = "=== Hinweis\nKeine labelId/sportType im Text gefunden — Details uebersprungen.\n";
            $this->warn('Keine Aktivitaet erkannt, Details uebersprungen.');
        }

        $call('queryDailyHealthData', ['days' => $days]);
        $call('querySleepOverview', ['startDate' => $start, 'endDate' => $end]);
        $call('querySleepHrv', ['startDate' => $start, 'endDate' => $end]);
        $call('queryRestingHeartRate', ['days' => $days]);
        $call('queryRecoveryStatus');
        $call('queryTrainingLoadAssessment', ['days' => $days]);
        $call('queryFitnessAssessmentOverview');
        $call('queryDevices');

        Storage::disk('local')->put("{$base}.txt", implode("\n", $out));
        $this->info("Gespeichert: {$base}.txt — herunterladen unter /admin/coros-probe");

        return self::SUCCESS;
    }
}

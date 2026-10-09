<?php

namespace App\Services\Observability;

use App\Models\PerfCommandRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Schreibt mit, welche Hintergrundarbeit wann gelaufen ist.
 *
 * Hier stand einmal eine vollstaendige Messung: jede Anfrage, jede langsame
 * Query, jeder Job, jeder ausgehende Aufruf. In einem Monat Betrieb ergab
 * das 51 Messpunkte und keine einzige Antwort, die nicht auch im Log
 * gestanden haette — bei zwei Athleten misst man keine Lastprobleme, man
 * erfindet sie. Geblieben ist die Achse, auf der Zone3 tatsaechlich
 * kaputtging: sechs Tage ohne Strava-Import, bemerkt vom Athleten selbst.
 *
 * Deshalb wird pro Kommando EINE Zeile fortgeschrieben, nicht angehaengt.
 * Die Frage lautet „lief `strava:sync` in den letzten 45 Minuten", und die
 * beantwortet eine Zeile.
 */
class Recorder
{
    public function enabled(): bool
    {
        return (bool) config('observability.enabled', true);
    }

    /**
     * Ein Kommando ist gelaufen.
     *
     * `push:wellbeing-reminders` laeuft jede Minute. Als Ereignisstrom
     * waeren das 43.000 Zeilen im Monat fuer eine Frage, die eine einzige
     * Zeile beantwortet.
     */
    public function command(string $command, float $durationMs, int $exitCode): void
    {
        if (! $this->enabled()) {
            return;
        }

        $ms = (int) round($durationMs);

        $this->write(function () use ($command, $ms, $exitCode) {
            $run = PerfCommandRun::firstOrNew(['command' => mb_substr($command, 0, 191)]);

            $run->last_run_at      = now();
            $run->last_duration_ms = $ms;
            $run->last_exit_code   = $exitCode;
            $run->slowest_ms       = max($ms, (int) $run->slowest_ms);
            $run->runs_total       = (int) $run->runs_total + 1;
            $run->failures_total   = (int) $run->failures_total + ($exitCode === 0 ? 0 : 1);

            $run->save();
        });
    }

    /**
     * Schreiben, ohne dass ein Fehler nach aussen dringt.
     *
     * Bewusst nur `debug`: eine volle Platte oder eine fehlende Migration
     * soll die Logs nicht fluten und schon gar nicht das Kommando
     * scheitern lassen, dessen Lauf hier nur protokolliert wird.
     */
    private function write(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::debug('Kommandolauf konnte nicht geschrieben werden', ['error' => $e->getMessage()]);
        }
    }
}

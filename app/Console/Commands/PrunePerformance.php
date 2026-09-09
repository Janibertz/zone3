<?php

namespace App\Console\Commands;

use App\Models\PerfAlert;
use App\Models\PerfEvent;
use Illuminate\Console\Command;

/**
 * Raeumt die Messwerte weg, bevor sie zum Problem werden.
 *
 * Die Logdatei in Produktion ist ohne Rotation gewachsen, bis niemand sie
 * mehr lesen konnte. Eine Messtabelle waechst schneller: sie bekommt eine
 * Zeile pro Anfrage. Das hier laeuft naechtlich und ist der Grund, warum die
 * Tabelle nicht dieselbe Geschichte schreibt.
 *
 * Geloescht wird in Haeppchen. Ein einzelnes DELETE ueber Hunderttausende
 * Zeilen sperrt die Tabelle, und die Tabelle wird bei jeder Anfrage
 * beschrieben.
 */
class PrunePerformance extends Command
{
    protected $signature = 'perf:prune {--days= : Abweichende Aufbewahrung in Tagen}';

    protected $description = 'Loescht Messwerte, die aelter sind als die Aufbewahrungsfrist';

    private const CHUNK = 2000;

    public function handle(): int
    {
        $days   = (int) ($this->option('days') ?: config('observability.retention_days', 14));
        $cutoff = now()->subDays($days);

        $deleted = 0;

        // Erst die IDs holen, dann ueber die IDs loeschen. `DELETE ... LIMIT`
        // gibt es unter MySQL, unter SQLite aber nur mit einer Compile-Option,
        // die selten gesetzt ist — und die Tests laufen auf SQLite.
        do {
            $ids   = PerfEvent::where('created_at', '<', $cutoff)->limit(self::CHUNK)->pluck('id');
            $round = $ids->count();

            if ($round > 0) {
                PerfEvent::whereIn('id', $ids)->delete();
                $deleted += $round;
            }
        } while ($round === self::CHUNK);

        // Erledigte Warnungen brauchen kein Gedaechtnis mehr. Offene bleiben,
        // egal wie alt — sie sind ja noch offen.
        $alerts = PerfAlert::whereNotNull('resolved_at')
            ->where('resolved_at', '<', $cutoff)
            ->delete();

        $this->info("{$deleted} Messwert(e) und {$alerts} erledigte Warnung(en) aelter als {$days} Tage geloescht.");

        return self::SUCCESS;
    }
}

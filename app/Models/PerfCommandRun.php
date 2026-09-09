<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Der Zustand eines Hintergrund-Kommandos, fortgeschrieben statt angehaengt.
 *
 * Die Frage lautet nicht „welche 43.000 Laeufe gab es diesen Monat", sondern
 * „lief `strava:sync` in den letzten 45 Minuten". Dafuer reicht eine Zeile
 * pro Kommando.
 */
class PerfCommandRun extends Model
{
    protected $fillable = [
        'command', 'last_run_at', 'last_duration_ms', 'last_exit_code', 'last_query_count',
        'slowest_ms', 'runs_total', 'failures_total',
    ];

    protected $casts = [
        'last_run_at'      => 'datetime',
        'last_duration_ms' => 'integer',
        'last_exit_code'   => 'integer',
        'last_query_count' => 'integer',
        'slowest_ms'       => 'integer',
        'runs_total'       => 'integer',
        'failures_total'   => 'integer',
    ];

    /**
     * Ueberfaellig? Nur beantwortbar fuer Kommandos, deren Taktung in der
     * Konfiguration steht — bei allen anderen gibt es keine Erwartung, die
     * verletzt werden koennte.
     */
    public function isOverdue(): bool
    {
        $expected = config('observability.expected_every_minutes')[$this->command] ?? null;

        if (! $expected || ! $this->last_run_at) {
            return false;
        }

        return $this->last_run_at->lt(now()->subMinutes($expected));
    }
}

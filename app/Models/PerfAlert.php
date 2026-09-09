<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Befund, zu dem schon einmal etwas gesagt wurde.
 *
 * Der Watchdog laeuft alle 15 Minuten. Ohne Gedaechtnis schickte er
 * denselben Satz viermal pro Stunde, und die Nachricht waere nach einem Tag
 * wertlos. `last_notified_at` plus die Abklingzeit aus der Konfiguration
 * sind das Gedaechtnis.
 */
class PerfAlert extends Model
{
    protected $fillable = ['key', 'message', 'last_notified_at', 'resolved_at', 'occurrences'];

    protected $casts = [
        'last_notified_at' => 'datetime',
        'resolved_at'      => 'datetime',
        'occurrences'      => 'integer',
    ];

    /** Darf zu diesem Befund erneut Bescheid gesagt werden? */
    public function mayNotify(): bool
    {
        if (! $this->last_notified_at) {
            return true;
        }

        return $this->last_notified_at->lt(
            now()->subHours((int) config('observability.alerts.cooldown_hours', 6))
        );
    }
}

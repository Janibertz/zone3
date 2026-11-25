<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Die Messpunkte einer Aktivität, auf 2 s ausgedünnt (Jan, 25.11.2026).
 *
 * `data` ist spaltenweise: `t` (Sekunden ab Start, Uhrzeit, nicht
 * Bewegungszeit), `d` (Meter gesamt), `v` (m/s), `hr` (Puls), `alt`
 * (Höhe in m). Eine Reihe ohne einen einzigen Wert fehlt ganz.
 *
 * Kommt nur von Uhr-Quellen (Garmin, COROS, Polar, FIT-Datei, intervals.icu,
 * ROUVY), nie von Strava. Steht nicht in Prompts.
 */
class ActivityStream extends Model
{
    protected $fillable = ['activity_id', 'source', 'interval_s', 'points', 'data'];

    protected $casts = ['data' => 'array'];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}

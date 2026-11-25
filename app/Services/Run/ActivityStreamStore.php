<?php

namespace App\Services\Run;

use App\Models\Activity;
use App\Models\ActivityStream;

/**
 * Legt die Messpunkte einer Aktivität ab. Wie bei den Zahlen gilt die
 * Quelle, die die Reihe zuerst geliefert hat: eine zweite Uhr-Quelle
 * ersetzt sie nicht, dieselbe Quelle frischt sie auf.
 */
class ActivityStreamStore
{
    /** Schlüssel in den Attributen eines Imports, nie eine Spalte. */
    public const KEY = '_stream';

    public static function keep(Activity $activity, string $source, array $data): void
    {
        if ($source === Activity::SOURCE_STRAVA) {
            return; // Strava liefert keine Reihe, und wenn doch: nicht speichern.
        }

        $existing = ActivityStream::where('activity_id', $activity->id)->first();
        if ($existing && $existing->source !== $source) {
            return;
        }

        ActivityStream::updateOrCreate(['activity_id' => $activity->id], [
            'source'     => $source,
            'interval_s' => StreamSampler::INTERVAL,
            'points'     => count($data['t'] ?? []),
            'data'       => $data,
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Grabstein: diese Strava-Aktivität hat der Athlet gelöscht und will
 * sie beim nächsten Abgleich nicht zurückbekommen.
 */
class IgnoredStravaActivity extends Model
{
    protected $fillable = ['user_id', 'strava_id'];

    /** Die Strava-IDs, die für diesen Athleten nicht mehr importiert werden. */
    public static function idsFor(int $userId): array
    {
        return static::where('user_id', $userId)->pluck('strava_id')->all();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Grabstein: diese Aktivität hat der Athlet gelöscht und will sie beim
 * nächsten Abgleich nicht zurückbekommen — aus welcher Quelle auch immer.
 */
class IgnoredActivity extends Model
{
    protected $fillable = ['user_id', 'source', 'external_id'];

    protected $casts = ['external_id' => 'integer'];

    /** Die IDs dieser Quelle, die für den Athleten nicht mehr importiert werden. */
    public static function idsFor(int $userId, string $source): array
    {
        return static::where('user_id', $userId)->where('source', $source)->pluck('external_id')->all();
    }

    public static function blocks(int $userId, string $source, int|string $externalId): bool
    {
        return static::where('user_id', $userId)->where('source', $source)->where('external_id', $externalId)->exists();
    }

    /** Grabsteine für jede ID, die die Aktivität trägt. */
    public static function bury(Activity $activity): void
    {
        foreach (Activity::ID_COLUMNS as $source => $column) {
            if ($activity->{$column}) {
                static::firstOrCreate([
                    'user_id'     => $activity->user_id,
                    'source'      => $source,
                    'external_id' => $activity->{$column},
                ]);
            }
        }
    }
}

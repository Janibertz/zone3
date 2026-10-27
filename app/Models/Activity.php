<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use HasFactory;

    public const SOURCE_STRAVA = 'strava';
    public const SOURCE_GARMIN = 'garmin';

    /** Je Quelle die Spalte mit ihrer ID. Eine Zeile kann beide tragen. */
    public const ID_COLUMNS = [
        self::SOURCE_STRAVA => 'strava_id',
        self::SOURCE_GARMIN => 'garmin_activity_id',
    ];

    /**
     * Sportfamilien für den Duplikat-Abgleich: ein Laufband-Lauf bei Garmin
     * und ein „Run“ bei Strava sind dieselbe Einheit, ein Lauf und eine
     * Fahrt zur selben Zeit nicht.
     */
    private const FAMILIES = [
        'run'  => ['Run', 'TrailRun', 'VirtualRun'],
        'ride' => ['Ride', 'VirtualRide', 'EBikeRide', 'MountainBikeRide', 'GravelRide'],
        'swim' => ['Swim'],
    ];

    protected $fillable = [
        'user_id',
        'strava_id',
        'garmin_activity_id',
        'source',
        'device_name',
        'name',
        'description',
        'type',
        'distance',
        'moving_time',
        'elapsed_time',
        'total_elevation_gain',
        'average_speed',
        'average_watts',
        'weighted_average_watts',
        'device_watts',
        'laps',
        'max_speed',
        'average_heartrate',
        'max_heartrate',
        'start_date',
        'location_city',
        'location_state',
        'location_country',
        'polyline',
        'weather',
        'best_efforts_synced_at',
        'start_lat',
        'start_lng',
    ];

    protected $casts = [
        'start_date'             => 'datetime',
        'polyline'               => 'array',
        'laps'                   => 'array',
        'weather'                => 'array',
        'best_efforts_synced_at' => 'datetime',
        'start_lat'              => 'float',
        'start_lng'              => 'float',
        'device_watts'           => 'boolean',
    ];

    /**
     * All columns except the heavy `polyline` / `laps` JSON blobs.
     * Use for list/summary queries where the route map is not rendered,
     * to avoid loading (and serializing) large GPS payloads.
     */
    public const SUMMARY_COLUMNS = [
        'id', 'user_id', 'strava_id', 'garmin_activity_id', 'source', 'name', 'description', 'type',
        'distance', 'moving_time', 'elapsed_time', 'total_elevation_gain',
        'average_speed', 'average_watts', 'weighted_average_watts', 'device_watts', 'max_speed', 'average_heartrate',
        'start_date', 'location_city', 'location_state', 'location_country',
        'created_at', 'updated_at',
    ];

    public static function sportFamily(?string $type): string
    {
        foreach (self::FAMILIES as $family => $types) {
            if (in_array($type, $types, true)) {
                return $family;
            }
        }

        return 'other:' . $type;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bestEfforts()
    {
        return $this->hasMany(BestEffort::class);
    }
}

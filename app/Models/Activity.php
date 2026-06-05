<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'strava_id',
        'name',
        'description',
        'type',
        'distance',
        'moving_time',
        'elapsed_time',
        'total_elevation_gain',
        'average_speed',
        'average_watts',
        'laps',
        'max_speed',
        'average_heartrate',
        'max_heartrate',
        'start_date',
        'location_city',
        'location_state',
        'location_country',
        'polyline',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'polyline'   => 'array',
        'laps'       => 'array',
    ];

    /**
     * All columns except the heavy `polyline` / `laps` JSON blobs.
     * Use for list/summary queries where the route map is not rendered,
     * to avoid loading (and serializing) large GPS payloads.
     */
    public const SUMMARY_COLUMNS = [
        'id', 'user_id', 'strava_id', 'name', 'description', 'type',
        'distance', 'moving_time', 'elapsed_time', 'total_elevation_gain',
        'average_speed', 'average_watts', 'max_speed', 'average_heartrate',
        'start_date', 'location_city', 'location_state', 'location_country',
        'created_at', 'updated_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

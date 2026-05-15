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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

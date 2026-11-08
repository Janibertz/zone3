<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GarminDailyMetric extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'source',
        'hrv',
        'resting_hr',
        'sleep_hours',
        'sleep_score',
        'body_battery_low',
        'body_battery_high',
        'stress_avg',
        'steps',
        'vo2max',
        'vo2max_cycling',
        'fitness_age',
        'spo2',
        'respiration_rate',
    ];

    protected $casts = [
        'date'           => 'date',
        'hrv'            => 'float',
        'sleep_hours'    => 'float',
        'vo2max'         => 'float',
        'vo2max_cycling' => 'float',
        'spo2'           => 'float',
        'respiration_rate' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

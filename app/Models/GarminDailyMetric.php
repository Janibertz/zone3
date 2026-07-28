<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GarminDailyMetric extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'hrv',
        'resting_hr',
        'sleep_hours',
        'sleep_score',
        'body_battery_low',
        'body_battery_high',
        'stress_avg',
        'steps',
        'training_readiness',
    ];

    protected $casts = [
        'date'        => 'date',
        'hrv'         => 'float',
        'sleep_hours' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

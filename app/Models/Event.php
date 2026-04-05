<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'event_date',
        'race_distance',
        'distance_km',
        'priority',
        'target_time_hours',
        'target_time_minutes',
        'notes',
    ];

    protected $casts = [
        'event_date'          => 'date',
        'distance_km'         => 'float',
        'target_time_hours'   => 'integer',
        'target_time_minutes' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function trainingPlans()
    {
        return $this->hasMany(TrainingPlan::class);
    }

    public function latestPlan()
    {
        return $this->hasOne(TrainingPlan::class)->latestOfMany();
    }

    public function getDistanceLabelAttribute(): string
    {
        return match ($this->race_distance) {
            '5km'           => '5 km',
            '10km'          => '10 km',
            'half_marathon' => 'Halbmarathon',
            'marathon'      => 'Marathon',
            default         => $this->distance_km ? number_format($this->distance_km, 1, ',', '.') . ' km' : 'Eigene Distanz',
        };
    }

    public function getTargetTimeFormattedAttribute(): ?string
    {
        $h = $this->target_time_hours;
        $m = $this->target_time_minutes;
        if ($h === 0 && $m === 0) return null;
        return $h > 0 ? sprintf('%d:%02d Std', $h, $m) : "{$m} Min";
    }

    public function getDaysUntilAttribute(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->event_date->copy()->startOfDay(), false);
    }

    public function getTrainingPhaseAttribute(): array
    {
        $days = $this->days_until;

        if ($days < 0) {
            return ['key' => 'past',      'label' => 'Vergangenheit', 'color' => 'gray'];
        } elseif ($days <= 14) {
            return ['key' => 'race_week', 'label' => 'Race Week',     'color' => 'red'];
        } elseif ($days <= 28) {
            return ['key' => 'taper',     'label' => 'Taper',         'color' => 'yellow'];
        } elseif ($days <= 70) {
            return ['key' => 'peak',      'label' => 'Peak',           'color' => 'orange'];
        } elseif ($days <= 112) {
            return ['key' => 'build',     'label' => 'Build',          'color' => 'blue'];
        } else {
            return ['key' => 'base',      'label' => 'Base',           'color' => 'green'];
        }
    }

    public function getWeeksUntilAttribute(): int
    {
        return max(0, (int) ceil($this->days_until / 7));
    }
}

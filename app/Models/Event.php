<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    /** Standard Backyard Ultra loop distance in km (4.167 mi). */
    public const BACKYARD_LAP_KM = 6.706;

    protected $appends = ['distance_label', 'target_time_formatted'];

    protected $fillable = [
        'user_id',
        'name',
        'event_date',
        'race_distance',
        'distance_km',
        'priority',
        'target_time_hours',
        'target_time_minutes',
        'target_yards',
        'plan_generating',
        'plan_error',
        'notes',
    ];

    protected $casts = [
        'event_date'          => 'date',
        'distance_km'         => 'float',
        'target_time_hours'   => 'integer',
        'target_time_minutes' => 'integer',
        'target_yards'        => 'integer',
        'plan_generating'     => 'boolean',
    ];

    public function isBackyard(): bool
    {
        return $this->race_distance === 'backyard_ultra';
    }

    /** Total target distance in km derived from the yard goal. */
    public function getTargetDistanceKmAttribute(): ?float
    {
        return $this->isBackyard() && $this->target_yards
            ? round($this->target_yards * self::BACKYARD_LAP_KM, 1)
            : null;
    }

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
            'backyard_ultra'=> 'Backyard Ultra',
            default         => $this->distance_km ? number_format($this->distance_km, 1, ',', '.') . ' km' : 'Eigene Distanz',
        };
    }

    public function getTargetTimeFormattedAttribute(): ?string
    {
        if ($this->isBackyard()) {
            if (! $this->target_yards) return null;
            $km = number_format($this->target_distance_km, 1, ',', '.');
            return "{$this->target_yards} Std (≈ {$km} km)";
        }

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

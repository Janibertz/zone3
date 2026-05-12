<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RunnerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'threshold_heart_rate',
        'max_heart_rate',
        'threshold_speed',
        'heart_rate_zones',
        'pace_zones',
        'has_completed_setup',
        'today_recommendation',
        'recommendation_date',
        'recommendation_wellbeing_id',
        'threshold_pace_calculated_at',
        'threshold_pace_history',
        'threshold_pace_calculating',
        'weekly_availability',
        'daily_message',
        'daily_message_date',
        'pending_pr_activity_id',
        'pending_pr_message',
    ];

    protected $casts = [
        'heart_rate_zones' => 'array',
        'pace_zones' => 'array',
        'threshold_pace_history' => 'array',
        'has_completed_setup' => 'boolean',
        'threshold_pace_calculated_at' => 'datetime',
        'threshold_pace_calculating' => 'boolean',
        'weekly_availability'        => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Calculate heart rate zones based on threshold HR
     */
    public function calculateHeartRateZones(): array
    {
        if (!$this->threshold_heart_rate || !$this->max_heart_rate) {
            return [];
        }

        $lthr = $this->threshold_heart_rate;
        $maxhr = $this->max_heart_rate;
        $hrr = $maxhr - 60; // Heart Rate Reserve

        return [
            'z1' => ['name' => 'Zone 1 (Recovery)', 'min' => 60, 'max' => (int)($lthr * 0.81)],
            'z2' => ['name' => 'Zone 2 (Aerobic)', 'min' => (int)($lthr * 0.81), 'max' => $lthr],
            'z3' => ['name' => 'Zone 3 (Tempo)', 'min' => $lthr, 'max' => (int)($lthr * 1.06)],
            'z4' => ['name' => 'Zone 4 (Threshold)', 'min' => (int)($lthr * 1.06), 'max' => (int)($lthr * 1.15)],
            'z5' => ['name' => 'Zone 5 (VO2 Max)', 'min' => (int)($lthr * 1.15), 'max' => $maxhr],
        ];
    }

    /**
     * Calculate pace zones based on threshold pace (in minutes as float)
     */
    public function calculatePaceZones(): array
    {
        if (!$this->threshold_speed) {
            return [];
        }

        // threshold_speed is stored as minutes (e.g. 5.5 = 5:30)
        $ts = $this->threshold_speed;

        // Build a model similar zu deinem Screenshot (4:19):
        // Z1 > +1:21, Z2 0:46 - 1:21, Z3 0:21 - 0:46, Z4 0:00 - 0:21, Z5 < -0:04 
        $z1Min = $ts + (1 + 21/60); // +1:21
        $z2Min = $ts + (0 + 46/60); // +0:46
        $z3Min = $ts + (0 + 21/60); // +0:21
        $z4Min = $ts;
        $z5Max = max(0, $ts - (0 + 4/60)); // -0:04

        return [
            'z1' => [
                'name' => 'Zone 1 (Recovery)',
                'min_pace' => $this->minutesToPace(round($z1Min, 2)),
                'max_pace' => '∞',
            ],
            'z2' => [
                'name' => 'Zone 2 (Easy)',
                'min_pace' => $this->minutesToPace(round($z2Min, 2)),
                'max_pace' => $this->minutesToPace(round($z1Min, 2)),
            ],
            'z3' => [
                'name' => 'Zone 3 (Tempo)',
                'min_pace' => $this->minutesToPace(round($z3Min, 2)),
                'max_pace' => $this->minutesToPace(round($z2Min, 2)),
            ],
            'z4' => [
                'name' => 'Zone 4 (Threshold)',
                'min_pace' => $this->minutesToPace(round($z4Min, 2)),
                'max_pace' => $this->minutesToPace(round($z3Min, 2)),
            ],
            'z5' => [
                'name' => 'Zone 5 (VO2 Max)',
                'min_pace' => '0:00',
                'max_pace' => $this->minutesToPace(round($z5Max, 2)),
            ],
        ];
    }

    /**
     * Convert minutes (float) to pace format (MM:SS)
     * E.g. 5.5 = "5:30"
     */
    private function minutesToPace(float $minutes): string
    {
        if ($minutes <= 0) return '—';
        $mins = (int)$minutes;
        $secs = (int)(($minutes - $mins) * 60);
        return sprintf('%d:%02d', $mins, $secs);
    }
}

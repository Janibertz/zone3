<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workout extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'description',
        'blocks',
        'tags',
        'estimated_distance_km',
        'estimated_duration_min',
        'times_used',
        'last_used_at',
    ];

    protected $casts = [
        'blocks'       => 'array',
        'tags'         => 'array',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Compute total distance in metres from blocks.
     */
    public static function computeDistanceM(array $blocks): float
    {
        $total = 0;
        foreach ($blocks as $block) {
            $mode = $block['duration_mode'] ?? 'time';
            if ($mode === 'distance' && isset($block['distance_m'])) {
                $total += (float) $block['distance_m'];
            }
            if (isset($block['steps'])) {
                $reps = (int) ($block['repetitions'] ?? 1);
                foreach ($block['steps'] as $step) {
                    $sMode = $step['duration_mode'] ?? 'time';
                    if ($sMode === 'distance' && isset($step['distance_m'])) {
                        $total += (float) $step['distance_m'] * $reps;
                    }
                }
            }
        }
        return $total;
    }

    /**
     * Compute total duration in seconds from blocks.
     */
    public static function computeDurationSec(array $blocks): int
    {
        $total = 0;
        foreach ($blocks as $block) {
            $mode = $block['duration_mode'] ?? 'time';
            if ($mode === 'time' && isset($block['duration_sec'])) {
                $total += (int) $block['duration_sec'];
            }
            if (isset($block['steps'])) {
                $reps = (int) ($block['repetitions'] ?? 1);
                foreach ($block['steps'] as $step) {
                    $sMode = $step['duration_mode'] ?? 'time';
                    if ($sMode === 'time' && isset($step['duration_sec'])) {
                        $total += (int) $step['duration_sec'] * $reps;
                    }
                }
            }
        }
        return $total;
    }
}

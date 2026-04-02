<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WellbeingEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'energy_level',
        'mood',
        'sleep_quality',
        'muscle_soreness',
        'stress_level',
        'notes',
        'is_sick',
        'is_injured',
    ];

    protected $casts = [
        'date' => 'date',
        'is_sick' => 'boolean',
        'is_injured' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get wellbeing score (average of all metrics)
     */
    public function getWellbeingScore(): float
    {
        $total = $this->energy_level + $this->mood + $this->sleep_quality + 
                 (10 - $this->muscle_soreness) + (10 - $this->stress_level);
        
        return round($total / 5, 1);
    }

    /**
     * Get wellbeing status
     */
    public function getStatus(): string
    {
        $score = $this->getWellbeingScore();

        if ($this->is_sick || $this->is_injured) {
            return '🤕 Nicht trainieren';
        } elseif ($score >= 8) {
            return '🚀 Perfekt für hartes Training';
        } elseif ($score >= 6) {
            return '💪 Moderates Training möglich';
        } elseif ($score >= 4) {
            return '😴 Nur leichtes Training';
        } else {
            return '⚠️ Ruhetag empfohlen';
        }
    }
}

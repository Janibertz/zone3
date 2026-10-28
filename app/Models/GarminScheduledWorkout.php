<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Workout, das Zone3 per Training API in den Garmin-Kalender gelegt hat.
 *
 * `payload_hash` ist der Fingerabdruck dessen, was gesendet wurde. Stimmt er
 * mit der Einheit noch ueberein, bleibt das Workout stehen — ohne einen
 * einzigen Aufruf bei Garmin.
 */
class GarminScheduledWorkout extends Model
{
    protected $fillable = [
        'user_id', 'training_session_id', 'workout_id', 'manual', 'date',
        'garmin_workout_id', 'garmin_schedule_id', 'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'date'   => 'date',
            'manual' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function trainingSession()
    {
        return $this->belongsTo(TrainingSession::class);
    }
}

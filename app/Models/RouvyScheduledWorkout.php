<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Welche Radeinheit als welches Workout in der ROUVY-Bibliothek steht. */
class RouvyScheduledWorkout extends Model
{
    protected $fillable = ['user_id', 'training_session_id', 'workout_id', 'planned_date', 'payload_hash'];

    protected function casts(): array
    {
        return ['planned_date' => 'date'];
    }
}

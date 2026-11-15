<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Welche Radeinheit als welches Event im intervals-Kalender steht. */
class IntervalsScheduledWorkout extends Model
{
    protected $fillable = ['user_id', 'training_session_id', 'external_id', 'event_id', 'planned_date', 'payload_hash'];

    protected function casts(): array
    {
        return ['planned_date' => 'date'];
    }
}

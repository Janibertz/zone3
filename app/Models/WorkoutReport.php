<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Meldung eines geteilten Workouts durch einen anderen Athleten. */
class WorkoutReport extends Model
{
    protected $fillable = ['workout_id', 'user_id', 'reason'];

    public function workout()
    {
        return $this->belongsTo(Workout::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrainingPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'event_id',
        'sessions',
        // is_active war nur als Cast hinterlegt, nicht als fillable. Die Jobs
        // setzen die Spalte über den Query Builder und kamen damit durch;
        // TrainingPlanController::show() benutzt aber $plan->update(...), um
        // den Plan eines vergangenen Events stillzulegen — und das lief ins
        // Leere. Der alte Plan blieb aktiv, und wer danach „den aktiven Plan"
        // suchte, konnte den falschen bekommen.
        'is_active',
        'context',
        'needs_plan_update',
        'availability_overrides',
        'actual_time_hours',
        'actual_time_minutes',
        'overall_rating',
        'result_notes',
        'predicted_finish_time',
        'predicted_pace',
        'prediction_trend',
        'prediction_target_delta_sec',
        'prediction_run_count',
        'prediction_text',
        'prediction_updated_at',
        'race_strategy_text',
        'race_analysis_text',
        'race_analysis_activity_id',
    ];

    protected $casts = [
        'sessions'                => 'array',
        'context'                 => 'array',
        'is_active'               => 'boolean',
        'needs_plan_update'       => 'boolean',
        'availability_overrides'  => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function trainingSessions()
    {
        return $this->hasMany(TrainingSession::class)->orderBy('planned_date');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Activity;

class TrainingSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'training_plan_id',
        'event_id',
        'activity_id',
        'planned_date',
        'type',
        'title',
        'description',
        'distance_km',
        'duration_min',
        'pace_target',
        'zone',
        'planned_snapshot',
        'was_unplanned',
        'intensity',
        'status',
        'skip_reason',
        'sort_order',
        'rating',
        'effort_perceived',
        'feeling_notes',
        'coach_review',
        'review_question',
        'review_options',
        'review_feedback',
        'reviewed_at',
        'nutrition_tips',
        'steps',
        'exercises',
    ];

    protected $casts = [
        'planned_date'     => 'date',
        'distance_km'      => 'float',
        'zone'             => 'integer',
        'planned_snapshot' => 'array',
        'was_unplanned'    => 'boolean',
        'sort_order'       => 'integer',
        'rating'           => 'integer',
        'effort_perceived' => 'integer',
        'review_options'   => 'array',
        'reviewed_at'      => 'datetime',
        'nutrition_tips'   => 'array',
        'steps'            => 'array',
        'exercises'        => 'array',
    ];

    public function user()        { return $this->belongsTo(User::class); }
    public function trainingPlan(){ return $this->belongsTo(TrainingPlan::class); }
    public function event()       { return $this->belongsTo(Event::class); }
    public function activity()    { return $this->belongsTo(Activity::class); }
}

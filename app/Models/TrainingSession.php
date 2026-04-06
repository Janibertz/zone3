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
        'intensity',
        'status',
        'skip_reason',
        'sort_order',
    ];

    protected $casts = [
        'planned_date' => 'date',
        'distance_km'  => 'float',
        'zone'         => 'integer',
        'sort_order'   => 'integer',
    ];

    public function user()        { return $this->belongsTo(User::class); }
    public function trainingPlan(){ return $this->belongsTo(TrainingPlan::class); }
    public function event()       { return $this->belongsTo(Event::class); }
    public function activity()    { return $this->belongsTo(Activity::class); }
}

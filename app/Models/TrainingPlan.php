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
        'context',
        'needs_plan_update',
    ];

    protected $casts = [
        'sessions'          => 'array',
        'context'           => 'array',
        'is_active'         => 'boolean',
        'needs_plan_update' => 'boolean',
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

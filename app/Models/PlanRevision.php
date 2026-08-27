<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanRevision extends Model
{
    protected $fillable = [
        'user_id',
        'event_id',
        'training_plan_id',
        'triggered_by',
        'changes',
        'corrections',
    ];

    protected $casts = [
        'changes'     => 'array',
        'corrections' => 'array',
    ];

    public const TRIGGER_LABELS = [
        'initial' => 'Erster Plan',
        'manual'  => 'Von dir neu berechnet',
        'weekly'  => 'Wochenplanung',
        'user'    => 'Nach deiner Rückmeldung',
        'auto'    => 'Automatisch angepasst',
    ];

    public function triggerLabel(): string
    {
        return self::TRIGGER_LABELS[$this->triggered_by] ?? self::TRIGGER_LABELS['auto'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}

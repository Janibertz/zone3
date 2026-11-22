<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Protokoll einer Nachricht an den Coach, siehe Migration `create_coach_turns_table`. */
class CoachTurn extends Model
{
    protected $fillable = [
        'user_id', 'question_id', 'reply_id', 'channel', 'engine', 'model', 'steps',
        'tools', 'changes', 'nudges', 'flagged', 'fallback', 'cost_eur', 'duration_ms',
    ];

    protected $casts = [
        'tools'    => 'array',
        'nudges'   => 'array',
        'flagged'  => 'boolean',
        'fallback' => 'boolean',
        'cost_eur' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

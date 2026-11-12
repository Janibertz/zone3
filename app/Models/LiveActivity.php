<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eine laufende Live Activity mit ihrem Update-Token (`LiveActivityService`). */
class LiveActivity extends Model
{
    protected $fillable = ['user_id', 'activity_id', 'day', 'push_token', 'state_hash', 'ended_at'];

    protected $hidden = ['push_token'];

    protected $casts = ['day' => 'date', 'ended_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Start-Token eines iPhones für die Live Activity (`LiveActivityService`). */
class LiveActivityDevice extends Model
{
    protected $fillable = ['user_id', 'start_token', 'started_on'];

    protected $hidden = ['start_token'];

    protected $casts = ['started_on' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

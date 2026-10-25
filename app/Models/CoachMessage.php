<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachMessage extends Model
{
    const UPDATED_AT = null;

    public const CHANNEL_WEB      = 'web';
    public const CHANNEL_TELEGRAM = 'telegram';

    protected $fillable = ['user_id', 'role', 'content', 'channel', 'reply_to_id', 'actions'];

    protected $casts = [
        'created_at' => 'datetime',
        'actions'    => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

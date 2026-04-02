<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StravaAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'strava_id',
        'username',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scope',
        'last_synced_at',
    ];

    protected $casts = [
        'scope' => 'array',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

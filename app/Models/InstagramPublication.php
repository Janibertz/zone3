<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramPublication extends Model
{
    public const WAITING   = 'waiting';
    public const PUBLISHED = 'published';
    public const FAILED    = 'failed';
    public const MISSED    = 'missed';

    protected $fillable = [
        'slug', 'type', 'scheduled_at', 'status', 'container_id',
        'media_id', 'permalink', 'error', 'published_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];
}

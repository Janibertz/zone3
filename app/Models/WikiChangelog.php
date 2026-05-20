<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WikiChangelog extends Model
{
    protected $fillable = [
        'commit_sha', 'branch', 'pusher_name', 'commits', 'files_changed', 'ai_summary', 'pushed_at',
    ];

    protected $casts = [
        'commits'       => 'array',
        'files_changed' => 'array',
        'pushed_at'     => 'datetime',
    ];
}

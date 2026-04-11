<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyReview extends Model
{
    protected $fillable = ['user_id', 'week_start', 'content'];

    protected $casts = [
        'week_start' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

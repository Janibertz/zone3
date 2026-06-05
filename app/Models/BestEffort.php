<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BestEffort extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'activity_id',
        'distance_key',
        'distance_m',
        'elapsed_time',
        'achieved_at',
    ];

    protected $casts = [
        'achieved_at'  => 'datetime',
        'distance_m'   => 'integer',
        'elapsed_time' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}

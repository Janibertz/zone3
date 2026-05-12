<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachMessage extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['user_id', 'role', 'content'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

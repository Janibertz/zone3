<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $fillable = ['name', 'email', 'message', 'user_id', 'handled_at'];

    protected $casts = ['handled_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

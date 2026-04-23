<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coach extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'specialty',
        'tagline',
        'description',
        'avatar_color',
        'avatar_initials',
        'personality_prompt',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}

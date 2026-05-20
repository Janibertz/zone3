<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WikiPage extends Model
{
    protected $fillable = [
        'slug', 'category', 'title', 'content', 'sort_order', 'updated_by',
    ];

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

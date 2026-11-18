<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Eine Nachricht aus „Push senden“ im Admin — für „Zuletzt gesendet“. */
class AdminPush extends Model
{
    protected $fillable = [
        'sent_by', 'audience', 'audience_label', 'target_user_id',
        'title', 'body', 'url', 'reached', 'via_telegram', 'via_push',
    ];

    public function target()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}

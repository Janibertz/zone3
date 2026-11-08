<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Gerät, das Push bekommt. `kind = web`: Browser bzw. Homescreen-App
 * (VAPID, Schlüssel in public_key/auth_token). `kind = apns`: die iOS-App,
 * `endpoint` ist dann das Gerätetoken von Apple.
 */
class PushSubscription extends Model
{
    public const KIND_WEB  = 'web';
    public const KIND_APNS = 'apns';

    protected $fillable = [
        'user_id',
        'kind',
        'endpoint',
        'public_key',
        'auth_token',
        'user_agent',
    ];

    protected $attributes = [
        'kind' => self::KIND_WEB,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

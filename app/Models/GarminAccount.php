<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class GarminAccount extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'password_encrypted',
        'cookies',
        'cookies_expire_at',
        'connected_at',
    ];

    protected $casts = [
        'cookies'           => 'array',
        'cookies_expire_at' => 'datetime',
        'connected_at'      => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function setPasswordAttribute(string $password): void
    {
        $this->attributes['password_encrypted'] = Crypt::encryptString($password);
    }

    public function getDecryptedPassword(): string
    {
        return Crypt::decryptString($this->password_encrypted);
    }

    public function hasFreshCookies(): bool
    {
        return ! empty($this->cookies) &&
               $this->cookies_expire_at !== null &&
               $this->cookies_expire_at->isFuture();
    }
}

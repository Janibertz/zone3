<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StravaAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'strava_id',
        'username',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scope',
        'last_synced_at',
        'sync_error',
        'sync_error_at',
    ];

    protected $casts = [
        'scope' => 'array',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'sync_error_at' => 'datetime',
    ];

    /**
     * Traegt das Konto noch, oder muss der Athlet neu verbinden?
     *
     * Ein vorhandener Refresh-Token ist nicht dasselbe wie ein gueltiger.
     * Wer Zone3 in seinen Strava-Einstellungen entzieht, hinterlaesst einen
     * Token, der weiter in der Datenbank steht und beim Abgleich mit 401
     * abgewiesen wird — sichtbar wurde das erst, als der Fehler protokolliert
     * wurde.
     */
    public function isUsable(): bool
    {
        return filled($this->refresh_token) && $this->sync_error === null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

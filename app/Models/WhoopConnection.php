<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Die WHOOP-Verbindung eines Athleten (OAuth2 mit Refresh-Token).
 *
 * Tokens verschlüsselt und versteckt. WHOOP ist reines Lesen — ohne
 * Einwilligung gibt es keine Verbindung (`ai_consent_at`, beim Verbinden
 * gesetzt, damit der Zeitpunkt nachweisbar ist).
 */
#[Hidden(['access_token', 'refresh_token'])]
class WhoopConnection extends Model
{
    protected $fillable = [
        'user_id', 'whoop_user_id', 'access_token', 'refresh_token', 'expires_at', 'scopes',
        'ai_consent_at', 'activities_synced_at', 'health_synced_at', 'last_error', 'last_error_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token'         => 'encrypted',
            'refresh_token'        => 'encrypted',
            'expires_at'           => 'datetime',
            'ai_consent_at'        => 'datetime',
            'activities_synced_at' => 'datetime',
            'health_synced_at'     => 'datetime',
            'last_error_at'        => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function readsData(): bool
    {
        return $this->ai_consent_at !== null;
    }

    /** Was das Profil anzeigt — ohne Tokens. */
    public function summary(): array
    {
        return [
            'connected_at' => $this->created_at?->toIso8601String(),
            'synced_at'    => ($this->health_synced_at ?? $this->activities_synced_at)?->toIso8601String(),
            'error'        => $this->last_error,
        ];
    }
}

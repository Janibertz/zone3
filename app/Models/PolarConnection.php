<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Die Polar-Verbindung eines Athleten (AccessLink, OAuth2).
 *
 * Der Token verlässt den Server nie: verschlüsselt gespeichert, versteckt
 * bei jeder Serialisierung. Was das Profil braucht, steht in `summary()`.
 *
 * Polar ist reines Lesen — ohne Einwilligung gibt es keine Verbindung
 * (`ai_consent_at` wird beim Verbinden gesetzt). Die Spalte steht trotzdem
 * da, damit der Zeitpunkt der Einwilligung nachweisbar ist.
 */
#[Hidden(['access_token'])]
class PolarConnection extends Model
{
    protected $fillable = [
        'user_id', 'polar_user_id', 'access_token', 'ai_consent_at',
        'device_name', 'activities_synced_at', 'last_error', 'last_error_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token'         => 'encrypted',
            'ai_consent_at'        => 'datetime',
            'activities_synced_at' => 'datetime',
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

    /** Was das Profil anzeigt — ohne Token. */
    public function summary(): array
    {
        return [
            'connected_at' => $this->created_at?->toIso8601String(),
            'synced_at'    => $this->activities_synced_at?->toIso8601String(),
            'device_name'  => $this->device_name,
            'error'        => $this->last_error,
        ];
    }
}

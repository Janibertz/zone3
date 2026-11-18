<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Die ROUVY-Verbindung eines Athleten (18.11.2026): Radeinheiten in die
 * ROUVY-Bibliothek, Fahrten zurück.
 *
 * Verbunden wird nur mit Einwilligung (`ai_consent_at`), weil die Fahrten in
 * Review und Plan (OpenAI) gehen — ROUVY erlaubt das nur mit ausdrücklicher
 * Einwilligung (api@rouvy.com, 18.11.2026; eine API-Policy folgt).
 */
#[Hidden(['access_token', 'refresh_token'])]
class RouvyConnection extends Model
{
    protected $fillable = [
        'user_id', 'rouvy_user_id', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'ai_consent_at',
        'workouts_synced_at', 'activities_synced_at', 'last_error', 'last_error_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token'         => 'encrypted',
            'refresh_token'        => 'encrypted',
            'expires_at'           => 'datetime',
            'ai_consent_at'        => 'datetime',
            'workouts_synced_at'   => 'datetime',
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
            'synced_at'    => $this->workouts_synced_at?->toIso8601String(),
            'error'        => $this->last_error,
        ];
    }
}

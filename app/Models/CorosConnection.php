<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Die COROS-Verbindung eines Athleten (MCP, OAuth2 mit PKCE).
 *
 * Wie bei Garmin verlassen die Tokens den Server nie: verschluesselt
 * gespeichert, versteckt bei jeder Serialisierung. Was das Profil braucht,
 * steht in `summary()`.
 */
#[Hidden(['access_token', 'refresh_token'])]
class CorosConnection extends Model
{
    protected $fillable = [
        'user_id', 'coros_user_id', 'client_id',
        'access_token', 'refresh_token', 'access_token_expires_at',
        'workout_sync', 'last_refreshed_at', 'refresh_error', 'refresh_error_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token'            => 'encrypted',
            'refresh_token'           => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'workout_sync'            => 'boolean',
            'last_refreshed_at'       => 'datetime',
            'refresh_error_at'        => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Was das Profil anzeigt — ohne Tokens. */
    public function summary(): array
    {
        return [
            'connected_at'  => $this->created_at?->toIso8601String(),
            'workout_sync'  => (bool) $this->workout_sync,
            'refresh_error' => $this->refresh_error,
        ];
    }
}

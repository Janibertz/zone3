<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Die offizielle Garmin-Verbindung eines Athleten.
 *
 * Die Tokens verlassen den Server nie: verschluesselt gespeichert, versteckt
 * bei jeder Serialisierung. Was das Frontend braucht, steht in `summary()`.
 */
#[Hidden(['access_token', 'refresh_token'])]
class GarminConnection extends Model
{
    /** Freigabe-Namen, wie `user/permissions` sie liefert. */
    public const ACTIVITY = 'ACTIVITY_EXPORT';
    public const HEALTH   = 'HEALTH_EXPORT';
    public const WORKOUTS = 'WORKOUT_IMPORT';

    protected $fillable = [
        'user_id', 'garmin_user_id',
        'access_token', 'refresh_token',
        'access_token_expires_at', 'refresh_token_expires_at',
        'permissions', 'permissions_checked_at', 'ai_consent_at',
        'last_refreshed_at', 'refresh_error', 'refresh_error_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token'             => 'encrypted',
            'refresh_token'            => 'encrypted',
            'access_token_expires_at'  => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'permissions'              => 'array',
            'permissions_checked_at'   => 'datetime',
            'ai_consent_at'            => 'datetime',
            'last_refreshed_at'        => 'datetime',
            'refresh_error_at'         => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, self::normalizePermissions($this->permissions), true);
    }

    /**
     * Die Freigaben als flache Liste von Namen. Die Spezifikation zeigt eine
     * nackte Liste, Garmin liefert sie aber eine Ebene tiefer verpackt —
     * am 27.10.2026 im Log als `[["ACTIVITY_EXPORT", …]]` gespeichert. Wer
     * nur die oberste Ebene las, fand keine einzige Freigabe.
     */
    public static function normalizePermissions(mixed $raw): array
    {
        return array_values(array_unique(array_filter(
            Arr::flatten((array) $raw),
            fn ($value) => is_string($value) && $value !== '',
        )));
    }

    /** Was das Profil anzeigt — ohne Tokens. */
    public function summary(): array
    {
        return [
            'connected_at'  => $this->created_at?->toIso8601String(),
            'activities'    => $this->allows(self::ACTIVITY),
            'health'        => $this->allows(self::HEALTH),
            'workouts'      => $this->allows(self::WORKOUTS),
            'refresh_error' => $this->refresh_error,
        ];
    }
}

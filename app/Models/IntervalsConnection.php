<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Die intervals.icu-Verbindung eines Athleten — nur als Brücke zu Zwift.
 *
 * intervals.icu kennt keinen Refresh-Token: der Access-Token gilt, bis der
 * Athlet die App dort entzieht. Verbunden wird nur mit Einwilligung
 * (`ai_consent_at`), weil die Zwift-Fahrten in Review und Plan (OpenAI) gehen.
 */
#[Hidden(['access_token'])]
class IntervalsConnection extends Model
{
    protected $fillable = [
        'user_id', 'athlete_id', 'access_token', 'scopes', 'ai_consent_at',
        'workouts_synced_at', 'activities_synced_at', 'last_error', 'last_error_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token'         => 'encrypted',
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

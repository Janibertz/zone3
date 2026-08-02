<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'slug', 'title', 'starts_at', 'yard_km', 'target_yards',
    'stopped_at_yard', 'outcome', 'embed_map',
    'garmin_session_id', 'garmin_token', 'is_active', 'last_polled_at',
    'last_error', 'state', 'series'])]
#[Hidden(['garmin_token', 'garmin_session_id'])]
class LiveTrack extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at'      => 'datetime',
            'last_polled_at' => 'datetime',
            'is_active'      => 'boolean',
            'embed_map'      => 'boolean',
            'yard_km'        => 'float',
            'garmin_token'   => 'encrypted',
            'state'          => 'array',
            'series'         => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Zugangsschlüssel für die öffentliche Seite — lang genug, um nicht geraten zu werden. */
    public static function newSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(16));
        } while (static::where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * Die LiveTrack-URL, die Garmin dem Läufer gibt. Wird zerlegt in
     * Session-ID und Token — beide bleiben serverseitig.
     */
    public function setLiveTrackUrl(?string $url): bool
    {
        if (! $url) {
            $this->garmin_session_id = null;
            $this->garmin_token      = null;
            return true;
        }

        if (! preg_match('#/session/([0-9a-f-]{8,})/token/([A-Za-z0-9]+)#i', $url, $m)) {
            return false;
        }

        $this->garmin_session_id = $m[1];
        $this->garmin_token      = $m[2];

        return true;
    }

    public function hasLiveTrack(): bool
    {
        return ! empty($this->garmin_session_id) && ! empty($this->garmin_token);
    }

    /** Öffentliche LiveTrack-Seite von Garmin — für die eingebettete Karte. */
    public function liveTrackUrl(): ?string
    {
        return $this->hasLiveTrack()
            ? "https://livetrack.garmin.com/session/{$this->garmin_session_id}/token/{$this->garmin_token}"
            : null;
    }
}

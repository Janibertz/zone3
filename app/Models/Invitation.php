<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Ein Einladungslink zur Registrierung.
 *
 * Offen für jeden, der ihn hat. Standard: einmal einlösbar und VALID_DAYS
 * Tage gültig. Seit 11.11.2026 gibt es auch Links für mehrere
 * (`max_uses` > 1, null = beliebig viele) mit längerer Laufzeit und einer
 * eigenen Quelle (`source` → `users.signup_source`), etwa für Freunde oder
 * einen Lauftreff. Wer über welchen Link kam, steht an `users.invitation_id`.
 * Ohne gültige Einladung gibt es kein Konto über /register.
 */
class Invitation extends Model
{
    public const VALID_DAYS = 14;
    public const MAX_DAYS = 365;

    protected $fillable = ['token', 'note', 'max_uses', 'uses_count', 'source', 'created_by', 'expires_at', 'used_at', 'used_by', 'revoked_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'revoked_at' => 'datetime',
        'max_uses'   => 'integer',
        'uses_count' => 'integer',
    ];

    /**
     * @param  int|null  $maxUses  1 = einmal, null = beliebig oft
     */
    public static function issue(?User $by, ?string $note = null, ?int $maxUses = 1, int $days = self::VALID_DAYS, ?string $source = null): self
    {
        return self::create([
            'token'      => Str::random(40),
            'note'       => $note,
            'max_uses'   => $maxUses,
            'uses_count' => 0,
            'source'     => $source,
            'created_by' => $by?->id,
            'expires_at' => now()->addDays(max(1, min($days, self::MAX_DAYS))),
        ]);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where(fn (Builder $q) => $q->whereNull('max_uses')->orWhereColumn('uses_count', '<', 'max_uses'));
    }

    public function isMulti(): bool
    {
        return $this->max_uses !== 1;
    }

    public function isFull(): bool
    {
        return $this->max_uses !== null && $this->uses_count >= $this->max_uses;
    }

    public function isUsable(): bool
    {
        return ! $this->isFull()
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /** Ein Konto ist über diesen Link entstanden. Aufrufer hält die Zeilensperre. */
    public function redeem(User $user): void
    {
        $this->update([
            'uses_count' => $this->uses_count + 1,
            'used_at'    => now(),
            'used_by'    => $user->id,
        ]);
        $user->forceFill(['invitation_id' => $this->id])->save();
    }

    /** offen · eingelöst (bzw. voll) · abgelaufen · zurückgezogen */
    public function status(): string
    {
        return match (true) {
            $this->isFull()             => 'used',
            $this->revoked_at !== null  => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default                     => 'open',
        };
    }

    public function url(): string
    {
        return route('register', ['invite' => $this->token]);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Bei einem Link für mehrere: wer zuletzt kam. */
    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function redeemers(): HasMany
    {
        return $this->hasMany(User::class, 'invitation_id');
    }
}

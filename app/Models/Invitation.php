<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Ein Einladungslink zur Registrierung.
 *
 * Offen für jeden, der ihn hat, aber nur einmal einlösbar und
 * VALID_DAYS Tage gültig. Ohne gültige Einladung gibt es kein Konto.
 */
class Invitation extends Model
{
    public const VALID_DAYS = 14;

    protected $fillable = ['token', 'note', 'created_by', 'expires_at', 'used_at', 'used_by', 'revoked_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public static function issue(?User $by, ?string $note = null): self
    {
        return self::create([
            'token'      => Str::random(40),
            'note'       => $note,
            'created_by' => $by?->id,
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /** offen · eingelöst · abgelaufen · zurückgezogen */
    public function status(): string
    {
        return match (true) {
            $this->used_at !== null    => 'used',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default                    => 'open',
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

    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}

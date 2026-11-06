<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wie ein Anbieter eine Katalog-Übung nennt (06.11.2026, Jans Entscheidung:
 * eine Zuordnung für alle Uhren statt Spalten je Anbieter).
 *
 * - `garmin`: „KATEGORIE/NAME" aus Garmins Übungsliste (FIT-Profil), z. B.
 *   `HIP_RAISE/KETTLEBELL_SWING`. Gilt in beide Richtungen: so geht die Übung
 *   auf die Uhr, und so kommt sie aus der FIT-Datei zurück.
 * - `coros`: der englische Name aus COROS' Übungswörterbuch (folgt).
 *
 * Ein weiterer Anbieter braucht nur Zeilen, keine Spalten. Je Anbieter und
 * Kennung gibt es genau eine Übung — sonst wäre der Rückweg mehrdeutig.
 */
class StrengthExerciseAlias extends Model
{
    public const GARMIN = 'garmin';
    public const COROS = 'coros';

    protected $fillable = ['strength_exercise_id', 'provider', 'external_key'];

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(StrengthExercise::class, 'strength_exercise_id');
    }

    public static function garminKey(?string $category, ?string $name): ?string
    {
        return $category && $name ? strtoupper($category) . '/' . strtoupper($name) : null;
    }
}

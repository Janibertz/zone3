<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Eine Übung aus dem Kraft-Katalog (siehe `StrengthCatalog`).
 *
 * `key` ist, was das Modell wählt und was an der Einheit gespeichert wird —
 * er ändert sich nie. Name, Ausführung und Bild darf der Admin ändern; eine
 * Einheit zeigt dann den neuen Stand.
 */
class StrengthExercise extends Model
{
    public const GROUPS = [
        'beine' => 'Beine',
        'huefte' => 'Hüfte & Gesäß',
        'waden' => 'Waden & Füße',
        'rumpf' => 'Rumpf',
        'oberkoerper' => 'Oberkörper',
        'sprung' => 'Sprungkraft',
        'mobilitaet' => 'Mobilität',
    ];

    public const EQUIPMENT = [
        'bodyweight' => 'Körpergewicht',
        'band' => 'Band',
        'dumbbells' => 'Kurzhanteln',
        'kettlebell' => 'Kettlebell',
        'gym' => 'Studio',
    ];

    public const LEVELS = [
        'beginner' => 'Einsteiger',
        'intermediate' => 'Fortgeschritten',
        'advanced' => 'Erfahren',
    ];

    protected $fillable = [
        'key', 'name', 'instructions', 'group', 'equipment', 'level',
        'default_sets', 'default_reps', 'default_load',
        'wger_id', 'license', 'license_author', 'active', 'sort',
    ];

    protected $casts = [
        'equipment' => 'array',
        'active' => 'boolean',
        'default_sets' => 'integer',
        'wger_id' => 'integer',
        'sort' => 'integer',
    ];

    public function image(): HasOne
    {
        return $this->hasOne(StrengthExerciseImage::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    /** Bild-URL mit Versionsanhang, damit ein neues Bild den Browser-Cache umgeht. */
    public function imageUrl(?int $version): ?string
    {
        return $version ? route('exercise-images.show', ['exercise' => $this->id, 'v' => $version]) : null;
    }

    /** „Bild: philip, CC-BY-SA 4.0, wger.de" — Pflicht bei CC-BY-SA. */
    public function credit(): ?string
    {
        if (! $this->wger_id || ! $this->license) {
            return null;
        }

        return 'Bild: '.($this->license_author ? $this->license_author.', ' : '').$this->license.', wger.de';
    }

    public function wgerUrl(): ?string
    {
        return $this->wger_id ? "https://wger.de/de/exercise/{$this->wger_id}/view/" : null;
    }
}

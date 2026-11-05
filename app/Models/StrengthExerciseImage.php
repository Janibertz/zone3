<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bild einer Kraftübung, base64 in der Datenbank (der Container hat keinen dauerhaften Speicher). */
class StrengthExerciseImage extends Model
{
    protected $fillable = ['strength_exercise_id', 'mime', 'data'];

    protected $hidden = ['data'];

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(StrengthExercise::class, 'strength_exercise_id');
    }
}

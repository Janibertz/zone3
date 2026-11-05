<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Platz im COROS-Kalender, den Zone3 belegt hat.
 *
 * MCP kann ein Workout dort ersetzen, aber weder loeschen noch verschieben.
 * Darum bleibt die Zeile, auch wenn die Einheit wegfaellt: `cancelled`
 * heisst, dort steht gerade „Entfaellt“, und der Platz ist frei fuer die
 * naechste Einheit an demselben Tag.
 */
class CorosScheduledWorkout extends Model
{
    protected $fillable = [
        'user_id', 'training_session_id', 'date', 'id_in_plan', 'payload_hash', 'cancelled',
    ];

    protected function casts(): array
    {
        return [
            'date'      => 'date',
            'cancelled' => 'boolean',
        ];
    }

    public function trainingSession()
    {
        return $this->belongsTo(TrainingSession::class);
    }
}

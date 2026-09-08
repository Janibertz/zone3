<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Anruf von Strava — festgehalten, egal was daraus wurde.
 *
 * Die Zeile entsteht, bevor der Handler irgendetwas filtert. Genau das ist
 * ihr Zweck: die Frage, die nicht zu beantworten war, lautete nicht „wurde
 * importiert?", sondern „kam ueberhaupt etwas an?".
 */
class StravaWebhookEvent extends Model
{
    protected $fillable = [
        'object_type', 'aspect_type', 'owner_id', 'object_id',
        'user_id', 'outcome', 'note',
    ];

    public const OUTCOME_IMPORTED      = 'imported';
    public const OUTCOME_WRONG_TYPE    = 'ignored_type';
    public const OUTCOME_UNKNOWN_OWNER = 'unknown_owner';
    public const OUTCOME_NOT_FETCHABLE = 'not_fetchable';
    public const OUTCOME_TOMBSTONED    = 'tombstoned';
    public const OUTCOME_DELETED       = 'deleted';
    public const OUTCOME_DELETE_UNKNOWN = 'delete_unknown';
    public const OUTCOME_UPDATED       = 'updated';
    public const OUTCOME_RECLASSIFIED  = 'reclassified';

    public const OUTCOME_LABELS = [
        self::OUTCOME_IMPORTED      => 'importiert',
        self::OUTCOME_WRONG_TYPE    => 'kein neues Training',
        self::OUTCOME_UNKNOWN_OWNER => 'kein Konto dazu',
        self::OUTCOME_NOT_FETCHABLE => 'nicht abrufbar',
        self::OUTCOME_TOMBSTONED    => 'gelöscht, bleibt draussen',
        self::OUTCOME_DELETED       => 'bei Strava gelöscht → hier entfernt',
        self::OUTCOME_DELETE_UNKNOWN => 'Löschung, war hier nicht vorhanden',
        self::OUTCOME_UPDATED       => 'aktualisiert',
        self::OUTCOME_RECLASSIFIED  => 'Sportart geändert → neu zugeordnet',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

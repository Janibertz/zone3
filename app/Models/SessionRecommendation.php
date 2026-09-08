<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Vorschlag des Coaches zur heutigen Einheit.
 *
 * Der Unterschied zur bisherigen Automatik ist keine Feinheit: vorher
 * ÄNDERTE der Coach, jetzt SCHLÄGT er VOR. Was in `after` steht, ist noch
 * nirgends geschrieben — es landet erst im Plan, wenn der Athlet zustimmt.
 */
class SessionRecommendation extends Model
{
    protected $fillable = [
        'user_id', 'training_session_id', 'wellbeing_entry_id',
        'source', 'reason', 'before', 'after', 'status', 'responded_at',
    ];

    protected $casts = [
        'before'       => 'array',
        'after'        => 'array',
        'responded_at' => 'datetime',
    ];

    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED  = 'expired';

    public const SOURCE_WELLBEING = 'wellbeing';

    /** Die Felder, die eine Empfehlung an der Einheit ueberhaupt anfassen darf. */
    public const FIELDS = [
        'type', 'title', 'description', 'distance_km',
        'duration_min', 'pace_target', 'zone', 'intensity',
    ];

    public function session()
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}

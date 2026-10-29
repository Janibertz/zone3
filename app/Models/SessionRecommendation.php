<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

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

    /**
     * Der Datenbank-Standard allein reicht nicht: nach `create()` kennt das
     * Modell ihn nicht, `isOpen()` war false — und der Observer schickte den
     * Vorschlag nie nach Telegram.
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
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

    /** Eine eigene Idee des Coaches aus dem Chat (seit 29.10.2026). */
    public const SOURCE_COACH = 'coach';

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

    /**
     * Den Vorschlag annehmen. Die einzige Stelle, die das tut — das Dashboard
     * und der Telegram-Knopf rufen beide hierher, sonst gaebe es zwei
     * Schreibwege auf dieselbe Einheit.
     *
     * Die Einheit bekommt `pinned_at` — genau wie alles, was der Athlet über
     * den Coach-Chat setzt. Ohne das würde die nächste Neuberechnung seine
     * Entscheidung still wieder wegwerfen.
     *
     * @return bool false, wenn die Einheit inzwischen fehlt
     */
    public function accept(): bool
    {
        if (! $this->isOpen()) {
            return true;
        }

        $session = $this->session;

        if (! $session) {
            $this->update(['status' => self::STATUS_EXPIRED, 'responded_at' => now()]);

            return false;
        }

        $session->update(array_intersect_key($this->after, array_flip(self::FIELDS)) + [
            'pinned_at' => now(),
            // Steps und Verpflegungstipps beschrieben die alte Einheit.
            'steps'          => null,
            'nutrition_tips' => null,
        ]);

        $this->update(['status' => self::STATUS_ACCEPTED, 'responded_at' => now()]);

        Log::info('Empfehlung angenommen', [
            'user_id'        => $this->user_id,
            'session_id'     => $session->id,
            'recommendation' => $this->id,
        ]);

        return true;
    }

    /** Den Vorschlag ablehnen — der Plan bleibt, wie er ist. */
    public function reject(): void
    {
        if (! $this->isOpen()) {
            return;
        }

        $this->update(['status' => self::STATUS_REJECTED, 'responded_at' => now()]);

        Log::info('Empfehlung abgelehnt', [
            'user_id'        => $this->user_id,
            'recommendation' => $this->id,
        ]);
    }
}

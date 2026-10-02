<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Was der Athlet vor einem neuen Block erzaehlt hat.
 *
 * Gefragt wird nur, was die Daten nicht hergeben — alles andere steht schon
 * in den Aktivitaeten, im Wellbeing und in den Bewertungen. Siehe die
 * Migration fuer die Begruendung.
 */
class PlanInterview extends Model
{
    /** Warum Einheiten ausfielen. Der Grund entscheidet die Konsequenz. */
    public const SKIP_REASONS = [
        'time'       => 'Zeit / Beruf',
        'body'       => 'Körper — müde, Wehwehchen',
        'motivation' => 'Motivation',
        'fit'        => 'Die Einheit passte nicht zum Tag',
        'weather'    => 'Wetter / äußere Umstände',
        'none'       => 'Kaum etwas ausgefallen',
    ];

    /** Worauf der neue Block zielt. */
    public const FOCUS = [
        'race'     => 'Auf ein Rennen hin',
        'base'     => 'Grundlage aufbauen, ohne Renntermin',
        'comeback' => 'Vorsichtig wieder einsteigen',
        'maintain' => 'Form halten',
    ];

    protected $fillable = [
        'user_id', 'event_id', 'reviewed_event_id',
        'actual_minutes', 'block_rating', 'block_note',
        'skip_reason', 'skip_note',
        'days_per_week', 'minutes_per_day', 'changes_note',
        'focus', 'free_note',
        'completed_at', 'applied_at',
    ];

    protected $casts = [
        'actual_minutes'  => 'integer',
        'block_rating'    => 'integer',
        'days_per_week'   => 'integer',
        'minutes_per_day' => 'integer',
        'completed_at'    => 'datetime',
        'applied_at'      => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function reviewedEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'reviewed_event_id');
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Wie stark der Umfang gegenueber der reinen Fortschreibung gedeckelt
     * wird.
     *
     * Die Zahlen sind bewusst grob: ein Interview liefert eine Richtung, keine
     * Messung. Wer aus Zeitmangel ausgefallen ist, bekommt einen kleineren
     * Block — sonst plant die App denselben Umfang noch einmal, der schon
     * beim letzten Mal nicht in die Woche passte, und die Ausfallquote bleibt,
     * wo sie war.
     *
     * `body` wiegt am schwersten: dort ist die Grenze keine Kalenderfrage.
     */
    public function volumeFactor(): float
    {
        return match ($this->skip_reason) {
            'body'       => 0.80,
            'time'       => 0.85,
            'motivation' => 0.90,
            default      => 1.0,
        };
    }

    /** Die gelaufene Zeit als „3:38 Std" bzw. „24 Min". */
    public function actualTimeLabel(): ?string
    {
        if (! $this->actual_minutes) {
            return null;
        }

        $h = intdiv($this->actual_minutes, 60);
        $m = $this->actual_minutes % 60;

        return $h > 0 ? sprintf('%d:%02d Std', $h, $m) : "{$m} Min";
    }
}

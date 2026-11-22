<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ein Paket Planänderungen des Coaches mit Vorher-Stand
 * ({@see \App\Services\Coach\PlanChange}). `undo()` stellt den alten Plan
 * wieder her, solange keine der Einheiten erledigt ist.
 */
class CoachChange extends Model
{
    protected $fillable = ['user_id', 'steps', 'lines', 'before', 'created_ids', 'plan_id', 'plan_overrides_before', 'undone_at'];

    protected $casts = [
        'steps'                 => 'array',
        'lines'                 => 'array',
        'before'                => 'array',
        'created_ids'           => 'array',
        'plan_overrides_before' => 'array',
        'undone_at'             => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Warum sich das Paket nicht mehr zurücknehmen lässt — null, wenn es geht.
     */
    public function undoBlocker(): ?string
    {
        if ($this->undone_at) {
            return 'Schon rückgängig gemacht.';
        }

        $ids = array_merge(array_map(fn ($row) => (int) $row['id'], $this->before ?? []), $this->created_ids ?? []);
        $completed = TrainingSession::whereIn('id', $ids)->where('status', 'completed')->exists();
        if ($completed) {
            return 'Eine der Einheiten ist inzwischen erledigt — das lässt sich nicht mehr zurücknehmen.';
        }

        foreach ($this->before ?? [] as $row) {
            if (substr((string) ($row['planned_date'] ?? ''), 0, 10) < today()->toDateString()) {
                return 'Der Tag ist vorbei — das lässt sich nicht mehr zurücknehmen.';
            }
        }

        return null;
    }

    /** Den Vorher-Stand wiederherstellen. Gibt false zurück, wenn es nicht geht. */
    public function undo(): bool
    {
        if ($this->undoBlocker()) {
            return false;
        }

        DB::transaction(function () {
            TrainingSession::where('user_id', $this->user_id)->whereIn('id', $this->created_ids ?? [])->get()->each->delete();

            foreach ($this->before ?? [] as $row) {
                $session = TrainingSession::find($row['id']) ?? new TrainingSession();
                $attributes = array_diff_key($row, array_flip(['created_at', 'updated_at']));
                // Die Zeile kommt roh aus der Datenbank: JSON als Text, so
                // wie es gespeichert war.
                $session->setRawAttributes(array_merge($session->getAttributes(), $attributes));
                $session->exists = TrainingSession::whereKey($row['id'])->exists();
                $session->save();
            }

            if ($this->plan_id && ($plan = TrainingPlan::find($this->plan_id))) {
                $plan->availability_overrides = $this->plan_overrides_before;
                $plan->save();
            }

            $this->undone_at = now();
            $this->save();
        });

        app(\App\Services\Coach\SessionWriter::class)->invalidateCoachCaches($this->user);

        return true;
    }
}

<?php

namespace App\Services;

use App\Models\Event;
use App\Models\PlanRevision;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Hält fest, was sich am Plan geändert hat — und warum.
 *
 * Eine Neuberechnung ersetzt den Plan vollständig: alter Plan gelöscht,
 * neuer angelegt. Für den Athleten sah das aus, als hätte sich der Plan über
 * Nacht von selbst umgeschrieben. Es gab keine Möglichkeit zu sehen, welche
 * Einheit gewichen war, welche dazukam, und ob der Coach das entschieden hat
 * oder der Validator die Antwort des Modells zurechtgerückt hat.
 *
 * Der Vergleich läuft über das Datum: für jeden Tag im neuen Plan, der auch
 * im alten vorkam, wird die inhaltliche Beschreibung verglichen. Nur die
 * Zukunft zählt — was gelaufen ist, ändert keine Neuberechnung mehr.
 */
class PlanRevisionRecorder
{
    /**
     * @param  Collection<int,TrainingSession>  $oldSessions  Der Stand vor dem Löschen.
     * @param  array<int,array<string,mixed>>   $newSessions  Die geprüfte Antwort des Modells.
     * @param  array<int,string>                $corrections  Bericht des Validators.
     */
    public function record(
        User $user,
        Event $event,
        ?TrainingPlan $newPlan,
        Collection $oldSessions,
        array $newSessions,
        array $corrections = [],
        string $triggeredBy = 'auto',
    ): ?PlanRevision {
        $changes = $this->diff($oldSessions, $newSessions);

        // Beim ersten Plan gibt es nichts zu vergleichen — der Eintrag
        // entsteht trotzdem, damit der Verlauf einen Anfang hat.
        if ($triggeredBy !== 'initial' && $changes === [] && $corrections === []) {
            return null;
        }

        return PlanRevision::create([
            'user_id'          => $user->id,
            'event_id'         => $event->id,
            'training_plan_id' => $newPlan?->id,
            'triggered_by'     => $triggeredBy,
            'changes'          => $changes,
            'corrections'      => array_values($corrections),
        ]);
    }

    /**
     * @param  Collection<int,TrainingSession>  $oldSessions
     * @param  array<int,array<string,mixed>>   $newSessions
     * @return array<int,array<string,string>>
     */
    public function diff(Collection $oldSessions, array $newSessions): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $old = $oldSessions
            ->filter(fn ($s) => $s->planned_date?->toDateString() >= $today)
            ->groupBy(fn ($s) => $s->planned_date->toDateString())
            ->map(fn ($day) => $day->map(fn ($s) => $this->describeSession(
                $s->type, $s->title, $s->distance_km, $s->duration_min
            ))->sort()->values()->all());

        $new = collect($newSessions)
            ->filter(fn ($s) => ($s['date'] ?? '') >= $today)
            ->groupBy(fn ($s) => $s['date'])
            ->map(fn ($day) => $day->map(fn ($s) => $this->describeSession(
                $s['type'] ?? '', $s['title'] ?? null, $s['distance_km'] ?? null, $s['duration_min'] ?? null
            ))->sort()->values()->all());

        $changes = [];

        foreach ($old->keys()->merge($new->keys())->unique()->sort() as $date) {
            $before = $old->get($date, []);
            $after  = $new->get($date, []);

            if ($before === $after) {
                continue;
            }

            $changes[] = [
                'date'  => $date,
                'label' => CarbonImmutable::parse($date)->locale('de')->isoFormat('dd, D. MMM'),
                'kind'  => $before === [] ? 'added' : ($after === [] ? 'removed' : 'changed'),
                'from'  => $before === [] ? null : implode(' + ', $before),
                'to'    => $after  === [] ? null : implode(' + ', $after),
            ];
        }

        return $changes;
    }

    /**
     * Eine Einheit in einer Zeile. Der Titel des Modells ist zu blumig für
     * einen Vergleich ("Lockerer Dauerlauf zum Ankurbeln") — verglichen wird
     * die Sache selbst: Typ, Umfang, Dauer.
     */
    private function describeSession(string $type, ?string $title, $distanceKm, $durationMin): string
    {
        if ($type === 'rest') {
            return 'Ruhetag';
        }

        $label = TrainingSession::TYPE_LABELS[$type] ?? ($title ?: $type);
        $parts = [];

        if ($distanceKm > 0) {
            $parts[] = rtrim(rtrim(number_format((float) $distanceKm, 1, ',', ''), '0'), ',') . ' km';
        }
        if ($durationMin > 0) {
            $parts[] = ((int) $durationMin) . ' min';
        }

        return $parts === [] ? $label : $label . ' (' . implode(', ', $parts) . ')';
    }
}

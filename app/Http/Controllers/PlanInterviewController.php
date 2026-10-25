<?php

namespace App\Http\Controllers;

use App\Models\PlanInterview;
use App\Services\PlanInterviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Das kurze Gespraech vor dem naechsten Trainingsblock.
 *
 * Gewuenscht: „Vielleicht wäre ein kurzes Interview nicht schlecht damit sich
 * der Plan perfekt einstellen kann."
 *
 * Kurz ist hier das Entwurfsziel, nicht die Floskel: fuenf Schritte, jeder
 * mit einer Frage, und jede Frage zeigt zuerst, was Zone3 ohnehin weiss. Wer
 * nach 132 Aktivitaeten noch nach dem Wochenumfang fragt, baut eine zweite
 * Wahrheit neben die Daten.
 */
class PlanInterviewController extends Controller
{
    public function __construct(private readonly PlanInterviewService $interviews) {}

    /**
     * Das Interview, optional fuer ein bestimmtes Event.
     *
     * Der Einstieg ist „Plan erstellen" — unter Events und auf der Planseite.
     * Es kommt bei JEDEM neuen Plan (Entscheidung von Jan, 25.10.2026), auch
     * wenn das letzte erst ein paar Wochen her ist: vorher wurde durchgewunken,
     * wer in den letzten acht Wochen geantwortet hatte, und damit fehlte genau
     * dann die Frage nach dem Startdatum. Jede Frage bleibt optional.
     */
    public function show(Request $request, PlanInterviewService $service): Response|RedirectResponse
    {
        $event = null;

        if ($request->filled('event')) {
            $event = Auth::user()->events()->find($request->integer('event'));
        }

        return Inertia::render('Plan/Interview', [
            'prefill' => $service->prefill(Auth::user()),
            'latest'  => $this->latest(),
            'event'   => $event ? [
                'id'         => $event->id,
                'name'       => $event->name,
                'date_label' => $event->event_date->format('d.m.Y'),
                'distance'   => $event->distance_label,
                'days_until' => $event->days_until,
                // Frühestens heute, spätestens am Vortag des Rennens.
                'start_min'  => now()->toDateString(),
                'start_max'  => $event->event_date->copy()->subDay()->toDateString(),
                'start_date' => $event->planStartsOn()->toDateString(),
            ] : null,
        ]);
    }

    /**
     * Antworten entgegennehmen, Folgen schreiben, Quittung zurueckgeben.
     *
     * Alles ist `nullable`: ein Interview, das man nur halb beantwortet, ist
     * mehr wert als eines, das man abbricht, weil ein Pflichtfeld im Weg
     * stand.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reviewed_event_id' => 'nullable|integer|exists:events,id',
            'actual_minutes'    => 'nullable|integer|min:1|max:6000',
            'block_rating'      => 'nullable|integer|min:1|max:5',
            'block_note'        => 'nullable|string|max:2000',

            'skip_reason'       => 'nullable|string|in:' . implode(',', array_keys(PlanInterview::SKIP_REASONS)),
            'skip_note'         => 'nullable|string|max:2000',

            'days_per_week'     => 'nullable|integer|min:1|max:7',
            'minutes_per_day'   => 'nullable|integer|min:20|max:600',
            'changes_note'      => 'nullable|string|max:2000',

            'focus'             => 'nullable|string|in:' . implode(',', array_keys(PlanInterview::FOCUS)),
            'event_id'          => 'nullable|integer|exists:events,id',
            'free_note'         => 'nullable|string|max:2000',

            'plan_start_date'   => 'nullable|date|after_or_equal:today',
        ]);

        $startDate = $data['plan_start_date'] ?? null;
        unset($data['plan_start_date']);

        // Fremde Events haben hier nichts zu suchen — `exists` prueft nur,
        // dass es die Zeile gibt, nicht wem sie gehoert.
        foreach (['reviewed_event_id', 'event_id'] as $key) {
            if (! empty($data[$key])) {
                abort_unless(
                    Auth::user()->events()->whereKey($data[$key])->exists(),
                    403,
                );
            }
        }

        // Vor dem Speichern prüfen — sonst stünde ein Interview doppelt da,
        // sobald der Athlet das Datum korrigiert.
        if ($startDate && ! empty($data['event_id'])) {
            $raceDay = Auth::user()->events()->findOrFail($data['event_id'])->event_date->toDateString();
            if ($startDate >= $raceDay) {
                return back()->withErrors(['plan_start_date' => 'Der Plan muss vor dem Renntag beginnen.']);
            }
        }

        $interview = PlanInterview::create($data + [
            'user_id'      => Auth::id(),
            'completed_at' => now(),
        ]);

        $changes = $this->interviews->apply($interview);

        $message = $changes === []
            ? 'Antworten gespeichert.'
            : 'Übernommen: ' . implode(' · ', $changes);

        // Wer ueber „Plan erstellen" kam, will zum Plan — nicht zurueck ins
        // Formular, und auch nicht vor einen zweiten „Plan erstellen"-Knopf.
        // `generate=1` laesst die Planseite den Bau sofort anstossen; dort
        // stehen schon alle Pruefungen (anderer aktiver Plan, Rennen vorbei)
        // samt Fehleranzeige.
        if (! empty($data['event_id'])) {
            $event = Auth::user()->events()->findOrFail($data['event_id']);

            // Heute als Start ist dasselbe wie kein Start — gespeichert wird
            // nur ein echtes Später, sonst stuende das Datum morgen in der
            // Vergangenheit und behauptete etwas.
            $event->update([
                'plan_start_date' => $startDate && $startDate > now()->toDateString() ? $startDate : null,
            ]);

            $when = $event->plan_start_date
                ? ' Der Plan beginnt am ' . $event->plan_start_date->format('d.m.Y') . '.'
                : '';

            return redirect()
                ->route('events.plan.show', ['event' => $data['event_id'], 'generate' => 1])
                ->with('success', $message . $when);
        }

        return redirect()->route('plan-interview.show')->with('success', $message);
    }

    /** @return array<string, mixed>|null */
    private function latest(): ?array
    {
        $interview = PlanInterview::where('user_id', Auth::id())
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->first();

        if (! $interview) {
            return null;
        }

        return [
            'at'          => $interview->completed_at->format('d.m.Y'),
            'focus_label' => PlanInterview::FOCUS[$interview->focus] ?? null,
            'skip_label'  => PlanInterview::SKIP_REASONS[$interview->skip_reason] ?? null,
            'days'        => $interview->days_per_week,
        ];
    }
}

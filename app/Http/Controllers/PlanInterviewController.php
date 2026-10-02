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

    public function show(PlanInterviewService $service): Response
    {
        return Inertia::render('Plan/Interview', [
            'prefill' => $service->prefill(Auth::user()),
            'latest'  => $this->latest(),
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
        ]);

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

        $interview = PlanInterview::create($data + [
            'user_id'      => Auth::id(),
            'completed_at' => now(),
        ]);

        $changes = $this->interviews->apply($interview);

        return redirect()
            ->route('plan-interview.show')
            ->with('success', $changes === []
                ? 'Antworten gespeichert.'
                : 'Übernommen: ' . implode(' · ', $changes));
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

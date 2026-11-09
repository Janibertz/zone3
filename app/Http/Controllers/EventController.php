<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Services\PaceFormat;
use App\Services\PeriodizationService;
use App\Services\RacePredictionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EventController extends Controller
{
    public function index()
    {
        // Deactivate any plans whose event has already passed
        TrainingPlan::where('user_id', Auth::id())
            ->where('is_active', true)
            ->whereHas('event', fn ($q) => $q->where('event_date', '<', now()->toDateString()))
            ->update(['is_active' => false]);

        // Für die Prognose am nächsten Rennen — dieselbe Rechnung wie auf
        // Dashboard und Planseite, ohne Modellaufruf.
        $vdot = Auth::user()->runnerProfile?->vdot();

        $events = Auth::user()->events()
            ->orderByRaw("CASE priority WHEN 'A' THEN 1 WHEN 'B' THEN 2 WHEN 'C' THEN 3 ELSE 4 END")
            ->orderBy('event_date')
            ->get()
            ->map(fn (Event $e) => $this->formatEvent($e, $vdot));

        $activePlan = TrainingPlan::where('user_id', Auth::id())
            ->where('is_active', true)
            ->whereHas('event', fn ($q) => $q->where('event_date', '>=', now()->toDateString()))
            ->latest()
            ->first();

        return Inertia::render('Events/Index', [
            'events'              => $events,
            'status'              => session('status'),
            'active_plan_event_id' => $activePlan?->event_id,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:150',
            'event_date'          => 'required|date|after:today',
            'race_distance'       => 'required|string|in:5km,10km,half_marathon,marathon,custom,backyard_ultra',
            'distance_km'         => 'nullable|numeric|min:0.1',
            'priority'            => 'required|in:A,B,C',
            'target_time_hours'   => 'required_unless:race_distance,backyard_ultra|integer|min:0|max:23',
            'target_time_minutes' => 'required_unless:race_distance,backyard_ultra|integer|min:0|max:59',
            'target_yards'        => 'required_if:race_distance,backyard_ultra|nullable|integer|min:1|max:100',
            'notes'               => 'nullable|string|max:1000',
        ]);

        Auth::user()->events()->create($validated);

        return redirect()->route('events.index')->with('status', 'event-created');
    }

    public function update(Request $request, Event $event)
    {
        abort_if($event->user_id !== Auth::id(), 403);

        $validated = $request->validate([
            'name'                => 'required|string|max:150',
            'event_date'          => 'required|date',
            'race_distance'       => 'required|string|in:5km,10km,half_marathon,marathon,custom,backyard_ultra',
            'distance_km'         => 'nullable|numeric|min:0.1',
            'priority'            => 'required|in:A,B,C',
            'target_time_hours'   => 'required_unless:race_distance,backyard_ultra|integer|min:0|max:23',
            'target_time_minutes' => 'required_unless:race_distance,backyard_ultra|integer|min:0|max:59',
            'target_yards'        => 'required_if:race_distance,backyard_ultra|nullable|integer|min:1|max:100',
            'notes'               => 'nullable|string|max:1000',
        ]);

        $event->update($validated);

        return redirect()->route('events.index')->with('status', 'event-updated');
    }

    public function destroy(Event $event)
    {
        abort_if($event->user_id !== Auth::id(), 403);
        $event->delete();

        return redirect()->route('events.index');
    }

    private function formatEvent(Event $e, ?float $vdot = null): array
    {
        $plan = $e->latestPlan;

        // Alles in Sekunden — die Seite schreibt es wie eine Uhr (1:29:59).
        $prediction = ($vdot && $e->days_until >= 0 && ! $e->isBackyard())
            ? app(RacePredictionService::class)->forVdot($vdot, $e->race_distance, $e->distance_km)
            : null;
        $resultMin = $plan && ($plan->actual_time_hours || $plan->actual_time_minutes)
            ? (int) $plan->actual_time_hours * 60 + (int) $plan->actual_time_minutes
            : null;

        return [
            'id'                     => $e->id,
            'name'                   => $e->name,
            'event_date'             => $e->event_date->format('Y-m-d'),
            'race_distance'          => $e->race_distance,
            'distance_km'            => $e->distance_km,
            'distance_label'         => $e->distance_label,
            'priority'               => $e->priority,
            'target_time_hours'      => $e->target_time_hours,
            'target_time_minutes'    => $e->target_time_minutes,
            'target_yards'           => $e->target_yards,
            'target_time_formatted'  => $e->target_time_formatted,
            'notes'                  => $e->notes,
            'days_until'             => $e->days_until,
            'plan_is_active'         => (bool) $plan?->is_active && $e->days_until >= 0,
            'plan_generated_at'      => $plan?->created_at?->format('d.m.Y H:i'),
            'race_km'                => $e->race_km,
            'target_seconds'         => $e->target_minutes ? $e->target_minutes * 60 : null,
            'target_pace'            => $e->target_pace_seconds ? PaceFormat::target($e->target_pace_seconds) : null,
            'predicted_seconds'      => $prediction['total_sec'] ?? null,
            'result_seconds'         => $resultMin ? $resultMin * 60 : null,
            'progress'               => $plan && $e->days_until >= 0 ? $this->progress($e) : null,
        ];
    }

    /**
     * Wie weit der Weg zum Rennen ist: Woche X von Y und die Phase. Für die
     * Fortschrittsleiste unter dem Foto auf der Rennen-Seite. Der Planstart
     * kommt aus {@see PeriodizationService::planBegan()} — dieselbe Stelle,
     * an der auch der Entlastungsrhythmus beginnt.
     *
     * @return array{week: int, weeks: int, percent: int, phase: string}|null
     */
    private function progress(Event $e): ?array
    {
        $periods = app(PeriodizationService::class);
        $began   = $periods->planBegan($e);
        $race    = CarbonImmutable::parse($e->event_date->toDateString());
        $total   = (int) $began->diffInDays($race, false);
        if ($total <= 0) {
            return null;
        }

        $done  = max(0, min($total, (int) $began->diffInDays(CarbonImmutable::today(), false)));
        $weeks = max(1, (int) ceil($total / 7));

        return [
            'week'    => min($weeks, intdiv($done, 7) + 1),
            'weeks'   => $weeks,
            'percent' => (int) round($done / $total * 100),
            'phase'   => $periods->phaseOn($e)['label'],
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class EventController extends Controller
{
    public function index()
    {
        $events = Auth::user()->events()
            ->orderByRaw("CASE priority WHEN 'A' THEN 1 WHEN 'B' THEN 2 WHEN 'C' THEN 3 ELSE 4 END")
            ->orderBy('event_date')
            ->get()
            ->map(fn (Event $e) => $this->formatEvent($e));

        return Inertia::render('Events/Index', [
            'events' => $events,
            'status' => session('status'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:150',
            'event_date'          => 'required|date|after:today',
            'race_distance'       => 'required|string|in:5km,10km,half_marathon,marathon,custom',
            'distance_km'         => 'nullable|numeric|min:0.1',
            'priority'            => 'required|in:A,B,C',
            'target_time_hours'   => 'required|integer|min:0|max:23',
            'target_time_minutes' => 'required|integer|min:0|max:59',
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
            'race_distance'       => 'required|string|in:5km,10km,half_marathon,marathon,custom',
            'distance_km'         => 'nullable|numeric|min:0.1',
            'priority'            => 'required|in:A,B,C',
            'target_time_hours'   => 'required|integer|min:0|max:23',
            'target_time_minutes' => 'required|integer|min:0|max:59',
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

    private function formatEvent(Event $e): array
    {
        $plan = $e->latestPlan;

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
            'target_time_formatted'  => $e->target_time_formatted,
            'notes'                  => $e->notes,
            'days_until'             => $e->days_until,
            'plan_is_active'         => (bool) $plan?->is_active,
            'plan_generated_at'      => $plan?->created_at?->format('d.m.Y H:i'),
        ];
    }
}

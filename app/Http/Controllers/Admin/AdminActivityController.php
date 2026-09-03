<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\ActivityDeletionService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aktivitäten über alle Athleten.
 *
 * Der Anlass steht wörtlich in einer Meldung: „ich habe keine Möglichkeit
 * eine Aktivität zu löschen. Ich hatte letztens den Fall das ich etwas
 * ausprobieren wollte und ich konnte die Einheit nicht mehr löschen noch
 * nicht mal im Admin Bereich." Löschen kann der Athlet inzwischen selbst —
 * ein Admin sah die Aktivitäten anderer aber weiterhin nirgends.
 *
 * Gelöscht wird über `ActivityDeletionService`, nie direkt: an einer
 * Aktivität hängen die abgehakte Einheit, ihre Bestzeiten, eine mögliche
 * Rennanalyse und der Grabstein, der den Wiederimport verhindert.
 */
class AdminActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Activity::query()->with('user:id,name');

        if ($request->filled('user')) {
            $query->where('user_id', $request->integer('user'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('strava_id', 'like', "%{$search}%"));
        }

        $page = $query->orderByDesc('start_date')->paginate(40)->withQueryString();

        // Wie viele Einheiten haengen an diesen Aktivitaeten? Einmal
        // gruppiert statt vierzig Einzelabfragen.
        $linked = TrainingSession::whereIn('activity_id', collect($page->items())->pluck('id'))
            ->selectRaw('activity_id, COUNT(*) as c')
            ->groupBy('activity_id')
            ->pluck('c', 'activity_id');

        $activities = $page
            ->through(fn (Activity $a) => [
                'id'          => $a->id,
                'strava_id'   => $a->strava_id,
                'user_id'     => $a->user_id,
                'user'        => $a->user?->name ?? "Nutzer {$a->user_id}",
                'name'        => $a->name,
                'type'        => $a->type,
                'start_date'  => $a->start_date?->toIso8601String(),
                'distance_km' => $a->distance > 0 ? round($a->distance / 1000, 2) : null,
                'duration_min' => $a->moving_time > 0 ? (int) round($a->moving_time / 60) : null,
                // Haengt eine Einheit daran? Dann ist das Loeschen kein
                // reines Aufraeumen — der Plan aendert sich mit.
                'sessions'    => (int) ($linked[$a->id] ?? 0),
            ]);

        return Inertia::render('Admin/Activities/Index', [
            'activities' => $activities,
            'filters'    => $request->only('user', 'type', 'search'),
            'users'      => User::has('activities')->orderBy('name')->get(['id', 'name']),
            'types'      => Activity::query()
                ->select('type')
                ->distinct()
                ->orderBy('type')
                ->pluck('type'),
        ]);
    }

    public function destroy(Activity $activity, ActivityDeletionService $deletion): RedirectResponse
    {
        $name   = $activity->name;
        $result = $deletion->delete($activity);

        $restored = $result['sessions_restored'] ?? 0;
        $deleted  = $result['sessions_deleted'] ?? 0;

        $note = match (true) {
            $restored > 0 => " {$restored} geplante Einheit(en) wiederhergestellt.",
            $deleted  > 0 => " {$deleted} ungeplante Einheit(en) entfernt.",
            default       => '',
        };

        return back()->with('success', "{$name} gelöscht.{$note}");
    }
}

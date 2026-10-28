<?php

namespace App\Http\Controllers;

use App\Models\Workout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class WorkoutController extends Controller
{
    // ── Pages ─────────────────────────────────────────────────────────────────

    public function index()
    {
        $workouts = Workout::where('user_id', Auth::id())
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($w) => $this->formatWorkout($w));

        $paceZones = Auth::user()->runnerProfile?->calculatePaceZones() ?? [];

        return Inertia::render('Workouts/Index', [
            'workouts'   => $workouts,
            'paceZones'  => $paceZones,
        ]);
    }

    public function list()
    {
        $workouts = Workout::where('user_id', Auth::id())
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($w) => $this->formatWorkout($w));

        return response()->json(['workouts' => $workouts]);
    }

    public function create()
    {
        $paceZones = Auth::user()->runnerProfile?->calculatePaceZones() ?? [];

        return Inertia::render('Workouts/Builder', [
            'workout'   => null,
            'paceZones' => $paceZones,
        ]);
    }

    public function edit(Workout $workout)
    {
        abort_if($workout->user_id !== Auth::id(), 403);

        $paceZones = Auth::user()->runnerProfile?->calculatePaceZones() ?? [];

        return Inertia::render('Workouts/Builder', [
            'workout'   => $this->formatWorkout($workout),
            'paceZones' => $paceZones,
        ]);
    }

    // ── CRUD API ──────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $this->validateWorkout($request);

        $blocks   = $data['blocks'];
        $distM    = Workout::computeDistanceM($blocks);
        $durSec   = Workout::computeDurationSec($blocks);

        $workout = Workout::create([
            'user_id'                => Auth::id(),
            'name'                   => $data['name'],
            'type'                   => $data['type'],
            'description'            => $data['description'] ?? null,
            'blocks'                 => $blocks,
            'tags'                   => $data['tags'] ?? [],
            'estimated_distance_km'  => $distM  > 0 ? round($distM / 1000, 2) : null,
            'estimated_duration_min' => $durSec > 0 ? (int) ceil($durSec / 60)  : null,
        ]);

        return response()->json(['workout' => $this->formatWorkout($workout)]);
    }

    public function update(Request $request, Workout $workout)
    {
        abort_if($workout->user_id !== Auth::id(), 403);

        $data   = $this->validateWorkout($request);
        $blocks = $data['blocks'];
        $distM  = Workout::computeDistanceM($blocks);
        $durSec = Workout::computeDurationSec($blocks);

        $workout->update([
            'name'                   => $data['name'],
            'type'                   => $data['type'],
            'description'            => $data['description'] ?? null,
            'blocks'                 => $blocks,
            'tags'                   => $data['tags'] ?? [],
            'estimated_distance_km'  => $distM  > 0 ? round($distM / 1000, 2) : null,
            'estimated_duration_min' => $durSec > 0 ? (int) ceil($durSec / 60)  : null,
        ]);

        return response()->json(['workout' => $this->formatWorkout($workout->fresh())]);
    }

    public function destroy(Workout $workout)
    {
        abort_if($workout->user_id !== Auth::id(), 403);
        $workout->delete();
        return response()->json(['success' => true]);
    }

    public function duplicate(Workout $workout)
    {
        abort_if($workout->user_id !== Auth::id(), 403);

        $copy = $workout->replicate();
        $copy->name      = $workout->name . ' (Kopie)';
        $copy->times_used = 0;
        $copy->last_used_at = null;
        $copy->save();

        return response()->json(['workout' => $this->formatWorkout($copy)]);
    }

    // ── Garmin ────────────────────────────────────────────────────────────────


    /**
     * Ein eigenes Workout freigeben oder die Freigabe zuruecknehmen.
     *
     * Nur der Ersteller. Ein Admin kann eine Freigabe zwar beenden, aber
     * ueber einen anderen Weg — und niemand kann ein fremdes Workout
     * aendern.
     */
    public function togglePublic(Request $request, Workout $workout)
    {
        abort_unless($workout->user_id === Auth::id(), 403);

        $makePublic = ! $workout->is_public;

        $workout->update([
            'is_public'    => $makePublic,
            'published_at' => $makePublic ? now() : null,
            // Eine erneute Freigabe loescht den Vermerk des Admins — sonst
            // stuende dort dauerhaft ein Grund, der nicht mehr gilt.
            'unpublished_reason' => null,
        ]);

        return response()->json(['workout' => $this->formatWorkout($workout->fresh())]);
    }

    /**
     * Die freigegebenen Workouts aller Athleten, gerechnet fuer DIESEN.
     *
     * Nach Lauftyp gruppiert — das ist die Frage, mit der ein Athlet hier
     * ankommt: „ich habe Bock auf Intervalle", nicht „ich suche Workout 47".
     */
    public function shared(Request $request, \App\Services\WorkoutPaceResolver $resolver)
    {
        $profile = $request->user()->runnerProfile;

        $shared = Workout::shared()
            ->with('user:id,name')
            ->orderByDesc('times_used')
            ->orderBy('name')
            ->get()
            ->map(function (Workout $w) use ($resolver, $profile, $request) {
                $resolved = $resolver->resolve($w, $profile);

                return [
                    'id'           => $w->id,
                    'name'         => $w->name,
                    'description'  => $w->description,
                    'type'         => $w->type,
                    'tags'         => $w->tags ?? [],
                    'author'       => $w->user?->name ?? 'Zone3',
                    'is_mine'      => $w->user_id === $request->user()->id,
                    'times_used'   => $w->times_used,
                    'duration_min' => $resolved['duration_min'],
                    'distance_km'  => $resolved['distance_km'],
                    'pace_target'  => $resolved['pace_target'],
                    'steps'        => $resolved['steps'],
                ];
            })
            ->groupBy('type');

        return response()->json([
            'workouts' => $shared,
            'types'    => \App\Models\TrainingSession::TYPE_LABELS,
            // Ohne Schwellenpace bleiben die Paces leer. Das gehoert gesagt,
            // statt „—" zu zeigen und den Athleten raten zu lassen.
            'has_paces' => (bool) ($profile?->threshold_speed),
        ]);
    }

    /**
     * Ein freigegebenes Workout auf eine geplante Einheit anwenden.
     *
     * Sie ersetzt die Einheit und bekommt `pinned_at` — der Athlet hat sie
     * ausgesucht, die naechste Neuberechnung darf sie nicht wegwerfen.
     */
    public function applyToSession(
        Request $request,
        \App\Models\TrainingSession $session,
        Workout $workout,
        \App\Services\WorkoutPaceResolver $resolver,
    ) {
        abort_unless($session->user_id === Auth::id(), 403);

        // Fremde Workouts nur, wenn sie freigegeben sind. Eigene immer.
        abort_unless($workout->is_public || $workout->user_id === Auth::id(), 403);

        if ($session->status !== 'planned') {
            return back()->with('error', 'Diese Einheit ist schon abgeschlossen.');
        }

        $resolved = $resolver->resolve($workout, $request->user()->runnerProfile);

        $session->update([
            'type'         => $workout->type,
            'title'        => $workout->name,
            'description'  => $workout->description ?? '',
            'duration_min' => $resolved['duration_min'] ?: $session->duration_min,
            'distance_km'  => $resolved['distance_km'],
            'pace_target'  => $resolved['pace_target'],
            'zone'         => $resolved['zone'],
            // Die Struktur kommt aus dem Workout — sie muss nicht erst von
            // einem Modell erfunden werden.
            'steps'          => $resolved['steps'],
            'nutrition_tips' => null,
            'pinned_at'      => now(),
        ]);

        $workout->increment('times_used');
        $workout->forceFill(['last_used_at' => now()])->save();

        Log::info('Workout auf Einheit angewendet', [
            'user_id'    => Auth::id(),
            'session_id' => $session->id,
            'workout_id' => $workout->id,
            'author_id'  => $workout->user_id,
        ]);

        return back()->with('success', "„{$workout->name}\u{201C} steht jetzt f\u{00FC}r diesen Tag.");
    }

    /**
     * Ein Workout an einem Tag einplanen — als echte Einheit in Zone3.
     *
     * Zone3 ist die Wahrheit, die Uhr folgt (Entscheidung von Jan,
     * 28.10.2026). Vorher legte „Senden" das Workout nur bei Garmin an; in
     * Zone3 stand es nirgends, und verschieben oder loeschen erreichte die Uhr
     * nie. Jetzt entsteht eine Einheit, und der Garmin-Abgleich nimmt sie mit
     * wie jede andere.
     *
     * Sie kommt **zusaetzlich**, nicht statt des geplanten Laufs: wer an
     * einem Tag bewusst zweimal laeuft, wird nicht eingeschraenkt (Jan). Darum
     * haengt sie an keinem Plan und keinem Rennen — eine Neuberechnung loescht
     * nur Einheiten ihrer Plaene, und der Planer sieht den Tag nicht als
     * belegt. Ohne laufenden Plan geht es genauso.
     */
    public function schedule(Request $request, Workout $workout, \App\Services\WorkoutPaceResolver $resolver)
    {
        abort_unless($workout->is_public || $workout->user_id === Auth::id(), 403);

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $user     = $request->user();
        $resolved = $resolver->resolve($workout, $user->runnerProfile);

        $session = \App\Models\TrainingSession::create([
            'user_id'          => $user->id,
            'training_plan_id' => null,
            'event_id'         => null,
            'planned_date'     => $data['date'],
            'type'             => $workout->type,
            'title'            => $workout->name,
            'description'      => $workout->description ?? '',
            'duration_min'     => $resolved['duration_min'] ?: null,
            'distance_km'      => $resolved['distance_km'],
            'pace_target'      => $resolved['pace_target'],
            'zone'             => $resolved['zone'],
            'steps'            => $resolved['steps'],
            'intensity'        => match (true) {
                in_array($workout->type, ['interval', 'tempo_run'], true) => 'high',
                ($resolved['zone'] ?? 2) >= 3                             => 'medium',
                default                                                   => 'low',
            },
            'status'           => 'planned',
            'pinned_at'        => now(),
            // Hinter alles, was an dem Tag schon steht.
            'sort_order'       => (int) \App\Models\TrainingSession::where('user_id', $user->id)
                ->whereDate('planned_date', $data['date'])->max('sort_order') + 1,
        ]);

        $workout->increment('times_used');
        $workout->forceFill(['last_used_at' => now()])->save();

        Log::info('Workout eingeplant', [
            'user_id' => $user->id, 'workout_id' => $workout->id, 'session_id' => $session->id, 'date' => $data['date'],
        ]);

        return response()->json([
            'success'    => true,
            'session_id' => $session->id,
            'garmin'     => $user->syncsPlanToGarmin(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validateWorkout(Request $request): array
    {
        return $request->validate([
            'name'        => 'required|string|max:120',
            'type'        => 'required|in:easy_run,tempo_run,interval,long_run',
            'description' => 'nullable|string|max:2000',
            'blocks'      => 'required|array|min:1',
            'tags'        => 'nullable|array',
            'tags.*'      => 'string|max:40',
        ]);
    }

    private function formatWorkout(Workout $w): array
    {
        return [
            'id'                     => $w->id,
            'name'                   => $w->name,
            'type'                   => $w->type,
            'description'            => $w->description,
            'blocks'                 => $w->blocks,
            'tags'                   => $w->tags ?? [],
            'estimated_distance_km'  => $w->estimated_distance_km,
            'estimated_duration_min' => $w->estimated_duration_min,
            'times_used'             => $w->times_used,
            'last_used_at'           => $w->last_used_at?->format('d.m.Y'),
            'updated_at'             => $w->updated_at->format('d.m.Y'),
            'is_public'              => (bool) $w->is_public,
            // Warum ein Admin die Freigabe zurueckgenommen hat. Ohne Grund
            // stuende der Ersteller vor einem stillen Verschwinden.
            'unpublished_reason'     => $w->unpublished_reason,
        ];
    }

}

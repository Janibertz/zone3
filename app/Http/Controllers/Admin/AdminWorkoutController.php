<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die freigegebenen Workouts — wer hat was geteilt.
 *
 * Ein Admin kann hier genau eines: eine Freigabe zurücknehmen. Er kann
 * fremde Workouts nicht ändern und nicht löschen. Das Workout verschwindet
 * dann aus der Auswahl der anderen Athleten und bleibt seinem Ersteller
 * unter „Workouts" erhalten — mit dem Grund daneben, damit er nicht vor
 * einem Verschwinden ohne Erklärung steht.
 */
class AdminWorkoutController extends Controller
{
    public function index(Request $request): Response
    {
        $workouts = Workout::with('user:id,name')
            ->when($request->boolean('all') === false, fn ($q) => $q->where(
                fn ($w) => $w->where('is_public', true)->orWhereNotNull('unpublished_reason')
            ))
            ->orderByDesc('is_public')
            ->orderByDesc('times_used')
            ->orderBy('name')
            ->get()
            ->map(fn (Workout $w) => [
                'id'           => $w->id,
                'name'         => $w->name,
                'description'  => $w->description,
                'type'         => $w->type,
                'author'       => $w->user?->name ?? 'unbekannt',
                'author_id'    => $w->user_id,
                'is_public'    => $w->is_public,
                'published_at' => $w->published_at?->toIso8601String(),
                'times_used'   => $w->times_used,
                'blocks'       => count($w->blocks ?? []),
                'reason'       => $w->unpublished_reason,
            ]);

        return Inertia::render('Admin/Workouts/Index', [
            'workouts' => $workouts,
            'showAll'  => $request->boolean('all'),
        ]);
    }

    /**
     * Die Freigabe zurücknehmen.
     *
     * Mit Grund: der Ersteller sieht ihn bei seinem Workout. Ohne wäre es
     * für ihn ein stilles Verschwinden.
     */
    public function unpublish(Request $request, Workout $workout): RedirectResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:200',
        ]);

        $workout->update([
            'is_public'          => false,
            'published_at'       => null,
            'unpublished_reason' => $data['reason'] ?? 'Von der Administration zurückgezogen.',
        ]);

        Log::warning('Workout-Freigabe zurueckgenommen', [
            'admin_id'   => $request->user()->id,
            'workout_id' => $workout->id,
            'author_id'  => $workout->user_id,
            'reason'     => $workout->unpublished_reason,
        ]);

        return back()->with('success', 'Die Freigabe wurde zurückgenommen.');
    }
}

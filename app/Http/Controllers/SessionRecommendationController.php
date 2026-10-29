<?php

namespace App\Http\Controllers;

use App\Models\SessionRecommendation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Über einen Vorschlag des Coaches entscheiden.
 *
 * Vorher gab es hier nichts zu entscheiden: `AdjustPlanForWellbeingJob`
 * schrieb die heutige Einheit um, sobald der Athlet sein Wellbeing
 * eingetragen hatte. Fachlich oft richtig — aber wer sich auf ein
 * Schwellentraining eingestellt hat und zwanzig lockere Minuten vorfindet,
 * erlebt seinen Plan als etwas, das ihm zustösst.
 */
class SessionRecommendationController extends Controller
{
    /** Den Vorschlag annehmen — siehe {@see SessionRecommendation::accept()}. */
    public function accept(Request $request, SessionRecommendation $recommendation): RedirectResponse|JsonResponse
    {
        $this->mine($request, $recommendation);

        if (! $recommendation->isOpen()) {
            return $this->done($request, $recommendation);
        }

        if (! $recommendation->accept()) {
            return $this->done($request, $recommendation, 'Die Einheit gibt es nicht mehr.', 'error');
        }

        return $this->done($request, $recommendation, 'Die Einheit wurde angepasst.');
    }

    /** Den Vorschlag ablehnen — der Plan bleibt, wie er ist. */
    public function reject(Request $request, SessionRecommendation $recommendation): RedirectResponse|JsonResponse
    {
        $this->mine($request, $recommendation);

        $recommendation->reject();

        return $this->done($request, $recommendation, 'Alles bleibt wie geplant.');
    }

    /**
     * Der Coach-Chat entscheidet per JSON: ein Inertia-Besuch würde die Seite
     * hinter dem Chat neu aufbauen und das Gespräch abreißen lassen.
     */
    private function done(Request $request, SessionRecommendation $recommendation, ?string $message = null, string $level = 'success'): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['status' => $recommendation->fresh()->status, 'message' => $message]);
        }

        return $message ? back()->with($level, $message) : back();
    }

    /** Fremde Empfehlungen gehen niemanden etwas an. */
    private function mine(Request $request, SessionRecommendation $recommendation): void
    {
        abort_unless($recommendation->user_id === $request->user()->id, 403);
    }
}

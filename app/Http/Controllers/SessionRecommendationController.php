<?php

namespace App\Http\Controllers;

use App\Models\SessionRecommendation;
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
    public function accept(Request $request, SessionRecommendation $recommendation): RedirectResponse
    {
        $this->mine($request, $recommendation);

        if (! $recommendation->isOpen()) {
            return back();
        }

        if (! $recommendation->accept()) {
            return back()->with('error', 'Die Einheit gibt es nicht mehr.');
        }

        return back()->with('success', 'Die Einheit wurde angepasst.');
    }

    /** Den Vorschlag ablehnen — der Plan bleibt, wie er ist. */
    public function reject(Request $request, SessionRecommendation $recommendation): RedirectResponse
    {
        $this->mine($request, $recommendation);

        $recommendation->reject();

        return back()->with('success', 'Alles bleibt wie geplant.');
    }

    /** Fremde Empfehlungen gehen niemanden etwas an. */
    private function mine(Request $request, SessionRecommendation $recommendation): void
    {
        abort_unless($recommendation->user_id === $request->user()->id, 403);
    }
}

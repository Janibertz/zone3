<?php

namespace App\Http\Controllers;

use App\Models\SessionRecommendation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
    /**
     * Den Vorschlag annehmen.
     *
     * Die Einheit bekommt `pinned_at` — genau wie alles, was der Athlet über
     * den Coach-Chat setzt. Ohne das würde die nächste Neuberechnung seine
     * Entscheidung still wieder wegwerfen.
     */
    public function accept(Request $request, SessionRecommendation $recommendation): RedirectResponse
    {
        $this->mine($request, $recommendation);

        if (! $recommendation->isOpen()) {
            return back();
        }

        $session = $recommendation->session;

        if (! $session) {
            $recommendation->update([
                'status'       => SessionRecommendation::STATUS_EXPIRED,
                'responded_at' => now(),
            ]);

            return back()->with('error', 'Die Einheit gibt es nicht mehr.');
        }

        $session->update(array_intersect_key(
            $recommendation->after,
            array_flip(SessionRecommendation::FIELDS),
        ) + [
            'pinned_at' => now(),
            // Steps und Verpflegungstipps beschrieben die alte Einheit.
            'steps'          => null,
            'nutrition_tips' => null,
        ]);

        $recommendation->update([
            'status'       => SessionRecommendation::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        Log::info('Empfehlung angenommen', [
            'user_id'        => $request->user()->id,
            'session_id'     => $session->id,
            'recommendation' => $recommendation->id,
        ]);

        return back()->with('success', 'Die Einheit wurde angepasst.');
    }

    /**
     * Den Vorschlag ablehnen — der Plan bleibt, wie er ist.
     */
    public function reject(Request $request, SessionRecommendation $recommendation): RedirectResponse
    {
        $this->mine($request, $recommendation);

        if ($recommendation->isOpen()) {
            $recommendation->update([
                'status'       => SessionRecommendation::STATUS_REJECTED,
                'responded_at' => now(),
            ]);

            Log::info('Empfehlung abgelehnt', [
                'user_id'        => $request->user()->id,
                'recommendation' => $recommendation->id,
            ]);
        }

        return back()->with('success', 'Alles bleibt wie geplant.');
    }

    /** Fremde Empfehlungen gehen niemanden etwas an. */
    private function mine(Request $request, SessionRecommendation $recommendation): void
    {
        abort_unless($recommendation->user_id === $request->user()->id, 403);
    }
}

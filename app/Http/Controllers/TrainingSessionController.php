<?php

namespace App\Http\Controllers;

use App\Models\TrainingSession;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TrainingSessionController extends Controller
{
    /**
     * Mark a session as completed.
     */
    public function complete(TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if($session->status !== 'planned', 422);

        $session->update(['status' => 'completed']);

        return response()->json(['session' => $this->formatSession($session)]);
    }

    /**
     * Mark a session as skipped.
     */
    public function skip(Request $request, TrainingSession $session)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if($session->status !== 'planned', 422);

        $request->validate([
            'reason' => 'nullable|string|max:200',
        ]);

        $session->update([
            'status'      => 'skipped',
            'skip_reason' => $request->reason,
        ]);

        return response()->json(['session' => $this->formatSession($session)]);
    }

    /**
     * AI-adjust a single session based on today's wellbeing.
     */
    public function adjust(TrainingSession $session, OpenAIService $openAI)
    {
        abort_if($session->user_id !== Auth::id(), 403);
        abort_if($session->status !== 'planned', 422);

        $wellbeing = Auth::user()->wellbeingEntries()
            ->where('date', now()->toDateString())
            ->first();

        if (! $wellbeing) {
            return response()->json([
                'error' => 'Kein Wellbeing-Eintrag für heute. Füge zuerst einen Eintrag hinzu.',
            ], 422);
        }

        $adjusted = $openAI->adjustSessionForWellbeing($session->toArray(), $wellbeing);

        if (! $adjusted) {
            return response()->json(['error' => 'KI-Anpassung fehlgeschlagen. Bitte versuche es erneut.'], 500);
        }

        $session->update(array_intersect_key($adjusted, array_flip([
            'type', 'title', 'description', 'distance_km',
            'duration_min', 'pace_target', 'zone', 'intensity',
        ])));

        return response()->json(['session' => $this->formatSession($session->fresh())]);
    }

    private function formatSession(TrainingSession $s): array
    {
        return [
            'id'           => $s->id,
            'planned_date' => $s->planned_date->format('Y-m-d'),
            'type'         => $s->type,
            'title'        => $s->title,
            'description'  => $s->description,
            'distance_km'  => $s->distance_km,
            'duration_min' => $s->duration_min,
            'pace_target'  => $s->pace_target,
            'zone'         => $s->zone,
            'intensity'    => $s->intensity,
            'status'       => $s->status,
            'skip_reason'  => $s->skip_reason,
            'sort_order'   => $s->sort_order,
            'activity_id'  => $s->activity_id,
        ];
    }
}

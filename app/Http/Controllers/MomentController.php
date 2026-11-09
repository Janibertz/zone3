<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ein Moment auf „Heute“ ist gesehen (seit 09.11.2026). Gemerkt am Konto,
 * damit er auf keinem Gerät ein zweites Mal kommt.
 */
class MomentController extends Controller
{
    public function seen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind'       => 'required|in:week,race',
            'week_start' => 'required_if:kind,week|date',
            'event_id'   => 'required_if:kind,race|integer',
        ]);

        $user = $request->user();
        if ($data['kind'] === 'week') {
            $user->moment_week_seen = $data['week_start'];
        } else {
            abort_unless($user->events()->whereKey($data['event_id'])->exists(), 404);
            $user->moment_race_seen = (int) $data['event_id'];
        }
        $user->save();

        return response()->json(['ok' => true]);
    }
}

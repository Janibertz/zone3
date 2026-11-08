<?php

namespace App\Http\Controllers;

use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Watch\AppleWorkoutWriter;
use App\Services\Watch\WorkoutKitPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Einheiten für die Apple Watch, eingeplant von der iOS-App über WorkoutKit
 * (seit 08.11.2026). Die Seite holt das JSON, das Plugin
 * `WatchWorkouts` legt es in die Training-App.
 *
 * Wer Garmin mit Workout-Freigabe verbunden hat, bekommt nichts: dort gehen
 * die Einheiten an die Garmin-Uhr (dieselbe Regel wie beim .workout-Knopf,
 * Entscheidung von Jan vom 25.10.2026).
 */
class AppleWatchController extends Controller
{
    /** Was die App wissen muss — null heißt: keine Watch-Einheiten. */
    public static function state(?User $user): ?array
    {
        if (! $user || $user->sendsWorkoutsViaGarminApi()) {
            return null;
        }

        return ['sync' => (bool) ($user->apple_watch_sync ?? true)];
    }

    /** Die nächsten sieben Tage für den Abgleich beim Öffnen der App. */
    public function upcoming(Request $request, WorkoutKitPayload $payload): JsonResponse
    {
        $user  = $request->user();
        $state = self::state($user);

        if (! $state || ! $state['sync']) {
            return response()->json(['enabled' => false, 'workouts' => []]);
        }

        return response()->json(['enabled' => true, 'workouts' => $payload->upcoming($user)]);
    }

    /** Eine Einheit für den Knopf „Auf die Apple Watch“. */
    public function show(Request $request, TrainingSession $session, WorkoutKitPayload $payload): JsonResponse
    {
        abort_if($session->user_id !== $request->user()->id, 403);
        abort_unless(AppleWorkoutWriter::supports($session), 404);

        return response()->json(['workout' => $payload->forSession($session)]);
    }

    /** Automatischen Abgleich an- oder abschalten (Profil → Verbindungen). */
    public function updateSync(Request $request): RedirectResponse
    {
        $request->validate(['enabled' => 'required|boolean']);
        $request->user()->update(['apple_watch_sync' => $request->boolean('enabled')]);

        return back();
    }
}

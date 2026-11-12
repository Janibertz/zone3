<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutReport;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ein geteiltes Workout melden (App Store, Guideline 1.2).
 *
 * Wer meldet, sieht es sofort nicht mehr. Die Admins bekommen Push und
 * entscheiden unter /admin/workouts, ob die Freigabe zurückgenommen wird —
 * eine Meldung allein nimmt nichts aus der Auswahl der anderen.
 */
class WorkoutReportController extends Controller
{
    public function store(Request $request, Workout $workout, WebPushService $push)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $user = $request->user();

        abort_if(! $workout->is_public || $workout->user_id === $user->id, 404);

        $report = WorkoutReport::firstOrCreate(
            ['workout_id' => $workout->id, 'user_id' => $user->id],
            ['reason' => $data['reason'] ?? null],
        );

        if ($report->wasRecentlyCreated) {
            $body = "„{$workout->name}“ wurde von {$user->name} gemeldet"
                . (! empty($data['reason']) ? ': ' . Str::limit($data['reason'], 120) : '.');

            foreach (User::where('is_admin', true)->get() as $admin) {
                try {
                    $push->sendToUser($admin, 'Workout gemeldet', $body, '/admin/workouts');
                } catch (Throwable $e) {
                    Log::warning('Workout-Meldung: Push ging nicht raus', ['admin_id' => $admin->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return response()->json(['reported' => true]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\RunnerProfile;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function show()
    {
        $user = Auth::user();

        if ($user->onboarding_completed_at) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Onboarding', [
            'stravaConnectUrl' => route('strava.connect'),
        ]);
    }

    /**
     * Estimate runner profile from simple race data via OpenAI.
     * Returns suggested values — user still confirms before saving.
     */
    public function estimateProfile(Request $request, OpenAIService $openAI)
    {
        $validated = $request->validate([
            'age'           => 'required|integer|min:14|max:90',
            'race_distance' => 'required|string|in:5km,10km,half_marathon,marathon',
            'race_time'     => ['required', 'string', 'regex:/^(\d+:)?\d{1,2}:\d{2}$/'],
            'weekly_runs'   => 'required|integer|min:1|max:14',
        ]);

        $estimate = $openAI->estimateProfileFromRaceData(
            $validated['age'],
            $validated['race_distance'],
            $validated['race_time'],
            $validated['weekly_runs'],
        );

        if (!$estimate) {
            return response()->json(['error' => 'Schätzung fehlgeschlagen. Bitte versuche es erneut.'], 500);
        }

        return response()->json($estimate);
    }

    /**
     * Save runner profile (manually entered or AI-estimated values).
     */
    public function saveProfile(Request $request)
    {
        $validated = $request->validate([
            'threshold_heart_rate' => 'required|integer|min:100|max:220',
            'max_heart_rate'       => 'required|integer|min:100|max:220',
            'threshold_speed'      => 'required|string|regex:/^[0-9]{1,2}:[0-9]{2}$/',
        ], [
            'threshold_speed.regex' => 'Bitte verwende das Format MM:SS (z.B. 5:30)',
        ]);

        $paceParts     = explode(':', $validated['threshold_speed']);
        $paceInMinutes = (float) ($paceParts[0] + ($paceParts[1] / 60));

        $user    = Auth::user();
        $profile = $user->runnerProfile ?? new RunnerProfile(['user_id' => $user->id]);

        $profile->user_id              = $user->id;
        $profile->threshold_heart_rate = (int) $validated['threshold_heart_rate'];
        $profile->max_heart_rate       = (int) $validated['max_heart_rate'];
        $profile->threshold_speed      = $paceInMinutes;
        $profile->heart_rate_zones     = $profile->calculateHeartRateZones();
        $profile->pace_zones           = $profile->calculatePaceZones();
        $profile->has_completed_setup  = true;
        $profile->save();

        return response()->json(['success' => true]);
    }

    /**
     * Save race goal with target time.
     */
    public function saveGoal(Request $request)
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:150',
            'race_distance'       => 'required|string|in:5km,10km,half_marathon,marathon,custom',
            'target_value'        => 'required|numeric|min:0.1',
            'unit'                => 'required|string|max:20',
            'target_time_hours'   => 'required|integer|min:0|max:23',
            'target_time_minutes' => 'required|integer|min:0|max:59',
            'race_date'           => 'required|date|after:today',
        ]);

        Auth::user()->goals()->create([
            'name'                => $validated['name'],
            'type'                => 'distance',
            'target_value'        => $validated['target_value'],
            'unit'                => $validated['unit'],
            'target_time_hours'   => $validated['target_time_hours'],
            'target_time_minutes' => $validated['target_time_minutes'],
            'start_date'          => now()->toDateString(),
            'end_date'            => $validated['race_date'],
            'active'              => true,
            'meta'                => ['race_distance' => $validated['race_distance']],
        ]);

        return response()->json(['success' => true]);
    }

    public function complete(Request $request)
    {
        $user = Auth::user();
        $user->onboarding_completed_at = now();
        $user->save();

        return redirect()->route('dashboard');
    }

    public function completeAndConnectStrava(Request $request)
    {
        $user = Auth::user();
        $user->onboarding_completed_at = now();
        $user->save();

        return redirect()->route('strava.connect');
    }
}

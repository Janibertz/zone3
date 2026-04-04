<?php

namespace App\Http\Controllers;

use App\Models\RunnerProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function show()
    {
        $user = Auth::user();

        // Already completed — send to dashboard
        if ($user->onboarding_completed_at) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Onboarding', [
            'stravaConnectUrl' => route('strava.connect'),
        ]);
    }

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

        $profile->user_id               = $user->id;
        $profile->threshold_heart_rate  = (int) $validated['threshold_heart_rate'];
        $profile->max_heart_rate        = (int) $validated['max_heart_rate'];
        $profile->threshold_speed       = $paceInMinutes;
        $profile->heart_rate_zones      = $profile->calculateHeartRateZones();
        $profile->pace_zones            = $profile->calculatePaceZones();
        $profile->has_completed_setup   = true;
        $profile->save();

        return response()->json(['success' => true]);
    }

    public function saveGoal(Request $request)
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:150',
            'type'                => 'required|string|in:distance,duration,frequency',
            'target_value'        => 'required|numeric|min:0',
            'unit'                => 'nullable|string|max:20',
            'target_time_hours'   => 'required|integer|min:0|max:23',
            'target_time_minutes' => 'required|integer|min:0|max:59',
            'start_date'          => 'required|date',
            'end_date'            => 'required|date|after_or_equal:start_date',
        ]);

        $validated['user_id'] = Auth::id();
        $validated['active']  = true;

        Auth::user()->goals()->create($validated);

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

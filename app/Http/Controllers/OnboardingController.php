<?php

namespace App\Http\Controllers;

use App\Models\Coach;
use App\Models\Event;
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

        $coaches = Coach::all(['id', 'name', 'slug', 'specialty', 'tagline', 'description', 'avatar_color', 'avatar_initials']);

        return Inertia::render('Onboarding', [
            'stravaConnectUrl' => route('strava.connect'),
            'coaches'          => $coaches,
        ]);
    }

    /**
     * Assign a coach to the user during onboarding.
     */
    public function saveCoach(Request $request)
    {
        $validated = $request->validate([
            'coach_id' => 'required|integer|exists:coaches,id',
        ]);

        $user = Auth::user();
        $user->coach_id = $validated['coach_id'];
        $user->save();

        return response()->json(['success' => true]);
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
     * Save weekly availability.
     */
    public function saveAvailability(Request $request)
    {
        $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];

        $validated = $request->validate([
            'availability'         => 'required|array',
            'availability.*.available'   => 'required|boolean',
            'availability.*.duration_min' => 'required|integer|min:0|max:300',
        ]);

        $user    = Auth::user();
        $profile = $user->runnerProfile ?? new RunnerProfile(['user_id' => $user->id]);
        $profile->user_id             = $user->id;
        $profile->weekly_availability = $validated['availability'];
        $profile->save();

        return response()->json(['success' => true]);
    }

    /**
     * Save strength & core training preferences.
     */
    public function saveStrength(Request $request)
    {
        $validated = $request->validate([
            'strength_enabled'       => 'required|boolean',
            'strength_days_per_week' => 'nullable|integer|min:1|max:4',
            'strength_equipment'     => 'nullable|array',
            'strength_equipment.*'   => 'string|in:kettlebell,dumbbells,gym,bodyweight,band',
            'strength_experience'    => 'nullable|in:beginner,intermediate,advanced',
        ]);

        $user    = Auth::user();
        $profile = $user->runnerProfile ?? new RunnerProfile(['user_id' => $user->id]);
        $profile->user_id                = $user->id;
        $profile->strength_enabled       = $validated['strength_enabled'];
        $profile->strength_days_per_week = $validated['strength_days_per_week'] ?? 2;
        $profile->strength_equipment     = $validated['strength_equipment'] ?? [];
        $profile->strength_experience    = $validated['strength_experience'] ?? null;
        $profile->save();

        return response()->json(['success' => true]);
    }

    /**
     * Save race goal as an Event (A-priority by default).
     */
    public function saveGoal(Request $request)
    {
        $validated = $request->validate([
            'name'                => 'required|string|max:150',
            'race_distance'       => 'required|string|in:5km,10km,half_marathon,marathon,custom,backyard_ultra',
            'distance_km'         => 'nullable|numeric|min:0.1',
            'target_time_hours'   => 'required_unless:race_distance,backyard_ultra|integer|min:0|max:23',
            'target_time_minutes' => 'required_unless:race_distance,backyard_ultra|integer|min:0|max:59',
            'target_yards'        => 'required_if:race_distance,backyard_ultra|nullable|integer|min:1|max:100',
            'race_date'           => 'required|date|after:today',
        ]);

        Event::create([
            'user_id'             => Auth::id(),
            'name'                => $validated['name'],
            'event_date'          => $validated['race_date'],
            'race_distance'       => $validated['race_distance'],
            'distance_km'         => $validated['distance_km'] ?? null,
            'priority'            => 'A',
            'target_time_hours'   => $validated['target_time_hours'] ?? 0,
            'target_time_minutes' => $validated['target_time_minutes'] ?? 0,
            'target_yards'        => $validated['target_yards'] ?? null,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Reset onboarding so the user can go through it again.
     */
    public function reset(Request $request)
    {
        $user = Auth::user();
        $user->onboarding_completed_at = null;
        $user->save();

        return redirect()->route('onboarding');
    }

    public function complete(Request $request)
    {
        $user = Auth::user();
        $user->onboarding_completed_at = now();

        // Assign Max (companion) as default if user skipped coach selection
        if (! $user->coach_id) {
            $default = Coach::where('slug', 'max')->first();
            if ($default) {
                $user->coach_id = $default->id;
            }
        }

        $user->save();

        return redirect()->route('dashboard');
    }

    public function completeAndConnectStrava(Request $request)
    {
        $user = Auth::user();
        $user->onboarding_completed_at = now();

        if (! $user->coach_id) {
            $default = Coach::where('slug', 'max')->first();
            if ($default) {
                $user->coach_id = $default->id;
            }
        }

        $user->save();

        // Inertia::location() triggers a full browser navigation (window.location.href)
        // instead of an XHR follow — required so the external Strava redirect works correctly.
        return Inertia::location(route('strava.connect'));
    }
}

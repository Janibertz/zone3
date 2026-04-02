<?php

namespace App\Http\Controllers;

use App\Models\RunnerProfile;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class RunnerProfileController extends Controller
{
    /**
     * Show the profile setup form
     */
    public function show()
    {
        $user = Auth::user();
        $profile = $user->runnerProfile;

        return Inertia::render('Profile/Setup', [
            'profile' => $profile,
        ]);
    }

    /**
     * Store or update runner profile with zone calculations
     */
    public function store(Request $request)
    {
        \Log::info('RunnerProfile Store called', [
            'all_input' => $request->all(),
            'user_id' => auth()->id(),
        ]);

        $validated = $request->validate([

            'threshold_heart_rate' => 'required|integer|min:100|max:220',
            'max_heart_rate' => 'required|integer|min:100|max:220',
            'threshold_speed' => 'required|string|regex:/^[0-9]{1,2}:[0-9]{2}$/',
        ], [
            'threshold_speed.regex' => 'Bitte verwende das Format MM:SS (z.B. 5:30)',
        ]);

        \Log::info('Validation passed', ['validated' => $validated]);

        try {
            // Convert pace string (MM:SS) to float (minutes)
            $paceParts = explode(':', $validated['threshold_speed']);
            $minutes = (int)$paceParts[0];
            $seconds = (int)$paceParts[1];
            $paceInMinutes = (float)($minutes + ($seconds / 60));

            $user = auth()->user();
            
            \Log::info('Creating/updating profile', [
                'user_id' => $user->id,
                'pace_string' => $validated['threshold_speed'],
                'pace_float' => $paceInMinutes,
            ]);

            // Create or update profile
            $profile = $user->runnerProfile ?? new RunnerProfile();
            
            // Set individual fields explicitly
            $profile->user_id = $user->id;
            $profile->threshold_heart_rate = (int)$validated['threshold_heart_rate'];
            $profile->max_heart_rate = (int)$validated['max_heart_rate'];
            $profile->threshold_speed = $paceInMinutes;
            
            // Calculate zones before saving
            $profile->heart_rate_zones = $profile->calculateHeartRateZones();
            $profile->pace_zones = $profile->calculatePaceZones();
            $profile->has_completed_setup = true;
            
            $profile->save();

            \Log::info('Profile saved successfully', [
                'profile_id' => $profile->id,
                'ftp' => $profile->ftp,
                'threshold_speed' => $profile->threshold_speed,
            ]);

            return redirect()->route('profile.edit')->with('status', 'athlete-saved');
        } catch (\Exception $e) {
            \Log::error('Error saving profile', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return redirect()->back()->with('error', 'Fehler beim Speichern: ' . $e->getMessage());
        }
    }

    /**
     * Preview pace zones based on input (for real-time preview)
     */
    public function previewZones(Request $request, OpenAIService $openAIService)
    {
        $validated = $request->validate([
            'threshold_speed' => 'required|string|regex:/^[0-9]{1,2}:[0-9]{2}$/',
        ], [
            'threshold_speed.regex' => 'Bitte verwende das Format MM:SS (z.B. 5:30)',
        ]);

        // Prefer ChatGPT-based zone calculation
        $zones = $openAIService->calculatePaceZonesWithAI($validated['threshold_speed']);

        // Fallback to local calculation if AI failed
        if (empty($zones)) {
            $paceParts = explode(':', $validated['threshold_speed']);
            $minutes = (int)$paceParts[0];
            $seconds = (int)$paceParts[1];
            $paceInMinutes = (float)($minutes + ($seconds / 60));

            $tempProfile = new RunnerProfile();
            $tempProfile->threshold_speed = $paceInMinutes;
            $zones = $tempProfile->calculatePaceZones();
        }

        return response()->json([
            'pace_zones' => $zones,
        ]);
    }

    /**
     * Get profile data as JSON (for API/Vue)
     */
    public function profile()
    {
        $user = Auth::user();
        $profile = $user->runnerProfile;

        if (!$profile) {
            return response()->json(['message' => 'Profil nicht gefunden'], 404);
        }

        return response()->json($profile);
    }
}

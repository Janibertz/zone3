<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Activity;
use App\Models\Coach;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        // Athlete stats
        $stats = Activity::where('user_id', $user->id)->selectRaw('
            COUNT(*) as total_runs,
            COALESCE(SUM(distance), 0) as total_distance,
            COALESCE(AVG(NULLIF(average_speed, 0)), 0) as avg_speed,
            COALESCE(MAX(distance), 0) as longest_run
        ')->first();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail'  => $user instanceof MustVerifyEmail,
            'status'           => session('status'),
            'runnerProfile'    => $user->runnerProfile,
            'stravaConnected'  => (bool) $user->stravaAccount,
            'stravaAccount'    => $user->stravaAccount ? [
                'username'       => $user->stravaAccount->username,
                'last_synced_at' => $user->stravaAccount->last_synced_at,
            ] : null,
            'notificationSettings' => [
                'push_enabled'            => (bool) $user->push_notifications_enabled,
                'wellbeing_reminder_time' => $user->wellbeing_reminder_time ?? '08:00',
                'notify_threshold_pace'   => (bool) ($user->notify_threshold_pace ?? true),
                'notify_plan_updated'     => (bool) ($user->notify_plan_updated ?? true),
            ],
            'vapidPublicKey' => config('services.webpush.public_key'),
            'coaches'   => Coach::all(['id', 'name', 'slug', 'specialty', 'tagline', 'description', 'avatar_color', 'avatar_initials']),
            'activeCoach' => $user->coach ? [
                'id'              => $user->coach->id,
                'name'            => $user->coach->name,
                'specialty'       => $user->coach->specialty,
                'tagline'         => $user->coach->tagline,
                'avatar_color'    => $user->coach->avatar_color,
                'avatar_initials' => $user->coach->avatar_initials,
            ] : null,
            'athleteStats' => [
                'total_runs'      => (int) $stats->total_runs,
                'total_km'        => round($stats->total_distance / 1000, 1),
                'longest_km'      => round($stats->longest_run / 1000, 2),
                'avg_pace'        => $this->speedToPace($stats->avg_speed),
            ],
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-information-updated');
    }

    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $user = $request->user();

        // Delete old avatar
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => $path]);

        return Redirect::route('profile.edit')->with('status', 'avatar-updated');
    }

    public function updateCoach(Request $request): RedirectResponse
    {
        $request->validate([
            'coach_id' => 'required|integer|exists:coaches,id',
        ]);

        $request->user()->update(['coach_id' => $request->coach_id]);

        return Redirect::route('profile.edit')->with('status', 'coach-updated');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    public function garminConnect(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:1',
        ]);

        $serviceUrl = config('services.fit.service_url');
        if (! $serviceUrl) {
            return response()->json(['error' => 'FIT-Service nicht verfügbar.'], 503);
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->post(rtrim($serviceUrl, '/') . '/send-to-garmin', [
                    'name'            => 'Zone3 Auth',
                    'date'            => now()->toDateString(),
                    'sport'           => 'running',
                    'steps'           => [],
                    'garmin_email'    => $request->email,
                    'garmin_password' => $request->password,
                ]);

            $json = $response->json() ?: [];

            if (isset($json['detail'])) {
                $detail = $json['detail'];
                if ($detail === 'mfa_required')
                    return response()->json(['error' => 'mfa_required'], 422);
                if (str_starts_with($detail, 'login_failed:'))
                    return response()->json(['error' => 'login_failed'], 422);
                // Other errors (e.g. empty steps) are OK if we got a session back
            }

            if (empty($json['session'])) {
                return response()->json(['error' => 'Keine Session zurückgegeben. Bitte überprüfe deine Zugangsdaten.'], 422);
            }

            Auth::user()->update([
                'garmin_email'   => $request->email,
                'garmin_session' => $json['session'],
            ]);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Garmin connect error: ' . $e->getMessage());
            return response()->json(['error' => 'Verbindung fehlgeschlagen: ' . $e->getMessage()], 503);
        }
    }

    private function speedToPace(float $speed): string
    {
        if ($speed <= 0) return '–';
        $secPerKm = 1000 / $speed;
        $min = (int) ($secPerKm / 60);
        $sec = (int) ($secPerKm % 60);
        return sprintf('%d:%02d', $min, $sec);
    }
}

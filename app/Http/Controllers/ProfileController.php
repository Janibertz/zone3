<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Activity;
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

    private function speedToPace(float $speed): string
    {
        if ($speed <= 0) return '–';
        $secPerKm = 1000 / $speed;
        $min = (int) ($secPerKm / 60);
        $sec = (int) ($secPerKm % 60);
        return sprintf('%d:%02d', $min, $sec);
    }
}

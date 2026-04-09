<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
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
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-information-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}

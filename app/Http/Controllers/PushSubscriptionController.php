<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PushSubscriptionController extends Controller
{
    /**
     * Save a new push subscription from the browser.
     */
    public function store(Request $request)
    {
        $request->validate([
            'endpoint'   => 'required|string',
            'public_key' => 'required|string',
            'auth_token' => 'required|string',
        ]);

        $user = Auth::user();

        PushSubscription::updateOrCreate(
            ['endpoint' => $request->endpoint],
            [
                'user_id'    => $user->id,
                'public_key' => $request->public_key,
                'auth_token' => $request->auth_token,
                'user_agent' => $request->userAgent(),
            ]
        );

        // Enable push notifications for this user
        $user->update(['push_notifications_enabled' => true]);

        return response()->json(['ok' => true]);
    }

    /**
     * Remove a push subscription (when user unsubscribes in browser).
     */
    public function destroy(Request $request)
    {
        $request->validate(['endpoint' => 'required|string']);

        PushSubscription::where('user_id', Auth::id())
            ->where('endpoint', $request->endpoint)
            ->delete();

        // If no subscriptions left, mark push as disabled
        if (Auth::user()->pushSubscriptions()->count() === 0) {
            Auth::user()->update(['push_notifications_enabled' => false]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Update notification preferences.
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'wellbeing_reminder_time' => 'nullable|date_format:H:i',
            'notify_threshold_pace'   => 'boolean',
            'notify_plan_updated'     => 'boolean',
            'notify_monthly_review'   => 'boolean',
        ]);

        Auth::user()->update($request->only([
            'wellbeing_reminder_time',
            'notify_threshold_pace',
            'notify_plan_updated',
            'notify_monthly_review',
        ]));

        return response()->json(['ok' => true]);
    }

    /**
     * Send a test push to the current user.
     */
    public function test(WebPushService $webPush)
    {
        $user = Auth::user();

        if ($user->pushSubscriptions()->count() === 0) {
            return response()->json(['error' => 'Keine aktive Push-Subscription gefunden.'], 422);
        }

        try {
            $webPush->sendTest($user);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Push test failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true]);
    }
}

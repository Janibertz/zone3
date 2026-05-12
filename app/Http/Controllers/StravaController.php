<?php

namespace App\Http\Controllers;

use App\Jobs\CalculateThresholdPaceJob;
use App\Models\Activity;
use App\Models\RunnerProfile;
use App\Models\StravaAccount;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\StravaService;
use App\Services\WebPushService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class StravaController extends Controller
{
    public function connect(StravaService $strava): RedirectResponse
    {
        return redirect()->away($strava->getAuthorizationUrl());
    }

    public function callback(Request $request, StravaService $strava): RedirectResponse
    {
        $request->validate(['code' => 'required|string']);

        $tokenData = $strava->exchangeCodeForToken($request->code);
        $user = $request->user();

        $user->stravaAccount()->updateOrCreate(
            ['strava_id' => $tokenData['athlete']['id']],
            [
                'username'         => $tokenData['athlete']['username'] ?? null,
                'access_token'     => $tokenData['access_token'],
                'refresh_token'    => $tokenData['refresh_token'],
                'token_expires_at' => Carbon::createFromTimestamp($tokenData['expires_at']),
                'scope'            => isset($tokenData['scope']) ? explode(',', $tokenData['scope']) : null,
            ]
        );

        return redirect()->route('dashboard')->with('success', 'Strava erfolgreich verbunden.');
    }

    /**
     * Disconnect Strava — removes the account record and all imported activities.
     */
    public function disconnect(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->stravaAccount?->delete();
        $user->activities()->delete();

        return redirect()->route('profile.edit')
            ->with('status', 'strava-disconnected');
    }

    /**
     * Manual sync: saves activities immediately, dispatches AI job if rate limit allows.
     */
    public function sync(Request $request, StravaService $strava): RedirectResponse
    {
        $user    = $request->user();
        $account = $user->stravaAccount;

        if (! $account) {
            return redirect()->route('dashboard')->with('error', 'Strava nicht verbunden.');
        }

        $activities = $strava->fetchRecentActivities($account);

        $newCount    = 0;
        $newRunCount = 0;

        foreach ($activities as $activityData) {
            $isNew = ! Activity::where('strava_id', $activityData['id'])
                ->where('user_id', $user->id)
                ->exists();

            $activity = Activity::updateOrCreate(
                ['strava_id' => $activityData['id'], 'user_id' => $user->id],
                [
                    'name'                 => $activityData['name'] ?? 'Aktivität',
                    'description'          => $activityData['description'] ?? null,
                    'type'                 => $activityData['type'] ?? 'Run',
                    'distance'             => $activityData['distance'] ?? 0,
                    'moving_time'          => $activityData['moving_time'] ?? 0,
                    'elapsed_time'         => $activityData['elapsed_time'] ?? 0,
                    'total_elevation_gain' => $activityData['total_elevation_gain'] ?? 0,
                    'average_speed'        => $activityData['average_speed'] ?? 0,
                    'max_speed'            => $activityData['max_speed'] ?? 0,
                    'average_heartrate'    => $activityData['average_heartrate'] ?? null,
                    'max_heartrate'        => $activityData['max_heartrate'] ?? null,
                    'start_date'           => $activityData['start_date'] ?? now(),
                    'location_city'        => $activityData['location_city'] ?? null,
                    'location_state'       => $activityData['location_state'] ?? null,
                    'location_country'     => $activityData['location_country'] ?? null,
                    'polyline'             => $this->extractPolyline($activityData),
                ]
            );

            if ($isNew) {
                $newCount++;
                if ($activity->type === 'Run') {
                    $newRunCount++;
                }
            }

            // Match to plan sessions or create unplanned entry for all activity types
            $this->matchActivityToSession($user->id, $activity);
        }

        $this->dispatchCalculationIfDue($user->id, $newRunCount);

        if ($newCount === 0) {
            $message = 'Keine neuen Aktivitäten gefunden.';
        } else {
            $message = "{$newCount} neue " . ($newCount === 1 ? 'Aktivität' : 'Aktivitäten') . " importiert.";
        }

        $profile = $user->fresh()->runnerProfile;
        if ($profile?->threshold_pace_calculating) {
            $message .= ' Schwellenpace wird im Hintergrund neu berechnet.';
        }

        return redirect()->route('dashboard')->with('sync_result', $message);
    }

    /**
     * Strava webhook verification (GET).
     */
    public function webhookVerify(Request $request): \Illuminate\Http\JsonResponse|Response
    {
        $verifyToken = config('services.strava.webhook_verify_token', 'zone3_webhook');

        if (
            $request->get('hub_mode') === 'subscribe' &&
            $request->get('hub_verify_token') === $verifyToken
        ) {
            return response()->json(['hub.challenge' => $request->get('hub_challenge')]);
        }

        return response('Unauthorized', 401);
    }

    /**
     * Strava webhook event handler (POST) — triggered automatically when a new activity is created.
     */
    public function webhook(Request $request, StravaService $strava, WebPushService $webPush): Response
    {
        $data = $request->all();

        if (
            ($data['object_type'] ?? '') !== 'activity' ||
            ($data['aspect_type'] ?? '') !== 'create'
        ) {
            return response('OK');
        }

        $account = StravaAccount::where('strava_id', $data['owner_id'] ?? null)->first();
        if (! $account) return response('OK');

        $activityData = $strava->fetchActivity($account, (int) $data['object_id']);
        if (! $activityData) return response('OK');

        $userId = $account->user_id;

        $activity = Activity::updateOrCreate(
            ['strava_id' => $activityData['id'], 'user_id' => $userId],
            [
                'name'                 => $activityData['name'] ?? 'Aktivität',
                'description'          => $activityData['description'] ?? null,
                'type'                 => $activityData['type'] ?? 'Run',
                'distance'             => $activityData['distance'] ?? 0,
                'moving_time'          => $activityData['moving_time'] ?? 0,
                'elapsed_time'         => $activityData['elapsed_time'] ?? 0,
                'total_elevation_gain' => $activityData['total_elevation_gain'] ?? 0,
                'average_speed'        => $activityData['average_speed'] ?? 0,
                'max_speed'            => $activityData['max_speed'] ?? 0,
                'average_heartrate'    => $activityData['average_heartrate'] ?? null,
                'max_heartrate'        => $activityData['max_heartrate'] ?? null,
                'start_date'           => $activityData['start_date'] ?? now(),
                'location_city'        => $activityData['location_city'] ?? null,
                'location_state'       => $activityData['location_state'] ?? null,
                'location_country'     => $activityData['location_country'] ?? null,
                'polyline'             => $this->extractPolyline($activityData),
            ]
        );

        $isRun = $activity->type === 'Run';
        $this->dispatchCalculationIfDue($userId, $isRun ? 1 : 0);
        $this->matchActivityToSession($userId, $activity);

        // Push notification for the user
        $user = User::find($userId);
        if ($user && $user->push_notifications_enabled) {
            $distKm  = $activity->distance > 0 ? round($activity->distance / 1000, 1) . ' km' : '';
            $body    = trim($activity->name . ($distKm ? " · {$distKm}" : ''));
            $webPush->sendToUser(
                $user,
                'Neue Aktivität importiert 🏃',
                $body,
                '/activities'
            );
        }

        return response('OK');
    }

    /**
     * Match a Strava activity to a planned training session (Runs only),
     * or create an unplanned completed entry for any activity type.
     */
    private function matchActivityToSession(int $userId, Activity $activity): void
    {
        $date = $activity->start_date->toDateString();

        // Non-Run activities (swim, bike, etc.) skip planned-session matching
        if ($activity->type !== 'Run') {
            if (TrainingSession::where('user_id', $userId)->where('activity_id', $activity->id)->exists()) {
                return;
            }
            $activePlan = TrainingPlan::where('user_id', $userId)->where('is_active', true)->latest()->first();
            if (! $activePlan) return;

            $distKm = $activity->distance > 0 ? round($activity->distance / 1000, 2) : null;
            $durMin = $activity->moving_time > 0 ? (int) round($activity->moving_time / 60) : null;

            TrainingSession::create([
                'user_id'          => $userId,
                'training_plan_id' => $activePlan->id,
                'event_id'         => $activePlan->event_id,
                'activity_id'      => $activity->id,
                'planned_date'     => $date,
                'type'             => 'easy_run',
                'title'            => $activity->name,
                'distance_km'      => $distKm,
                'duration_min'     => $durMin,
                'pace_target'      => null,
                'zone'             => null,
                'intensity'        => 'medium',
                'status'           => 'completed',
                'sort_order'       => 999,
            ]);
            return;
        }

        // 1. Find any planned session in the active plan on the same date
        $session = TrainingSession::where('user_id', $userId)
            ->where('planned_date', $date)
            ->where('status', 'planned')
            ->whereHas('trainingPlan', fn ($q) => $q->where('is_active', true))
            ->first();

        $distKm = $activity->distance > 0 ? round($activity->distance / 1000, 2) : null;
        $durMin = $activity->moving_time > 0 ? (int) round($activity->moving_time / 60) : null;
        $pace   = $this->paceFromSpeed($activity->average_speed);

        if ($session) {
            if ($session->type === 'rest') {
                // User ran on a rest day — delete rest entry, create real run entry
                $plan = $session->trainingPlan;
                $session->delete();
                TrainingSession::create([
                    'user_id'          => $userId,
                    'training_plan_id' => $plan->id,
                    'event_id'         => $plan->event_id,
                    'activity_id'      => $activity->id,
                    'planned_date'     => $date,
                    'type'             => 'easy_run',
                    'title'            => $activity->name,
                    'distance_km'      => $distKm,
                    'duration_min'     => $durMin,
                    'pace_target'      => $pace,
                    'zone'             => null,
                    'intensity'        => 'medium',
                    'status'           => 'completed',
                    'sort_order'       => 0,
                ]);
            } else {
                // Replace planned session data with actual Strava data
                $session->update([
                    'status'       => 'completed',
                    'activity_id'  => $activity->id,
                    'distance_km'  => $distKm ?? $session->distance_km,
                    'duration_min' => $durMin ?? $session->duration_min,
                    'pace_target'  => $pace ?? $session->pace_target,
                ]);
            }
            // Flag plan for recalculation
            $session->trainingPlan?->update(['needs_plan_update' => true]);
            return;
        }

        // 2. No planned session – check if an unplanned entry for this activity already exists
        if (TrainingSession::where('user_id', $userId)->where('activity_id', $activity->id)->exists()) {
            return;
        }

        // Create an unplanned completed entry in the active plan
        $activePlan = TrainingPlan::where('user_id', $userId)
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $activePlan) return;

        TrainingSession::create([
            'user_id'          => $userId,
            'training_plan_id' => $activePlan->id,
            'event_id'         => $activePlan->event_id,
            'activity_id'      => $activity->id,
            'planned_date'     => $date,
            'type'             => 'easy_run',
            'title'            => $activity->name,
            'distance_km'      => $distKm,
            'duration_min'     => $durMin,
            'pace_target'      => $pace,
            'zone'             => null,
            'intensity'        => 'medium',
            'status'           => 'completed',
            'sort_order'       => 999,
        ]);

        // Signal that the remaining planned sessions should be recalculated
        $activePlan->needs_plan_update = true;
        $activePlan->save();
    }

    /** Convert Strava average_speed (m/s) to "M:SS" pace string, or null. */
    private function paceFromSpeed(float $mps): ?string
    {
        if ($mps <= 0) return null;
        $secPerKm = 1000 / $mps;
        return sprintf('%d:%02d', (int)($secPerKm / 60), (int)($secPerKm % 60));
    }

    /**
     * Dispatch AI calculation job if:
     *  - At least one new Run was added
     *  - Last calculation was > 24h ago (or never)
     *  - No calculation is already running
     */
    private function dispatchCalculationIfDue(int $userId, int $newRunCount): void
    {
        if ($newRunCount === 0) return;

        $profile = RunnerProfile::firstOrCreate(
            ['user_id' => $userId],
            ['has_completed_setup' => false]
        );

        $lastCalc = $profile->threshold_pace_calculated_at;
        $isDue    = $lastCalc === null || $lastCalc->lt(now()->subHours(24));

        if ($isDue && ! $profile->threshold_pace_calculating) {
            $profile->threshold_pace_calculating = true;
            $profile->save();
            CalculateThresholdPaceJob::dispatch($userId);
        }
    }

    /**
     * Extract the best available polyline from Strava activity data.
     * List endpoint → summary_polyline only.
     * Single activity endpoint → polyline (detailed) preferred, fallback summary_polyline.
     * Returns null if neither is present or both are empty strings.
     */
    private function extractPolyline(array $activityData): ?array
    {
        $poly = $activityData['map']['polyline'] ?? null;
        if (empty($poly)) {
            $poly = $activityData['map']['summary_polyline'] ?? null;
        }
        return $poly ? ['polyline' => $poly] : null;
    }
}

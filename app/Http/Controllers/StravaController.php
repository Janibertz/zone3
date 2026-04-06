<?php

namespace App\Http\Controllers;

use App\Jobs\CalculateThresholdPaceJob;
use App\Models\Activity;
use App\Models\RunnerProfile;
use App\Models\StravaAccount;
use App\Services\StravaService;
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

            Activity::updateOrCreate(
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
                    'polyline'             => isset($activityData['map']['polyline'])
                        ? ['polyline' => $activityData['map']['polyline']]
                        : null,
                ]
            );

            if ($isNew) {
                $newCount++;
                if (($activityData['type'] ?? 'Run') === 'Run') {
                    $newRunCount++;
                }
            }
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
    public function webhook(Request $request, StravaService $strava): Response
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

        Activity::updateOrCreate(
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
                'polyline'             => isset($activityData['map']['summary_polyline'])
                    ? ['polyline' => $activityData['map']['summary_polyline']]
                    : null,
            ]
        );

        $this->dispatchCalculationIfDue($userId, ($activityData['type'] ?? '') === 'Run' ? 1 : 0);

        return response('OK');
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
}

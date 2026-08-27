<?php

namespace App\Http\Controllers;

use App\Jobs\CalculateThresholdPaceJob;
use App\Jobs\GenerateSessionReviewJob;
use App\Jobs\RegeneratePlanJob;
use App\Models\Activity;
use App\Models\RunnerProfile;
use App\Models\StravaAccount;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\BestEffortService;
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
    public function sync(Request $request, StravaService $strava, BestEffortService $bestEfforts): RedirectResponse
    {
        $user    = $request->user();
        $account = $user->stravaAccount;

        if (! $account) {
            return redirect()->route('dashboard')->with('error', 'Strava nicht verbunden.');
        }

        $activities = $strava->fetchRecentActivities($account);

        $newCount      = 0;
        $newRunCount   = 0;
        $lapBackfilled = 0;

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
                    'average_watts'        => $activityData['average_watts'] ?? null,
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
                // One detail call yields both laps and best_efforts (the activity
                // list endpoint carries neither).
                $detail = $strava->fetchActivity($account, (int) $activityData['id']);
                if ($detail) {
                    $this->applyStartCoords($activity, $detail);
                    if (! empty($detail['laps'])) {
                        $activity->laps = $this->normalizeLaps($detail['laps']);
                        $activity->save();
                    }
                    if ($activity->type === 'Run') {
                        $newRunCount++;
                        $newRecords = $bestEfforts->syncFromActivityData($activity, $detail);
                        if (! empty($newRecords)) {
                            $this->flagPendingPr($user->id, $activity->id);
                        }
                    } else {
                        // Mark non-runs as processed so the backfill skips them.
                        $activity->forceFill(['best_efforts_synced_at' => now()])->save();
                    }
                }
            } elseif ($activity->best_efforts_synced_at === null && $lapBackfilled < 10) {
                // Backfill laps + best efforts for activities imported before this feature.
                // No celebration for these historical runs.
                $detail = $strava->fetchActivity($account, (int) $activityData['id']);
                if ($detail) {
                    $this->applyStartCoords($activity, $detail);
                    if ($activity->laps === null) {
                        $activity->laps = ! empty($detail['laps'])
                            ? $this->normalizeLaps($detail['laps'])
                            : [];
                        $activity->save();
                    }
                    if ($activity->type === 'Run') {
                        $bestEfforts->syncFromActivityData($activity, $detail);
                    } else {
                        $activity->forceFill(['best_efforts_synced_at' => now()])->save();
                    }
                }
                $lapBackfilled++;
            }

            // Match to plan sessions or create unplanned entry for all activity types
            $this->matchActivityToSession($user->id, $activity);
        }

        $this->dispatchCalculationIfDue($user->id, $newRunCount);
        $this->dispatchPlanRegenerationIfNeeded($user->id);

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
    public function webhook(Request $request, StravaService $strava, WebPushService $webPush, BestEffortService $bestEfforts): Response
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
                'average_watts'        => $activityData['average_watts'] ?? null,
                'max_speed'            => $activityData['max_speed'] ?? 0,
                'average_heartrate'    => $activityData['average_heartrate'] ?? null,
                'max_heartrate'        => $activityData['max_heartrate'] ?? null,
                'start_date'           => $activityData['start_date'] ?? now(),
                'location_city'        => $activityData['location_city'] ?? null,
                'location_state'       => $activityData['location_state'] ?? null,
                'location_country'     => $activityData['location_country'] ?? null,
                'polyline'             => $this->extractPolyline($activityData),
                'laps'                 => ! empty($activityData['laps'])
                    ? $this->normalizeLaps($activityData['laps'])
                    : null,
                'start_lat'            => $activityData['start_latlng'][0] ?? null,
                'start_lng'            => $activityData['start_latlng'][1] ?? null,
            ]
        );

        $isRun = $activity->type === 'Run';
        $this->dispatchCalculationIfDue($userId, $isRun ? 1 : 0);
        $this->matchActivityToSession($userId, $activity);
        $this->dispatchPlanRegenerationIfNeeded($userId);

        // Generate a coach review for every session this activity just completed.
        TrainingSession::where('user_id', $userId)
            ->where('activity_id', $activity->id)
            ->where('status', 'completed')
            ->whereNull('reviewed_at')
            ->pluck('id')
            ->each(fn ($id) => GenerateSessionReviewJob::dispatch($id)->delay(now()->addSeconds(20)));
        if ($isRun) {
            // Webhook payload is the full activity detail → best_efforts present.
            $newRecords = $bestEfforts->syncFromActivityData($activity, $activityData);
            if (! empty($newRecords)) {
                $this->flagPendingPr($userId, $activity->id);
            }
        }

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

        // Strength activities (gym, kettlebell, …) come in as WeightTraining/Workout.
        $isStrength = in_array($activity->type, ['WeightTraining', 'Workout'], true);

        // Non-Run activities skip the run-session matching below.
        if ($activity->type !== 'Run') {
            $distKm = $activity->distance > 0 ? round($activity->distance / 1000, 2) : null;
            $durMin = $activity->moving_time > 0 ? (int) round($activity->moving_time / 60) : null;

            // Strength activity → complete a planned strength/core/mobility session on this date
            if ($isStrength) {
                $strengthSession = TrainingSession::where('user_id', $userId)
                    ->whereDate('planned_date', $date)
                    ->where('status', 'planned')
                    ->whereIn('type', ['strength', 'core', 'mobility'])
                    ->whereHas('trainingPlan', fn ($q) => $q->where('is_active', true))
                    ->first();

                if ($strengthSession) {
                    $strengthSession->update([
                        'status'       => 'completed',
                        'activity_id'  => $activity->id,
                        'sport_type'   => $activity->type,
                        'duration_min' => $durMin ?? $strengthSession->duration_min,
                    ]);
                    return;
                }
            }

            // No matching planned session → create an unplanned completed entry
            if (TrainingSession::where('user_id', $userId)->where('activity_id', $activity->id)->exists()) {
                return;
            }
            $activePlan = TrainingPlan::where('user_id', $userId)->where('is_active', true)->latest()->first();
            if (! $activePlan) return;

            // Die Sportart wird mitgefuehrt. Vorher landete alles, was nicht
            // Lauf und nicht Kraft war — Schwimmen, Radfahren, Yoga — als
            // `easy_run` in der Datenbank. Der Coach las den Trainingstyp,
            // sah "Lockerer Lauf" und fragte den Athleten nach seinem Lauf.
            $isRunLike = in_array($activity->type, TrainingSession::RUN_SPORTS, true);

            TrainingSession::create([
                'user_id'          => $userId,
                'training_plan_id' => $activePlan->id,
                'event_id'         => $activePlan->event_id,
                'activity_id'      => $activity->id,
                'planned_date'     => $date,
                // Kein Lauf-Platzhalter mehr. `cross_training` faellt aus
                // jeder Laufauswertung schon am Typ heraus — auch dort, wo
                // jemand vergisst, zusaetzlich die Sportart zu pruefen.
                'type'             => $isStrength ? 'strength' : 'cross_training',
                'sport_type'       => $activity->type,
                'title'            => $activity->name,
                // Nur Laufkilometer zaehlen in den Wochenumfang. Eine
                // 1,5-km-Schwimmeinheit neben einem 20-km-Longrun waere sonst
                // in jeder Statistik dieselbe Einheit "Kilometer".
                'distance_km'      => ($isStrength || ! $isRunLike) ? null : $distKm,
                'duration_min'     => $durMin,
                'pace_target'      => null,
                'zone'             => null,
                'intensity'        => 'medium',
                // Kein Planeintrag dahinter — als ungeplant kennzeichnen.
                'was_unplanned'    => true,
                'status'           => 'completed',
                'sort_order'       => 999,
            ]);
            return;
        }

        // 1. Find any planned RUN session in the active plan on the same date
        //    (strength/core/mobility sessions are only completed by strength activities)
        $session = TrainingSession::where('user_id', $userId)
            ->whereDate('planned_date', $date)
            ->where('status', 'planned')
            ->whereNotIn('type', ['strength', 'core', 'mobility'])
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
                    // Geplant war Ruhe — das ist keine erfuellte Einheit.
                    'was_unplanned'    => true,
                    'planned_snapshot' => ['type' => 'rest', 'title' => $session->title],
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
                // Der Plan wird hier von den echten Werten ueberschrieben.
                // Vorher festhalten, was geplant war — sonst laesst sich
                // hinterher nicht mehr sagen, ob die Einheit so gelaufen
                // wurde wie vorgesehen. Das Coach-Review las bis dahin fuer
                // "Geplant" und "Absolviert" dieselben Felder.
                $session->update([
                    'planned_snapshot' => $session->planned_snapshot ?? [
                        'type'         => $session->type,
                        'title'        => $session->title,
                        'distance_km'  => $session->distance_km,
                        'duration_min' => $session->duration_min,
                        'pace_target'  => $session->pace_target,
                        'zone'         => $session->zone,
                        'intensity'    => $session->intensity,
                    ],
                    'status'       => 'completed',
                    'activity_id'  => $activity->id,
                    'distance_km'  => $distKm ?? $session->distance_km,
                    'duration_min' => $durMin ?? $session->duration_min,
                    'pace_target'  => $pace ?? $session->pace_target,
                ]);

                // Test run completed: bypass 24h cooldown and recalculate threshold immediately
                if ($session->type === 'test_run') {
                    $this->dispatchCalculationForTestRun($userId);
                }
            }
            // BEWUSST KEINE Neuberechnung: der Athlet ist gelaufen, was im
            // Plan stand. Frueher warf jeder Import den gesamten Restplan neu,
            // und weil das Modell nicht deterministisch ist, sah die Woche
            // danach anders aus als vorher — ohne dass sich etwas geaendert
            // haette. Die Einheit fliesst ueber den Kontext in die naechste
            // planmaessige Berechnung ein.
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
            // Es gab an dem Tag keine geplante Einheit — dieser Lauf kam dazu.
            'was_unplanned'    => true,
            'status'           => 'completed',
            'sort_order'       => 999,
        ]);

        // Ein ungeplanter Lauf ist echte Zusatzbelastung und bleibt damit ein
        // Anlass. Die naechsten Tage sind davon geschuetzt (siehe
        // RegeneratePlanJob::FREEZE_DAYS) — die Anpassung greift ab dem
        // vierten Tag.
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
     * Dispatch plan regeneration job if the active plan has needs_plan_update = true.
     * Uses a 5-minute delay to batch multiple activity imports.
     */
    private function dispatchPlanRegenerationIfNeeded(int $userId): void
    {
        $needs = \App\Models\TrainingPlan::where('user_id', $userId)
            ->where('is_active', true)
            ->where('needs_plan_update', true)
            ->exists();

        if ($needs) {
            RegeneratePlanJob::dispatch($userId, RegeneratePlanJob::REASON_AUTO)->delay(now()->addMinutes(5));
        }
    }

    /**
     * Flag an activity as the source of a fresh personal record so the dashboard
     * celebrates it (message generated lazily by GeneratePrMessageJob).
     */
    /**
     * Store the activity's start coordinates (from Strava's start_latlng)
     * so the weather feature can resolve the user's training location.
     */
    private function applyStartCoords(Activity $activity, array $detail): void
    {
        $latlng = $detail['start_latlng'] ?? null;
        if (is_array($latlng) && count($latlng) === 2 && $latlng[0] !== null) {
            $activity->start_lat = $latlng[0];
            $activity->start_lng = $latlng[1];
            $activity->save();
        }
    }

    private function flagPendingPr(int $userId, int $activityId): void
    {
        $profile = RunnerProfile::where('user_id', $userId)->first();
        if ($profile) {
            $profile->pending_pr_activity_id = $activityId;
            $profile->pending_pr_message     = null; // generated lazily on dashboard load
            $profile->save();
        }
    }

    /**
     * Normalize raw Strava lap data to a compact format for storage and display.
     * Keeps only the fields needed for the UI.
     */
    private function normalizeLaps(array $rawLaps): array
    {
        return array_map(fn ($lap) => [
            'index'             => $lap['lap_index']         ?? null,
            'name'              => $lap['name']              ?? null,
            'elapsed_time'      => $lap['elapsed_time']      ?? 0,
            'moving_time'       => $lap['moving_time']       ?? 0,
            'distance'          => $lap['distance']          ?? 0,
            'average_speed'     => $lap['average_speed']     ?? null,
            'max_speed'         => $lap['max_speed']         ?? null,
            'average_heartrate' => $lap['average_heartrate'] ?? null,
            'max_heartrate'     => $lap['max_heartrate']     ?? null,
            'pace_zone'         => $lap['pace_zone']         ?? null,
        ], $rawLaps);
    }

    /**
     * Dispatch threshold recalculation for a test run, bypassing the 24h cooldown.
     * Called when a planned test_run session is matched to a Strava activity.
     */
    private function dispatchCalculationForTestRun(int $userId): void
    {
        $profile = RunnerProfile::firstOrCreate(
            ['user_id' => $userId],
            ['has_completed_setup' => false]
        );
        if (! $profile->threshold_pace_calculating) {
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

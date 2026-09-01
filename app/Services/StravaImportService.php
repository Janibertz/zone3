<?php

namespace App\Services;

use App\Jobs\CalculateThresholdPaceJob;
use App\Jobs\RegeneratePlanJob;
use App\Models\Activity;
use App\Models\IgnoredStravaActivity;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;

/**
 * Was mit einer Strava-Aktivität geschieht, nachdem sie hereingekommen ist.
 *
 * Diese Logik stand im `StravaController` und wurde von zwei Wegen benutzt:
 * der manuellen Synchronisation und dem Webhook. Sie musste hier heraus,
 * damit der Webhook sie einem Job übergeben kann, statt sie im Request
 * auszuführen — und nicht in einer zweiten Fassung.
 *
 * Alles hier ist wiederholbar. Strava stellt einen Webhook mehrfach zu,
 * wenn die Antwort auf sich warten lässt, und ein Job wird nach einem
 * Fehlschlag erneut versucht. `updateOrCreate` auf (strava_id, user_id),
 * die Prüfung auf ein bereits verknüpftes `activity_id` und das
 * `reviewed_at`-Kriterium tragen das: der zweite Durchlauf schreibt
 * dieselben Werte und legt nichts doppelt an.
 */
class StravaImportService
{
    /**
     * Eine Aktivität aus Stravas Detailantwort anlegen oder auffrischen.
     *
     * Gibt `null` zurück, wenn der Athlet die Aktivität gelöscht hat: der
     * Grabstein in `ignored_strava_activities` verhindert, dass Import und
     * Webhook sie wieder hereinholen.
     */
    public function importFromDetail(int $userId, array $activityData): ?Activity
    {
        $stravaId = $activityData['id'] ?? null;
        if (! $stravaId) {
            return null;
        }

        if (IgnoredStravaActivity::where('user_id', $userId)->where('strava_id', $stravaId)->exists()) {
            return null;
        }

        return Activity::updateOrCreate(
            ['strava_id' => $stravaId, 'user_id' => $userId],
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
    }

    /**
     * Match a Strava activity to a planned training session (Runs only),
     * or create an unplanned completed entry for any activity type.
     */
    public function matchActivityToSession(int $userId, Activity $activity): void
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
    public function paceFromSpeed(float $mps): ?string
    {
        $pace = PaceFormat::fromSpeed($mps);

        return $pace === PaceFormat::NONE ? null : $pace;
    }

    /**
     * Dispatch AI calculation job if:
     *  - At least one new Run was added
     *  - Last calculation was > 24h ago (or never)
     *  - No calculation is already running
     */
    public function dispatchCalculationIfDue(int $userId, int $newRunCount): void
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
    public function dispatchPlanRegenerationIfNeeded(int $userId): void
    {
        $needs = TrainingPlan::where('user_id', $userId)
            ->where('is_active', true)
            ->where('needs_plan_update', true)
            ->exists();

        if ($needs) {
            RegeneratePlanJob::dispatch($userId, RegeneratePlanJob::REASON_AUTO)->delay(now()->addMinutes(5));
        }
    }

    /**
     * Store the activity's start coordinates (from Strava's start_latlng)
     * so the weather feature can resolve the user's training location.
     */
    public function applyStartCoords(Activity $activity, array $detail): void
    {
        $latlng = $detail['start_latlng'] ?? null;
        if (is_array($latlng) && count($latlng) === 2 && $latlng[0] !== null) {
            $activity->start_lat = $latlng[0];
            $activity->start_lng = $latlng[1];
            $activity->save();
        }
    }

    /**
     * Flag an activity as the source of a fresh personal record so the dashboard
     * celebrates it (message generated lazily by GeneratePrMessageJob).
     */
    public function flagPendingPr(int $userId, int $activityId): void
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
    public function normalizeLaps(array $rawLaps): array
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
    public function dispatchCalculationForTestRun(int $userId): void
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
    public function extractPolyline(array $activityData): ?array
    {
        $poly = $activityData['map']['polyline'] ?? null;
        if (empty($poly)) {
            $poly = $activityData['map']['summary_polyline'] ?? null;
        }
        return $poly ? ['polyline' => $poly] : null;
    }}

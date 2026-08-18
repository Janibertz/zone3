<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\CoachChatController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\LiveTrackController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TrainingPlanController;
use App\Http\Controllers\TrainingSessionController;
use App\Http\Controllers\RunnerProfileController;
use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingSession;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\StravaController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WellbeingController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\NewsletterController;
use App\Models\WeeklyReview;
use App\Services\ProgressService;
use App\Services\ReturnToRunService;
use App\Services\TrainingLoadService;
use Carbon\Carbon;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// App version endpoint — used by frontend to detect new deployments
Route::get('/app-version', function () {
    $manifest = public_path('build/manifest.json');
    $version  = file_exists($manifest) ? substr(md5_file($manifest), 0, 12) : 'dev';
    return response()->json(['version' => $version])
        ->header('Cache-Control', 'no-store, no-cache');
})->name('app.version');

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/support', fn() => Inertia::render('Support'))->name('support');

// Newsletter unsubscribe — public, no auth required
Route::get('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe');
Route::post('/newsletter/resubscribe',        [NewsletterController::class, 'resubscribe'])->name('newsletter.resubscribe');

// Support Tickets
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/support/tickets',                         [SupportTicketController::class, 'index']) ->name('support.tickets.index');
    Route::post('/support/tickets',                        [SupportTicketController::class, 'store']) ->name('support.tickets.store');
    Route::get('/support/tickets/{ticket}',                [SupportTicketController::class, 'show'])  ->name('support.tickets.show');
    Route::post('/support/tickets/{ticket}/reply',         [SupportTicketController::class, 'reply']) ->name('support.tickets.reply');
});
Route::get('/privacy', fn() => redirect()->route('support'))->name('privacy');

// ── Onboarding (auth required, no onboarding-complete check) ──────────────
Route::middleware('auth')->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding/coach', [OnboardingController::class, 'saveCoach'])->name('onboarding.coach');
    Route::post('/onboarding/estimate-profile', [OnboardingController::class, 'estimateProfile'])->name('onboarding.estimate-profile');
    Route::post('/onboarding/profile', [OnboardingController::class, 'saveProfile'])->name('onboarding.profile');
    Route::post('/onboarding/availability', [OnboardingController::class, 'saveAvailability'])->name('onboarding.availability');
    Route::post('/onboarding/strength', [OnboardingController::class, 'saveStrength'])->name('onboarding.strength');
    Route::post('/onboarding/goal', [OnboardingController::class, 'saveGoal'])->name('onboarding.goal');
    Route::post('/onboarding/reset', [OnboardingController::class, 'reset'])->name('onboarding.reset');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
    Route::post('/onboarding/complete-strava', [OnboardingController::class, 'completeAndConnectStrava'])->name('onboarding.complete-strava');
});

Route::get('/dashboard', function (ProgressService $progressService, TrainingLoadService $trainingLoadService, ReturnToRunService $returnToRunService) {
    $user = auth()->user();

    $upcomingEvents = $user->events()
        ->where('event_date', '>=', now()->toDateString())
        ->orderBy('event_date')
        ->get()
        ->map(fn($e) => [
            'id'                  => $e->id,
            'name'                => $e->name,
            'event_date'          => $e->event_date->format('Y-m-d'),
            'race_distance'       => $e->race_distance,
            'distance_label'      => $e->distance_label,
            'priority'            => $e->priority,
            'target_time_hours'   => $e->target_time_hours,
            'target_time_minutes' => $e->target_time_minutes,
            'target_time_formatted' => $e->target_time_formatted,
            'days_until'          => $e->days_until,
            'weeks_until'         => $e->weeks_until,
            'training_phase'      => $e->training_phase,
        ]);

    $runnerProfile = $user->runnerProfile;
    $thresholdPaceFormatted = null;
    $racePredictions = null;

    if ($runnerProfile && $runnerProfile->threshold_speed) {
        $ts   = $runnerProfile->threshold_speed;
        $mins = (int)$ts;
        $secs = (int)(($ts - $mins) * 60);
        $thresholdPaceFormatted = sprintf('%d:%02d', $mins, $secs);

        // Race predictions using Jack Daniels T-pace relationships
        $tpSec = $ts * 60; // threshold pace in seconds/km
        $races = [
            '5k'       => ['distance' => 5.0,     'multiplier' => 0.90, 'label' => '5 km'],
            '10k'      => ['distance' => 10.0,    'multiplier' => 0.95, 'label' => '10 km'],
            'half'     => ['distance' => 21.0975, 'multiplier' => 1.03, 'label' => 'Halbmarathon'],
            'marathon' => ['distance' => 42.195,  'multiplier' => 1.12, 'label' => 'Marathon'],
        ];

        $racePredictions = [];
        foreach ($races as $key => $race) {
            $paceSec   = $tpSec * $race['multiplier'];
            $totalSec  = (int)($paceSec * $race['distance']);
            $paceMin   = (int)($paceSec / 60);
            $paceSecs  = (int)($paceSec % 60);
            $h  = (int)($totalSec / 3600);
            $m  = (int)(($totalSec % 3600) / 60);
            $s  = $totalSec % 60;

            $racePredictions[$key] = [
                'label'      => $race['label'],
                'pace'       => sprintf('%d:%02d', $paceMin, $paceSecs),
                'total_time' => $h > 0
                    ? sprintf('%d:%02d:%02d', $h, $m, $s)
                    : sprintf('%d:%02d', $m, $s),
            ];
        }
    }

    // Today's session from the active training plan
    $activePlan = $user->activeTrainingPlan;
    $todayStr   = now()->toDateString();
    $todayPlanSession = null;
    if ($activePlan) {
        $s = TrainingSession::where('training_plan_id', $activePlan->id)
            ->where('planned_date', $todayStr)
            ->orderBy('sort_order')
            ->first();

        // If today is skipped, fall through to show next upcoming session instead
        if ($s && $s->status === 'skipped') {
            $s = null;
        }

        // No session today (or skipped) → show next upcoming planned session
        if (! $s) {
            $s = TrainingSession::where('training_plan_id', $activePlan->id)
                ->where('planned_date', '>', $todayStr)
                ->where('status', 'planned')
                ->where('type', '!=', 'rest')
                ->orderBy('planned_date')
                ->orderBy('sort_order')
                ->first();
        }

        if ($s) {
            $todayPlanSession = [
                'id'           => $s->id,
                'type'         => $s->type,
                'title'        => $s->title,
                'description'  => $s->description,
                'distance_km'  => $s->distance_km,
                'duration_min' => $s->duration_min,
                'pace_target'  => $s->pace_target,
                'zone'         => $s->zone,
                'intensity'    => $s->intensity,
                'status'       => $s->status,
                'activity_id'  => $s->activity_id,
                'event_id'     => $s->event_id,
                'plan_id'      => $activePlan->id,
                'event_name'   => $activePlan->event?->name,
                'session_date' => $s->planned_date->format('Y-m-d'),
                'is_today'     => $s->planned_date->toDateString() === $todayStr,
            ];
        }
    }

    // Standalone recommendation session (accepted by user, no active plan)
    $todayRecommendationSession = null;
    if (! $activePlan) {
        $sr = TrainingSession::where('user_id', $user->id)
            ->where('planned_date', $todayStr)
            ->whereNull('training_plan_id')
            ->orderBy('sort_order')
            ->first();
        if ($sr) {
            $todayRecommendationSession = [
                'id'           => $sr->id,
                'type'         => $sr->type,
                'title'        => $sr->title,
                'description'  => $sr->description,
                'distance_km'  => $sr->distance_km,
                'duration_min' => $sr->duration_min,
                'pace_target'  => $sr->pace_target,
                'zone'         => $sr->zone,
                'intensity'    => $sr->intensity,
                'status'       => $sr->status,
            ];
        }
    }

    return Inertia::render('Dashboard', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
        'stravaConnected' => (bool) $user->stravaAccount,
        'stravaAccount' => $user->stravaAccount ? [
            'username' => $user->stravaAccount->username,
            'last_synced_at' => $user->stravaAccount->last_synced_at,
        ] : null,
        'events' => $upcomingEvents,
        'recentActivities' => Activity::where('user_id', $user->id)->orderByDesc('start_date')->limit(20)->get(Activity::SUMMARY_COLUMNS),
        'suggestions' => $progressService->generateTrainingSuggestions($user),
        'thresholdPace' => $thresholdPaceFormatted,
        'thresholdPaceCalculatedAt' => $runnerProfile?->threshold_pace_calculated_at?->format('d.m.Y H:i'),
        'paceZones' => $runnerProfile?->pace_zones,
        'thresholdPaceHistory' => $runnerProfile?->threshold_pace_history ?? [],
        'racePredictions' => $racePredictions,
        'thresholdPaceCalculating' => (bool) ($runnerProfile?->threshold_pace_calculating),
        'syncResult' => session('sync_result'),
        'todayPlanSession' => $todayPlanSession,
        'todayRecommendationSession' => $todayRecommendationSession,
        'hasActivePlan' => (bool) $activePlan,
        'returnToRun' => $returnToRunService->statusFor($user),
        // whereDate statt where: die Spalte ist ein DATE, kommt je nach
        // Treiber aber als "Y-m-d H:i:s" zurueck — ein Stringvergleich
        // gegen "Y-m-d" trifft dann nie.
        'hasWellbeingToday' => $user->wellbeingEntries()
            ->whereDate('date', now()->toDateString())
            ->exists(),

        // Werte des heutigen Check-ins — speisen die Kennzahl-Kacheln, wenn
        // keine Garmin-Verbindung besteht.
        'wellbeingToday' => (function () use ($user) {
            $entry = $user->wellbeingEntries()
                ->whereDate('date', now()->toDateString())
                ->first(['energy_level', 'mood', 'sleep_quality', 'muscle_soreness', 'stress_level', 'is_sick', 'is_injured']);

            return $entry ? [
                'energy_level'    => $entry->energy_level,
                'mood'            => $entry->mood,
                'sleep_quality'   => $entry->sleep_quality,
                'muscle_soreness' => $entry->muscle_soreness,
                'stress_level'    => $entry->stress_level,
                'is_sick'         => (bool) $entry->is_sick,
                'is_injured'      => (bool) $entry->is_injured,
            ] : null;
        })(),

        // Sessions completed without any feedback (no stars, no RPE, no notes), excluding rest days
        'unratedSessions' => TrainingSession::where('user_id', $user->id)
            ->where('status', 'completed')
            ->where('type', '!=', 'rest')
            ->whereNull('rating')
            ->whereNull('effort_perceived')
            ->where(fn ($q) => $q->whereNull('feeling_notes')->orWhere('feeling_notes', ''))
            ->whereHas('trainingPlan')
            ->with('activity:id,name')
            ->orderByDesc('planned_date')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'id'            => $s->id,
                'title'         => $s->title,
                'activity_name' => $s->activity?->name,
                'type'          => $s->type,
                'planned_date'  => $s->planned_date->format('Y-m-d'),
                'distance_km'   => $s->distance_km,
                'activity_id'   => $s->activity_id,
                'event_id'      => $s->event_id,
            ])
            ->values(),

        // CTL / ATL / TSB training load metrics
        'trainingLoad' => $trainingLoadService->calculate($user->id),

        // Weekly AI review (generated every Monday, cached for the week)
        'weeklyReview' => (function () use ($user) {
            $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY)->subWeek()->toDateString();
            $review = WeeklyReview::where('user_id', $user->id)
                ->where('week_start', $weekStart)
                ->first();
            return $review ? ['content' => $review->content, 'week_start' => $weekStart] : null;
        })(),

        // Pending PR celebration from coach — generated off-request by a job
        // so the dashboard render never blocks on an OpenAI call. The message
        // appears on the next load once the job has cached it.
        'coachPrMessage' => (function () use ($user) {
            $profile = $user->runnerProfile;
            if (! $profile || ! $profile->pending_pr_activity_id) return null;

            // Return cached message if available
            if ($profile->pending_pr_message) {
                return $profile->pending_pr_message;
            }

            // Not generated yet → kick off background generation, show nothing for now.
            \App\Jobs\GeneratePrMessageJob::dispatch($user->id);
            return null;
        })(),

        'aiUsage' => (function () use ($user) {
            $limit = $user->ai_daily_limit ?? 20;
            return ['used' => \App\Models\AiLog::todayCountForUser($user->id), 'limit' => $limit];
        })(),

        // Wochenabfrage — Sonntag und Montag, einmal je Woche. Das Raster im
        // Profil ist der Normalfall; Urlaub und volle Wochen sind Ausnahmen,
        // fuer die es bisher keine Eingabe gab.
        'weekCheck' => (function () use ($user) {
            $profile = $user->runnerProfile;
            if (! \App\Http\Controllers\WeekAvailabilityController::isDue($profile)) {
                return null;
            }

            $start   = \App\Http\Controllers\WeekAvailabilityController::upcomingWeekStart();
            $weekly  = $profile?->weekly_availability ?? [];
            $isoKeys = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday',
                        5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $date = $start->addDays($i);
                $slot = $weekly[$isoKeys[$date->isoWeekday()]] ?? null;

                $days[] = [
                    'date'         => $date->format('Y-m-d'),
                    'label'        => $date->locale('de')->isoFormat('dd, D. MMM'),
                    'available'    => (bool) ($slot['available'] ?? false),
                    'duration_min' => (int) ($slot['duration_min'] ?? 0),
                    'fixed'        => $slot['fixed']['label'] ?? null,
                ];
            }

            return ['weekStart' => $start->format('Y-m-d'), 'days' => $days];
        })(),

        // Garmin recovery data (HRV, sleep, RHR, Body Battery, stress, readiness).
        // Synced read-only via SyncGarminHealthJob. Missing values stay null ("keine Daten").
        'garminMetrics' => (function () use ($user) {
            if (empty($user->garmin_session)) return null;

            $rows = \App\Models\GarminDailyMetric::where('user_id', $user->id)
                ->where('date', '>=', now()->subDays(60)->toDateString())
                ->orderBy('date')
                ->get([
                    'date', 'hrv', 'resting_hr', 'sleep_hours', 'sleep_score',
                    'body_battery_low', 'body_battery_high', 'stress_avg',
                    'steps', 'training_readiness',
                ]);

            if ($rows->isEmpty()) return ['latest' => null, 'series' => []];

            $series = $rows->map(fn ($r) => [
                'date'               => $r->date->format('Y-m-d'),
                'hrv'                => $r->hrv,
                'resting_hr'         => $r->resting_hr,
                'sleep_hours'        => $r->sleep_hours,
                'sleep_score'        => $r->sleep_score,
                'body_battery_low'   => $r->body_battery_low,
                'body_battery_high'  => $r->body_battery_high,
                'stress_avg'         => $r->stress_avg,
                'steps'              => $r->steps,
                'training_readiness' => $r->training_readiness,
            ])->values();

            return ['latest' => $series->last(), 'series' => $series];
        })(),

        // Activities for the recovery dashboard's logbook + weekly-km chart (last 60 days).
        'recoveryActivities' => (function () use ($user) {
            if (empty($user->garmin_session) && ! $user->stravaAccount) return [];

            return Activity::where('user_id', $user->id)
                ->where('start_date', '>=', now()->subDays(60))
                ->orderByDesc('start_date')
                ->limit(200)
                ->get(['id', 'name', 'type', 'distance', 'moving_time', 'average_speed', 'average_heartrate', 'start_date'])
                ->map(fn ($a) => [
                    'date'        => $a->start_date?->format('Y-m-d'),
                    'name'        => $a->name,
                    'type'        => $a->type,
                    'distance_km' => $a->distance ? round($a->distance / 1000, 2) : null,
                    'moving_time' => $a->moving_time,
                    'speed'       => $a->average_speed,   // m/s
                    'avg_hr'      => $a->average_heartrate,
                ])
                ->values();
        })(),
    ]);
})->middleware(['auth', 'verified', 'onboarding'])->name('dashboard');

Route::get('/api/ai/recommendation/today', [AIController::class, 'recommendToday'])->middleware(['auth', 'verified'])->name('ai.recommendation.today');
Route::post('/api/ai/recommendation/accept', [AIController::class, 'acceptRecommendation'])->middleware(['auth', 'verified'])->name('ai.recommendation.accept');
Route::post('/api/ai/recommendation/adjust', [AIController::class, 'adjustRecommendation'])->middleware(['auth', 'verified'])->name('ai.recommendation.adjust');
Route::get('/api/ai/daily-message', [AIController::class, 'dailyMessage'])->middleware(['auth', 'verified'])->name('ai.daily-message');
Route::get('/api/weather/today', [\App\Http\Controllers\WeatherController::class, 'today'])->middleware(['auth', 'verified'])->name('weather.today');
Route::post('/api/return-to-run/dismiss', [\App\Http\Controllers\ReturnToRunController::class, 'dismiss'])->middleware(['auth', 'verified'])->name('return-to-run.dismiss');

Route::middleware(['auth', 'onboarding'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::patch('/profile/coach', [ProfileController::class, 'updateCoach'])->name('profile.coach');
    Route::post('/profile/garmin-connect', [ProfileController::class, 'garminConnect'])->name('profile.garmin-connect');
    Route::post('/profile/garmin-sync-health', [ProfileController::class, 'garminSyncHealth'])->name('profile.garmin-sync-health');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Push Notifications
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
    Route::patch('/push/settings', [PushSubscriptionController::class, 'updateSettings'])->name('push.settings');
    Route::post('/push/test', [PushSubscriptionController::class, 'test'])->name('push.test');

    Route::get('/strava/connect', [StravaController::class, 'connect'])->name('strava.connect');
    Route::get('/strava/callback', [StravaController::class, 'callback'])->name('strava.callback');
    Route::post('/strava/sync', [StravaController::class, 'sync'])->name('strava.sync');
    Route::delete('/strava/disconnect', [StravaController::class, 'disconnect'])->name('strava.disconnect');


    Route::get('/activities', [ActivityController::class, 'index'])->name('activities.index');
    Route::get('/activities/{activity}', [ActivityController::class, 'show'])->name('activities.show');
    Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');

    // Wrapped / Rückblick
    Route::get('/rueckblick',         [\App\Http\Controllers\WrappedController::class, 'index'])->name('wrapped.index');
    Route::get('/api/wrapped',        [\App\Http\Controllers\WrappedController::class, 'stats'])->name('wrapped.stats');
    Route::get('/api/wrapped/review', [\App\Http\Controllers\WrappedController::class, 'review'])->name('wrapped.review');
    Route::get('/calendar', function () {
        $user = auth()->user();

        // Active plan sessions for calendar
        $activePlan = $user->activeTrainingPlan;
        $sessionQuery = TrainingSession::where('user_id', $user->id)
            ->where(function ($q) use ($activePlan) {
                // Active plan sessions
                if ($activePlan) {
                    $q->where('training_plan_id', $activePlan->id);
                }
                // Standalone recommendation sessions (no plan)
                $q->orWhereNull('training_plan_id');
            })
            // Completed sessions are already represented by Strava activities – don't show twice
            ->where('status', '!=', 'completed')
            ->orderBy('planned_date');

        $trainingSessions = $sessionQuery->get()->map(fn ($s) => [
            'id'           => $s->id,
            'planned_date' => $s->planned_date->format('Y-m-d'),
            'type'         => $s->type,
            'title'        => $s->title,
            'description'  => $s->description,
            'distance_km'  => $s->distance_km,
            'duration_min' => $s->duration_min,
            'pace_target'  => $s->pace_target,
            'zone'         => $s->zone,
            'intensity'    => $s->intensity,
            'status'       => $s->status,
            'skip_reason'  => $s->skip_reason,
            'rating'       => $s->rating,
            'event_id'     => $s->event_id,
            'activity_id'  => $s->activity_id,
        ])->toArray();

        return Inertia::render('Calendar', [
            'activities' => Activity::where('user_id', $user->id)->orderByDesc('start_date')->get(Activity::SUMMARY_COLUMNS),
            'events'     => $user->events()->orderBy('event_date')->get()->map(fn($e) => [
                'id'                    => $e->id,
                'name'                  => $e->name,
                'event_date'            => $e->event_date->format('Y-m-d'),
                'race_distance'         => $e->race_distance,
                'distance_label'        => $e->distance_label,
                'priority'              => $e->priority,
                'target_time_hours'     => $e->target_time_hours,
                'target_time_minutes'   => $e->target_time_minutes,
                'target_time_formatted' => $e->target_time_formatted,
                'days_until'            => $e->days_until,
                'training_phase'        => $e->training_phase,
            ]),
            'trainingSessions' => $trainingSessions,
        ]);
    })->name('calendar');

    Route::get('/goals', [GoalController::class, 'index'])->name('goals.index');
    Route::post('/goals', [GoalController::class, 'store'])->name('goals.store');
    Route::delete('/goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');

    // Events + Training Plans
    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::post('/events', [EventController::class, 'store'])->name('events.store');
    Route::patch('/events/{event}', [EventController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
    Route::get('/events/{event}/plan', [TrainingPlanController::class, 'show'])->name('events.plan.show');
    Route::get('/events/{event}/race-strategy', [TrainingPlanController::class, 'raceStrategy'])->name('events.plan.strategy');
    Route::get('/events/{event}/race-analysis', [TrainingPlanController::class, 'raceAnalysis'])->name('events.plan.analysis');
    Route::post('/events/{event}/plan/generate', [TrainingPlanController::class, 'generate'])->name('events.plan.generate');
    Route::get('/events/{event}/plan/generate-status', [TrainingPlanController::class, 'generateStatus'])->name('events.plan.generate-status');
    Route::post('/events/{event}/plan/cancel', [TrainingPlanController::class, 'cancel'])->name('events.plan.cancel');
    Route::patch('/events/{event}/plan/availability', [TrainingPlanController::class, 'updateAvailabilityOverride'])->name('events.plan.availability');
    Route::patch('/events/{event}/plan/result', [TrainingPlanController::class, 'saveResult'])->name('events.plan.result');

    // Training Sessions
    Route::patch('/training-sessions/{session}/complete', [TrainingSessionController::class, 'complete'])->name('training-sessions.complete');
    Route::patch('/training-sessions/{session}/skip', [TrainingSessionController::class, 'skip'])->name('training-sessions.skip');
    Route::post('/training-sessions/{session}/adjust', [TrainingSessionController::class, 'adjust'])->name('training-sessions.adjust');
    Route::post('/training-sessions/{session}/adjust-intensity', [TrainingSessionController::class, 'adjustIntensity'])->name('training-sessions.adjust-intensity');
    Route::post('/training-sessions/{session}/send-to-garmin', [TrainingSessionController::class, 'sendToGarmin'])->name('training-sessions.send-to-garmin');
    Route::delete('/garmin/disconnect', [TrainingSessionController::class, 'garminDisconnect'])->name('garmin.disconnect');
    Route::get('/training-sessions/{session}/nutrition-tips', [TrainingSessionController::class, 'nutritionTips'])->name('training-sessions.nutrition-tips');
    Route::get('/training-sessions/{session}/steps', [TrainingSessionController::class, 'sessionSteps'])->name('training-sessions.steps');
    Route::patch('/training-sessions/{session}/rate', [TrainingSessionController::class, 'rate'])->name('training-sessions.rate');
    Route::patch('/training-sessions/{session}/review-feedback', [TrainingSessionController::class, 'reviewFeedback'])->name('training-sessions.review-feedback');
    Route::patch('/training-sessions/{session}/apply-workout', [TrainingSessionController::class, 'applyWorkout'])->name('training-sessions.apply-workout');
    Route::post('/training-sessions/{session}/reset-cache', [TrainingSessionController::class, 'resetCache'])->name('training-sessions.reset-cache');
    Route::post('/newsletter/preference', [NewsletterController::class, 'updatePreference'])->name('newsletter.preference');

    // ── Workout Baukasten ─────────────────────────────────────────────────────
    Route::get('/workouts',                        [\App\Http\Controllers\WorkoutController::class, 'index'])    ->name('workouts.index');
    Route::get('/workouts/new',                    [\App\Http\Controllers\WorkoutController::class, 'create'])   ->name('workouts.create');
    Route::get('/workouts/list',                   [\App\Http\Controllers\WorkoutController::class, 'list'])     ->name('workouts.list');
    Route::get('/workouts/{workout}/edit',         [\App\Http\Controllers\WorkoutController::class, 'edit'])     ->name('workouts.edit');
    Route::post('/workouts',                       [\App\Http\Controllers\WorkoutController::class, 'store'])    ->name('workouts.store');
    Route::put('/workouts/{workout}',              [\App\Http\Controllers\WorkoutController::class, 'update'])   ->name('workouts.update');
    Route::delete('/workouts/{workout}',           [\App\Http\Controllers\WorkoutController::class, 'destroy'])  ->name('workouts.destroy');
    Route::post('/workouts/{workout}/duplicate',   [\App\Http\Controllers\WorkoutController::class, 'duplicate'])->name('workouts.duplicate');
    Route::post('/workouts/{workout}/send-to-garmin', [\App\Http\Controllers\WorkoutController::class, 'sendToGarmin'])->name('workouts.send-to-garmin');

    Route::post('/plans/generate', [PlanController::class, 'generate'])->name('plans.generate');

    // Runner Profile Routes (GET redirects to merged profile page)
    Route::get('/profile/runner', fn() => redirect()->route('profile.edit'))->name('runner.profile.show');
    Route::post('/profile/runner', [RunnerProfileController::class, 'store'])->name('runner.profile.store');
    Route::post('/api/profile/zones', [RunnerProfileController::class, 'previewZones'])->name('runner.profile.preview-zones');
    Route::get('/api/profile/runner', [RunnerProfileController::class, 'profile'])->name('runner.profile.api');

    // Wellbeing Routes
    Route::get('/api/wellbeing/today', [WellbeingController::class, 'today'])->name('wellbeing.today');
    // Wochenabfrage: passt die kommende Woche zum Raster im Profil?
    Route::post('/api/week-availability/confirm', [\App\Http\Controllers\WeekAvailabilityController::class, 'confirm'])->name('week-availability.confirm');
    Route::post('/api/week-availability',         [\App\Http\Controllers\WeekAvailabilityController::class, 'store'])->name('week-availability.store');

    Route::post('/api/wellbeing', [WellbeingController::class, 'store'])->name('wellbeing.store');
    Route::get('/api/wellbeing/status', [WellbeingController::class, 'status'])->name('wellbeing.status');
    Route::get('/api/wellbeing/latest/{count?}', [WellbeingController::class, 'latest'])->name('wellbeing.latest');

    // AI Routes
    Route::get('/ai/analyze/{goal}', [AIController::class, 'analyzeGoal'])->name('ai.analyze');
    Route::get('/ai/plan/{goal}', [AIController::class, 'generatePlan'])->name('ai.plan');
    Route::get('/ai/suggestions', [AIController::class, 'suggestionsForAll'])->name('ai.suggestions');

    // Coach Chat
    Route::get('/api/coach/messages', [CoachChatController::class, 'messages'])->name('coach.messages');
    Route::post('/api/coach/chat', [CoachChatController::class, 'send'])->name('coach.send');
    Route::post('/api/coach/pr-dismiss', [AIController::class, 'dismissPr'])->name('coach.pr.dismiss');
});

// Strava Webhook — no auth middleware (called by Strava servers)
Route::get('/strava/webhook', [StravaController::class, 'webhookVerify'])->name('strava.webhook.verify');
Route::post('/strava/webhook', [StravaController::class, 'webhook'])->name('strava.webhook');

// GitHub Webhook — no auth middleware, signature-verified
Route::post('/webhook/github', [WebhookController::class, 'github'])->name('webhook.github');

// ── LiveTrack ────────────────────────────────────────────────────────────────
// Oeffentliche Verfolgerseite: kein Login, Zugang allein ueber den
// unratebaren Slug. Gedrosselt, damit der Link nicht durchprobiert werden kann.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/live/{slug}', [LiveTrackController::class, 'show'])->name('live.show');
    Route::get('/live/{slug}/data', [LiveTrackController::class, 'data'])->name('live.data');
    // Steuerung durch die Crew — der Schluessel steckt im Rumpf, nicht im Pfad,
    // damit er nicht in Server-Logs und Referrern landet.
    Route::post('/live/{slug}/crew', [LiveTrackController::class, 'crewUpdate'])->name('live.crew');
});

// Verwaltung durch den Laeufer selbst
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/livetrack', [LiveTrackController::class, 'manage'])->name('live.manage');
    Route::post('/livetrack', [LiveTrackController::class, 'store'])->name('live.store');
    Route::post('/livetrack/test', [LiveTrackController::class, 'testPoll'])->name('live.test');
    Route::post('/livetrack/finish', [LiveTrackController::class, 'finish'])->name('live.finish');
});

require __DIR__.'/auth.php';

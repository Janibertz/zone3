<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TrainingPlanController;
use App\Http\Controllers\RunnerProfileController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\StravaController;
use App\Http\Controllers\WellbeingController;
use App\Models\Activity;
use App\Services\ProgressService;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// ── Onboarding (auth required, no onboarding-complete check) ──────────────
Route::middleware('auth')->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding/estimate-profile', [OnboardingController::class, 'estimateProfile'])->name('onboarding.estimate-profile');
    Route::post('/onboarding/profile', [OnboardingController::class, 'saveProfile'])->name('onboarding.profile');
    Route::post('/onboarding/goal', [OnboardingController::class, 'saveGoal'])->name('onboarding.goal');
    Route::post('/onboarding/reset', [OnboardingController::class, 'reset'])->name('onboarding.reset');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
    Route::post('/onboarding/complete-strava', [OnboardingController::class, 'completeAndConnectStrava'])->name('onboarding.complete-strava');
});

Route::get('/dashboard', function (ProgressService $progressService) {
    $user = auth()->user();

    $goalsWithProgress = $user->goals()->get()->map(function ($goal) use ($progressService) {
        return [
            'id' => $goal->id,
            'name' => $goal->name,
            'type' => $goal->type,
            'target_value' => $goal->target_value,
            'unit' => $goal->unit,
            'target_time_hours' => $goal->target_time_hours,
            'target_time_minutes' => $goal->target_time_minutes,
            'start_date' => $goal->start_date,
            'end_date' => $goal->end_date,
            'active' => $goal->active,
            'progress' => $progressService->calculateProgress($goal),
        ];
    });

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
        'goals' => $goalsWithProgress,
        'recentActivities' => Activity::where('user_id', $user->id)->orderByDesc('start_date')->limit(10)->get(),
        'suggestions' => $progressService->generateTrainingSuggestions($user),
        'thresholdPace' => $thresholdPaceFormatted,
        'thresholdPaceCalculatedAt' => $runnerProfile?->threshold_pace_calculated_at?->format('d.m.Y H:i'),
        'paceZones' => $runnerProfile?->pace_zones,
        'thresholdPaceHistory' => $runnerProfile?->threshold_pace_history ?? [],
        'racePredictions' => $racePredictions,
        'thresholdPaceCalculating' => (bool) ($runnerProfile?->threshold_pace_calculating),
        'syncResult' => session('sync_result'),
    ]);
})->middleware(['auth', 'verified', 'onboarding'])->name('dashboard');

Route::get('/api/ai/recommendation/today', [AIController::class, 'recommendToday'])->middleware(['auth', 'verified'])->name('ai.recommendation.today');

Route::middleware(['auth', 'onboarding'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/strava/connect', [StravaController::class, 'connect'])->name('strava.connect');
    Route::get('/strava/callback', [StravaController::class, 'callback'])->name('strava.callback');
    Route::post('/strava/sync', [StravaController::class, 'sync'])->name('strava.sync');

    Route::get('/activities', [ActivityController::class, 'index'])->name('activities.index');
    Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');

    Route::get('/goals', [GoalController::class, 'index'])->name('goals.index');
    Route::post('/goals', [GoalController::class, 'store'])->name('goals.store');
    Route::delete('/goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');

    // Events + Training Plans
    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::post('/events', [EventController::class, 'store'])->name('events.store');
    Route::patch('/events/{event}', [EventController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
    Route::get('/events/{event}/plan', [TrainingPlanController::class, 'show'])->name('events.plan.show');
    Route::post('/events/{event}/plan/generate', [TrainingPlanController::class, 'generate'])->name('events.plan.generate');

    Route::post('/plans/generate', [PlanController::class, 'generate'])->name('plans.generate');

    // Runner Profile Routes (GET redirects to merged profile page)
    Route::get('/profile/runner', fn() => redirect()->route('profile.edit'))->name('runner.profile.show');
    Route::post('/profile/runner', [RunnerProfileController::class, 'store'])->name('runner.profile.store');
    Route::post('/api/profile/zones', [RunnerProfileController::class, 'previewZones'])->name('runner.profile.preview-zones');
    Route::get('/api/profile/runner', [RunnerProfileController::class, 'profile'])->name('runner.profile.api');

    // Wellbeing Routes
    Route::get('/api/wellbeing/today', [WellbeingController::class, 'today'])->name('wellbeing.today');
    Route::post('/api/wellbeing', [WellbeingController::class, 'store'])->name('wellbeing.store');
    Route::get('/api/wellbeing/status', [WellbeingController::class, 'status'])->name('wellbeing.status');
    Route::get('/api/wellbeing/latest/{count?}', [WellbeingController::class, 'latest'])->name('wellbeing.latest');

    // AI Routes
    Route::get('/ai/analyze/{goal}', [AIController::class, 'analyzeGoal'])->name('ai.analyze');
    Route::get('/ai/plan/{goal}', [AIController::class, 'generatePlan'])->name('ai.plan');
    Route::get('/ai/suggestions', [AIController::class, 'suggestionsForAll'])->name('ai.suggestions');
});

// Strava Webhook — no auth middleware (called by Strava servers)
Route::get('/strava/webhook', [StravaController::class, 'webhookVerify'])->name('strava.webhook.verify');
Route::post('/strava/webhook', [StravaController::class, 'webhook'])->name('strava.webhook');

require __DIR__.'/auth.php';

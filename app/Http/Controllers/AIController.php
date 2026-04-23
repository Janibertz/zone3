<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Services\OpenAIService;
use App\Services\ProgressService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIController extends Controller
{
    public function __construct(
        protected OpenAIService $openAI,
        protected ProgressService $progress
    ) {}

    /**
     * Get AI-powered analysis for a specific goal
     */
    public function analyzeGoal(Request $request, Goal $goal)
    {
        // Check authorization
        if ($goal->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        // Get goal data
        $goalData = [
            'name' => $goal->name,
            'target_value' => $goal->target_value,
            'start_date' => $goal->start_date->toDateString(),
            'end_date' => $goal->end_date->toDateString(),
        ];

        // Get progress data
        $progressData = $this->progress->calculateProgress($goal);

        // Get recent activities
        $recentActivities = $goal->user->activities()
            ->orderByDesc('start_date')
            ->limit(5)
            ->get()
            ->toArray();

        // Get today's wellbeing entry
        $wellbeingData = $goal->user->wellbeingEntries()
            ->orderByDesc('date')
            ->first();

        // Generate AI analysis
        $this->openAI->withCoach($goal->user->coach?->personality_prompt);
        $analysis = $this->openAI->analyzeTraining($goalData, $progressData, $recentActivities, $wellbeingData);

        return response()->json([
            'analysis' => $analysis,
            'progress' => $progressData,
        ]);
    }

    /**
     * Generate AI training plan for a goal
     */
    public function generatePlan(Request $request, Goal $goal)
    {
        // Check authorization
        if ($goal->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        // Get goal data
        $goalData = [
            'name' => $goal->name,
            'target_value' => $goal->target_value,
            'start_date' => $goal->start_date->toDateString(),
            'end_date' => $goal->end_date->toDateString(),
        ];

        // Get progress data
        $progressData = $this->progress->calculateProgress($goal);

        // Generate plan
        $this->openAI->withCoach($goal->user->coach?->personality_prompt);
        $plan = $this->openAI->generateTrainingPlan($goalData, $progressData);

        return response()->json([
            'plan' => $plan,
        ]);
    }

    /**
     * Get all AI suggestions for user's goals
     */
    public function suggestionsForAll(Request $request)
    {
        $user = $request->user();
        $goals = $user->goals()->where('active', true)->get();

        $suggestions = [];
        foreach ($goals as $goal) {
            $progressData = $this->progress->calculateProgress($goal);
            $goalData = [
                'name' => $goal->name,
                'target_value' => $goal->target_value,
            ];

            $recentActivities = $user->activities()
                ->orderByDesc('start_date')
                ->limit(3)
                ->get()
                ->toArray();

            $this->openAI->withCoach($user->coach?->personality_prompt);
            $analysis = $this->openAI->analyzeTraining($goalData, $progressData, $recentActivities);

            $suggestions[] = [
                'goal_id' => $goal->id,
                'goal_name' => $goal->name,
                'analysis' => $analysis,
            ];
        }

        return response()->json([
            'suggestions' => $suggestions,
        ]);
    }

    public function recommendToday(Request $request)
    {
        $user = $request->user();

        $runnerProfile = $user->runnerProfile;

        $yesterday = Carbon::yesterday()->toDateString();
        $yesterdayActivity = $user->activities()
            ->whereDate('start_date', $yesterday)
            ->orderByDesc('start_date')
            ->first();

        $today = Carbon::today()->toDateString();
        $todayWellbeing = $user->wellbeingEntries()
            ->whereDate('date', $today)
            ->first();

        $wellbeingExists = (bool) $todayWellbeing;

        $activeGoal = $user->goals()->where('active', true)->orderBy('end_date')->first();

        $goalData = $activeGoal ? [
            'name' => $activeGoal->name,
            'target_value' => $activeGoal->target_value,
            'unit' => $activeGoal->unit,
            'start_date' => $activeGoal->start_date->toDateString(),
            'end_date' => $activeGoal->end_date->toDateString(),
        ] : null;

        $progressData = $activeGoal ? $this->progress->calculateProgress($activeGoal) : [
            'completed_distance_km' => 0,
            'target_distance_km' => 0,
            'progress_percentage' => 0,
            'status' => 'none',
            'days_remaining' => 0,
        ];

        $recommendation = null;
        $recommendationMessage = 'Bitte trage zunächst dein Wellbeing für heute ein, um eine Trainingsempfehlung zu erhalten.';

        if ($wellbeingExists) {
            // Check cached recommendation (same day + same entry) — stored as JSON string
            if ($runnerProfile &&
                $runnerProfile->recommendation_date == $today &&
                $runnerProfile->recommendation_wellbeing_id == $todayWellbeing->id &&
                !empty($runnerProfile->today_recommendation))
            {
                $decoded = json_decode($runnerProfile->today_recommendation, true);
                if (is_array($decoded)) {
                    $recommendation = $decoded;
                }
            }

            if (! $recommendation) {
                $this->openAI->withCoach($user->coach?->personality_prompt);
                $recommendation = $this->openAI->generateTodayRecommendation(
                    $runnerProfile ? [
                        'threshold_heart_rate' => $runnerProfile->threshold_heart_rate,
                        'max_heart_rate' => $runnerProfile->max_heart_rate,
                        'threshold_speed' => $runnerProfile->threshold_speed,
                    ] : null,
                    $yesterdayActivity ? $yesterdayActivity->toArray() : null,
                    $todayWellbeing ? $todayWellbeing->toArray() : null,
                    $goalData,
                    $progressData
                );

                if ($runnerProfile && $recommendation) {
                    $runnerProfile->today_recommendation = json_encode($recommendation, JSON_UNESCAPED_UNICODE);
                    $runnerProfile->recommendation_date = $today;
                    $runnerProfile->recommendation_wellbeing_id = $todayWellbeing->id;
                    $runnerProfile->save();
                }
            }

            $recommendationMessage = null;
        }

        return response()->json([
            'recommendation' => $recommendation,
            'recommendation_message' => $recommendationMessage,
            'wellbeing_exists' => $wellbeingExists,
        ]);
    }

    /**
     * Accept a recommendation and persist it as a standalone TrainingSession.
     */
    public function acceptRecommendation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'         => 'required|string|in:easy_run,tempo_run,interval,long_run,race_prep,rest',
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string|max:2000',
            'distance_km'  => 'nullable|numeric|min:0',
            'duration_min' => 'nullable|integer|min:0',
            'pace_target'  => 'nullable|string|max:20',
            'zone'         => 'nullable|integer|min:1|max:5',
            'intensity'    => 'nullable|string|in:low,medium,high',
        ]);

        $user  = $request->user();
        $today = Carbon::today()->toDateString();

        TrainingSession::updateOrCreate(
            [
                'user_id'          => $user->id,
                'planned_date'     => $today,
                'training_plan_id' => null,
            ],
            array_merge($data, [
                'status'     => 'planned',
                'sort_order' => 0,
            ])
        );

        return response()->json(['success' => true]);
    }

    /**
     * Adjust a recommendation harder or softer via AI.
     */
    public function adjustRecommendation(Request $request): JsonResponse
    {
        $request->validate([
            'direction' => 'required|in:harder,softer',
            'current'   => 'required|array',
        ]);

        $user           = $request->user();
        $today          = Carbon::today()->toDateString();
        $runnerProfile  = $user->runnerProfile;
        $todayWellbeing = $user->wellbeingEntries()->whereDate('date', $today)->first();

        $this->openAI->withCoach($user->coach?->personality_prompt);
        $adjusted = $this->openAI->adjustTodayRecommendation(
            $request->current,
            $request->direction,
            $runnerProfile ? [
                'threshold_heart_rate' => $runnerProfile->threshold_heart_rate,
                'max_heart_rate'       => $runnerProfile->max_heart_rate,
                'threshold_speed'      => $runnerProfile->threshold_speed,
            ] : null,
            $todayWellbeing ? $todayWellbeing->toArray() : null
        );

        return response()->json(['recommendation' => $adjusted]);
    }
}

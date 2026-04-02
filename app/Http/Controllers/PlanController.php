<?php

namespace App\Http\Controllers;

use App\Services\StravaService;
use App\Services\TrainingPlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function generate(Request $request, StravaService $strava, TrainingPlanService $generator)
    {
        $data = $request->validate([
            'goal_id' => 'required|integer|exists:goals,id',
        ]);

        $goal = $request->user()->goals()->findOrFail($data['goal_id']);
        $activities = [];

        if ($request->user()->stravaAccount) {
            $activities = $strava->fetchRecentActivities($request->user()->stravaAccount);
        }

        $plan = $generator->generatePlan($goal, $activities);

        return response()->json(["plan" => $plan]);
    }
}

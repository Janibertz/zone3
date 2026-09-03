<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\AiLog;
use App\Models\Coach;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\SystemHealth;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    public function __construct(private readonly SystemHealth $health) {}

    public function index()
    {
        $today = now()->toDateString();

        $stats = [
            'total_users'      => User::count(),
            'verified_users'   => User::whereNotNull('email_verified_at')->count(),
            'onboarded_users'  => User::whereNotNull('onboarding_completed_at')->count(),
            'admin_users'      => User::where('is_admin', true)->count(),
            'strava_users'     => User::has('stravaAccount')->count(),
            'total_activities' => Activity::count(),
            'total_events'     => Event::count(),
            'upcoming_events'  => Event::where('event_date', '>=', $today)->count(),
            'total_wellbeing'  => WellbeingEntry::count(),
            'active_plans'     => TrainingPlan::where('is_active', true)->count(),
            'ai_calls_today'   => AiLog::whereDate('created_at', $today)->count(),
            'ai_cost_today'    => (float) AiLog::whereDate('created_at', $today)->sum('cost_eur'),
            'ai_cost_total'    => (float) AiLog::sum('cost_eur'),
            'ai_calls_total'   => AiLog::count(),
        ];

        $registrationsPerMonth = User::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $activitiesPerMonth = Activity::selectRaw("DATE_FORMAT(start_date, '%Y-%m') as month, COUNT(*) as count")
            ->where('start_date', '>=', now()->subMonths(6)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $aiCostsPerDay = AiLog::selectRaw("DATE(created_at) as day, SUM(cost_eur) as cost, COUNT(*) as calls")
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->groupByRaw('DATE(created_at)')
            ->orderBy('day')
            ->get();

        $coachDistribution = Coach::withCount('users')
            ->orderByDesc('users_count')
            ->get(['id', 'name', 'avatar_color', 'avatar_initials']);

        $wellbeingTrend = WellbeingEntry::selectRaw(
            "`date`,
             ROUND(AVG((energy_level + mood + sleep_quality + (10 - muscle_soreness) + (10 - stress_level)) / 5.0), 1) as avg_score,
             COUNT(*) as entries"
        )
            ->where('date', '>=', now()->subDays(13)->toDateString())
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $recentUsers = User::latest()
            ->limit(5)
            ->get(['id', 'name', 'email', 'is_admin', 'is_active', 'created_at']);

        return Inertia::render('Admin/Dashboard', [
            'stats'                 => $stats,
            'registrationsPerMonth' => $registrationsPerMonth,
            'activitiesPerMonth'    => $activitiesPerMonth,
            'aiCostsPerDay'         => $aiCostsPerDay,
            'coachDistribution'     => $coachDistribution,
            'wellbeingTrend'        => $wellbeingTrend,
            'recentUsers'           => $recentUsers,
            // Eine Seite, die niemand aufruft, hilft niemandem: was auf
            // /admin/system rot waere, steht hier als eine Zeile.
            'systemHealth'          => $this->health->summary(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Goal;
use App\Models\User;
use App\Models\WellbeingEntry;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'total_users'      => User::count(),
            'verified_users'   => User::whereNotNull('email_verified_at')->count(),
            'onboarded_users'  => User::whereNotNull('onboarding_completed_at')->count(),
            'admin_users'      => User::where('is_admin', true)->count(),
            'strava_users'     => User::has('stravaAccount')->count(),
            'total_activities' => Activity::count(),
            'total_goals'      => Goal::count(),
            'active_goals'     => Goal::where('active', true)->count(),
            'total_wellbeing'  => WellbeingEntry::count(),
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

        $recentUsers = User::latest()
            ->limit(5)
            ->get(['id', 'name', 'email', 'is_admin', 'is_active', 'created_at']);

        return Inertia::render('Admin/Dashboard', [
            'stats'                  => $stats,
            'registrationsPerMonth'  => $registrationsPerMonth,
            'activitiesPerMonth'     => $activitiesPerMonth,
            'recentUsers'            => $recentUsers,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class StatisticsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Last 12 months of data
        $since = now()->subMonths(12)->startOfMonth();

        $activities = Activity::where('user_id', $user->id)
            ->where('type', 'Run')
            ->where('start_date', '>=', $since)
            ->orderBy('start_date')
            ->get();

        // Monthly volume (km + time)
        $monthlyStats = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i)->startOfMonth();
            $label = $month->locale('de')->isoFormat('MMM YY');
            $key   = $month->format('Y-m');

            $monthActivities = $activities->filter(fn($a) =>
                Carbon::parse($a->start_date)->format('Y-m') === $key
            );

            $monthlyStats[] = [
                'label'    => $label,
                'km'       => round($monthActivities->sum('distance') / 1000, 1),
                'runs'     => $monthActivities->count(),
                'time_min' => (int) ($monthActivities->sum('moving_time') / 60),
            ];
        }

        // Weekly volume for last 8 weeks
        $weeklyStats = [];
        for ($i = 7; $i >= 0; $i--) {
            $weekStart = now()->subWeeks($i)->startOfWeek();
            $weekEnd   = (clone $weekStart)->endOfWeek();
            $label     = 'KW ' . $weekStart->format('W');

            $weekActivities = $activities->filter(fn($a) =>
                Carbon::parse($a->start_date)->between($weekStart, $weekEnd)
            );

            $weeklyStats[] = [
                'label' => $label,
                'km'    => round($weekActivities->sum('distance') / 1000, 1),
                'runs'  => $weekActivities->count(),
            ];
        }

        // Pace trend (last 20 runs, avg pace min/km)
        $last20 = Activity::where('user_id', $user->id)
            ->where('type', 'Run')
            ->where('average_speed', '>', 0)
            ->orderByDesc('start_date')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        $paceTrend = $last20->map(function ($a) {
            $paceSecPerKm = $a->average_speed > 0 ? 1000 / $a->average_speed : 0;
            $paceMin = (int) ($paceSecPerKm / 60);
            $paceSec = (int) ($paceSecPerKm % 60);
            return [
                'date'       => Carbon::parse($a->start_date)->format('d.m'),
                'pace_sec'   => (int) $paceSecPerKm,
                'pace_label' => sprintf('%d:%02d', $paceMin, $paceSec),
                'distance'   => round($a->distance / 1000, 2),
                'name'       => $a->name,
            ];
        })->values();

        // Totals
        $totalRuns = Activity::where('user_id', $user->id)->where('type', 'Run')->count();
        $totalKm   = round(Activity::where('user_id', $user->id)->where('type', 'Run')->sum('distance') / 1000, 1);
        $totalTime = Activity::where('user_id', $user->id)->where('type', 'Run')->sum('moving_time');
        $totalElevation = Activity::where('user_id', $user->id)->where('type', 'Run')->sum('total_elevation_gain');

        $avgPaceSec = null;
        $avgPaceLabel = null;
        if ($last20->count() > 0) {
            $avgSpeed = $last20->avg('average_speed');
            if ($avgSpeed > 0) {
                $avgPaceSec = 1000 / $avgSpeed;
                $avgPaceLabel = sprintf('%d:%02d', (int)($avgPaceSec / 60), (int)($avgPaceSec % 60));
            }
        }

        return Inertia::render('Statistics', [
            'monthlyStats' => $monthlyStats,
            'weeklyStats'  => $weeklyStats,
            'paceTrend'    => $paceTrend,
            'totals' => [
                'runs'       => $totalRuns,
                'km'         => $totalKm,
                'time_min'   => (int) ($totalTime / 60),
                'elevation'  => (int) $totalElevation,
                'avg_pace'   => $avgPaceLabel,
            ],
        ]);
    }
}

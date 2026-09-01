<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use App\Services\PaceFormat;

class StatisticsController extends Controller
{
    /**
     * Strava-Typen je Sportart-Gruppe. Dieselbe Einteilung wie im Frontend
     * (useActivityTypes.js) — sie muss zusammenpassen, sonst filtert der
     * Reiter etwas anderes, als er verspricht.
     */
    private const SPORT_TYPES = [
        'run'      => ['Run', 'VirtualRun', 'TrailRun'],
        'ride'     => ['Ride', 'VirtualRide', 'EBikeRide'],
        'swim'     => ['Swim'],
        'walk'     => ['Walk', 'Hike'],
        'strength' => ['Workout', 'WeightTraining', 'Yoga'],
    ];

    public function index(Request $request)
    {
        $user = $request->user();

        // Die Seite zaehlte fest nur Laeufe. Ueber Strava kommt aber alles
        // herein, und wer Rad faehrt, sah seine Kilometer nirgends.
        $sport = $request->string('sport')->toString() ?: 'all';
        if ($sport !== 'all' && ! isset(self::SPORT_TYPES[$sport])) {
            $sport = 'all';
        }

        $ofSport = fn ($query) => $sport === 'all'
            ? $query
            : $query->whereIn('type', self::SPORT_TYPES[$sport]);

        // Last 12 months of data
        $since = now()->subMonths(12)->startOfMonth();

        $activities = $ofSport(
            Activity::where('user_id', $user->id)
                ->where('start_date', '>=', $since)
        )
            ->orderBy('start_date')
            ->get(['id', 'start_date', 'distance', 'moving_time']);

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

        // Der Pace-Verlauf bleibt bei Laeufen: eine Minutenzahl je Kilometer
        // ueber Radfahrten waere zwar rechenbar, aber ohne Aussage.
        $last20 = Activity::where('user_id', $user->id)
            ->whereIn('type', self::SPORT_TYPES['run'])
            ->where('average_speed', '>', 0)
            ->orderByDesc('start_date')
            ->limit(20)
            ->get(['id', 'start_date', 'average_speed', 'distance', 'name'])
            ->reverse()
            ->values();

        $paceTrend = $last20->map(function ($a) {
            $paceSecPerKm = $a->average_speed > 0 ? 1000 / $a->average_speed : 0;
            return [
                'date'       => Carbon::parse($a->start_date)->format('d.m'),
                'pace_sec'   => (int) $paceSecPerKm,
                'pace_label' => PaceFormat::fromSeconds($paceSecPerKm),
                'distance'   => round($a->distance / 1000, 2),
                'name'       => $a->name,
            ];
        })->values();

        // Totals
        $totals = $ofSport(Activity::where('user_id', $user->id));

        $totalRuns      = (clone $totals)->count();
        $totalKm        = round((clone $totals)->sum('distance') / 1000, 1);
        $totalTime      = (clone $totals)->sum('moving_time');
        $totalElevation = (clone $totals)->sum('total_elevation_gain');

        $avgPaceSec = null;
        $avgPaceLabel = null;
        if ($last20->count() > 0) {
            $avgSpeed = $last20->avg('average_speed');
            if ($avgSpeed > 0) {
                $avgPaceSec = 1000 / $avgSpeed;
                $avgPaceLabel = PaceFormat::fromSeconds($avgPaceSec);
            }
        }

        return Inertia::render('Statistics', [
            'sport'        => $sport,
            'sportOptions' => $this->sportOptions(),
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

    /**
     * Die Filterreiter: Laufen, Rad, Schwimmen — fest, nicht aus dem
     * Datenbestand abgeleitet.
     *
     * Zuerst standen hier nur die Sportarten, die der Athlet auch betreibt.
     * Das nimmt der App aber ihre Form: die drei Disziplinen sind die
     * Struktur, auf die Zone3 zulaeuft, und ein leerer Schwimm-Reiter ist
     * eine Einladung, keine Luecke. Gehen und Kraft zaehlen unter "Alle"
     * mit, ohne eigenen Reiter — die Summe der drei ist deshalb kleiner als
     * "Alle", und das ist richtig so.
     *
     * Dieselbe Liste steht in useActivityTypes.js; sie muss zusammenpassen.
     *
     * @return list<array{value: string, label: string}>
     */
    private function sportOptions(): array
    {
        return [
            ['value' => 'all',  'label' => 'Alle'],
            ['value' => 'run',  'label' => 'Laufen'],
            ['value' => 'ride', 'label' => 'Rad'],
            ['value' => 'swim', 'label' => 'Schwimmen'],
        ];
    }
}

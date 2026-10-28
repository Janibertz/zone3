<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use App\Models\BestEffort;
use App\Services\BestEffortService;
use App\Services\IntensityDistributionService;

class StatisticsController extends Controller
{
    /**
     * Strava-Typen je Sportart-Gruppe. Dieselbe Einteilung wie im Frontend
     * (useActivityTypes.js) — sie muss zusammenpassen, sonst filtert der
     * Reiter etwas anderes, als er verspricht. Die Aktivitätenliste filtert
     * mit derselben Tabelle.
     */
    public const SPORT_TYPES = [
        'run'      => ['Run', 'VirtualRun', 'TrailRun'],
        'ride'     => ['Ride', 'VirtualRide', 'EBikeRide'],
        'swim'     => ['Swim'],
        'walk'     => ['Walk', 'Hike'],
        'strength' => ['Workout', 'WeightTraining', 'Yoga'],
    ];

    /** Wie viele Balken je Zeitraum: zwölf Wochen, zwölf Monate, fünf Jahre. */
    private const BUCKETS = ['week' => 12, 'month' => 12, 'year' => 5];

    /**
     * Statistik im Story-Look (Redesign 28.10.2026): eine Hauptzahl für den
     * laufenden Zeitraum, Balken für die letzten, vier Kacheln, Bestzeiten.
     *
     * Verglichen wird der laufende Zeitraum mit dem vorigen **bis zum
     * selben Tag** — ein angefangener Monat gegen einen ganzen wäre am
     * Monatsanfang immer „−90 %", ganz gleich wie trainiert wurde.
     */
    public function index(Request $request, IntensityDistributionService $intensity, BestEffortService $bestEfforts)
    {
        $user = $request->user();

        // Die Seite zaehlte fest nur Laeufe. Ueber Strava kommt aber alles
        // herein, und wer Rad faehrt, sah seine Kilometer nirgends.
        $sport = $request->string('sport')->toString() ?: 'all';
        if ($sport !== 'all' && ! isset(self::SPORT_TYPES[$sport])) {
            $sport = 'all';
        }

        $period = $request->string('period')->toString() ?: 'month';
        if (! isset(self::BUCKETS[$period])) {
            $period = 'month';
        }

        $ofSport = fn ($query) => $sport === 'all'
            ? $query
            : $query->whereIn('type', self::SPORT_TYPES[$sport]);

        $now   = now();
        $start = $this->periodStart($now, $period);
        $first = $this->shift($start, $period, -(self::BUCKETS[$period] - 1));

        $activities = $ofSport(Activity::where('user_id', $user->id))
            ->where('start_date', '>=', $first)
            ->orderBy('start_date')
            ->get(['id', 'type', 'start_date', 'distance', 'moving_time', 'total_elevation_gain']);

        $buckets = [];
        for ($i = 0; $i < self::BUCKETS[$period]; $i++) {
            $from = $this->shift($first, $period, $i);
            $to   = $this->shift($from, $period, 1);
            $in   = $activities->filter(fn ($a) => $a->start_date >= $from && $a->start_date < $to);

            $buckets[] = [
                'label'   => $this->bucketLabel($from, $period),
                'km'      => round($in->sum('distance') / 1000, 1),
                'count'   => $in->count(),
                'seconds' => (int) $in->sum('moving_time'),
            ];
        }

        // Laufender Zeitraum gegen den vorigen bis zum selben Zeitpunkt.
        $prevStart = $this->shift($start, $period, -1);
        $prevEnd   = $prevStart->copy()->add($start->diff($now));
        $current   = $activities->filter(fn ($a) => $a->start_date >= $start);
        $previous  = $ofSport(Activity::where('user_id', $user->id))
            ->whereBetween('start_date', [$prevStart, $prevEnd])
            ->get(['type', 'distance', 'moving_time']);

        $runner = $user->runnerProfile;

        return Inertia::render('Statistics', [
            'sport'        => $sport,
            'period'       => $period,
            'sportOptions' => $this->sportOptions(),
            'buckets'      => $buckets,
            'current'      => [
                'label'       => $this->currentLabel($start, $period),
                'prev_label'  => $this->previousLabel($prevStart, $period),
                'km'          => round($current->sum('distance') / 1000, 1),
                'prev_km'     => round($previous->sum('distance') / 1000, 1),
                'count'       => $current->count(),
                'seconds'     => (int) $current->sum('moving_time'),
                'elevation'   => (int) round($current->sum('total_elevation_gain')),
                'speed'       => $this->speed($current, $sport),
                'prev_speed'  => $this->speed($previous, $sport),
            ],
            // 80/20 und Bestzeiten sagen nur über Läufe etwas.
            'intensity' => in_array($sport, ['all', 'run'], true)
                ? $intensity->forUser($user->id, $runner?->vdot(), null, $runner?->trainingModelOn() ?? 'polarized')
                : null,
            'bests' => in_array($sport, ['all', 'run'], true) ? $this->bests($user->id) : [],
            'totals' => [
                'runs' => $ofSport(Activity::where('user_id', $user->id))->count(),
                'km'   => round($ofSport(Activity::where('user_id', $user->id))->sum('distance') / 1000, 1),
            ],
        ]);
    }

    private function periodStart(Carbon $now, string $period): Carbon
    {
        return match ($period) {
            'week'  => $now->copy()->startOfWeek(),
            'year'  => $now->copy()->startOfYear(),
            default => $now->copy()->startOfMonth(),
        };
    }

    private function shift(Carbon $date, string $period, int $n): Carbon
    {
        return match ($period) {
            'week'  => $date->copy()->addWeeks($n),
            'year'  => $date->copy()->addYearsNoOverflow($n),
            default => $date->copy()->addMonthsNoOverflow($n),
        };
    }

    private function bucketLabel(Carbon $from, string $period): string
    {
        return match ($period) {
            'week'  => $from->format('j.n.'),
            'year'  => $from->format('Y'),
            default => $from->locale('de')->isoFormat('MMM'),
        };
    }

    private function currentLabel(Carbon $start, string $period): string
    {
        return match ($period) {
            'week'  => 'Diese Woche',
            'year'  => $start->format('Y'),
            default => $start->locale('de')->isoFormat('MMMM'),
        };
    }

    private function previousLabel(Carbon $start, string $period): string
    {
        return match ($period) {
            'week'  => 'Vorwoche',
            'year'  => $start->format('Y'),
            default => $start->locale('de')->isoFormat('MMMM'),
        };
    }

    /**
     * Durchschnittstempo in m/s. Unter „Alle" nur über Läufe — eine Pace
     * über Rad und Laufen gemischt hätte keine Aussage.
     */
    private function speed($activities, string $sport): ?float
    {
        if ($sport === 'all') {
            $activities = $activities->whereIn('type', self::SPORT_TYPES['run']);
        }

        $time = $activities->sum('moving_time');

        return $time > 0 ? round($activities->sum('distance') / $time, 4) : null;
    }

    /**
     * Die beste Zeit je Distanz, über alle Zeit.
     *
     * @return list<array{label: string, seconds: int, date: string, activity_id: int|null}>
     */
    private function bests(int $userId): array
    {
        $rows = BestEffort::where('user_id', $userId)
            ->whereIn('distance_key', array_keys(BestEffortService::DISTANCES))
            ->orderBy('elapsed_time')
            ->get(['distance_key', 'elapsed_time', 'achieved_at', 'activity_id'])
            ->unique('distance_key');

        $out = [];
        foreach (BestEffortService::DISTANCES as $key => $_) {
            $best = $rows->firstWhere('distance_key', $key);
            if (! $best) {
                continue;
            }
            $out[] = [
                'label'       => BestEffortService::LABELS[$key],
                'seconds'     => $best->elapsed_time,
                'date'        => $best->achieved_at->format('Y-m-d'),
                'activity_id' => $best->activity_id,
            ];
        }

        return $out;
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

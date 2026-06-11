<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\User;
use Carbon\Carbon;

/**
 * Aggregates a year or month into a "Wrapped"-style retrospective:
 * totals, records, patterns, streak, PRs and a comparison to the previous period.
 */
class WrappedService
{
    private const WEEKDAYS = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
    private const MONTHS    = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public function generate(User $user, string $period = 'year', ?int $year = null, ?int $month = null): array
    {
        $period = $period === 'month' ? 'month' : 'year';
        $year   = $year ?: (int) now()->year;

        if ($period === 'month') {
            $month     = ($month >= 1 && $month <= 12) ? $month : (int) now()->month;
            $start     = Carbon::create($year, $month, 1)->startOfDay();
            $end       = (clone $start)->endOfMonth()->endOfDay();
            $label     = self::MONTHS[$month - 1] . ' ' . $year;
            $prevStart = (clone $start)->subMonthNoOverflow()->startOfMonth();
            $prevEnd   = (clone $prevStart)->endOfMonth();
            $prevLabel = self::MONTHS[$prevStart->month - 1] . ' ' . $prevStart->year;
        } else {
            $start     = Carbon::create($year, 1, 1)->startOfDay();
            $end       = (clone $start)->endOfYear();
            $label     = (string) $year;
            $prevStart = Carbon::create($year - 1, 1, 1)->startOfDay();
            $prevEnd   = (clone $prevStart)->endOfYear();
            $prevLabel = (string) ($year - 1);
        }

        $runs = Activity::where('user_id', $user->id)
            ->where('type', 'Run')
            ->whereBetween('start_date', [$start, $end])
            ->get(['id', 'name', 'distance', 'moving_time', 'total_elevation_gain', 'average_speed', 'start_date']);

        if ($runs->isEmpty()) {
            return ['period' => $period, 'period_label' => $label, 'has_data' => false];
        }

        // ── Totals ──
        $totalKm    = round($runs->sum('distance') / 1000, 1);
        $hours      = round($runs->sum('moving_time') / 3600, 1);
        $elevation  = (int) $runs->sum('total_elevation_gain');
        $activeDays = $runs->map(fn ($a) => Carbon::parse($a->start_date)->toDateString())->unique()->count();

        // ── Records ──
        $longest = $runs->sortByDesc('distance')->first();
        $fastest = $runs->filter(fn ($a) => $a->distance >= 2000 && $a->average_speed > 0)
            ->sortByDesc('average_speed')->first();

        // ── Patterns ──
        $byWeekday   = $runs->groupBy(fn ($a) => Carbon::parse($a->start_date)->dayOfWeekIso); // 1=Mon..7=Sun
        $favWeekday  = $byWeekday->sortByDesc(fn ($g) => $g->count())->keys()->first();

        $daytime = ['Früh' => 0, 'Mittag' => 0, 'Abend' => 0];
        foreach ($runs as $a) {
            $h = Carbon::parse($a->start_date)->hour;
            $daytime[$h < 11 ? 'Früh' : ($h < 17 ? 'Mittag' : 'Abend')]++;
        }
        arsort($daytime);
        $favDaytime = array_key_first($daytime);

        // ── Busiest month (year) or week (month) by km ──
        if ($period === 'year') {
            $kmByMonth = [];
            foreach ($runs as $a) {
                $m = (int) Carbon::parse($a->start_date)->month;
                $kmByMonth[$m] = ($kmByMonth[$m] ?? 0) + $a->distance / 1000;
            }
            arsort($kmByMonth);
            $topMonth = array_key_first($kmByMonth);
            $busiest  = ['type' => 'month', 'label' => self::MONTHS[$topMonth - 1], 'km' => round($kmByMonth[$topMonth], 1)];
        } else {
            $kmByWeek = [];
            foreach ($runs as $a) {
                $w = Carbon::parse($a->start_date)->format('o-W');
                $kmByWeek[$w] = ($kmByWeek[$w] ?? 0) + $a->distance / 1000;
            }
            arsort($kmByWeek);
            $topWeek = array_key_first($kmByWeek);
            $busiest = ['type' => 'week', 'label' => 'KW ' . substr($topWeek, -2), 'km' => round($kmByWeek[$topWeek], 1)];
        }

        // ── Longest streak of consecutive run days ──
        $dates = $runs->map(fn ($a) => Carbon::parse($a->start_date)->toDateString())->unique()->sort()->values()->all();
        $longestStreak = $this->longestStreak($dates);

        // ── PRs achieved in the period ──
        $prRows      = BestEffort::where('user_id', $user->id)
            ->whereBetween('achieved_at', [$start, $end])->get(['distance_key']);
        $prDistances = $prRows->pluck('distance_key')->unique()
            ->map(fn ($k) => BestEffortService::LABELS[$k] ?? $k)->values()->all();

        // ── Comparison to previous period ──
        $prevKm = round(Activity::where('user_id', $user->id)->where('type', 'Run')
            ->whereBetween('start_date', [$prevStart, $prevEnd])->sum('distance') / 1000, 1);
        $vsPrevious = $prevKm > 0
            ? ['prev_label' => $prevLabel, 'prev_km' => $prevKm, 'delta_pct' => (int) round(($totalKm - $prevKm) / $prevKm * 100)]
            : null;

        return [
            'period'       => $period,
            'period_label' => $label,
            'has_data'     => true,
            'totals'       => [
                'runs'        => $runs->count(),
                'km'          => $totalKm,
                'hours'       => $hours,
                'elevation'   => $elevation,
                'active_days' => $activeDays,
            ],
            'longest_run'      => [
                'km'   => round($longest->distance / 1000, 1),
                'name' => $longest->name,
                'date' => Carbon::parse($longest->start_date)->format('d.m.Y'),
            ],
            'fastest_run'      => $fastest ? [
                'pace' => $this->pace($fastest->average_speed),
                'km'   => round($fastest->distance / 1000, 1),
                'name' => $fastest->name,
                'date' => Carbon::parse($fastest->start_date)->format('d.m.Y'),
            ] : null,
            'favorite_weekday' => ['label' => self::WEEKDAYS[$favWeekday - 1], 'count' => $byWeekday[$favWeekday]->count()],
            'favorite_daytime' => ['label' => $favDaytime, 'count' => $daytime[$favDaytime]],
            'busiest'          => $busiest,
            'longest_streak'   => $longestStreak,
            'prs'              => ['count' => $prRows->count(), 'distances' => $prDistances],
            'vs_previous'      => $vsPrevious,
            'fun'              => ['marathons' => round($totalKm / 42.195, 1)],
        ];
    }

    /** Years and recent months that contain runs, for the period switcher. */
    public function availablePeriods(User $user): array
    {
        $dates = Activity::where('user_id', $user->id)->where('type', 'Run')
            ->orderByDesc('start_date')->pluck('start_date');

        $years = $dates->map(fn ($d) => (int) Carbon::parse($d)->year)->unique()->values()->all();

        // The current month is only shown once it is over (e.g. June appears on
        // July 1st) — a month's review should reflect the complete month.
        $currentMonth = now()->format('Y-m');

        $months = $dates->map(fn ($d) => Carbon::parse($d)->format('Y-m'))
            ->unique()
            ->reject(fn ($ym) => $ym >= $currentMonth)
            ->take(12)
            ->map(function ($ym) {
                [$y, $m] = explode('-', $ym);
                return ['value' => $ym, 'label' => self::MONTHS[(int) $m - 1] . ' ' . $y];
            })->values()->all();

        return ['years' => $years ?: [(int) now()->year], 'months' => $months];
    }

    private function longestStreak(array $dates): int
    {
        if (empty($dates)) return 0;
        $best = 1; $cur = 1;
        for ($i = 1; $i < count($dates); $i++) {
            $diff = (int) abs(Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($dates[$i])));
            if ($diff === 1) { $cur++; $best = max($best, $cur); }
            elseif ($diff > 1) { $cur = 1; }
        }
        return $best;
    }

    private function pace(float $mps): string
    {
        if ($mps <= 0) return '–';
        $secPerKm = (int) round(1000 / $mps);
        return sprintf('%d:%02d', intdiv($secPerKm, 60), $secPerKm % 60);
    }
}

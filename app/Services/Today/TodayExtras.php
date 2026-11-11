<?php

namespace App\Services\Today;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\PaceFormat;
use App\Services\PeriodizationService;
use App\Services\Polyline;
use App\Services\RacePredictionService;
use Carbon\CarbonImmutable;

/**
 * „Mehr Leben“ auf Heute (Jan, 10.11.2026, Mockup „Zone3 mit mehr Leben“):
 * die Rennkarte, ein Satz vom Coach und der letzte Lauf als Route. Alles
 * aus den Daten, kein Modellaufruf — der Satz muss stimmen, nicht klingen.
 */
class TodayExtras
{
    private const RUN_TYPES = ['Run', 'TrailRun', 'VirtualRun'];

    /** Rennen des aktiven Plans (auch ohne Rennen), sonst das nächste Rennen. */
    public function raceCard(User $user): ?array
    {
        $plan  = TrainingPlan::where('user_id', $user->id)->where('is_active', true)->with('event')->latest()->first();
        $event = $plan?->event && $plan->event->days_until >= 0 ? $plan->event : null;
        $event ??= $user->events()->races()
            ->whereDate('event_date', '>=', now()->toDateString())
            ->orderByRaw("CASE priority WHEN 'A' THEN 0 WHEN 'B' THEN 1 ELSE 2 END")
            ->orderBy('event_date')
            ->first();

        if (! $event) {
            return null;
        }

        $vdot       = $user->runnerProfile?->vdot();
        $prediction = $vdot && ! $event->isBackyard()
            ? app(RacePredictionService::class)->forVdot($vdot, $event->isOpen() ? '5km' : $event->race_distance, $event->distance_km)
            : null;

        return [
            'id'         => $event->id,
            'name'       => $event->name,
            'open'       => $event->isOpen(),
            'open_test'  => (bool) $event->open_test,
            'block'      => (int) $event->block_number,
            'days'       => $event->days_until,
            'date'       => $event->event_date->toDateString(),
            'date_label' => $event->event_date->locale('de')->translatedFormat('j. F Y'),
            'target'     => $event->target_time_formatted,
            'progress'   => $plan && $plan->event_id === $event->id ? app(PeriodizationService::class)->progress($event) : null,
            'prediction' => $prediction['time'] ?? null,
            'href'       => $plan && $plan->event_id === $event->id ? route('events.plan.show', $event->id) : route('events.index'),
        ];
    }

    /** Der letzte Lauf der letzten 14 Tage mit Strecke, als Linie für die Karte. */
    public function lastRun(User $user): ?array
    {
        $run = Activity::where('user_id', $user->id)
            ->whereIn('type', self::RUN_TYPES)
            ->where('start_date', '>=', now()->subDays(14))
            ->whereNotNull('polyline')
            ->orderByDesc('start_date')
            ->first();

        $encoded = $run?->polyline['polyline'] ?? null;
        $path    = $encoded ? self::sketch(Polyline::decode($encoded), 300, 200, 28) : null;
        if (! $run || ! $path) {
            return null;
        }

        return [
            'id'    => $run->id,
            'day'   => self::dayLabel(CarbonImmutable::parse($run->start_date)),
            'name'  => $run->name,
            'km'    => round($run->distance / 1000, 1),
            'pace'  => $run->average_speed > 0 ? PaceFormat::fromSpeed((float) $run->average_speed) : null,
            'hr'    => $run->average_heartrate ? (int) round($run->average_heartrate) : null,
            'path'  => $path,
        ];
    }

    /**
     * Ein Satz aus den Daten, in dieser Reihenfolge: der Puls bei gleichem
     * Tempo (das Zeichen, dass die Grundlage wächst), sonst der Stand der
     * Woche, sonst der Weg zum Rennen. Nur, was sich belegen lässt —
     * schlechter gewordene Werte sagt der Satz nicht, das ist Sache des
     * Coaches im Gespräch.
     *
     * @param  list<array{type: string, sport_type: ?string, distance_km: ?float, status: string}>  $weekPlan
     */
    public function coachLine(User $user, array $weekPlan, ?array $race): ?string
    {
        return $this->heartRateLine($user)
            ?? $this->weekLine($weekPlan)
            ?? $this->raceLine($race);
    }

    private function heartRateLine(User $user): ?string
    {
        $last = Activity::where('user_id', $user->id)
            ->whereIn('type', self::RUN_TYPES)
            ->where('start_date', '>=', now()->subDays(4))
            ->whereNotNull('average_heartrate')
            ->where('distance', '>=', 5000)
            ->orderByDesc('start_date')
            ->first();
        if (! $last || $last->average_speed <= 0) {
            return null;
        }

        $pace  = 1000 / (float) $last->average_speed;
        $start = CarbonImmutable::parse($last->start_date);
        $older = Activity::where('user_id', $user->id)
            ->whereIn('type', self::RUN_TYPES)
            ->whereNotNull('average_heartrate')
            ->whereBetween('start_date', [$start->subDays(42), $start->subDays(21)])
            ->where('distance', '>=', $last->distance * 0.6)
            ->where('average_speed', '>', 0)
            ->get()
            ->filter(fn (Activity $a) => abs(1000 / (float) $a->average_speed - $pace) <= 10);

        if ($older->count() < 1) {
            return null;
        }

        $was  = (int) round($older->avg('average_heartrate'));
        $now  = (int) round($last->average_heartrate);
        if ($was - $now < 3) {
            return null;
        }

        $meanTs = (int) $older->avg(fn (Activity $a) => CarbonImmutable::parse($a->start_date)->timestamp);
        $weeks  = max(3, (int) round(($start->timestamp - $meanTs) / (7 * 86400)));
        $km    = str_replace('.', ',', (string) round($last->distance / 1000, 1));
        $words = [3 => 'drei', 4 => 'vier', 5 => 'fünf', 6 => 'sechs'][$weeks] ?? (string) $weeks;

        return self::dayLabel($start) . " {$km} km in " . PaceFormat::fromSeconds($pace)
            . " bei Puls {$now}. Vor {$words} Wochen lag der bei gleichem Tempo bei {$was}. Deine Grundlage wächst.";
    }

    private function weekLine(array $weekPlan): ?string
    {
        $runs = array_values(array_filter($weekPlan, fn ($s) => $s['type'] !== 'rest' && ($s['distance_km'] ?? 0) > 0 && empty($s['sport_type'])));
        if (! $runs) {
            return null;
        }

        $planned = array_sum(array_column($runs, 'distance_km'));
        $done    = array_sum(array_map(fn ($s) => $s['status'] === 'completed' ? $s['distance_km'] : 0, $runs));
        $open    = count(array_filter($runs, fn ($s) => $s['status'] === 'planned'));
        $fmt     = fn (float $v) => str_replace('.', ',', (string) round($v, 1));

        if ($open === 0) {
            if ($done <= 0) {
                return null;
            }
            // „Steht" nur, wenn der Plan die Woche bis Sonntag kennt (auch
            // mit Ruhetagen) — vor einem späteren Planstart stehen dort nur
            // die gelaufenen Einheiten, und am Dienstag ist nichts fertig.
            $sunday  = CarbonImmutable::today()->endOfWeek()->toDateString();
            $planned = max(array_merge([''], array_column($weekPlan, 'date'))) >= $sunday || CarbonImmutable::today()->isSunday();

            return $planned
                ? "Diese Woche steht: {$fmt($done)} km gelaufen. Gut gemacht."
                : "Diese Woche bisher {$fmt($done)} km gelaufen.";
        }
        if ($done <= 0) {
            return "Diese Woche sind {$fmt($planned)} km geplant, in " . ($open === 1 ? 'einer Einheit' : "{$open} Einheiten") . '. Fang locker an.';
        }

        return "Diese Woche {$fmt($done)} von {$fmt($planned)} km. Noch " . ($open === 1 ? 'eine Einheit' : "{$open} Einheiten") . ', dann steht die Woche.';
    }

    private function raceLine(?array $race): ?string
    {
        if (! $race || $race['days'] < 1) {
            return null;
        }
        $p = $race['progress'];
        if ($race['open']) {
            return $p ? "Block {$race['block']}, Woche {$p['week']} von {$p['weeks']}: {$p['phase']}." : null;
        }
        $week = $p && ($p['starts_in'] ?? 0) === 0 ? " Woche {$p['week']} von {$p['weeks']}: {$p['phase']}." : '';

        return "Noch {$race['days']} Tage bis {$race['name']}.{$week}";
    }

    private static function dayLabel(CarbonImmutable $d): string
    {
        $diff = (int) $d->startOfDay()->diffInDays(CarbonImmutable::today(), false);

        return match (true) {
            $diff === 0 => 'Heute',
            $diff === 1 => 'Gestern',
            default     => $d->locale('de')->translatedFormat('l'),
        };
    }

    /**
     * Die Route als SVG-Pfad in einem Rahmen von w × h, mit Rand. Höchstens
     * 160 Punkte — mehr sieht man auf einer Kachel nicht.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public static function sketch(array $points, int $w, int $h, int $pad): ?string
    {
        if (count($points) < 2) {
            return null;
        }

        $step   = max(1, (int) ceil(count($points) / 160));
        $points = array_values(array_filter($points, fn ($i) => $i % $step === 0, ARRAY_FILTER_USE_KEY));
        $midLat = deg2rad(array_sum(array_column($points, 0)) / count($points));
        $xy     = array_map(fn ($p) => [$p[1] * cos($midLat), -$p[0]], $points);

        $xs = array_column($xy, 0);
        $ys = array_column($xy, 1);
        $spanX = max($xs) - min($xs);
        $spanY = max($ys) - min($ys);
        if ($spanX <= 0 && $spanY <= 0) {
            return null;
        }

        $scale = min(($w - 2 * $pad) / max($spanX, 1e-9), ($h - 2 * $pad) / max($spanY, 1e-9));
        $offX  = ($w - $spanX * $scale) / 2;
        $offY  = ($h - $spanY * $scale) / 2;

        $d = '';
        foreach ($xy as $i => [$x, $y]) {
            $px = round(($x - min($xs)) * $scale + $offX, 1);
            $py = round(($y - min($ys)) * $scale + $offY, 1);
            $d .= ($i === 0 ? 'M' : 'L') . $px . ' ' . $py;
        }

        return $d;
    }
}

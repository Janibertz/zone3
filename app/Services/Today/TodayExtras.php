<?php

namespace App\Services\Today;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\PaceFormat;
use App\Services\PeriodizationService;
use App\Services\Polyline;
use App\Services\RacePredictionService;
use Carbon\CarbonImmutable;

/**
 * „Mehr Leben“ auf Heute (Jan, 10.11.2026, Mockup „Zone3 mit mehr Leben“):
 * die Rennkarte, ein Satz vom Coach und die letzte Einheit als Route
 * oder Foto. Alles
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

    /**
     * Sportarten unter „Zuletzt“ (Jan, 17.11.2026: auch Schwimmen, Kraft
     * und Rad). Gehen, Wandern und Yoga bleiben draußen — der Coach schweigt
     * dazu ja auch (`SessionHighlights::silent()`).
     */
    public const LAST_TYPES = [
        'Run', 'TrailRun', 'VirtualRun',
        'Ride', 'VirtualRide', 'EBikeRide',
        'Swim',
        'WeightTraining', 'Workout',
    ];

    /**
     * Die letzten Einheiten für den Slider unter „Zuletzt“ (Jan, 17.11.2026:
     * „die letzten 5 Aktivitäten“, egal wie alt). Mit Strecke als Linie für
     * die Karte, ohne (Laufband, Zwift, Becken, Kraft) mit dem Foto der
     * Einheit wie im Aufmacher und im Widget: `photo_type` ist der Typ der
     * verknüpften Planeinheit, sonst der Sport (`heroPhoto()` im Frontend).
     */
    public function recentActivities(User $user, int $limit = 5): array
    {
        $activities = Activity::where('user_id', $user->id)
            ->whereIn('type', self::LAST_TYPES)
            ->orderByDesc('start_date')
            ->limit($limit)
            ->get();

        $sessionTypes = TrainingSession::whereIn('activity_id', $activities->pluck('id'))
            ->orderBy('sort_order')
            ->get(['activity_id', 'type'])
            ->groupBy('activity_id')
            ->map(fn ($g) => $g->first()->type);

        return $activities->map(fn (Activity $a) => $this->presentActivity($a, $sessionTypes[$a->id] ?? null))->values()->all();
    }

    /** Die letzte Einheit — die erste Karte von `recentActivities()`. */
    public function lastActivity(User $user): ?array
    {
        return $this->recentActivities($user, 1)[0] ?? null;
    }

    /**
     * Aktivitäten von heute als Folien für den Aufmacher (Jan, 17.11.2026:
     * „alle Einheiten egal ob Plan oder nicht“). Nur, was keine der schon
     * gezeigten Einheiten trägt; dieselben Sportarten wie unter „Zuletzt“.
     * Die Form gleicht einer Planeinheit, damit `TodayHero` sie zeichnet —
     * mit den gemessenen Zahlen statt der geplanten.
     *
     * @param  int[]  $exceptActivityIds
     */
    public function todayActivitySlides(User $user, array $exceptActivityIds = []): array
    {
        return Activity::where('user_id', $user->id)
            ->whereIn('type', self::LAST_TYPES)
            ->whereDate('start_date', CarbonImmutable::today()->toDateString())
            ->when($exceptActivityIds, fn ($q) => $q->whereNotIn('id', $exceptActivityIds))
            ->orderBy('start_date')
            ->get()
            ->map(fn (Activity $a) => self::activitySlide($a))
            ->all();
    }

    /** Eine Aktivität in der Form einer erledigten Einheit für den Aufmacher. */
    public static function activitySlide(Activity $a, ?string $type = null): array
    {
        $run      = in_array($a->type, self::RUN_TYPES, true);
        $ride     = in_array($a->type, ['Ride', 'VirtualRide', 'EBikeRide'], true);
        $strength = in_array($a->type, ['WeightTraining', 'Workout'], true);
        $seconds  = (int) ($a->moving_time ?: $a->elapsed_time);
        $pace     = $run && $a->average_speed > 0 ? PaceFormat::fromSpeed((float) $a->average_speed) : null;

        return [
            'slide_id'     => 'activity-' . $a->id,
            // Ohne Planeinheit ist nicht bekannt, ob es locker war: Chip „Laufen“, nicht „Lockerer Lauf“.
            'activity_type' => $a->type,
            'id'           => null,
            'type'         => $type ?? ($run ? 'easy_run' : ($strength ? 'strength' : 'cross_training')),
            'sport_type'   => $run || $strength ? null : $a->type,
            'title'        => $a->name,
            'description'  => null,
            'steps'        => null,
            // Becken in Kilometern wäre schief, die Strecke steht dort in Metern.
            'distance_km'  => ($run || $ride) && $a->distance > 0 ? round($a->distance / 1000, 1) : null,
            'duration_min' => $seconds > 0 ? (int) round($seconds / 60) : null,
            'pace_target'  => $pace,
            'zone'         => null,
            'measured'     => true,
            'status'       => 'completed',
            'activity_id'  => $a->id,
            'is_today'     => true,
            'path'         => self::routePath($a),
            'hr'           => $a->average_heartrate ? (int) round($a->average_heartrate) : null,
        ];
    }

    /**
     * Eine erledigte Einheit zeigt oben, was gemessen wurde (Jan, 17.11.2026:
     * „Die erledigt sind können ja auch die Strecke anzeigen wie unten bei
     * Zuletzt“): Route, Strecke, Dauer, Tempo und Puls der Aktivität statt
     * der geplanten Zahlen. Typ, Titel und Ablauf bleiben die der Einheit.
     */
    public static function withMeasured(array $slide, ?Activity $a): array
    {
        if (! $a || ($slide['status'] ?? null) !== 'completed') {
            return $slide;
        }
        $m = self::activitySlide($a);

        return array_merge($slide, [
            'distance_km'  => $m['distance_km'],
            'duration_min' => $m['duration_min'] ?? $slide['duration_min'],
            'pace_target'  => $m['pace_target'],
            'path'         => $m['path'],
            'hr'           => $m['hr'],
            'measured'     => true,
        ]);
    }

    private static function routePath(Activity $a): ?string
    {
        $encoded = $a->polyline['polyline'] ?? null;

        return $encoded ? self::sketch(Polyline::decode($encoded), 300, 200, 28) : null;
    }

    private function presentActivity(Activity $a, ?string $sessionType): array
    {
        $path    = self::routePath($a);
        $sets    = is_array($a->strength_sets) ? count($a->strength_sets) : 0;

        return [
            'id'            => $a->id,
            'day'           => self::dayLabel(CarbonImmutable::parse($a->start_date)),
            'name'          => $a->name,
            'type'          => $a->type,
            'distance'      => (float) $a->distance,
            'average_speed' => (float) $a->average_speed,
            'moving_time'   => (int) ($a->moving_time ?: $a->elapsed_time),
            'hr'            => $a->average_heartrate ? (int) round($a->average_heartrate) : null,
            'sets'          => $sets ?: null,
            'path'          => $path,
            'photo_type'    => $sessionType && $sessionType !== 'rest' ? $sessionType : self::photoType($a->type),
            'sport_type'    => in_array($a->type, self::RUN_TYPES, true) ? null : $a->type,
        ];
    }

    private static function photoType(string $type): string
    {
        return match (true) {
            in_array($type, ['WeightTraining', 'Workout'], true) => 'strength',
            default => 'easy_run',
        };
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
            // Ohne Zeitgrenze kann die fünfte Karte Wochen alt sein — dann
            // wäre „Sonntag“ irreführend.
            $diff < 7   => $d->locale('de')->translatedFormat('l'),
            // „Di 20.10.“ wie unter „Als Nächstes“.
            default     => str_replace('.', '', $d->locale('de')->translatedFormat('D')) . ' ' . $d->format('j.n.'),
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

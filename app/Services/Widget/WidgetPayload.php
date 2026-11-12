<?php

namespace App\Services\Widget;

use App\Http\Controllers\AIController;
use App\Models\Activity;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\PaceFormat;
use App\Services\Today\TodayExtras;
use Carbon\CarbonImmutable;

/**
 * Was das iOS-Widget zeigt (Mockup „Zone3 Widget“, Jan am 11.11.2026):
 * die Einheit von heute, „Als Nächstes“ mit Läufen, Kraft und Rad, die
 * Rennleiste und die Wochen-Kilometer.
 *
 * Das Widget rechnet nichts außer „welcher Tag ist heute“: es bekommt
 * vierzehn Tage, damit es nach Mitternacht von selbst auf den neuen Tag
 * springt, auch wenn es eine Weile nichts Neues laden durfte. Kein
 * Modellaufruf — der Tagesvorschlag kommt nur mit, wenn er heute schon
 * gespeichert wurde.
 */
class WidgetPayload
{
    public const DAYS = 14;

    /** Kurz, weil der Chip im kleinen Widget 158 pt breit ist. */
    private const LABELS = [
        'easy_run' => 'Locker', 'tempo_run' => 'Tempo', 'interval' => 'Intervall', 'long_run' => 'Lang',
        'race_prep' => 'Rennen', 'progressive_run' => 'Progressiv', 'test_run' => 'Test',
        'back_to_back_long' => 'Lang', 'time_on_feet' => 'Lang', 'yard_simulation' => 'Backyard',
        'night_run' => 'Nachtlauf', 'strength' => 'Kraft', 'core' => 'Rumpf', 'mobility' => 'Mobilität',
        'rest' => 'Ruhetag', 'cross_training' => 'Alternativ',
    ];

    /** Dieselben Akzente wie `useSessionTypes.js`. */
    private const ACCENTS = [
        'easy_run' => 'success', 'progressive_run' => 'success', 'mobility' => 'success',
        'tempo_run' => 'warn', 'strength' => 'warn', 'core' => 'warn',
        'interval' => 'danger',
        'long_run' => 'info', 'back_to_back_long' => 'info', 'cross_training' => 'info',
        'race_prep' => 'accent', 'test_run' => 'accent', 'time_on_feet' => 'accent',
        'yard_simulation' => 'accent', 'night_run' => 'accent',
        'rest' => 'neutral',
    ];

    /** Dieselben Fotos wie `useMomentPhotos.js`. */
    private const PHOTOS = [
        'tempo_run' => 'street', 'interval' => 'street', 'progressive_run' => 'street', 'test_run' => 'street',
        'long_run' => 'long', 'back_to_back_long' => 'long', 'time_on_feet' => 'long', 'yard_simulation' => 'long',
        'race_prep' => 'race',
        'rest' => 'legs', 'strength' => 'legs', 'core' => 'legs', 'mobility' => 'legs',
    ];

    public function __construct(private TodayExtras $extras) {}

    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        $today = CarbonImmutable::today();
        $plan  = $user->activeTrainingPlan;
        $race  = $this->extras->raceCard($user);

        return [
            'v'            => 1,
            'generated_at' => now()->toIso8601String(),
            'base'         => rtrim(config('app.url'), '/'),
            'days'         => $plan ? $this->days($user, $plan, $today) : [],
            'suggestion'   => $this->suggestion($user, $today),
            'plan_start'   => ($s = $plan?->event?->plan_start_date) && $s->isAfter($today) ? $s->toDateString() : null,
            'race'         => $race ? [
                'name'   => $race['name'],
                'date'   => $race['date'],
                'target' => $race['target'],
                'open'   => $race['open'],
                'week'   => $race['progress']['week'] ?? null,
                'weeks'  => $race['progress']['weeks'] ?? null,
            ] : null,
            'week'         => $this->week($user, $plan, $today),
        ];
    }

    /** @return list<array{date: string, items: list<array<string, mixed>>}> */
    private function days(User $user, $plan, CarbonImmutable $today): array
    {
        return $this->sessions($user, $plan, $today, $today->addDays(self::DAYS))
            ->groupBy(fn ($s) => $s->planned_date->toDateString())
            ->map(fn ($group, $date) => [
                'date'  => $date,
                'items' => $group->map(fn ($s) => $this->item($s, $this->href($plan, $s)))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Die Einheiten eines Tages in derselben Form wie im Widget, auch ohne
     * aktiven Plan (eingeplante Builder-Workouts). Für die Live Activity.
     *
     * @return list<array<string, mixed>>
     */
    public function itemsOn(User $user, CarbonImmutable $day): array
    {
        $plan = $user->activeTrainingPlan;

        return $this->sessions($user, $plan, $day, $day->addDay())
            ->map(fn ($s) => $this->item($s, $this->href($plan, $s)))
            ->values()
            ->all();
    }

    private function sessions(User $user, $plan, CarbonImmutable $from, CarbonImmutable $until)
    {
        return TrainingSession::with('activity')
            ->where(fn ($q) => $q
                ->when($plan, fn ($q) => $q->where('training_plan_id', $plan->id))
                ->orWhere(fn ($q) => $q->whereNull('training_plan_id')->where('user_id', $user->id)))
            ->whereDate('planned_date', '>=', $from->toDateString())
            ->whereDate('planned_date', '<', $until->toDateString())
            ->where('status', '!=', 'skipped')
            ->orderBy('planned_date')
            ->orderBy('sort_order')
            ->get();
    }

    private function href($plan, TrainingSession $s): string
    {
        if (! $s->training_plan_id || ! $plan) {
            return '/dashboard';
        }

        return ($plan->event_id ? "/events/{$plan->event_id}/plan" : '/calendar') . "?open={$s->id}";
    }

    /** @return array<string, mixed> */
    private function item(TrainingSession $s, string $url): array
    {
        $ride  = in_array($s->sport_type, TrainingSession::BIKE_SPORTS, true);
        $steps = is_array($s->steps) ? $s->steps : [];
        $act   = $s->status === 'completed' ? $s->activity : null;

        return [
            'id'     => $s->id,
            'activity_id' => $s->status === 'completed' ? $s->activity_id : null,
            'type'   => $s->type,
            'label'  => $ride ? 'Rad' : (self::LABELS[$s->type] ?? 'Training'),
            'accent' => self::ACCENTS[$s->type] ?? 'success',
            'photo'  => $ride ? 'field' : (self::PHOTOS[$s->type] ?? 'field'),
            'run'    => $s->isRun(),
            'title'  => $s->title,
            'km'     => $s->distance_km && ! $ride ? round((float) $s->distance_km, 1) : null,
            'min'    => $s->duration_min ? (int) $s->duration_min : null,
            'pace'   => $ride ? null : self::paceValue($s->pace_target, $steps),
            'zone'   => $s->type === 'rest' ? null : self::zoneSpan($steps, $s->zone),
            'band'   => self::band($steps),
            'status' => $s->status,
            'actual' => $act ? [
                'km'   => $act->distance ? round($act->distance / 1000, 1) : null,
                'pace' => $act->average_speed > 0 && ! $ride ? PaceFormat::fromSpeed((float) $act->average_speed) : null,
                'min'  => $act->moving_time ? (int) round($act->moving_time / 60) : null,
            ] : null,
            'url'    => $url,
        ];
    }

    /**
     * Der Tagesvorschlag des Coaches, wenn heute keine Planeinheit ansteht
     * (ohne Plan oder vor dem Planstart). Nur der gespeicherte — das Widget
     * löst nie einen Modellaufruf aus.
     *
     * @return array<string, mixed>|null
     */
    /** Der gespeicherte Tagesvorschlag von heute (Live Activity), ohne Modellaufruf. */
    public function suggestionFor(User $user, CarbonImmutable $day): ?array
    {
        return $this->suggestion($user, $day);
    }

    private function suggestion(User $user, CarbonImmutable $today): ?array
    {
        $profile = $user->runnerProfile;
        if (! $profile || $profile->recommendation_date != $today->toDateString() || empty($profile->today_recommendation)) {
            return null;
        }

        $rec = json_decode($profile->today_recommendation, true);
        if (! is_array($rec) || ($rec['type'] ?? 'rest') === 'rest') {
            return null;
        }

        $rec   = app(AIController::class)->withStructure($rec, $user);
        $steps = is_array($rec['steps'] ?? null) ? $rec['steps'] : [];
        $type  = $rec['type'];

        return [
            'date'   => $today->toDateString(),
            'id'     => null,
            'type'   => $type,
            'label'  => self::LABELS[$type] ?? 'Training',
            'accent' => self::ACCENTS[$type] ?? 'success',
            'photo'  => self::PHOTOS[$type] ?? 'field',
            'run'    => in_array($type, TrainingSession::RUN_TYPES, true),
            'title'  => $rec['title'] ?? 'Vorschlag',
            'km'     => ! empty($rec['distance_km']) ? round((float) $rec['distance_km'], 1) : null,
            'min'    => ! empty($rec['duration_min']) ? (int) $rec['duration_min'] : null,
            'pace'   => self::paceValue($rec['pace_target'] ?? null, $steps),
            'zone'   => self::zoneSpan($steps, $rec['zone'] ?? null),
            'band'   => self::band($steps),
            'status' => 'suggested',
            'actual' => null,
            'url'    => '/dashboard',
        ];
    }

    /** Gelaufene gegen geplante Lauf-Kilometer, Montag bis Sonntag. */
    private function week(User $user, $plan, CarbonImmutable $today): array
    {
        $monday = $today->startOfWeek();
        $sunday = $monday->endOfWeek();

        $run = (float) Activity::where('user_id', $user->id)
            ->whereIn('type', TrainingSession::RUN_SPORTS)
            ->whereBetween('start_date', [$monday->startOfDay(), $sunday->endOfDay()])
            ->sum('distance') / 1000;

        $planned = $plan ? (float) TrainingSession::where('training_plan_id', $plan->id)
            ->runsOnly()
            ->whereDate('planned_date', '>=', $monday->toDateString())
            ->whereDate('planned_date', '<=', $sunday->toDateString())
            ->where('status', '!=', 'skipped')
            ->sum('distance_km') : 0.0;

        return [
            'monday'     => $monday->toDateString(),
            'run_km'     => round($run, 1),
            'planned_km' => $planned > 0 ? round($planned, 1) : null,
        ];
    }

    /** Der nackte Pace-Wert ohne „/km“, sonst die Pace des härtesten Abschnitts. */
    public static function paceValue(?string $paceTarget, array $steps = []): ?string
    {
        $work = collect($steps)->filter(fn ($s) => ($s['type'] ?? null) === 'work' && ! empty($s['pace_target']))
            ->sortByDesc(fn ($s) => (int) ($s['zone'] ?? 0))
            ->first();
        $raw = $work['pace_target'] ?? $paceTarget;
        if (! $raw || $raw === 'null') {
            return null;
        }

        $first = trim(explode(';', (string) $raw)[0]);
        $value = trim(preg_replace('/\s*(min)?\s*\/\s*km\.?\s*$/i', '', $first));

        return $value !== '' ? $value : null;
    }

    /** „4“ oder „2–4“ aus den Belastungsabschnitten, wie `zoneSpan()` in `useSessionTypes.js`. */
    public static function zoneSpan(array $steps, $fallback = null): ?string
    {
        $zones = collect($steps)
            ->filter(fn ($s) => ($s['type'] ?? null) === 'work' && (int) ($s['zone'] ?? 0) > 0)
            ->map(fn ($s) => (int) $s['zone']);

        if ($zones->isEmpty()) {
            return $fallback ? (string) $fallback : null;
        }

        return $zones->min() === $zones->max() ? (string) $zones->min() : $zones->min() . '–' . $zones->max();
    }

    /**
     * Der Ablauf als Band, wie `stepBand()` in `useSessionTypes.js`: jeder
     * Abschnitt so breit, wie er dauert, Wiederholungen ausgeschrieben,
     * Strecken mit 5:00/km als Zeit.
     *
     * @return list<array{f: float, z: int, w: bool}>
     */
    public static function band(array $steps): array
    {
        $minutes = fn ($s) => (float) ($s['duration_min'] ?? 0)
            ?: ((float) ($s['distance_m'] ?? 0) ? (float) $s['distance_m'] / 1000 * 5 : 1.0);

        $steps = array_values($steps);
        $out   = [];
        for ($i = 0; $i < count($steps); $i++) {
            $s     = $steps[$i];
            $reps  = (int) ($s['repetitions'] ?? 1) > 1 ? (int) $s['repetitions'] : 1;
            $next  = $steps[$i + 1] ?? null;
            $pause = ($s['type'] ?? null) === 'work' && ($next['type'] ?? null) === 'rest' ? $next : null;

            for ($r = 0; $r < $reps; $r++) {
                $out[] = ['f' => round($minutes($s), 2), 'z' => (int) ($s['zone'] ?? 0), 'w' => ($s['type'] ?? null) === 'work'];
                if ($pause && ($reps > 1 ? $r < $reps - 1 || (int) ($pause['repetitions'] ?? 1) >= $reps : true)) {
                    $out[] = ['f' => round($minutes($pause), 2), 'z' => 0, 'w' => false];
                }
            }
            if ($pause) {
                $i++;
            }
        }

        // Ein Band mit hundert Streifen ist im Widget nur Rauschen.
        return count($out) > 40 ? [] : $out;
    }
}

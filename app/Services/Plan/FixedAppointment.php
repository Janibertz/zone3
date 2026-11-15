<?php

namespace App\Services\Plan;

use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Der feste Termin aus dem Wochenraster (Laufclub, Vereinstraining) — die
 * eine Regel, wann er an einem Tag gilt.
 *
 * Er fällt nur aus, wenn der Tag als „keine Zeit" gesperrt ist (Deine Woche,
 * Ruhetag beim Coach, Pause). Eine andere Dauer an dem Tag lässt ihn stehen
 * (Jan, 15.11.2026: „wenn ich die Dauer ändere, bleibt es beim Laufclub,
 * außer ich sage es ausdrücklich dem Coach"). Vorher strich jede Ausnahme
 * an einem Tag den Termin, auch eine, die dem Raster glich.
 */
class FixedAppointment
{
    /**
     * @param  array<string, mixed>  $weekly     runner_profiles.weekly_availability
     * @param  array<string, mixed>  $overrides  training_plans.availability_overrides
     * @return array{type: string, label: string}|null
     */
    public static function on(array $weekly, array $overrides, CarbonInterface $date): ?array
    {
        $day   = $weekly[strtolower($date->englishDayOfWeek)] ?? null;
        $fixed = is_array($day) ? ($day['fixed'] ?? null) : null;
        if (! is_array($fixed) || ! ($day['available'] ?? true)) {
            return null;
        }

        $override = $overrides[$date->toDateString()] ?? null;
        if (is_array($override) && ! ($override['available'] ?? true)) {
            return null;
        }

        return [
            'type'  => $fixed['type'] ?? 'interval',
            'label' => $fixed['label'] ?? 'Fester Termin',
        ];
    }

    /** Für einen Athleten, mit den Ausnahmen seines aktiven Plans. */
    public static function forUser(User $user, CarbonInterface $date): ?array
    {
        return self::on(
            $user->runnerProfile?->weekly_availability ?? [],
            $user->activeTrainingPlan?->availability_overrides ?? [],
            $date,
        );
    }

    /**
     * Die Termine, die noch kein Plan trägt: ohne aktiven Plan oder vor
     * seinem Start (Jan, 15.11.2026: Plan ab 30.11., der Laufclub am
     * Dienstag davor stand nirgends). Ab dem Planstart legt das Gerüst den
     * Termin als echte Einheit an. Nur ab heute, und nicht an einem Tag, an
     * dem schon ein Lauf oder ein Ruhetag steht.
     *
     * @return list<array<string, mixed>>  in der Form der Kalender-Einheiten
     */
    public static function unplanned(User $user, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $weekly = $user->runnerProfile?->weekly_availability ?? [];
        if (! collect($weekly)->contains(fn ($d) => is_array($d) && ! empty($d['fixed']))) {
            return [];
        }

        $plan  = $user->activeTrainingPlan;
        $start = $plan
            ? (($s = $plan->event?->plan_start_date) ? CarbonImmutable::parse($s)->startOfDay() : null)
            : $until->addDay();
        if ($plan && ! $start) {
            return [];
        }

        $from = $from->max(CarbonImmutable::today());
        $last = $until->min($start->subDay());
        if ($last->lt($from)) {
            return [];
        }

        $taken = TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', '>=', $from->toDateString())
            ->whereDate('planned_date', '<=', $last->toDateString())
            ->where('status', '!=', 'skipped')
            ->get(['planned_date', 'type', 'sport_type'])
            ->filter(fn ($s) => $s->type === 'rest' || $s->isRun())
            ->map(fn ($s) => $s->planned_date->toDateString())
            ->flip();

        $overrides = $plan?->availability_overrides ?? [];
        $out = [];
        for ($d = $from; $d->lte($last); $d = $d->addDay()) {
            $key = $d->toDateString();
            if (isset($taken[$key]) || ! ($fixed = self::on($weekly, $overrides, $d))) {
                continue;
            }

            $out[] = [
                'id'           => 'fixed-' . $key,
                'planned_date' => $key,
                'type'         => $fixed['type'],
                'title'        => $fixed['label'],
                'description'  => 'Fester Termin aus deiner Verfügbarkeit. Den Ablauf gibt der Termin vor.',
                'distance_km'  => null,
                'duration_min' => null,
                'pace_target'  => null,
                'zone'         => null,
                'intensity'    => null,
                'status'       => 'appointment',
                'skip_reason'  => null,
                'rating'       => null,
                'event_id'     => null,
                'activity_id'  => null,
                'sport_type'   => null,
                'steps'        => null,
            ];
        }

        return $out;
    }
}

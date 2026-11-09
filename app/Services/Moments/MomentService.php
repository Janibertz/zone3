<?php

namespace App\Services\Moments;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\PaceFormat;
use App\Services\PeriodizationService;
use Carbon\CarbonImmutable;

/**
 * Die Momente mit Foto auf „Heute“ (seit 09.11.2026, Mockups 3 und 4 aus dem
 * Canvas „Zone3 Momente mit Emotion“): „Woche geschafft“ und „Rennen
 * gelaufen“. Beide lesen nur, was schon in der Datenbank steht — kein
 * Modellaufruf, keine Schätzung. Gesehen wird am Konto gemerkt
 * (`users.moment_week_seen`, `users.moment_race_seen`), damit ein Moment auf
 * dem iPhone und am Desktop nur einmal kommt.
 */
class MomentService
{
    /** Ab so vielen Planeinheiten ist eine Woche eine Feier wert. */
    public const MIN_SESSIONS = 2;

    /** Wie lange nach dem Renntag der Moment noch erscheint. */
    public const RACE_DAYS = 7;

    /** Ein Lauf am Renntag zählt als das Rennen ab diesem Anteil der Distanz. */
    public const RACE_DISTANCE_SHARE = 0.9;

    /** Bis zu so vielen Wochen zurück wird die Serie gezählt. */
    private const STREAK_LIMIT = 26;

    /**
     * Die letzte Woche (Mo–So), wenn jede Planeinheit darin erledigt ist.
     *
     * @return array{week_start: string, done: int, total: int, km: float, streak: int,
     *               days: list<array{date: string, type: ?string, done: bool}>,
     *               next: ?array{label: string, recovery: bool, weeks_to_race: int, race: string}}|null
     */
    public function weekDone(User $user, ?CarbonImmutable $today = null): ?array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $start = $today->startOfWeek()->subWeek();

        if ($user->moment_week_seen && $user->moment_week_seen->toDateString() === $start->toDateString()) {
            return null;
        }

        $sessions = $this->planSessions($user, $start);
        $total    = $sessions->count();
        $done     = $sessions->where('status', 'completed')->count();
        if ($total < self::MIN_SESSIONS || $done < $total) {
            return null;
        }

        $days = [];
        for ($d = $start; $d->lte($start->endOfWeek()); $d = $d->addDay()) {
            $onDay = $sessions->filter(fn ($s) => $s->planned_date->toDateString() === $d->toDateString());
            // Der Punkt trägt die Farbe des Laufs, sonst der ersten Einheit.
            $main = $onDay->first(fn ($s) => in_array($s->type, TrainingSession::RUN_TYPES, true)) ?? $onDay->first();
            $days[] = ['date' => $d->toDateString(), 'type' => $main?->type, 'done' => (bool) $main];
        }

        $meters = Activity::where('user_id', $user->id)
            ->whereIn('type', TrainingSession::RUN_SPORTS)
            ->whereBetween('start_date', [$start->toDateTimeString(), $start->endOfWeek()->toDateTimeString()])
            ->sum('distance');

        return [
            'week_start' => $start->toDateString(),
            'done'       => $done,
            'total'      => $total,
            'km'         => round($meters / 1000, 1),
            'streak'     => $this->streak($user, $start),
            'days'       => $days,
            'next'       => $this->nextWeek($user, $today),
        ];
    }

    /**
     * Ein Rennen der letzten sieben Tage mit seiner Zeit — sobald der Lauf
     * vom Renntag da ist. Die Zeit ist die Uhrzeit (`elapsed_time`), nicht
     * die Bewegungszeit: im Rennen zählt auch der Stau am Verpflegungspunkt.
     *
     * @return array{event_id: int, name: string, distance: string, date: string, seconds: int,
     *               target_seconds: ?int, pace: ?string, activity_id: int}|null
     */
    public function raceRun(User $user, ?CarbonImmutable $today = null): ?array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        $events = Event::where('user_id', $user->id)
            ->whereDate('event_date', '<=', $today->toDateString())
            ->whereDate('event_date', '>=', $today->subDays(self::RACE_DAYS)->toDateString())
            ->orderByDesc('event_date')
            ->get();

        foreach ($events as $event) {
            if ($event->isBackyard() || (int) $user->moment_race_seen === $event->id) {
                continue;
            }
            $run = $this->raceActivity($user, $event);
            if (! $run) {
                continue;
            }

            $seconds = (int) ($run->elapsed_time ?: $run->moving_time);
            $km      = $event->race_km ?: $run->distance / 1000;

            return [
                'event_id'       => $event->id,
                'name'           => $event->name,
                'distance'       => $event->distance_label,
                'date'           => $event->event_date->toDateString(),
                'seconds'        => $seconds,
                'target_seconds' => $event->target_minutes ? $event->target_minutes * 60 : null,
                'pace'           => $km > 0 ? PaceFormat::fromSeconds($seconds / $km) : null,
                'activity_id'    => $run->id,
            ];
        }

        return null;
    }

    /**
     * Der Lauf des Rennens: zuerst der, an dem die Renneinheit hängt, sonst
     * der längste Lauf des Tages, wenn er die Renndistanz fast erreicht.
     * Eine lockere Runde am Renntag ist kein Rennen.
     */
    private function raceActivity(User $user, Event $event): ?Activity
    {
        $date = $event->event_date->toDateString();

        $linked = TrainingSession::where('user_id', $user->id)
            ->where('type', 'race_prep')
            ->whereDate('planned_date', $date)
            ->whereNotNull('activity_id')
            ->value('activity_id');
        if ($linked && ($a = Activity::find($linked))) {
            return $a;
        }

        $km = $event->race_km;
        if (! $km) {
            return null;
        }

        return Activity::where('user_id', $user->id)
            ->whereIn('type', TrainingSession::RUN_SPORTS)
            ->whereDate('start_date', $date)
            ->where('distance', '>=', $km * 1000 * self::RACE_DISTANCE_SHARE)
            ->orderByDesc('distance')
            ->first();
    }

    /** Planeinheiten einer Woche ohne Ruhetage. */
    private function planSessions(User $user, CarbonImmutable $start)
    {
        return TrainingSession::where('user_id', $user->id)
            ->whereNotNull('training_plan_id')
            ->where('type', '!=', 'rest')
            ->whereDate('planned_date', '>=', $start->toDateString())
            ->whereDate('planned_date', '<=', $start->endOfWeek()->toDateString())
            ->get();
    }

    /** Wie viele Wochen am Stück bis einschließlich `$start` alles erledigt war. */
    private function streak(User $user, CarbonImmutable $start): int
    {
        $streak = 0;
        for ($w = $start; $streak < self::STREAK_LIMIT; $w = $w->subWeek()) {
            $sessions = $this->planSessions($user, $w);
            if ($sessions->isEmpty() || $sessions->where('status', '!=', 'completed')->isNotEmpty()) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /** Was diese Woche kommt — Phase und ob sie entlastet. */
    private function nextWeek(User $user, CarbonImmutable $today): ?array
    {
        $plan = TrainingPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('event', fn ($q) => $q->whereDate('event_date', '>=', $today->toDateString()))
            ->latest()
            ->first();
        if (! $plan) {
            return null;
        }

        $week = app(PeriodizationService::class)->forWeek($plan->event, $today);

        return [
            'label'         => $week['label'],
            'recovery'      => $week['recovery'],
            'weeks_to_race' => $week['weeks_to_race'],
            'race'          => $plan->event->name,
        ];
    }
}

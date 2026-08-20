<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Sammelt den Kontext für die Planerstellung.
 *
 * Beide Jobs — die erste Erstellung und die Neuberechnung — hatten diese
 * ~150 Zeilen jeweils als eigene Kopie. Die Kopien waren bereits
 * auseinandergelaufen: einer lud die Aktivitäten mit allen Spalten inklusive
 * Streckenverlauf, der andere nur die Zusammenfassung. Solche Unterschiede
 * fallen niemandem auf, solange es zwei Stellen gibt.
 */
class PlanContextBuilder
{
    public function __construct(
        private readonly TrainingLoadService $trainingLoad,
        private readonly WeeklyPatternService $pattern,
        private readonly GarminHealthSummary $garmin,
    ) {}

    public function build(User $user, Event $event, array $availabilityOverrides = []): PlanContext
    {
        $windowFrom = CarbonImmutable::today();
        $windowTo   = $windowFrom->addDays(min(Event::PLAN_HORIZON_DAYS, $event->days_until + 1) - 1);

        $finalized = $this->finalizedSessions($user, $event);

        $context = new PlanContext(
            event:                 $event,
            windowFrom:            $windowFrom,
            windowTo:              $windowTo,
            profile:               $this->profile($user),
            recentActivities:      $this->recentActivities($user),
            wellbeing:             $this->wellbeing($user),
            sessionRatings:        $this->sessionRatings($user),
            weeklyAvailability:    $user->runnerProfile?->weekly_availability,
            availabilityOverrides: $availabilityOverrides,
            trainingLoad:          $this->trainingLoad->calculate($user->id),
            pastPlanResults:       $this->pastPlanResults($user),
            otherEvents:           $this->otherEvents($user, $event, $windowTo),
            finalizedSessions:     $finalized,
            followUpGoal:          $this->followUpGoal($user, $event),
            coachNotes:            $user->runnerProfile?->coach_notes,
        );

        // Gerüst und Garmin-Zusammenfassung bauen auf dem Rest auf.
        return $context->with(
            skeleton: $this->pattern->build(
                $event,
                $windowFrom,
                $windowTo,
                $context->weeklyAvailability,
                $availabilityOverrides,
                $context->finalizedDates(),
            ),
            garminText: empty($user->garmin_session)
                ? null
                : $this->garmin->toPromptSection($this->garmin->forUser($user->id, $windowFrom)),
        );
    }

    private function recentActivities(User $user): array
    {
        return $user->activities()
            ->where('start_date', '>=', now()->subWeeks(4))
            ->orderByDesc('start_date')
            ->limit(20)
            ->get(Activity::SUMMARY_COLUMNS)
            ->map(fn ($a) => [
                'date'         => $a->start_date?->format('Y-m-d') ?? '',
                'name'         => $a->name,
                'distance_km'  => round($a->distance / 1000, 2),
                'duration_min' => (int) round($a->moving_time / 60),
                'pace'         => $a->average_speed > 0 ? $this->formatPace($a->average_speed) : null,
                'avg_hr'       => $a->average_heartrate ? (int) $a->average_heartrate : null,
            ])
            ->toArray();
    }

    private function wellbeing(User $user): array
    {
        return $user->wellbeingEntries()
            ->where('date', '>=', now()->subDays(14)->toDateString())
            ->orderByDesc('date')
            ->limit(14)
            ->get()
            ->map(fn ($w) => [
                'date'       => $w->date->format('Y-m-d'),
                'energy'     => $w->energy_level,
                'sleep'      => $w->sleep_quality,
                'soreness'   => $w->muscle_soreness,
                'stress'     => $w->stress_level,
                'is_sick'    => $w->is_sick,
                'is_injured' => $w->is_injured,
            ])
            ->toArray();
    }

    private function profile(User $user): ?array
    {
        $rp = $user->runnerProfile;
        if (! $rp) {
            return null;
        }

        $pace = $rp->threshold_speed;
        $mins = (int) $pace;
        $secs = (int) (($pace - $mins) * 60);

        return [
            'threshold_pace' => sprintf('%d:%02d', $mins, $secs),
            'threshold_hr'   => $rp->threshold_heart_rate,
            'max_hr'         => $rp->max_heart_rate,
            'strength'       => [
                'enabled'       => (bool) $rp->strength_enabled,
                'days_per_week' => $rp->strength_days_per_week,
                'equipment'     => $rp->strength_equipment ?? [],
                'experience'    => $rp->strength_experience,
            ],
        ];
    }

    private function sessionRatings(User $user): array
    {
        return TrainingSession::where('user_id', $user->id)
            ->whereNotNull('rating')
            ->where('status', 'completed')
            ->orderByDesc('planned_date')
            ->limit(30)
            ->get()
            ->map(fn ($s) => [
                'date'             => $s->planned_date->format('Y-m-d'),
                'type'             => $s->type,
                'distance_km'      => $s->distance_km,
                'rating'           => $s->rating,
                'effort_perceived' => $s->effort_perceived,
                'feeling_notes'    => $s->feeling_notes,
            ])
            ->toArray();
    }

    private function pastPlanResults(User $user): array
    {
        return TrainingPlan::where('user_id', $user->id)
            ->whereNotNull('overall_rating')
            ->whereHas('event', fn ($q) => $q->where('event_date', '<', now()->toDateString()))
            ->with('event:id,name,race_distance,target_time_hours,target_time_minutes')
            ->latest()
            ->limit(3)
            ->get()
            ->map(fn ($p) => [
                'event_name'     => $p->event->name,
                'race_distance'  => $p->event->race_distance,
                'target_time'    => sprintf('%d:%02d', $p->event->target_time_hours, $p->event->target_time_minutes),
                'actual_time'    => $p->actual_time_hours !== null
                    ? sprintf('%d:%02d', $p->actual_time_hours, $p->actual_time_minutes)
                    : null,
                'overall_rating' => $p->overall_rating,
                'result_notes'   => $p->result_notes,
            ])
            ->toArray();
    }

    /**
     * Andere Rennen im Planungsfenster — an diesen Tagen wird nicht trainiert.
     *
     * Das Fenster reichte hier fest über zehn Tage, obwohl geplant wird über
     * {@see Event::PLAN_HORIZON_DAYS} (14). Rennen am elften bis vierzehnten
     * Tag tauchten damit nicht auf, und der Athlet bekam dort Training.
     */
    private function otherEvents(User $user, Event $event, CarbonImmutable $windowTo): array
    {
        return Event::where('user_id', $user->id)
            ->where('id', '!=', $event->id)
            ->where('event_date', '>=', now()->toDateString())
            ->where('event_date', '<=', $windowTo->toDateString())
            ->get()
            ->map(fn ($e) => [
                'date'     => $e->event_date->format('Y-m-d'),
                'name'     => $e->name,
                'distance' => $e->distance_label,
                'priority' => $e->priority,
            ])
            ->toArray();
    }

    /**
     * Das nächste A-/B-Rennen nach diesem Event. Damit weiß der Coach, welche
     * Fähigkeit er über diesen Block hinweg erhalten muss.
     */
    private function followUpGoal(User $user, Event $event): ?array
    {
        $next = Event::where('user_id', $user->id)
            ->where('id', '!=', $event->id)
            ->whereDate('event_date', '>', $event->event_date->toDateString())
            ->whereIn('priority', ['A', 'B'])
            ->orderBy('event_date')
            ->first();

        return $next ? [
            'date'     => $next->event_date->format('Y-m-d'),
            'name'     => $next->name,
            'distance' => $next->distance_label,
            'priority' => $next->priority,
        ] : null;
    }

    /**
     * Abgeschlossene und abgesagte Einheiten: die künftigen als Sperre für
     * den Planer, die vergangenen sieben Tage als Begründungskontext
     * (Krankheit, Verletzung, Erschöpfung).
     */
    private function finalizedSessions(User $user, Event $event): array
    {
        $planIds = TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->pluck('id');

        $future = TrainingSession::whereIn('training_plan_id', $planIds)
            ->whereIn('status', ['skipped', 'completed'])
            ->where('planned_date', '>=', now()->toDateString())
            ->get()
            ->map(fn ($s) => [
                'date'        => $s->planned_date->format('Y-m-d'),
                'type'        => $s->type,
                'status'      => $s->status,
                'skip_reason' => $s->skip_reason,
            ]);

        $pastSkipped = TrainingSession::where('user_id', $user->id)
            ->where('status', 'skipped')
            ->where('planned_date', '>=', now()->subDays(7)->toDateString())
            ->where('planned_date', '<', now()->toDateString())
            ->get()
            ->map(fn ($s) => [
                'date'        => $s->planned_date->format('Y-m-d'),
                'type'        => $s->type,
                'status'      => 'skipped',
                'skip_reason' => $s->skip_reason,
            ]);

        return $pastSkipped->concat($future)->values()->toArray();
    }

    private function formatPace(float $metersPerSecond): string
    {
        $secondsPerKm = 1000 / $metersPerSecond;

        return sprintf('%d:%02d', (int) ($secondsPerKm / 60), (int) ($secondsPerKm % 60));
    }
}

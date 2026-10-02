<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use App\Services\PaceFormat;

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
        private readonly PlanInterviewService $interviews,
        private readonly GarminHealthSummary $garmin,
        private readonly ReturnToRunService $returnToRun,
        private readonly TrainingPaceService $paces,
        private readonly WeeklyVolumeService $volume,
        private readonly LongRunPlanService $longRuns,
    ) {}

    /**
     * Der längste verfügbare Tag der Woche. Er begrenzt, wie lang der lange
     * Lauf überhaupt werden kann — wer sonntags drei Stunden hat, kann eine
     * andere Leiter steigen als wer eine hat.
     */
    private function longestDayBudget(?array $availability): ?int
    {
        if (! $availability) {
            return null;
        }

        $minutes = collect($availability)
            ->filter(fn ($d) => ($d['available'] ?? false))
            ->map(fn ($d) => (int) ($d['duration_min'] ?? 0))
            ->max();

        return $minutes > 0 ? $minutes : null;
    }

    public function build(User $user, Event $event, array $availabilityOverrides = []): PlanContext
    {
        $windowFrom = CarbonImmutable::today();
        $windowTo   = $windowFrom->addDays(min(Event::PLAN_HORIZON_DAYS, $event->days_until + 1) - 1);

        $finalized = $this->finalizedSessions($user, $event);
        $pinned    = $this->pinnedSessions($user, $event);
        $wellbeing = $this->wellbeing($user);

        // Ob der Athlet gerade wieder einsteigt, entscheidet eine Stelle für
        // alle: das Gerüst legt danach die Einheiten fest, und der Prompt
        // beschreibt dieselbe Stufe. Vorher hatte jede Seite ihre eigene
        // Erkennung — und beide widersprachen sich im selben Prompt.
        $comeback = $this->returnToRun->forPlan($user, $wellbeing, $finalized);

        $availability = $user->runnerProfile?->weekly_availability;
        $paces        = $this->paces->forEvent($event, $user->runnerProfile?->threshold_speed);
        $interview    = $this->interviews->forPlan($user);
        $volume       = $this->applyInterviewToVolume(
            $this->volume->forUser($user->id, $windowFrom),
            $interview,
        );

        // Die langen Läufe stehen vor dem Wochengerüst fest — sie sind der
        // Grund, warum der Plan überhaupt so aussieht, wie er aussieht, und
        // nicht das Ergebnis dessen, was am Sonntag noch übrig ist.
        $longRuns = $this->longRuns->forEvent(
            $event,
            $volume,
            $paces['long_sec'] ?? null,
            $this->longestDayBudget($availability),
            $windowFrom,
        );

        $context = new PlanContext(
            event:                 $event,
            windowFrom:            $windowFrom,
            windowTo:              $windowTo,
            profile:               $this->profile($user),
            recentActivities:      $this->recentActivities($user),
            wellbeing:             $wellbeing,
            sessionRatings:        $this->sessionRatings($user),
            weeklyAvailability:    $availability,
            availabilityOverrides: $availabilityOverrides,
            trainingLoad:          $this->trainingLoad->calculate($user->id),
            pastPlanResults:       $this->pastPlanResults($user),
            otherEvents:           $this->otherEvents($user, $event, $windowTo),
            // Das Gespraech vor dem Block. Die strukturierten Folgen
            // (Wochenraster, Rennbilanz) hat `PlanInterviewService::apply()`
            // schon geschrieben — hier kommt nur noch, womit das Geruest und
            // der Prompt rechnen: Ausrichtung und Umfangsfaktor.
            interview:             $interview,
            finalizedSessions:     $finalized,
            followUpGoal:          $this->followUpGoal($user, $event),
            coachNotes:            $user->runnerProfile?->coach_notes,
            comeback:              $comeback,
            pinnedSessions:        $pinned,
            crossTraining:         $this->crossTraining($user),
            paces:                 $paces,
            volume:                $volume,
            longRuns:              $longRuns,
        );

        // Gerüst und Garmin-Zusammenfassung bauen auf dem Rest auf.
        return $context->with(
            skeleton: $this->pattern->build(
                $event,
                $windowFrom,
                $windowTo,
                $availability,
                $availabilityOverrides,
                $context->blockedDates(),
                $comeback,
                $longRuns,
                // Der Umfangsdeckel gehoert ins Geruest, nicht nur in den
                // Prompt: sonst belegt das Geruest die Tage nach
                // Verfuegbarkeit und der Deckel daneben ist unerfuellbar.
                $volume,
                $paces['long_sec'] ?? null,
                // Rennen im Fenster gehören INS Gerüst, nicht nur in den
                // Prompt. Standen sie nur dort, lieferte das Modell den
                // verlangten Ruhetag — und der Validator ersetzte ihn durch
                // die Einheit, die im Gerüst stand. Am Renntag stand dann ein
                // Tempolauf.
                //
                // `races()` und nicht `otherEvents`: letzteres lässt das
                // Zielrennen dieses Plans aus, und genau dort stand zuletzt
                // ein „Langer Lauf" am Marathon-Renntag.
                $this->races($user, $event, $windowTo),
            ),
            garminText: empty($user->garmin_session)
                ? null
                : $this->garmin->toPromptSection($this->garmin->forUser($user->id, $windowFrom)),
        );
    }

    /**
     * Die letzten Läufe — und zwar nur Läufe.
     *
     * Vorher stand hier jede Aktivität: Radfahrten mit „Pace 2:28 min/km",
     * Spaziergänge mit 13:20 und GPS-Fehlstarts über 0,01 km. Aus dieser
     * Liste sollte das Modell die Form ablesen. Es sah einen Athleten, der
     * mal 2:28 und mal 13:20 pro Kilometer läuft.
     */
    private function recentActivities(User $user): array
    {
        return $user->activities()
            ->where('type', 'Run')
            ->where('distance', '>=', 1000)
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

    /**
     * Alles andere als Laufen, zusammengefasst. Es ersetzt keine Laufeinheit,
     * kostet aber Zeit und Erholung — der Coach soll es sehen, ohne es für
     * Lauftempo zu halten.
     *
     * @return array<string, array{count: int, minutes: int}>
     */
    private function crossTraining(User $user): array
    {
        return $user->activities()
            ->where('type', '!=', 'Run')
            ->where('start_date', '>=', now()->subWeeks(4))
            ->where('moving_time', '>=', 600)
            ->get(['id', 'type', 'moving_time'])
            ->groupBy('type')
            ->map(fn ($rows) => [
                'count'   => $rows->count(),
                'minutes' => (int) round($rows->sum('moving_time') / 60),
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

        return [
            'threshold_pace' => PaceFormat::fromMinutes($rp->threshold_speed),
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

    /**
     * Bewertete Einheiten. Solche über wenige Meter sind derselbe GPS-Müll
     * wie oben — als „easy_run 0.01 km ⭐⭐⭐⭐⭐" lehrte das Modell bisher,
     * dass Mini-Einheiten hervorragend ankommen.
     */
    private function sessionRatings(User $user): array
    {
        return TrainingSession::where('user_id', $user->id)
            ->whereNotNull('rating')
            ->where('status', 'completed')
            ->where(fn ($q) => $q->where('distance_km', '>=', 1)->orWhereNull('distance_km'))
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
                // Die Zahl, nicht das Etikett: das Gerüst zieht die Kilometer
                // des Rennens vom Wochenumfang ab.
                'km'       => $e->race_km,
                'priority' => $e->priority,
            ])
            ->toArray();
    }

    /**
     * Den Umfangsdeckel um das korrigieren, was der Athlet erzaehlt hat.
     *
     * `WeeklyVolumeService` schreibt die letzten Wochen fort: Durchschnitt
     * plus 10 %. Das ist richtig, solange die vergangenen Wochen gelaufen
     * WURDEN. Wer sagt „ich hatte keine Zeit" oder „die Beine machten nicht
     * mit", bekommt sonst denselben Umfang noch einmal vorgelegt, der schon
     * beim letzten Mal nicht in die Woche passte — und die Ausfallquote
     * bleibt, wo sie war.
     *
     * Korrigiert wird HIER und nicht im Geruest, weil derselbe `$volume`
     * danach an zwei Stellen geht: ins Geruest (bindend) und in den
     * Prompt-Abschnitt (erklaerend). Zwei getrennte Korrekturen waeren die
     * doppelte Wahrheit, die dieses Projekt schon mehrfach bezahlt hat.
     *
     * @param  array<string, mixed>       $volume
     * @param  array<string, mixed>|null  $interview
     * @return array<string, mixed>
     */
    private function applyInterviewToVolume(array $volume, ?array $interview): array
    {
        $factor = (float) ($interview['volume_factor'] ?? 1.0);

        if ($factor >= 1.0 || empty($volume['next_week_max'])) {
            return $volume;
        }

        $volume['next_week_max']    = round($volume['next_week_max'] * $factor, 1);
        $volume['reduced_by']       = $interview['skip_label'] ?? null;
        $volume['reduced_factor']   = $factor;

        return $volume;
    }

    /**
     * ALLE Rennen im Planungsfenster — auch das Zielrennen dieses Plans.
     *
     * `otherEvents()` daneben liefert bewusst nur die anderen: es fuettert
     * einen Prompt-Abschnitt, der die Nebenrennen benennt. Das Geruest
     * braucht dagegen jeden Renntag, und genau hier fehlte einer.
     *
     * `otherEvents()` schliesst das Zielevent per `id != $event->id` aus.
     * Als die Nebenrennen ins Geruest wanderten, erbte es diesen Ausschluss —
     * und am Renntag des Berlin Marathons stand weiterhin „Langer Lauf,
     * 9 km, Tempo bewusst zurueckhalten". Der ganze Taper davor lief auf ein
     * Rennen zu, das im Plan nicht vorkam.
     *
     * @return array<string, array<string, mixed>>  Datum => Renndaten
     */
    private function races(User $user, Event $event, CarbonImmutable $windowTo): array
    {
        return Event::where('user_id', $user->id)
            ->whereDate('event_date', '>=', now()->toDateString())
            ->whereDate('event_date', '<=', $windowTo->toDateString())
            ->orderBy('event_date')
            ->get()
            ->mapWithKeys(fn (Event $e) => [$e->event_date->format('Y-m-d') => [
                'date'       => $e->event_date->format('Y-m-d'),
                'name'       => $e->name,
                'km'         => $e->race_km,
                'priority'   => $e->priority,
                // Die eingetragene Zielzeit schlaegt jede Schaetzung aus der
                // Zonentabelle. Ist keine gesetzt (z. B. beim Wattlauf),
                // bleibt es bei der Schaetzung.
                'target_min' => $e->target_minutes,
                'target_pace' => $e->target_pace_seconds
                    ? PaceFormat::target($e->target_pace_seconds)
                    : null,
                'is_goal'    => $e->id === $event->id,
            ]])
            ->all();
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
    /**
     * Einheiten, die der Athlet selbst gesetzt hat und die deshalb stehen
     * bleiben. Fuer den Planer sind sie belegte Tage: er soll darum herum
     * planen, nicht daneben.
     */
    private function pinnedSessions(User $user, Event $event): array
    {
        return TrainingSession::where('user_id', $user->id)
            ->where('event_id', $event->id)
            ->whereNotNull('pinned_at')
            ->where('status', 'planned')
            ->where('planned_date', '>=', now()->toDateString())
            ->orderBy('planned_date')
            ->get()
            ->map(fn ($s) => [
                'date'         => $s->planned_date->format('Y-m-d'),
                'type'         => $s->type,
                'title'        => $s->title,
                'distance_km'  => $s->distance_km,
                'duration_min' => $s->duration_min,
            ])
            ->toArray();
    }

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

        return PaceFormat::fromSeconds($secondsPerKm);
    }
}

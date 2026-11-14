<?php

namespace App\Http\Controllers\Diagnose;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use App\Models\CoachMessage;
use App\Models\LiveActivity;
use App\Models\PlanRevision;
use App\Models\SessionRecommendation;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\LiveActivity\LiveActivityService;
use App\Services\LogReader;
use App\Services\Observability\Watchdog;
use App\Services\SystemHealth;
use App\Services\Widget\WidgetPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lesender Diagnose-Zugang für Claude (Jan, 14.11.2026: „Ein Diagnose-
 * Endpunkt für dich wäre nice“). Feste Berichte statt freier Abfragen,
 * nur GET, kein Modellaufruf, keine Schreibzugriffe. Bewusst nie drin:
 * E-Mail, Passwörter, Tokens jeder Art (Strava, Garmin, Push, Widget,
 * Kalender) — nur Vornamen und IDs.
 */
class DiagnoseController extends Controller
{
    public function system(SystemHealth $health, Watchdog $watchdog): JsonResponse
    {
        // Die Systemseite im Admin zeigt volle Namen; hier gelten nur
        // Vornamen, wie in allen anderen Berichten.
        return response()->json(self::firstNamesOnly([
            'now'            => now()->toIso8601String(),
            'summary'        => $health->summary(),
            'queues'         => $health->queues(),
            'failed_jobs'    => $health->failedJobs(),
            'plan_health'    => $health->planHealth(),
            'environment'    => $health->environment(),
            'watchdog'       => $watchdog->findings(),
            'live_activities'=> $health->liveActivities(),
            'strava_webhook' => $health->webhookHits(),
        ]));
    }

    /** Jeden vollen Namen eines Kontos im Bericht durch den Vornamen ersetzen. */
    private static function firstNamesOnly(array $report): array
    {
        $names = User::query()->whereNotNull('name')->get()
            ->mapWithKeys(fn (User $u) => [(string) $u->name => self::firstName($u)])
            ->filter(fn ($first, $full) => $first !== $full)
            ->all();

        if ($names === []) {
            return $report;
        }

        array_walk_recursive($report, function (&$value) use ($names) {
            if (is_string($value) && isset($names[$value])) {
                $value = $names[$value];
            }
        });

        return $report;
    }

    public function logs(Request $request, LogReader $reader): JsonResponse
    {
        return response()->json($reader->tail(
            search: $request->string('q')->toString() ?: null,
            level: $request->string('level')->toString() ?: null,
            limit: min(500, max(1, $request->integer('limit', 100))),
        ));
    }

    public function users(): JsonResponse
    {
        return response()->json(User::query()->orderBy('id')->get()->map(fn (User $u) => [
            'id'              => $u->id,
            'first_name'      => self::firstName($u),
            'is_admin'        => (bool) $u->is_admin,
            'waitlisted'      => (bool) $u->waitlisted_at,
            'activity_source' => $u->activitySource(),
            'active_plan_id'  => $u->activeTrainingPlan?->id,
            'last_activity'   => $u->activities()->max('start_date'),
            'created_at'      => $u->created_at?->toDateString(),
        ]));
    }

    public function user(Request $request, User $user, WidgetPayload $widget, LiveActivityService $live): JsonResponse
    {
        $day  = CarbonImmutable::parse($request->string('day')->toString() ?: 'today')->startOfDay();
        $from = $day->subDays(max(0, min(60, $request->integer('back', 3))));
        $to   = $day->addDays(max(0, min(60, $request->integer('ahead', 7))));
        $full = $request->boolean('full');

        $profile = $user->runnerProfile;
        $plan    = $user->activeTrainingPlan;

        $sessions = TrainingSession::query()
            ->where('user_id', $user->id)
            ->whereDate('planned_date', '>=', $from->toDateString())
            ->whereDate('planned_date', '<=', $to->toDateString())
            ->orderBy('planned_date')->orderBy('sort_order')
            ->get()
            ->map(fn (TrainingSession $s) => [
                'id'          => $s->id,
                'date'        => CarbonImmutable::parse($s->planned_date)->toDateString(),
                'plan_id'     => $s->training_plan_id,
                'type'        => $s->type,
                'sport_type'  => $s->sport_type,
                'title'       => $s->title,
                'status'      => $s->status,
                'pinned'      => (bool) $s->pinned_at,
                'variant'     => $s->workout_variant,
                'distance_km' => $s->distance_km,
                'duration_min'=> $s->duration_min,
                'pace_target' => $s->pace_target,
                'zone'        => $s->zone,
                'activity_id' => $s->activity_id,
                'steps'       => $full ? $s->steps : (is_array($s->steps) ? count($s->steps) . ' Abschnitte' : null),
            ]);

        return response()->json([
            'user' => ['id' => $user->id, 'first_name' => self::firstName($user)],
            'day'  => $day->toDateString(),
            'profile' => $profile ? [
                'vdot'                  => $profile->vdotInfo(),
                'threshold_speed'       => $profile->threshold_speed,
                'training_model'        => $profile->trainingModelOn($day),
                'weekly_availability'   => $profile->weekly_availability,
                'training_paused_until' => $profile->training_paused_until,
                'recommendation_date'   => $profile->recommendation_date,
                'today_recommendation'  => is_string($profile->today_recommendation) ? json_decode($profile->today_recommendation, true) : $profile->today_recommendation,
            ] : null,
            'plan' => $plan ? [
                'id'                     => $plan->id,
                'event_id'               => $plan->event_id,
                'event'                  => $plan->event?->only(['name', 'goal_type', 'event_date', 'race_distance', 'target_time', 'plan_start_date']),
                'availability_overrides' => $plan->availability_overrides,
                'generating_at'          => $plan->plan_generating_at ?? null,
            ] : null,
            'sessions'        => $sessions,
            'widget_items'    => $widget->itemsOn($user, $day),
            'suggestion'      => $widget->suggestionFor($user, $day),
            'live_activity'   => [
                'state'   => $live->state($user, $day),
                'start_at'=> $live->startAt($user, $day)->toIso8601String(),
                'rows'    => LiveActivity::where('user_id', $user->id)->latest('id')->limit(5)->get()
                    ->map->only(['activity_id', 'day', 'ended_at', 'last_event', 'last_result', 'last_push_at', 'created_at']),
            ],
            'wellbeing' => WellbeingEntry::where('user_id', $user->id)
                ->whereDate('date', '>=', $day->subDays(7)->toDateString())->orderBy('date')->get()
                ->map->only(['date', 'energy_level', 'mood', 'sleep_quality', 'muscle_soreness', 'stress_level', 'is_sick', 'notes']),
            'recommendations' => SessionRecommendation::where('user_id', $user->id)->latest('id')->limit(5)->get()
                ->map->only(['id', 'training_session_id', 'source', 'status', 'reason', 'before', 'after', 'created_at']),
            'coach_messages' => CoachMessage::where('user_id', $user->id)->latest('id')->limit(min(50, $request->integer('messages', 12)))->get()
                ->reverse()->values()
                ->map(fn ($m) => ['id' => $m->id, 'at' => $m->created_at?->toIso8601String(), 'role' => $m->role, 'channel' => $m->channel, 'kind' => $m->kind, 'content' => $m->content, 'actions' => $m->actions]),
            'plan_revisions' => PlanRevision::where('user_id', $user->id)->latest('id')->limit(5)->get()
                ->map->only(['id', 'triggered_by', 'corrections', 'created_at']),
            'ai_logs' => AiLog::where('user_id', $user->id)->latest('id')->limit(min(50, $request->integer('ai', 10)))->get()
                ->map(fn ($l) => array_filter([
                    'id'        => $l->id,
                    'at'        => $l->created_at?->toIso8601String(),
                    'call_type' => $l->call_type,
                    'model'     => $l->model,
                    'status'    => $l->status ?? null,
                    'cost_eur'  => $l->cost_eur ?? null,
                    'prompt'    => $full ? ($l->full_prompt ?? null) : null,
                    'response'  => $full ? ($l->full_response ?? null) : null,
                ], fn ($v) => $v !== null)),
        ]);
    }

    private static function firstName(User $u): string
    {
        return strtok((string) $u->name, ' ') ?: '—';
    }
}

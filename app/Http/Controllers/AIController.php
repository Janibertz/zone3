<?php

namespace App\Http\Controllers;

use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Services\AI\CoachingTextService;
use App\Services\ProgressService;
use App\Services\ReturnToRunService;
use App\Services\Run\SessionStructure;
use App\Services\WeatherService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\PaceFormat;

class AIController extends Controller
{
    public function __construct(
        protected CoachingTextService $text,
        protected ProgressService $progress,
        protected WeatherService $weather,
        protected ReturnToRunService $returnToRun,
        protected SessionStructure $structure,
    ) {}

    public function recommendToday(Request $request)
    {
        $user = $request->user();

        $runnerProfile = $user->runnerProfile;

        $yesterday = Carbon::yesterday()->toDateString();
        $yesterdayActivity = $user->activities()
            ->whereDate('start_date', $yesterday)
            ->orderByDesc('start_date')
            ->first();

        $today = Carbon::today()->toDateString();
        $todayWellbeing = $user->wellbeingEntries()
            ->whereDate('date', $today)
            ->first();

        $wellbeingExists = (bool) $todayWellbeing;

        // Only consider goals that haven't already ended (exclude past events)
        $activeGoal = $user->goals()
            ->where('active', true)
            ->where('end_date', '>=', $today)
            ->orderBy('end_date')
            ->first();

        $goalData = $activeGoal ? [
            'name' => $activeGoal->name,
            'target_value' => $activeGoal->target_value,
            'unit' => $activeGoal->unit,
            'start_date' => $activeGoal->start_date->toDateString(),
            'end_date' => $activeGoal->end_date->toDateString(),
        ] : null;

        $progressData = $activeGoal ? $this->progress->calculateProgress($activeGoal) : [
            'completed_distance_km' => 0,
            'target_distance_km' => 0,
            'progress_percentage' => 0,
            'status' => 'none',
            'days_remaining' => 0,
        ];

        // Upcoming events/races (today onwards, nearest first)
        $upcomingEvents = $user->events()
            ->where('event_date', '>=', $today)
            ->orderBy('event_date')
            ->limit(5)
            ->get()
            ->map(fn ($e) => [
                'name'           => $e->name,
                'event_date'     => $e->event_date->toDateString(),
                'days_until'     => (int) Carbon::today()->diffInDays($e->event_date, false),
                'distance_label' => $e->distance_label,
            ])
            ->toArray();

        $todayAvailability = $this->todayAvailability($user);

        $recommendation = null;
        $recommendationMessage = 'Bitte trage zunächst deine Tagesform für heute ein, um eine Trainingsempfehlung zu erhalten.';

        // Krank, verletzt oder in einer Pause: es gibt nichts zu empfehlen,
        // und das entscheidet keine KI. Das Modell bekam „krank" gar nicht zu
        // sehen und schrieb — vom Wiedereinstieg-Hinweis gedrängt — einen
        // lockeren Lauf für jemanden, der am Morgen operiert worden war.
        if ($rest = $this->restRecommendation($runnerProfile, $todayWellbeing, $todayAvailability)) {
            return response()->json([
                'recommendation'         => $rest,
                'recommendation_message' => null,
                'wellbeing_exists'       => $wellbeingExists,
            ]);
        }

        if ($wellbeingExists) {
            // Check cached recommendation (same day + same entry) — stored as JSON string
            if ($runnerProfile &&
                $runnerProfile->recommendation_date == $today &&
                $runnerProfile->recommendation_wellbeing_id == $todayWellbeing->id &&
                !empty($runnerProfile->today_recommendation))
            {
                $decoded = json_decode($runnerProfile->today_recommendation, true);
                if (is_array($decoded)) {
                    $recommendation = $decoded;
                }
            }

            if (! $recommendation) {
                $this->text->withCoach($user->coach?->personality_prompt)->forUser($user->id);
                $recommendation = $this->text->generateTodayRecommendation(
                    $runnerProfile ? [
                        'threshold_heart_rate' => $runnerProfile->threshold_heart_rate,
                        'max_heart_rate'       => $runnerProfile->max_heart_rate,
                        'threshold_speed'      => $this->formatPace($runnerProfile->threshold_speed),
                        'paces'                => $this->trainingPaces($runnerProfile),
                    ] : null,
                    $yesterdayActivity ? $yesterdayActivity->toArray() : null,
                    $todayWellbeing ? $todayWellbeing->toArray() : null,
                    $goalData,
                    $progressData,
                    $upcomingEvents,
                    $todayAvailability,
                    $this->weather->forUser($user),
                    $this->returnToRun->statusFor($user),
                    $user->planStartsLater()
                );

                if ($runnerProfile && $recommendation) {
                    $runnerProfile->today_recommendation = json_encode($recommendation, JSON_UNESCAPED_UNICODE);
                    $runnerProfile->recommendation_date = $today;
                    $runnerProfile->recommendation_wellbeing_id = $todayWellbeing->id;
                    $runnerProfile->save();
                }
            }

            $recommendationMessage = null;
        }

        // Auch auf den gespeicherten Vorschlag: der Katalog kostet nichts,
        // und ein Vorschlag von heute Morgen bekommt so die richtigen Zahlen.
        if ($recommendation) {
            $recommendation = $this->withStructure($recommendation, $user);
        }

        return response()->json([
            'recommendation' => $recommendation,
            'recommendation_message' => $recommendationMessage,
            'wellbeing_exists' => $wellbeingExists,
        ]);
    }

    /**
     * Der Ruhetag, wenn heute nicht gelaufen wird — oder null.
     *
     * @return array<string, mixed>|null
     */
    private function restRecommendation(?\App\Models\RunnerProfile $profile, ?\App\Models\WellbeingEntry $wellbeing, ?array $availability = null): ?array
    {
        if ($profile?->isPausedOn()) {
            $until = $profile->training_paused_until->format('d.m.');
            $why   = $profile->training_pause_reason ? " ({$profile->training_pause_reason})" : '';

            return $this->rest("Trainingspause bis {$until}{$why}", 'Erhol dich in Ruhe. Danach steigen wir behutsam wieder ein.');
        }

        if ($wellbeing?->is_sick || $wellbeing?->is_injured) {
            $what = $wellbeing->is_injured ? 'verletzt' : 'krank';

            return $this->rest('Heute kein Training', "Du hast dich als {$what} eingetragen. Ruhe ist heute das Training. Wenn du länger aussetzt, sag es dem Coach, dann pausiert er deinen Plan.");
        }

        // Laut Wochenraster (oder Ausnahme für heute) keine Zeit: dann gibt
        // es nichts vorzuschlagen — das Modell bekam den Hinweis zwar,
        // entschied aber selbst (10.11.2026, Jan: „soll die Verfügbarkeit
        // beachten").
        if ($availability !== null && ! $availability['available']) {
            return $this->rest('Heute frei', 'Laut deiner Verfügbarkeit hast du heute keine Zeit fürs Training. Genieß den Ruhetag.');
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function rest(string $title, string $description): array
    {
        return [
            'type'         => 'rest',
            'title'        => $title,
            'description'  => $description,
            'distance_km'  => 0,
            'duration_min' => 0,
            'pace_target'  => null,
            'zone'         => null,
            'intensity'    => 'low',
        ];
    }

    /**
     * Accept a recommendation and persist it as a standalone TrainingSession.
     */
    public function acceptRecommendation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'         => 'required|string|in:easy_run,tempo_run,interval,long_run,race_prep,rest',
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string|max:2000',
            'distance_km'  => 'nullable|numeric|min:0',
            'duration_min' => 'nullable|integer|min:0',
            'pace_target'  => 'nullable|string|max:20',
            'zone'         => 'nullable|integer|min:0|max:5',
            'intensity'    => 'nullable|string|in:low,medium,high',
            'workout_variant' => 'nullable|string|max:50',
            'steps'        => 'nullable|array',
        ]);

        $user  = $request->user();
        $today = Carbon::today()->toDateString();

        TrainingSession::updateOrCreate(
            [
                'user_id'          => $user->id,
                'planned_date'     => $today,
                'training_plan_id' => null,
                // Ein eingeplantes Builder-Workout ist gepinnt und bleibt
                // stehen; die Empfehlung kommt daneben.
                'pinned_at'        => null,
            ],
            array_merge($data, [
                'status'     => 'planned',
                'sort_order' => 0,
            ])
        );

        return response()->json(['success' => true]);
    }

    /**
     * Adjust a recommendation harder or softer via AI.
     */
    public function adjustRecommendation(Request $request): JsonResponse
    {
        $request->validate([
            'direction' => 'required|in:harder,softer',
            'current'   => 'required|array',
        ]);

        $user           = $request->user();
        $today          = Carbon::today()->toDateString();
        $runnerProfile  = $user->runnerProfile;
        $todayWellbeing = $user->wellbeingEntries()->whereDate('date', $today)->first();

        $this->text->withCoach($user->coach?->personality_prompt)->forUser($user->id);
        $adjusted = $this->text->adjustTodayRecommendation(
            // Der Ablauf kommt danach wieder aus dem Katalog.
            \Illuminate\Support\Arr::except($request->current, ['steps', 'workout_variant']),
            $request->direction,
            $runnerProfile ? [
                'threshold_heart_rate' => $runnerProfile->threshold_heart_rate,
                'max_heart_rate'       => $runnerProfile->max_heart_rate,
                'threshold_speed'      => $this->formatPace($runnerProfile->threshold_speed),
            ] : null,
            $todayWellbeing ? $todayWellbeing->toArray() : null
        );

        return response()->json(['recommendation' => $adjusted ? $this->withStructure($adjusted, $user) : null]);
    }

    /**
     * Return (or generate + cache) today's daily motivational message from the coach.
     */
    public function dailyMessage(Request $request): JsonResponse
    {
        $user          = $request->user();
        $runnerProfile = $user->runnerProfile;
        $today         = Carbon::today()->toDateString();

        // Return cached message if still fresh
        if ($runnerProfile &&
            $runnerProfile->daily_message_date == $today &&
            !empty($runnerProfile->daily_message))
        {
            return response()->json(['message' => $runnerProfile->daily_message]);
        }

        // Gather context for the message
        $todaySession = TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $today)
            ->where('status', 'planned')
            ->first();

        $upcomingEvents = $user->events()
            ->where('event_date', '>=', $today)
            ->orderBy('event_date')
            ->limit(3)
            ->get()
            ->map(fn ($e) => [
                'name'       => $e->name,
                'event_date' => $e->event_date->toDateString(),
                'days_until' => (int) Carbon::today()->diffInDays($e->event_date, false),
            ])
            ->toArray();

        $this->text->withCoach($user->coach?->personality_prompt)->forUser($user->id);
        $message = $this->text->generateDailyMessage(
            $todaySession?->type,
            $todaySession?->title,
            $upcomingEvents,
            $this->weather->forUser($user)
        );

        if ($runnerProfile && $message) {
            $runnerProfile->daily_message      = $message;
            $runnerProfile->daily_message_date = $today;
            $runnerProfile->save();
        }

        return response()->json(['message' => $message]);
    }

    /**
     * Dismiss the pending PR celebration so it no longer appears on the dashboard.
     */
    public function dismissPr(Request $request): JsonResponse
    {
        $profile = $request->user()->runnerProfile;
        if ($profile) {
            $profile->pending_pr_activity_id = null;
            $profile->pending_pr_message     = null;
            $profile->save();
        }
        return response()->json(['success' => true]);
    }

    /**
     * Ablauf, Dauer, Strecke und Pace des Vorschlags aus dem Katalog — wie im
     * Plan. Das Modell wählt Typ und Dauer; die Zahlen kommen aus dem VDOT.
     *
     * Gemeldet am 10.11.2026: „Lockerer Kältestart, 3 km in 30 min,
     * 9:00–10:00 /km" für einen Läufer mit Ziel HM unter 1:30. Das Modell
     * bekam keine Trainingstempi, nur die gemessene Schwelle (oft leer), und
     * Strecke und Dauer schrieb es unabhängig voneinander.
     *
     * @param  array<string, mixed>  $rec
     * @return array<string, mixed>
     */
    private function withStructure(array $rec, \App\Models\User $user): array
    {
        if (($rec['type'] ?? 'rest') === 'rest') {
            return $rec;
        }

        $availability = $this->todayAvailability($user);

        // Den festen Termin (Laufclub) gibt der Trainer vor — kein Ablauf,
        // keine erfundene Pace (wie im Plan, TrainingSession::isFixedAppointment()).
        if (! empty($availability['fixed']) && ($rec['type'] ?? null) === ($availability['fixed']['type'] ?? 'interval')) {
            return array_merge($rec, ['pace_target' => null, 'steps' => null]);
        }

        // Das Zeitfenster des Tages ist eine Obergrenze. Die Strecke rechnet
        // der Katalog danach aus der gekürzten Dauer.
        $budget = $availability['duration_min'] ?? null;
        if ($budget && (int) ($rec['duration_min'] ?? 0) > $budget) {
            $rec['duration_min'] = $budget;
            $rec['distance_km']  = 0;
        }

        $session = new TrainingSession([
            'type'         => $rec['type'],
            'title'        => $rec['title'] ?? '',
            'description'  => $rec['description'] ?? null,
            'duration_min' => (int) ($rec['duration_min'] ?? 0),
            'distance_km'  => (float) ($rec['distance_km'] ?? 0),
            'pace_target'  => $rec['pace_target'] ?? null,
            'planned_date' => Carbon::today(),
        ]);
        $session->user_id = $user->id;
        $session->setRelation('user', $user);

        if (! $this->structure->apply($session, $rec['workout_variant'] ?? null)) {
            return $rec;
        }

        return array_merge($rec, [
            'description'     => $session->description,
            'duration_min'    => $session->duration_min,
            'distance_km'     => $session->distance_km,
            'pace_target'     => $session->pace_target,
            'zone'            => $session->zone,
            'steps'           => $session->steps,
            'workout_variant' => $session->workout_variant,
        ]);
    }

    /**
     * Die Verfügbarkeit heute: die Ausnahme im aktiven Plan („Deine Woche"),
     * sonst das Wochenraster. Null, wenn nichts gepflegt ist.
     *
     * @return array{available: bool, duration_min: int|null, fixed: array|null}|null
     */
    private function todayAvailability(\App\Models\User $user): ?array
    {
        $today     = Carbon::today();
        $override  = $user->activeTrainingPlan?->availability_overrides[$today->toDateString()] ?? null;
        $weekly    = $user->runnerProfile?->weekly_availability;
        $day       = $weekly[strtolower($today->format('l'))] ?? null;

        if ($override !== null) {
            return [
                'available'    => (bool) ($override['available'] ?? true),
                'duration_min' => ((int) ($override['duration_min'] ?? 0)) ?: null,
                'fixed'        => null,
            ];
        }

        if ($day === null) {
            // Kein Raster gepflegt: keine bekannte Grenze, nicht gesperrt —
            // dieselbe Regel wie im Gerüst.
            return empty($weekly) ? null : ['available' => false, 'duration_min' => null, 'fixed' => null];
        }

        return [
            'available'    => (bool) ($day['available'] ?? false),
            'duration_min' => ((int) ($day['duration_min'] ?? 0)) ?: null,
            'fixed'        => $day['fixed'] ?? null,
        ];
    }

    /**
     * Die Trainingstempi aus dem VDOT — dieselben wie im Plan.
     *
     * @return array<string, string>|null
     */
    private function trainingPaces(\App\Models\RunnerProfile $profile): ?array
    {
        $vdot = $profile->vdotInfo();
        if (! $vdot) {
            return null;
        }

        $p = app(\App\Services\TrainingPaceService::class)->forEvent(new \App\Models\Event(), $vdot['value'], $vdot['label'] ?? null);

        return $p ? array_intersect_key($p, array_flip(['easy', 'threshold', 'interval'])) : null;
    }

    private function formatPace(?float $paceMinutes): ?string
    {
        if (! $paceMinutes) return null;

        return PaceFormat::fromMinutes($paceMinutes);
    }
}

<?php

namespace App\Services;

use App\Jobs\CalculateThresholdPaceJob;
use App\Jobs\GenerateSessionReviewJob;
use App\Jobs\RegeneratePlanJob;
use App\Models\Activity;
use App\Models\RunnerProfile;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Was mit einer Aktivität geschieht, egal woher sie kommt.
 *
 * Bis zum 28.10.2026 stand das alles in `StravaImportService`. Mit Garmin
 * als zweiter Quelle wäre es sonst in einer zweiten Fassung entstanden —
 * und zwei Fassungen derselben Zuordnung laufen auseinander. Hier liegt,
 * was nicht nach Strava fragt: eine Zeile anlegen oder an eine bestehende
 * hängen, die Zuordnung zur geplanten Einheit, Review, Push, Schwellenpace.
 * `StravaImportService` übersetzt nur noch Stravas Antwort.
 *
 * Alles hier ist wiederholbar. Strava stellt einen Webhook mehrfach zu,
 * wenn die Antwort auf sich warten lässt, und ein Job wird nach einem
 * Fehlschlag erneut versucht. Die Suche über die ID der Quelle, die
 * Prüfung auf ein bereits verknüpftes `activity_id` und das
 * `reviewed_at`-Kriterium tragen das: der zweite Durchlauf schreibt
 * dieselben Werte und legt nichts doppelt an.
 */
class ActivityImportService
{
    public function __construct(
        private readonly ActivityMatcher $matcher,
        private readonly WebPushService $webPush,
    ) {}

    /**
     * Eine Aktivität aus einer Quelle anlegen, auffrischen oder anhängen.
     *
     * 1. Gibt es die ID dieser Quelle schon, wird die Zeile aufgefrischt.
     * 2. Gibt es dieselbe Einheit aus der anderen Quelle (`ActivityMatcher`),
     *    bekommt diese Zeile die zweite ID — keine neue Zeile.
     * 3. Sonst wird sie angelegt.
     *
     * Wessen Zahlen gelten, sagt `source` an der Zeile: die Quelle, die sie
     * angelegt hat. Eine zweite Quelle ergänzt nur leere Felder.
     *
     * `wasRecentlyCreated` an der Rückgabe sagt dem Aufrufer, ob die
     * Aktivität neu ist. Nur dann laufen Zuordnung, Review und Push —
     * sonst bekäme ein Athlet beim Quellenwechsel jeden Lauf zweimal
     * besprochen.
     */
    public function store(int $userId, string $source, int $externalId, array $attributes, ?int $otherId = null): Activity
    {
        $column = Activity::ID_COLUMNS[$source];

        $existing = Activity::where('user_id', $userId)->where($column, $externalId)->first();

        if ($existing) {
            $existing->source === $source
                ? $existing->fill($attributes)
                : $this->fillEmpty($existing, $attributes);
            $existing->save();

            return $existing;
        }

        $twin = $this->matcher->findTwin($userId, $source, $attributes, $otherId);

        if ($twin) {
            $twin->{$column} = $externalId;
            $this->fillEmpty($twin, $attributes);
            $twin->save();

            Log::info('Aktivitaet aus zweiter Quelle angehaengt', [
                'user_id'     => $userId,
                'activity_id' => $twin->id,
                'source'      => $source,
                $column       => $externalId,
            ]);

            return $twin;
        }

        return Activity::create(['user_id' => $userId, $column => $externalId, 'source' => $source] + $attributes);
    }

    /**
     * Was eine neue Aktivität auslöst: Schwellenpace, Zuordnung zur
     * geplanten Einheit, Review, Bestzeiten und die Push-Nachricht.
     *
     * Die Bestzeiten kommen von der Quelle (Strava liefert sie mit, Garmin
     * nicht), deshalb als Rückruf. Er gibt die neuen Rekorde zurück.
     *
     * Die Neuberechnung des Plans löst der Aufrufer aus, nach dem letzten
     * Import eines Durchgangs — sie soll alles Neue sehen.
     */
    public function processNew(Activity $activity, ?callable $syncBestEfforts = null): void
    {
        $userId = $activity->user_id;
        $isRun  = $activity->type === 'Run';

        $this->dispatchCalculationIfDue($userId, $isRun ? 1 : 0);
        $this->matchActivityToSession($userId, $activity);

        // Ein Review fuer jede Einheit, die diese Aktivitaet abgeschlossen hat.
        TrainingSession::where('user_id', $userId)
            ->where('activity_id', $activity->id)
            ->where('status', 'completed')
            ->whereNull('reviewed_at')
            ->pluck('id')
            ->each(fn ($id) => GenerateSessionReviewJob::dispatch($id)->delay(now()->addSeconds(20)));

        if ($isRun && $syncBestEfforts) {
            $newRecords = $syncBestEfforts($activity);
            if (! empty($newRecords)) {
                $this->flagPendingPr($userId, $activity->id);
            }
        }

        $user = User::find($userId);
        if ($user && $user->push_notifications_enabled) {
            $distKm = $activity->distance > 0 ? round($activity->distance / 1000, 1) . ' km' : '';
            $body   = trim($activity->name . ($distKm ? " · {$distKm}" : ''));

            $this->webPush->sendToUser($user, 'Neue Aktivität importiert 🏃', $body, '/activities');
        }
    }

    /**
     * Nur leere Felder ergänzen. Die Zahlen der Quelle, die die Zeile
     * angelegt hat, bleiben stehen — zwei Uhren messen nie genau gleich, und
     * ein Lauf soll nicht bei jedem Abgleich seine Distanz wechseln.
     */
    private function fillEmpty(Activity $activity, array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $current = $activity->{$key};

            if (($current === null || $current === '' || $current === []) && $value !== null) {
                $activity->{$key} = $value;
            }
        }
    }

    /**
     * Die verknuepften Einheiten den geaenderten Aktivitaetsdaten angleichen.
     *
     * Fuer den Fall, dass sich bei Strava etwas geaendert hat, ABER nicht die
     * Sportart: ein korrigierter Titel, eine nachgemessene Distanz. Dann
     * bleibt die Zuordnung richtig, und nur die Zahlen wandern mit.
     *
     * Der Titel folgt nur bei einer UNGEPLANTEN Einheit — dort ist er der
     * Aktivitaetsname. Bei einer geplanten steht dort, was der Coach
     * vorgesehen hat („Schwelle mit Kontrolle"); den durch „Morning Run" zu
     * ersetzen waere ein Verlust, kein Abgleich.
     *
     * Der Schnappschuss wird nicht angefasst: er beschreibt, was GEPLANT war,
     * und daran aendert eine Korrektur bei Strava nichts.
     */
    public function syncSessionsWithActivity(Activity $activity): int
    {
        $isRunLike = in_array($activity->type, TrainingSession::RUN_SPORTS, true);

        $distKm = $activity->distance > 0 ? round($activity->distance / 1000, 2) : null;
        $durMin = $activity->moving_time > 0 ? (int) round($activity->moving_time / 60) : null;
        $pace   = $this->paceFromSpeed($activity->average_speed);

        $touched = 0;

        foreach (TrainingSession::where('activity_id', $activity->id)->get() as $session) {
            $update = [
                // Nur Laufkilometer zaehlen in den Wochenumfang — dieselbe
                // Regel wie bei der Zuordnung.
                'distance_km'  => $isRunLike ? ($distKm ?? $session->distance_km) : null,
                'duration_min' => $durMin ?? $session->duration_min,
                // NULL heisst Laufen; alles andere traegt seine Sportart.
                'sport_type'   => $activity->type === 'Run' ? null : $activity->type,
            ];

            if ($isRunLike) {
                $update['pace_target'] = $pace ?? $session->pace_target;
            }

            if ($session->was_unplanned) {
                $update['title'] = $activity->name;
            }

            $session->update($update);
            $touched++;
        }

        return $touched;
    }

    /**
     * Match an activity to a planned training session (Runs only),
     * or create an unplanned completed entry for any activity type.
     */
    public function matchActivityToSession(int $userId, Activity $activity): void
    {
        $date = $activity->start_date->toDateString();

        // Strength activities (gym, kettlebell, …) come in as WeightTraining/Workout.
        $isStrength = in_array($activity->type, ['WeightTraining', 'Workout'], true);

        // Non-Run activities skip the run-session matching below.
        if ($activity->type !== 'Run') {
            $distKm = $activity->distance > 0 ? round($activity->distance / 1000, 2) : null;
            $durMin = $activity->moving_time > 0 ? (int) round($activity->moving_time / 60) : null;

            // Strength activity → complete a planned strength/core/mobility session on this date
            if ($isStrength) {
                $strengthSession = TrainingSession::where('user_id', $userId)
                    ->whereDate('planned_date', $date)
                    ->where('status', 'planned')
                    ->whereIn('type', ['strength', 'core', 'mobility'])
                    ->whereHas('trainingPlan', fn ($q) => $q->where('is_active', true))
                    ->first();

                if ($strengthSession) {
                    $strengthSession->update([
                        'status'       => 'completed',
                        'activity_id'  => $activity->id,
                        'sport_type'   => $activity->type,
                        'duration_min' => $durMin ?? $strengthSession->duration_min,
                    ]);
                    return;
                }
            }

            // Rad → die geplante Radeinheit des Tages. Eine Fahrt draußen
            // erfüllt die Zwift-Einheit genauso; was geplant war, hält der
            // Schnappschuss fest.
            if (in_array($activity->type, TrainingSession::BIKE_SPORTS, true)) {
                $bikeSession = TrainingSession::where('user_id', $userId)
                    ->whereDate('planned_date', $date)
                    ->where('status', 'planned')
                    ->where('type', 'cross_training')
                    ->whereIn('sport_type', TrainingSession::BIKE_SPORTS)
                    ->whereHas('trainingPlan', fn ($q) => $q->where('is_active', true))
                    ->first();

                if ($bikeSession) {
                    $bikeSession->update([
                        'planned_snapshot' => $bikeSession->planned_snapshot ?? [
                            'type'         => $bikeSession->type,
                            'title'        => $bikeSession->title,
                            'duration_min' => $bikeSession->duration_min,
                            'intensity'    => $bikeSession->intensity,
                        ],
                        'status'       => 'completed',
                        'activity_id'  => $activity->id,
                        'sport_type'   => $activity->type,
                        'duration_min' => $durMin ?? $bikeSession->duration_min,
                    ]);
                    return;
                }
            }

            // No matching planned session → create an unplanned completed entry
            if (TrainingSession::where('user_id', $userId)->where('activity_id', $activity->id)->exists()) {
                return;
            }
            $activePlan = TrainingPlan::where('user_id', $userId)->where('is_active', true)->latest()->first();
            if (! $activePlan) return;

            // Die Sportart wird mitgefuehrt. Vorher landete alles, was nicht
            // Lauf und nicht Kraft war — Schwimmen, Radfahren, Yoga — als
            // `easy_run` in der Datenbank. Der Coach las den Trainingstyp,
            // sah "Lockerer Lauf" und fragte den Athleten nach seinem Lauf.
            $isRunLike = in_array($activity->type, TrainingSession::RUN_SPORTS, true);

            TrainingSession::create([
                'user_id'          => $userId,
                'training_plan_id' => $activePlan->id,
                'event_id'         => $activePlan->event_id,
                'activity_id'      => $activity->id,
                'planned_date'     => $date,
                // Kein Lauf-Platzhalter mehr. `cross_training` faellt aus
                // jeder Laufauswertung schon am Typ heraus — auch dort, wo
                // jemand vergisst, zusaetzlich die Sportart zu pruefen.
                'type'             => $isStrength ? 'strength' : 'cross_training',
                'sport_type'       => $activity->type,
                'title'            => $activity->name,
                // Nur Laufkilometer zaehlen in den Wochenumfang. Eine
                // 1,5-km-Schwimmeinheit neben einem 20-km-Longrun waere sonst
                // in jeder Statistik dieselbe Einheit "Kilometer".
                'distance_km'      => ($isStrength || ! $isRunLike) ? null : $distKm,
                'duration_min'     => $durMin,
                'pace_target'      => null,
                'zone'             => null,
                'intensity'        => 'medium',
                // Kein Planeintrag dahinter — als ungeplant kennzeichnen.
                'was_unplanned'    => true,
                'status'           => 'completed',
                'sort_order'       => 999,
            ]);
            return;
        }

        // 1. Find any planned RUN session in the active plan on the same date
        //    (strength/core/mobility sessions are only completed by strength activities)
        $session = TrainingSession::where('user_id', $userId)
            ->whereDate('planned_date', $date)
            ->where('status', 'planned')
            // Eine geplante Radeinheit erfüllt ein Lauf nicht — sie wartet auf die Fahrt.
            ->whereNotIn('type', ['strength', 'core', 'mobility', 'cross_training'])
            ->whereHas('trainingPlan', fn ($q) => $q->where('is_active', true))
            ->first();

        $distKm = $activity->distance > 0 ? round($activity->distance / 1000, 2) : null;
        $durMin = $activity->moving_time > 0 ? (int) round($activity->moving_time / 60) : null;
        $pace   = $this->paceFromSpeed($activity->average_speed);

        if ($session) {
            if ($session->type === 'rest') {
                // User ran on a rest day — delete rest entry, create real run entry
                $plan = $session->trainingPlan;
                $session->delete();
                TrainingSession::create([
                    'user_id'          => $userId,
                    'training_plan_id' => $plan->id,
                    'event_id'         => $plan->event_id,
                    'activity_id'      => $activity->id,
                    'planned_date'     => $date,
                    // Geplant war Ruhe — das ist keine erfuellte Einheit.
                    'was_unplanned'    => true,
                    'planned_snapshot' => ['type' => 'rest', 'title' => $session->title],
                    'type'             => 'easy_run',
                    'title'            => $activity->name,
                    'distance_km'      => $distKm,
                    'duration_min'     => $durMin,
                    'pace_target'      => $pace,
                    'zone'             => null,
                    'intensity'        => 'medium',
                    'status'           => 'completed',
                    'sort_order'       => 0,
                ]);
            } else {
                // Der Plan wird hier von den echten Werten ueberschrieben.
                // Vorher festhalten, was geplant war — sonst laesst sich
                // hinterher nicht mehr sagen, ob die Einheit so gelaufen
                // wurde wie vorgesehen. Das Coach-Review las bis dahin fuer
                // "Geplant" und "Absolviert" dieselben Felder.
                $session->update([
                    'planned_snapshot' => $session->planned_snapshot ?? [
                        'type'         => $session->type,
                        'title'        => $session->title,
                        'distance_km'  => $session->distance_km,
                        'duration_min' => $session->duration_min,
                        'pace_target'  => $session->pace_target,
                        'zone'         => $session->zone,
                        'intensity'    => $session->intensity,
                    ],
                    'status'       => 'completed',
                    'activity_id'  => $activity->id,
                    'distance_km'  => $distKm ?? $session->distance_km,
                    'duration_min' => $durMin ?? $session->duration_min,
                    'pace_target'  => $pace ?? $session->pace_target,
                ]);

                // Test run completed: bypass 24h cooldown and recalculate threshold immediately
                if ($session->type === 'test_run') {
                    $this->dispatchCalculationForTestRun($userId);
                }
            }
            // BEWUSST KEINE Neuberechnung: der Athlet ist gelaufen, was im
            // Plan stand. Frueher warf jeder Import den gesamten Restplan neu,
            // und weil das Modell nicht deterministisch ist, sah die Woche
            // danach anders aus als vorher — ohne dass sich etwas geaendert
            // haette. Die Einheit fliesst ueber den Kontext in die naechste
            // planmaessige Berechnung ein.
            return;
        }

        // 2. No planned session – check if an unplanned entry for this activity already exists
        if (TrainingSession::where('user_id', $userId)->where('activity_id', $activity->id)->exists()) {
            return;
        }

        // Create an unplanned completed entry in the active plan
        $activePlan = TrainingPlan::where('user_id', $userId)
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $activePlan) return;

        TrainingSession::create([
            'user_id'          => $userId,
            'training_plan_id' => $activePlan->id,
            'event_id'         => $activePlan->event_id,
            'activity_id'      => $activity->id,
            'planned_date'     => $date,
            'type'             => 'easy_run',
            'title'            => $activity->name,
            'distance_km'      => $distKm,
            'duration_min'     => $durMin,
            'pace_target'      => $pace,
            'zone'             => null,
            'intensity'        => 'medium',
            // Es gab an dem Tag keine geplante Einheit — dieser Lauf kam dazu.
            'was_unplanned'    => true,
            'status'           => 'completed',
            'sort_order'       => 999,
        ]);

        // Ein ungeplanter Lauf ist echte Zusatzbelastung und bleibt damit ein
        // Anlass. Die naechsten Tage sind davon geschuetzt (siehe
        // RegeneratePlanJob::FREEZE_DAYS) — die Anpassung greift ab dem
        // vierten Tag.
        $activePlan->needs_plan_update = true;
        $activePlan->save();
    }

    /** Convert Strava average_speed (m/s) to "M:SS" pace string, or null. */
    public function paceFromSpeed(float $mps): ?string
    {
        $pace = PaceFormat::fromSpeed($mps);

        return $pace === PaceFormat::NONE ? null : $pace;
    }

    /**
     * Dispatch AI calculation job if:
     *  - At least one new Run was added
     *  - Last calculation was > 24h ago (or never)
     *  - No calculation is already running
     */
    public function dispatchCalculationIfDue(int $userId, int $newRunCount): void
    {
        if ($newRunCount === 0) return;

        $profile = RunnerProfile::firstOrCreate(
            ['user_id' => $userId],
            ['has_completed_setup' => false]
        );

        $lastCalc = $profile->threshold_pace_calculated_at;
        $isDue    = $lastCalc === null || $lastCalc->lt(now()->subHours(24));

        if ($isDue && ! $profile->threshold_pace_calculating) {
            $profile->threshold_pace_calculating = true;
            $profile->save();
            CalculateThresholdPaceJob::dispatch($userId);
        }
    }

    /**
     * Dispatch plan regeneration job if the active plan has needs_plan_update = true.
     * Uses a 5-minute delay to batch multiple activity imports.
     */
    public function dispatchPlanRegenerationIfNeeded(int $userId): void
    {
        $needs = TrainingPlan::where('user_id', $userId)
            ->where('is_active', true)
            ->where('needs_plan_update', true)
            ->exists();

        if ($needs) {
            RegeneratePlanJob::dispatch($userId, RegeneratePlanJob::REASON_AUTO)->delay(now()->addMinutes(5));
        }
    }

    /**
     * Flag an activity as the source of a fresh personal record so the dashboard
     * celebrates it (message generated lazily by GeneratePrMessageJob).
     */
    public function flagPendingPr(int $userId, int $activityId): void
    {
        $profile = RunnerProfile::where('user_id', $userId)->first();
        if ($profile) {
            $profile->pending_pr_activity_id = $activityId;
            $profile->pending_pr_message     = null; // generated lazily on dashboard load
            $profile->save();
        }
    }

    /**
     * Dispatch threshold recalculation for a test run, bypassing the 24h cooldown.
     * Called when a planned test_run session is matched to a Strava activity.
     */
    public function dispatchCalculationForTestRun(int $userId): void
    {
        $profile = RunnerProfile::firstOrCreate(
            ['user_id' => $userId],
            ['has_completed_setup' => false]
        );
        if (! $profile->threshold_pace_calculating) {
            $profile->threshold_pace_calculating = true;
            $profile->save();
            CalculateThresholdPaceJob::dispatch($userId);
        }
    }
}

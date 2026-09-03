<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\AI\TrainingPlanGenerator;
use App\Services\PlanContextBuilder;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\GenerateRacePredictionJob;
use App\Services\PaceFormat;

class RegeneratePlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 600; // reasoning model + OpenAI latency can exceed 2 min

    /**
     * Warum neu gerechnet wird. Der Anlass entscheidet zweierlei: ob die
     * Sechs-Stunden-Sperre greift, und wie weit in die nahe Zukunft die
     * Neuberechnung ueberhaupt hineinreichen darf.
     *
     * Vorher gab es dafuer einen Wahrheitswert `userTriggered`, der nur die
     * Sperre umging. Jede Neuberechnung wuerfelte damit den gesamten
     * Restplan neu — auch die, die durch nichts weiter ausgeloest war, als
     * dass der Athlet getan hatte, was im Plan stand.
     */
    public const REASON_MANUAL       = 'manual';        // Knopfdruck auf der Planseite
    public const REASON_SKIP         = 'skip';          // Einheit ausgelassen
    public const REASON_AVAILABILITY = 'availability';  // Wochenabfrage beantwortet
    public const REASON_WELLBEING    = 'wellbeing';     // Krankheit, Erschoepfung
    public const REASON_THRESHOLD    = 'threshold';     // Schwellenpace verschoben
    public const REASON_GAP          = 'gap';           // Planfenster laeuft aus
    public const REASON_WEEKLY       = 'weekly';       // Sonntags: die kommende Woche schreiben
    public const REASON_AUTO         = 'auto';

    /**
     * Anlaesse, die der Athlet selbst gesetzt hat. Sie umgehen die Sperre
     * und duerfen bis in den heutigen Tag hineinreichen — wer eine Einheit
     * auslaesst oder krank ist, will die Antwort darauf sofort sehen.
     */
    private const IMMEDIATE = [
        self::REASON_MANUAL,
        self::REASON_SKIP,
        self::REASON_AVAILABILITY,
        self::REASON_WELLBEING,
        // Der Wochenschreiber ist der verabredete Zeitpunkt, an dem sich die
        // Woche aendern DARF. Wuerde er die naechsten Tage einfrieren, koennte
        // er genau die Tage nicht schreiben, um die es ihm geht.
        self::REASON_WEEKLY,
    ];

    /**
     * Wie viele Tage im Voraus unantastbar sind, wenn die Neuberechnung
     * NICHT vom Athleten ausgeloest wurde.
     *
     * Der Athlet stellt sich auf seine Einheiten ein. Ein Schwellentraining,
     * das ueber Nacht zu zwanzig lockeren Minuten wird, ist auch dann
     * aergerlich, wenn die neue Einheit fuer sich genommen sinnvoll waere.
     */
    public const FREEZE_DAYS = 3;

    public function __construct(
        public readonly int    $userId,
        public readonly string $reason = self::REASON_AUTO,
    ) {}

    /** Wie der Anlass im Aenderungsverlauf heisst. */
    private function revisionLabel(): string
    {
        return match ($this->reason) {
            self::REASON_MANUAL       => 'manual',
            self::REASON_SKIP,
            self::REASON_WELLBEING    => 'user',
            self::REASON_WEEKLY       => 'weekly',
            self::REASON_AVAILABILITY => 'availability',
            default                   => 'auto',
        };
    }

    /** Umgeht diese Neuberechnung die Sechs-Stunden-Sperre? */
    private function isImmediate(): bool
    {
        return in_array($this->reason, self::IMMEDIATE, true);
    }

    /**
     * Bis zu welchem Tag bleiben geplante Einheiten unangetastet?
     * Null heisst: nichts ist eingefroren.
     */
    private function frozenThrough(): ?string
    {
        return $this->isImmediate()
            ? null
            : now()->addDays(self::FREEZE_DAYS)->toDateString();
    }

    public function handle(
        TrainingPlanGenerator $planner,
        WebPushService $webPush,
        PlanContextBuilder $contextBuilder,
    ): void
    {
        $user = User::find($this->userId);
        if (! $user) return;

        $plan = TrainingPlan::where('user_id', $this->userId)
            ->where('is_active', true)
            ->where('needs_plan_update', true)
            ->first();

        if (! $plan) return;

        // Debounce: skip if plan was regenerated less than 6 hours ago (batches Strava-sync triggers)
        // User-triggered actions (skip/complete) bypass this debounce for immediate response
        if (! $this->isImmediate() && $plan->created_at->gt(now()->subHours(6))) {
            Log::info('RegeneratePlanJob: plan recently created, skipping', ['plan_id' => $plan->id]);
            $plan->update(['needs_plan_update' => false]);
            return;
        }

        $event = $plan->event;
        if (! $event || $event->days_until < 0) {
            $plan->update(['needs_plan_update' => false]);
            return;
        }

        // ── Kontext ─────────────────────────────────────────────────────────────
        // Derselbe Aufbau wie bei der Erstgenerierung. Vorher lagen hier
        // ~150 kopierte Zeilen, die bereits auseinandergelaufen waren.
        $context  = $contextBuilder->build($user, $event, $plan->availability_overrides ?? []);
        $skeleton = $context->skeleton;

        // Muss vor dem Löschen der alten Pläne feststehen.
        $oldPlanIds        = TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->pluck('id');
        $preservedSessions = TrainingSession::whereIn('training_plan_id', $oldPlanIds)
            ->whereIn('status', ['skipped', 'completed'])
            ->get();

        // Der Stand vor der Neuberechnung — Grundlage für den Änderungsverlauf.
        // Gleich unten werden diese Einheiten gelöscht.
        $sessionsBefore = TrainingSession::whereIn('training_plan_id', $oldPlanIds)
            ->where('status', 'planned')
            ->get();

        // ── Was muss ueberhaupt neu geschrieben werden? ─────────────────────
        //
        // Das Geruest ist deterministisch. Traegt ein Tag denselben Slot wie
        // vorher und steht dort bereits eine Einheit, gibt es nichts neu zu
        // erfinden — und genau dieses Neu-Erfinden war der Grund, warum
        // derselbe Donnerstag nach jeder Neuberechnung anders aussah.
        //
        // Eingefrorene Tage zaehlen immer als erhalten: sie sollen sich
        // nicht aendern, also darf das Modell sie auch nicht neu schreiben.
        $frozenThrough = $this->frozenThrough();

        $delta = app(\App\Services\PlanDeltaService::class)->split($skeleton, $sessionsBefore);

        $keptDates = collect($delta['keep'])
            ->merge($frozenThrough
                ? $sessionsBefore->filter(fn ($s) => $s->planned_date->format('Y-m-d') <= $frozenThrough)
                    ->map(fn ($s) => $s->planned_date->format('Y-m-d'))
                : [])
            ->unique()
            ->values();

        $staleDates = collect($delta['stale'])->reject(fn ($d) => $keptDates->contains($d))->values();

        // Nichts zu tun. Der Aufruf beim Modell entfaellt vollstaendig —
        // frueher lief er auch dann, wenn das Ergebnis dasselbe sein musste.
        if ($staleDates->isEmpty()) {
            Log::info('RegeneratePlanJob: nichts zu aendern', [
                'user_id' => $this->userId,
                'reason'  => $this->reason,
                'kept'    => $keptDates->count(),
            ]);
            $plan->update(['needs_plan_update' => false]);
            return;
        }

        // Die erhaltenen Tage aus dem Geruest nehmen und dem Modell als
        // Kontext nennen. Beides zusammen sorgt dafuer, dass es sie nicht
        // zurueckgibt und die offenen Tage trotzdem dazu passend fuellt.
        $keptSessions = [];
        foreach ($keptDates as $date) {
            if (isset($skeleton['days'][$date])) {
                $skeleton['days'][$date]['kept'] = true;
            }

            foreach ($sessionsBefore->filter(fn ($s) => $s->planned_date->format('Y-m-d') === $date) as $kept) {
                $keptSessions[] = [
                    'date'         => $date,
                    'type'         => $kept->type,
                    'title'        => $kept->title,
                    'distance_km'  => $kept->distance_km,
                    'duration_min' => $kept->duration_min,
                ];
            }
        }

        $context = $context->with(skeleton: $skeleton, keptSessions: $keptSessions);

        Log::info('RegeneratePlanJob: Teil-Neuberechnung', [
            'user_id' => $this->userId,
            'reason'  => $this->reason,
            'stale'   => $staleDates->count(),
            'kept'    => $keptDates->count(),
        ]);

        // ── Call OpenAI ─────────────────────────────────────────────────────────
        $planner->withCoach($user->coach?->personality_prompt)->forUser($user->id);
        try {
            $aiSessions = $planner->generateEventTrainingPlan($context);
        } catch (\Throwable $e) {
            Log::error('RegeneratePlanJob: OpenAI error', ['error' => $e->getMessage(), 'user_id' => $this->userId]);
            return;
        }

        if (! $aiSessions) {
            Log::warning('RegeneratePlanJob: no sessions returned', ['user_id' => $this->userId]);
            return;
        }

        $eventDateStr = $event->event_date->format('Y-m-d');

        $checked    = app(\App\Services\TrainingPlanValidator::class)
            ->validate($aiSessions, $skeleton, $eventDateStr);
        $aiSessions = $checked['sessions'];

        if ($checked['report']) {
            Log::info('Plan validator corrected the AI output', [
                'user_id'     => $this->userId,
                'corrections' => $checked['report'],
            ]);
        }

        // ── Replace plan in DB ──────────────────────────────────────────────────
        try {
            // $oldPlanIds already computed above (before AI call)
            // Was der Athlet selbst gesetzt hat, ueberlebt die Neuberechnung.
            // Ohne diese Ausnahme verschwand ein im Chat bestellter Longrun
            // beim naechsten Durchlauf still wieder.
            // Geloescht wird nur, was tatsaechlich neu geschrieben wird.
            // Erhaltene Tage (unveraendertes Geruest oder eingefroren) und
            // vom Athleten gesetzte Einheiten bleiben stehen.
            TrainingSession::whereIn('training_plan_id', $oldPlanIds)
                ->where('status', 'planned')
                ->whereNull('pinned_at')
                ->whereIn('planned_date', $staleDates->all())
                ->delete();
            TrainingPlan::where('event_id', $event->id)->where('user_id', $user->id)->delete();

            $newPlan = TrainingPlan::create([
                'user_id'  => $user->id,
                'event_id' => $event->id,
                'sessions' => $aiSessions,
                'context'  => [
                    'activities_used'    => count($context->recentActivities),
                    'wellbeing_entries'  => count($context->wellbeing),
                    'has_runner_profile' => $context->profile !== null,
                    'used_garmin'        => $context->garminText !== null,
                    'weekly_pattern'     => collect($skeleton['weeks'])->map(fn ($w) => $w['planned'])->all(),
                    'corrections'        => $checked['report'],
                    'days_until_event'   => $event->days_until,
                    'auto_regenerated'   => true,
                ],
            ]);

            DB::table('training_plans')
                ->where('id', $newPlan->id)
                ->update(['is_active' => true, 'needs_plan_update' => false]);

            // Der alte Plan ist geloescht; die gesetzten Einheiten haengen
            // sonst an einer Plan-ID, die es nicht mehr gibt — die Planseite
            // laedt ausschliesslich Einheiten des aktiven Plans und haette
            // sie damit nicht mehr gezeigt.
            TrainingSession::where('user_id', $user->id)
                ->where('event_id', $event->id)
                ->where('status', 'planned')
                ->update(['training_plan_id' => $newPlan->id]);

            // Fuer dieses Event steht jetzt ein frischer Plan. Ein noch
            // gesetzter Erstellungs-Schalter kann nur von einem Lauf stammen,
            // der nicht mehr kommt — die Planseite zeigte sonst weiter
            // "analysiert deine Daten", obwohl der Plan daneben liegt.
            if ($event->plan_generating) {
                $event->update([
                    'plan_generating'    => false,
                    'plan_generating_at' => null,
                    'plan_error'         => null,
                ]);
            }

            // Erhaltene Einheiten blockieren ihren Tag nur für die eigene
            // Sorte — sonst fiele an einem Doppel-Tag die Krafteinheit weg,
            // sobald der Lauf importiert wurde.
            $extraTypes = ['strength', 'core', 'mobility'];

            $preservedRunDates = $preservedSessions
                ->reject(fn ($s) => in_array($s->type, $extraTypes, true))
                ->pluck('planned_date')->map(fn ($d) => $d->format('Y-m-d'))
                ->unique()->flip()->toArray();

            $preservedExtraDates = $preservedSessions
                ->filter(fn ($s) => in_array($s->type, $extraTypes, true))
                ->pluck('planned_date')->map(fn ($d) => $d->format('Y-m-d'))
                ->unique()->flip()->toArray();

            foreach ($preservedSessions as $session) {
                $session->update(['training_plan_id' => $newPlan->id]);
            }

            // Tage, die stehengeblieben sind, duerfen nicht doppelt angelegt
            // werden — sonst stuenden dort zwei Einheiten.
            $keptDates = TrainingSession::where('training_plan_id', $newPlan->id)
                ->where('status', 'planned')
                ->pluck('planned_date')
                ->map(fn ($d) => $d->format('Y-m-d'))
                ->unique()
                ->flip()
                ->toArray();

            foreach ($aiSessions as $i => $s) {
                $date  = $s['date'] ?? '';
                $extra = in_array($s['type'] ?? '', $extraTypes, true);

                if (isset($keptDates[$date])) {
                    continue;
                }

                if ($extra ? isset($preservedExtraDates[$date]) : isset($preservedRunDates[$date])) {
                    continue;
                }

                if (($s['type'] ?? '') === 'rest' && (isset($preservedRunDates[$date]) || isset($preservedExtraDates[$date]))) {
                    continue;
                }
                TrainingSession::create([
                    'user_id'          => $user->id,
                    'training_plan_id' => $newPlan->id,
                    'event_id'         => $event->id,
                    'planned_date'     => $s['date'],
                    'type'             => $s['type'] ?? 'easy_run',
                    'title'            => $s['title'] ?? '',
                    'description'      => $s['description'] ?? '',
                    'distance_km'      => ($s['distance_km'] ?? 0) ?: null,
                    'duration_min'     => ($s['duration_min'] ?? 0) ?: null,
                    'pace_target'      => ($s['pace_target'] === 'null' || empty($s['pace_target'])) ? null : $s['pace_target'],
                    'zone'             => $s['zone'] ?? null,
                    'intensity'        => $s['intensity'] ?? 'low',
                    'exercises'        => ! empty($s['exercises']) && is_array($s['exercises']) ? $s['exercises'] : null,
                    'status'           => 'planned',
                    'sort_order'       => $i,
                ]);
            }

            // Ein Gerüsttag ohne Einheit ist ein Loch im Plan.
            $this->sealGaps($user, $event, $newPlan, $skeleton);

            // Erst JETZT der Verlauf — und gegen das, was wirklich in der
            // Datenbank steht.
            //
            // Vorher stand er weiter oben und verglich $aiSessions — den
            // vom Validator geprüften Plan. Damit sah er alles, was der
            // Validator korrigiert, aber nichts von dem, was DANACH noch
            // geschieht: die Schleife unten überspringt Tage, die sie für
            // belegt hält, und sealGaps() trägt Ruhetage nach. Dort entstand
            // die Meldung „Ruhetag → Lockerer Lauf" für einen Tag, an dem nie
            // eine Einheit stand.
            app(\App\Services\PlanRevisionRecorder::class)->record(
                user:        $user,
                event:       $event,
                newPlan:     $newPlan,
                oldSessions: $sessionsBefore,
                newSessions: $this->writtenPlan($newPlan),
                corrections: $checked['report'] ?? [],
                triggeredBy: $this->revisionLabel(),
            );

            // ── Retroactive run matching ──────────────────────────────────────
            $planDates = collect($aiSessions)->pluck('date');
            $planStart = $planDates->min();
            $planEnd   = $planDates->max();

            if ($planStart && $planEnd) {
                $recentRuns = $user->activities()
                    ->where('type', 'Run')
                    ->whereDate('start_date', '>=', $planStart)
                    ->whereDate('start_date', '<=', $planEnd)
                    ->get();

                foreach ($recentRuns as $run) {
                    $date          = $run->start_date->toDateString();
                    $plannedOnDate = TrainingSession::where('training_plan_id', $newPlan->id)
                        ->whereDate('planned_date', $date)
                        ->where('status', 'planned')
                        ->orderBy('sort_order')
                        ->get();
                    // A day can hold two sessions (e.g. run + strength). Match the imported
                    // run to the RUNNING session, never a strength/core/mobility block. If the
                    // day is only strength (no run planned), leave it null so the run is added
                    // as a separate completed session below instead of overwriting the strength.
                    $sessionOnDate = $plannedOnDate->first(fn ($s) => ! in_array($s->type, ['rest', 'strength', 'core', 'mobility']))
                        ?? $plannedOnDate->first(fn ($s) => $s->type === 'rest');

                    $distKm = $run->distance > 0 ? round($run->distance / 1000, 2) : null;
                    $durMin = $run->moving_time > 0 ? (int) round($run->moving_time / 60) : null;
                    $pace   = $this->paceFromSpeed($run->average_speed);

                    if ($sessionOnDate && $sessionOnDate->type !== 'rest') {
                        $sessionOnDate->update([
                            'status'       => 'completed',
                            'activity_id'  => $run->id,
                            'distance_km'  => $distKm ?? $sessionOnDate->distance_km,
                            'duration_min' => $durMin ?? $sessionOnDate->duration_min,
                            'pace_target'  => $pace ?? $sessionOnDate->pace_target,
                        ]);
                        TrainingSession::where('user_id', $user->id)
                            ->where('activity_id', $run->id)
                            ->where('id', '!=', $sessionOnDate->id)
                            ->delete();
                    } elseif ($sessionOnDate && $sessionOnDate->type === 'rest') {
                        $sessionOnDate->delete();
                        TrainingSession::create([
                            'user_id'          => $user->id,
                            'training_plan_id' => $newPlan->id,
                            'event_id'         => $newPlan->event_id,
                            'activity_id'      => $run->id,
                            'planned_date'     => $date,
                            'type'             => 'easy_run',
                            'title'            => $run->name,
                            'distance_km'      => $distKm,
                            'duration_min'     => $durMin,
                            'pace_target'      => $pace,
                            'zone'             => null,
                            'intensity'        => 'medium',
                            'status'           => 'completed',
                            'sort_order'       => 0,
                        ]);
                    } else {
                        $existingSession = TrainingSession::where('user_id', $user->id)
                            ->where('activity_id', $run->id)
                            ->first();

                        if ($existingSession) {
                            $existingSession->update(['training_plan_id' => $newPlan->id, 'event_id' => $newPlan->event_id]);
                        } else {
                            TrainingSession::create([
                                'user_id'          => $user->id,
                                'training_plan_id' => $newPlan->id,
                                'event_id'         => $newPlan->event_id,
                                'activity_id'      => $run->id,
                                'planned_date'     => $date,
                                'type'             => 'easy_run',
                                'title'            => $run->name,
                                'distance_km'      => $distKm,
                                'duration_min'     => $durMin,
                                'pace_target'      => $pace,
                                'zone'             => null,
                                'intensity'        => 'medium',
                                'status'           => 'completed',
                                'sort_order'       => 999,
                            ]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('RegeneratePlanJob: database error', ['error' => $e->getMessage(), 'user_id' => $this->userId]);
            return;
        }

        // Clear cached coaching messages so the dashboard regenerates them with the new plan context
        $user->runnerProfile?->update([
            'today_recommendation' => null,
            'recommendation_date'  => null,
            'daily_message'        => null,
            'daily_message_date'   => null,
        ]);

        if ($user->push_notifications_enabled && $user->notify_plan_updated) {
            $coachName = $user->coach?->name ?? 'Dein Coach';

            // Die Wochenplanung ist kein "wurde neu berechnet" — sie ist der
            // verabredete Zeitpunkt, an dem die Woche entsteht.
            [$title, $body] = $this->reason === self::REASON_WEEKLY
                ? ["Deine Woche steht 🗓️",
                   "{$coachName} hat deine Trainingswoche geschrieben. Schau sie dir an."]
                : ["{$coachName} hat deinen Plan aktualisiert",
                   "Dein Trainingsplan für {$event->name} wurde neu berechnet."];

            $webPush->sendToUser($user, $title, $body, "/events/{$event->id}/plan");
        }

        // Refresh race prediction in background
        GenerateRacePredictionJob::dispatch($newPlan->id)->delay(now()->addSeconds(10));

        Log::info('RegeneratePlanJob: plan regenerated', ['user_id' => $this->userId, 'event_id' => $event->id]);
    }

    private function formatPace(float $mps): string
    {
        return PaceFormat::fromSpeed($mps);
    }

    private function paceFromSpeed(float $mps): ?string
    {
        $pace = PaceFormat::fromSpeed($mps);

        return $pace === PaceFormat::NONE ? null : $pace;
    }


    /**
     * Der Plan, wie er nach dem Schreiben dasteht.
     *
     * Bewusst aus der Datenbank gelesen und nicht aus `$aiSessions`
     * zusammengesetzt: Validator, Anlege-Schleife und `sealGaps()` koennen
     * alle noch etwas veraendern, und was der Athlet im Verlauf liest, soll
     * das sein, was er im Plan sieht.
     *
     * Nur `planned` — dieselbe Auswahl wie beim Vorher-Stand, sonst
     * verglichen wir Aepfel mit Birnen.
     *
     * @return list<array<string, mixed>>
     */
    private function writtenPlan(TrainingPlan $plan): array
    {
        return TrainingSession::where('training_plan_id', $plan->id)
            ->where('status', 'planned')
            ->get()
            ->map(fn (TrainingSession $s) => [
                'date'         => $s->planned_date->format('Y-m-d'),
                'type'         => $s->type,
                'title'        => $s->title,
                'distance_km'  => $s->distance_km,
                'duration_min' => $s->duration_min,
            ])
            ->all();
    }

    /**
     * Kein Gerüsttag darf am Ende leer sein.
     *
     * Zweimal ist genau das passiert — am 31.08. und am 03.09. Beide Male
     * stand der Tag im Gerüst, beide Male meldete der Verlauf eine Änderung,
     * und beide Male gab es hinterher keine einzige Einheit an dem Datum. In
     * der App sah das aus wie ein Loch mitten in der Woche.
     *
     * Der Weg dahin ist lang: das Modell kann einen Tag weglassen, der
     * Validator trägt ihn nur nach, wenn er weder `finalized` noch `kept`
     * ist, und die Anlege-Schleife überspringt Tage, die sie für schon
     * belegt hält. Jede dieser Weichen ist für sich richtig; zusammen können
     * sie einen Tag fallen lassen, ohne dass irgendwo etwas rot wird.
     *
     * Statt jede Weiche einzeln abzusichern, wird hier am Ende nachgesehen,
     * was tatsächlich in der Datenbank steht. Das ist die einzige Stelle, an
     * der die Frage überhaupt beantwortbar ist: der Verlauf wird aus der
     * MODELLAUSGABE geschrieben, nicht aus dem Ergebnis — er hätte das Loch
     * nie gemeldet, und hat es auch nicht.
     *
     * Gefüllt wird mit einem Ruhetag. Ein erfundenes Training wäre schlimmer
     * als die ehrliche Aussage, dass hier nichts geplant ist — und der
     * Logeintrag sagt, dass etwas schiefging.
     *
     * @param  array<string, mixed>  $skeleton
     */
    private function sealGaps(User $user, Event $event, TrainingPlan $newPlan, array $skeleton): void
    {
        $days = $skeleton['days'] ?? [];
        if ($days === []) {
            return;
        }

        // Was an dem Tag ueberhaupt existiert — gleich welcher Plan, gleich
        // welcher Status. Nur ein wirklich leerer Tag ist ein Loch; eine
        // abgehakte oder ausgelassene Einheit ist keins.
        //
        // Ueber whereDate und einen Bereich, nicht ueber whereIn: MySQL legt
        // in einer date-Spalte `2026-08-27` ab, SQLite `2026-08-27 00:00:00`.
        // Ein whereIn mit blossen Datumsstrings trifft unter SQLite nichts —
        // dann saehe jeder Tag leer aus und die Kontrolle wuerde die ganze
        // Woche mit Ruhetagen zupflastern.
        $keys = array_keys($days);

        $occupied = TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', '>=', min($keys))
            ->whereDate('planned_date', '<=', max($keys))
            ->pluck('planned_date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->flip();

        $filled = [];

        foreach ($days as $date => $day) {
            if (isset($occupied[$date])) {
                continue;
            }

            TrainingSession::create([
                'user_id'          => $user->id,
                'training_plan_id' => $newPlan->id,
                'event_id'         => $event->id,
                'planned_date'     => $date,
                'type'             => 'rest',
                'title'            => 'Ruhetag',
                'description'      => 'Für diesen Tag kam keine Einheit zurück — eingetragen, damit der Plan keine Lücke hat.',
                'intensity'        => 'low',
                'status'           => 'planned',
                'sort_order'       => 0,
            ]);

            $filled[] = $date;
        }

        if ($filled !== []) {
            Log::warning('Plan hatte Lücken — mit Ruhetagen geschlossen', [
                'user_id' => $user->id,
                'event_id' => $event->id,
                'plan_id' => $newPlan->id,
                'dates'   => $filled,
                'reason'  => $this->reason,
            ]);
        }
    }
}

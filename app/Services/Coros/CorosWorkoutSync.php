<?php

namespace App\Services\Coros;

use App\Models\CorosScheduledWorkout;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Garmin\GarminWorkoutSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Der Plan auf der COROS-Uhr — heute und morgen, automatisch.
 *
 * Anders als bei Garmin kann Zone3 im COROS-Kalender nichts loeschen oder
 * verschieben (MCP, Stand 05.11.2026). Daraus folgen zwei Regeln:
 *
 * - **Nur zwei Tage voraus.** Was so nah liegt, aendert sich selten. Jeder
 *   Tag weiter waere ein Tag mehr, an dem eine verschobene Einheit als
 *   Ruine stehen bleibt.
 * - **Ersetzen statt loeschen.** Aendert sich eine Einheit am selben Tag,
 *   wird ihr Platz ersetzt. Faellt sie weg (verschoben, ausgelassen,
 *   geloescht, neu berechnet), steht dort „Entfaellt“, und der Platz wird
 *   fuer die naechste Einheit an demselben Tag wieder benutzt.
 *
 * Was gesendet wurde, steht mit Fingerabdruck in `coros_scheduled_workouts`;
 * ein unveraenderter Plan kostet keinen Aufruf. Erledigte Einheiten bleiben
 * stehen. Vereinstermine, Rennen, Rad und Kraft gehen nicht auf die Uhr —
 * dieselben Regeln wie bei Garmin.
 */
class CorosWorkoutSync
{
    /** Heute und morgen. */
    public const DAYS_AHEAD = 2;

    public function __construct(
        private readonly CorosCalendar $calendar,
        private readonly CorosWorkoutBuilder $builder,
    ) {}

    /** @return array{created:int, replaced:int, cancelled:int, kept:int} */
    public function sync(User $user): array
    {
        $result     = ['created' => 0, 'replaced' => 0, 'cancelled' => 0, 'kept' => 0];
        $connection = $user->corosConnection;

        if (! $connection) {
            return $result;
        }

        $wanted = $connection->workout_sync ? $this->wanted($user) : collect();
        $rows   = CorosScheduledWorkout::with('trainingSession')
            ->where('user_id', $user->id)
            ->whereDate('date', '>=', today()->toDateString())
            ->orderBy('id')
            ->get();

        try {
            // 1. Was schon steht: behalten, am selben Tag ersetzen oder absagen.
            foreach ($rows as $row) {
                $session = $row->trainingSession;

                if ($row->cancelled) {
                    continue;
                }

                if ($session && $session->status === 'completed') {
                    $result['kept']++;
                    continue;
                }

                $payload = $session ? ($wanted[$session->id] ?? null) : null;

                if ($payload && $payload['date'] === $row->date->toDateString()) {
                    $wanted->forget($session->id);

                    if ($row->payload_hash === $payload['hash']) {
                        $result['kept']++;
                        continue;
                    }

                    $this->calendar->replace($connection, $payload['date'], $row->id_in_plan, $payload['course']);
                    $row->update(['payload_hash' => $payload['hash']]);
                    $result['replaced']++;
                    continue;
                }

                $this->calendar->replace($connection, $row->date->toDateString(), $row->id_in_plan, $this->builder->placeholder());
                $row->update(['cancelled' => true, 'training_session_id' => null, 'payload_hash' => null]);
                $result['cancelled']++;
            }

            // 2. Was fehlt: auf einen freien Platz desselben Tages, sonst neu.
            $free = $rows->filter(fn ($r) => $r->cancelled)->groupBy(fn ($r) => $r->date->toDateString());

            foreach ($wanted as $sessionId => $payload) {
                $slot = $free->get($payload['date'])?->shift();

                if ($slot) {
                    $this->calendar->replace($connection, $payload['date'], $slot->id_in_plan, $payload['course']);
                    $slot->update(['cancelled' => false, 'training_session_id' => $sessionId, 'payload_hash' => $payload['hash']]);
                    $result['replaced']++;
                    continue;
                }

                $idInPlan = $this->calendar->create($connection, $payload['date'], $payload['course']);
                CorosScheduledWorkout::create([
                    'user_id'             => $user->id,
                    'training_session_id' => $sessionId,
                    'date'                => $payload['date'],
                    'id_in_plan'          => $idInPlan,
                    'payload_hash'        => $payload['hash'],
                ]);
                $result['created']++;
            }
        } catch (CorosException $e) {
            // Ein abgelehntes Workout oder ein toter Token: abbrechen, der
            // naechste Lauf macht weiter. Was bis hier geschah, ist gespeichert.
            Log::warning('COROS-Kalender: Abgleich abgebrochen', [
                'user_id' => $user->id,
                'status'  => $e->status,
                'error'   => mb_substr($e->getMessage(), 0, 300),
                'result'  => $result,
            ]);
        }

        if ($result['created'] || $result['replaced'] || $result['cancelled']) {
            Log::info('COROS-Kalender abgeglichen', ['user_id' => $user->id] + $result);
        }

        return $result;
    }

    /**
     * Beim Trennen: was bevorsteht und nicht gelaufen ist, auf „Entfaellt“
     * setzen, solange der Token noch gilt. Ein Fehler haelt das Trennen
     * nicht auf.
     */
    public function cancelAll(User $user): void
    {
        $connection = $user->corosConnection;

        $rows = CorosScheduledWorkout::with('trainingSession')
            ->where('user_id', $user->id)
            ->where('cancelled', false)
            ->whereDate('date', '>=', today()->toDateString())
            ->get()
            ->reject(fn ($r) => $r->trainingSession?->status === 'completed');

        foreach ($rows as $row) {
            try {
                $this->calendar->replace($connection, $row->date->toDateString(), $row->id_in_plan, $this->builder->placeholder());
            } catch (CorosException $e) {
                Log::warning('COROS-Kalender: Workout beim Trennen nicht abgesagt', [
                    'user_id' => $user->id, 'error' => $e->getMessage(),
                ]);
                break;
            }
        }
    }

    /**
     * Was im COROS-Kalender stehen soll, nach Einheit.
     *
     * @return Collection<int, array{course: array, date: string, hash: string}>
     */
    private function wanted(User $user): Collection
    {
        $plan  = $user->activeTrainingPlan;
        $today = today()->toDateString();

        $sessions = TrainingSession::where('user_id', $user->id)
            ->where(function ($q) use ($plan) {
                $q->whereNull('training_plan_id');
                if ($plan) {
                    $q->orWhere('training_plan_id', $plan->id);
                }
            })
            ->where('status', 'planned')
            ->whereDate('planned_date', '>=', $today)
            ->whereDate('planned_date', '<=', today()->addDays(self::DAYS_AHEAD - 1)->toDateString())
            ->orderBy('planned_date')->orderBy('sort_order')->orderBy('id')
            ->get();

        $weekly    = $user->runnerProfile?->weekly_availability ?? [];
        $overrides = $plan?->availability_overrides ?? [];

        return $sessions
            ->reject(fn (TrainingSession $s) => GarminWorkoutSync::isFixedAppointment($s, $weekly, $overrides))
            ->filter(fn (TrainingSession $s) => CorosWorkoutBuilder::supports($s))
            ->mapWithKeys(function (TrainingSession $s) {
                $course = $this->builder->forSession($s);
                $date   = $s->planned_date->toDateString();

                return [$s->id => [
                    'course' => $course,
                    'date'   => $date,
                    'hash'   => sha1(json_encode($course) . '|' . $date),
                ]];
            });
    }
}

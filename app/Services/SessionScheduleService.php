<?php

namespace App\Services;

use App\Models\TrainingPlan;
use App\Models\TrainingSession;
use App\Models\User;
use InvalidArgumentException;

/**
 * Eine Einheit verschieben oder loeschen — vom Athleten selbst, per Geste.
 *
 * Gewuenscht am 28.10.2026: „Wenn ich in Zone3 das Training verschiebe oder
 * loesche, soll das an Garmin gehen." In der Oberflaeche ging beides bis
 * dahin gar nicht, nur ueber den Coach-Chat. Jetzt: in der Liste lange
 * druecken und auf einen anderen Tag ziehen, nach links wischen zum Loeschen.
 * Die Uhr folgt ueber den `TrainingSessionObserver`.
 *
 * Anders als der Coach raeumt ein Verschieben den Zieltag nicht frei. Der
 * Coach haelt „ein Lauf pro Tag" ein, weil er sonst zwei Laeufe plant, wo
 * einer gemeint war; wer selbst einen zweiten Lauf auf einen Tag zieht, will
 * genau das (Jan: „Ich moechte den Sportler nicht einschraenken"). Nur ein
 * Ruhetag am Zieltag faellt weg — Ruhe und Lauf zugleich ist ein Widerspruch.
 */
class SessionScheduleService
{
    /** Was sich verschieben und loeschen laesst: geplant, kein Ruhetag, kein Rennen. */
    public static function canChange(TrainingSession $session): bool
    {
        return $session->status === 'planned'
            && ! in_array($session->type, ['rest', 'race_prep'], true);
    }

    public function move(TrainingSession $session, string $date): void
    {
        $this->guard($session);

        $from = $session->planned_date->toDateString();
        if ($from === $date) {
            return;
        }
        if ($date < today()->toDateString()) {
            throw new InvalidArgumentException('In die Vergangenheit lässt sich nichts verschieben.');
        }

        $user = $session->user;

        TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $date)
            ->where('status', 'planned')
            ->where('type', 'rest')
            ->get()
            ->each->delete();

        // Die Schritte bleiben: die Einheit ist dieselbe, nur an einem
        // anderen Tag. Hinter alles, was dort schon steht.
        $session->update([
            'planned_date' => $date,
            'sort_order'   => (int) TrainingSession::where('user_id', $user->id)
                ->whereDate('planned_date', $date)->max('sort_order') + 1,
            'pinned_at'    => now(),
        ]);

        if ($session->training_plan_id) {
            $this->sealVacatedDay($user, $from, 'verschoben');
        }

        $this->forgetRecommendation($user);
    }

    public function delete(TrainingSession $session): void
    {
        $this->guard($session);

        $user = $session->user;
        $date = $session->planned_date->toDateString();
        $hadPlan = (bool) $session->training_plan_id;

        $session->delete();

        if ($hadPlan) {
            $this->sealVacatedDay($user, $date, 'gestrichen');
        }

        $this->forgetRecommendation($user);
    }

    /**
     * Ein Tag, dem die letzte Einheit genommen wurde, bekommt einen Ruhetag.
     *
     * Genau hier entstand das Loch im Plan, und der Hergang ist banal: Jan
     * hatte das Mittwochstraining aus Zeitgruenden abgesagt, die Neuberechnung
     * legte es auf Donnerstag, und dann sagte er dem Coach, er schaffe es
     * doch noch am Mittwoch. Der Coach schob die Einheit zurueck — und
     * `move_training_session` setzte nur `planned_date` um. Der Donnerstag
     * blieb leer zurueck, ohne dass irgendjemand nachsah.
     *
     * Die Kontrolle in `RegeneratePlanJob::sealGaps()` faengt das nicht: sie
     * laeuft in der Neuberechnung, und hier wird keine ausgeloest. Der Tag
     * muss dort geschlossen werden, wo er geleert wird — im Coach-Chat und
     * bei einer Geste in der Liste.
     *
     * Ein Ruhetag ist die ehrliche Darstellung — an dem Tag steht jetzt kein
     * Training. Ein Loch behauptet dagegen gar nichts und sieht in der App
     * aus wie ein Fehler, was es ja auch war.
     */
    public function sealVacatedDay(User $user, string $date, string $why): void
    {
        $stillThere = TrainingSession::where('user_id', $user->id)
            ->whereDate('planned_date', $date)
            ->exists();

        if ($stillThere) {
            return;
        }

        // Ohne aktiven Plan haette der Ruhetag keinen, an dem er haengt — die
        // Planseite laedt nur Einheiten des aktiven Plans, er waere unsichtbar.
        $plan = TrainingPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $plan) {
            return;
        }

        TrainingSession::create([
            'user_id'          => $user->id,
            'training_plan_id' => $plan->id,
            'event_id'         => $plan->event_id,
            'planned_date'     => $date,
            'status'           => 'planned',
            'sort_order'       => 0,
            'type'             => 'rest',
            'title'            => 'Ruhetag',
            'description'      => "Die Einheit dieses Tages wurde {$why}.",
            'intensity'        => 'rest',
        ]);
    }

    private function guard(TrainingSession $session): void
    {
        if (! self::canChange($session)) {
            throw new InvalidArgumentException('Diese Einheit lässt sich nicht mehr ändern.');
        }
    }

    /** Die Tagesempfehlung kannte noch den alten Stand. */
    private function forgetRecommendation(User $user): void
    {
        $user->runnerProfile?->update([
            'today_recommendation' => null,
            'recommendation_date'  => null,
            'daily_message'        => null,
            'daily_message_date'   => null,
        ]);
    }
}

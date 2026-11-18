<?php

namespace App\Observers;

use App\Jobs\SyncCorosWorkoutsJob;
use App\Jobs\SyncGarminWorkoutsJob;
use App\Jobs\SyncIntervalsWorkoutsJob;
use App\Models\TrainingSession;

/**
 * Jede Aenderung an einer Einheit — Coach, Tagesform-Vorschlag, Tausch,
 * Neuberechnung — zieht den Garmin- und den COROS-Kalender nach. Ein Weg fuer alle
 * Ausloeser, statt an jeder Stelle daran zu denken.
 *
 * Massen-Updates und -Loeschungen (`->delete()` auf einer Abfrage) laufen an
 * Beobachtern vorbei. Die Plan-Jobs legen danach aber neue Einheiten an, und
 * das meldet sich; fuer den Rest gibt es den stuendlichen Lauf.
 */
class TrainingSessionObserver
{
    public function saved(TrainingSession $session): void
    {
        $this->queue($session);
    }

    public function deleted(TrainingSession $session): void
    {
        $this->queue($session);
    }

    private function queue(TrainingSession $session): void
    {
        $user = $session->user;

        if ($user?->sendsWorkoutsViaGarminApi()) {
            SyncGarminWorkoutsJob::dispatch($user->id)->delay(now()->addSeconds(SyncGarminWorkoutsJob::DELAY_SECONDS));
        }

        if ($user?->corosConnection) {
            SyncCorosWorkoutsJob::dispatch($user->id)->delay(now()->addSeconds(SyncCorosWorkoutsJob::DELAY_SECONDS));
        }

        // intervals.icu nur fuer Radeinheiten (Zwift); alles andere geht dort nicht hin.
        if ($user?->intervalsConnection && ($session->hasBikeSteps() || $session->wasChanged('steps') || ! $session->exists)) {
            SyncIntervalsWorkoutsJob::dispatch($user->id)->delay(now()->addSeconds(SyncIntervalsWorkoutsJob::DELAY_SECONDS));
        }

        // ROUVY ebenso nur fuer Radeinheiten.
        if ($user?->rouvyConnection && ($session->hasBikeSteps() || $session->wasChanged('steps') || ! $session->exists)) {
            \App\Jobs\SyncRouvyWorkoutsJob::dispatch($user->id)->delay(now()->addSeconds(\App\Jobs\SyncRouvyWorkoutsJob::DELAY_SECONDS));
        }
    }
}

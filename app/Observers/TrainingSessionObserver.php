<?php

namespace App\Observers;

use App\Jobs\SyncGarminWorkoutsJob;
use App\Models\TrainingSession;

/**
 * Jede Aenderung an einer Einheit — Coach, Tagesform-Vorschlag, Tausch,
 * Neuberechnung — zieht den Garmin-Kalender nach. Ein Weg fuer alle
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
    }
}

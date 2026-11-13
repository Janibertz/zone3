<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WhoopWebhookEvent;
use App\Services\Whoop\WhoopActivityImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Ein Workout, das WHOOP per Webhook gemeldet hat (anlegen/ändern oder löschen). */
class ImportWhoopWorkoutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $userId, public string $workoutId, public bool $deleted = false, public ?int $eventId = null) {}

    public function handle(WhoopActivityImporter $importer): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $outcome = $this->deleted
            ? $importer->deleteById($user, $this->workoutId)
            : $importer->importById($user, $this->workoutId);

        if ($this->eventId) {
            WhoopWebhookEvent::whereKey($this->eventId)->update(['outcome' => $outcome]);
        }
    }
}

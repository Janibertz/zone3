<?php

namespace App\Jobs;

use App\Models\IntervalsWebhookEvent;
use App\Models\User;
use App\Services\Intervals\IntervalsActivityImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Eine Fahrt aus dem intervals-Webhook übernehmen oder löschen. */
class ImportIntervalsActivityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public readonly int $userId,
        public readonly string $activityId,
        public readonly bool $deleted = false,
        public readonly ?int $eventId = null,
        public readonly ?array $summary = null,
    ) {}

    public function handle(IntervalsActivityImporter $importer): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $outcome = $this->deleted
            ? $importer->deleteById($user, $this->activityId)
            : $importer->importById($user, $this->activityId, $this->summary);

        if ($this->eventId) {
            IntervalsWebhookEvent::whereKey($this->eventId)->update(['outcome' => $outcome]);
        }
    }
}

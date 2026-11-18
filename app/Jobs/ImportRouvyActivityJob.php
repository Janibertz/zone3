<?php

namespace App\Jobs;

use App\Models\RouvyWebhookEvent;
use App\Models\User;
use App\Services\Rouvy\RouvyActivityImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Eine Fahrt aus dem ROUVY-Webhook übernehmen. */
class ImportRouvyActivityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public readonly int $userId,
        public readonly array $activity,
        public readonly ?int $eventId = null,
    ) {}

    public function handle(RouvyActivityImporter $importer): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $outcome = $importer->importPayload($user, $this->activity);

        if ($this->eventId) {
            RouvyWebhookEvent::whereKey($this->eventId)->update(['outcome' => $outcome]);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\PolarWebhookEvent;
use App\Models\User;
use App\Services\Polar\PolarActivityImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Ein Training, das Polar per Webhook gemeldet hat. */
class ImportPolarExerciseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $userId, public string $exerciseId, public ?int $eventId = null) {}

    public function handle(PolarActivityImporter $importer): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $outcome = $importer->importById($user, $this->exerciseId);

        if ($this->eventId) {
            PolarWebhookEvent::whereKey($this->eventId)->update(['outcome' => $outcome]);
        }
    }
}

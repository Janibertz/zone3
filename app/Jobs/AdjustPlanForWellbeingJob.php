<?php

namespace App\Jobs;

use App\Models\TrainingSession;
use App\Models\WellbeingEntry;
use App\Models\User;
use App\Services\AI\SessionContentService;
use App\Services\GarminHealthSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AdjustPlanForWellbeingJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(
        public readonly int $userId,
        public readonly int $wellbeingEntryId,
    ) {}

    public function handle(SessionContentService $sessions, GarminHealthSummary $garmin): void
    {
        $user = User::find($this->userId);
        if (! $user) return;

        $wellbeing = WellbeingEntry::find($this->wellbeingEntryId);
        if (! $wellbeing) return;

        $today = $wellbeing->date->format('Y-m-d');

        // Find today's planned (non-rest) session in the active plan
        $session = TrainingSession::where('user_id', $this->userId)
            ->where('planned_date', $today)
            ->where('status', 'planned')
            ->where('type', '!=', 'rest')
            ->whereHas('trainingPlan', fn ($q) => $q->where('is_active', true))
            ->first();

        if (! $session) {
            Log::info('AdjustPlanForWellbeingJob: no planned session for today', [
                'user_id' => $this->userId,
                'date'    => $today,
            ]);
            return;
        }

        // Die gemessenen Werte des Tages — nicht der Wochenschnitt. Fuer die
        // Frage "was mache ich heute" ist der zu traege: wer nach vier
        // Stunden Schlaf aufsteht, soll heute etwas anderes laufen und nicht
        // erst, wenn der Schnitt nachgezogen hat.
        $metrics = $garmin->forDay($this->userId, \Carbon\CarbonImmutable::parse($today));

        $adjusted = $sessions->adjustSessionForWellbeing($session->toArray(), $wellbeing, $metrics);

        if (! $adjusted) {
            Log::warning('AdjustPlanForWellbeingJob: AI returned no result', [
                'user_id'    => $this->userId,
                'session_id' => $session->id,
            ]);
            return;
        }

        $session->update(array_intersect_key($adjusted, array_flip([
            'type', 'title', 'description', 'distance_km',
            'duration_min', 'pace_target', 'zone', 'intensity',
        ])));

        Log::info('AdjustPlanForWellbeingJob: session adjusted', [
            'user_id'      => $this->userId,
            'session_id'   => $session->id,
            'new_type'     => $adjusted['type'] ?? null,
            'garmin_flags' => $metrics['flags'] ?? [],
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('AdjustPlanForWellbeingJob failed', [
            'user_id' => $this->userId,
            'error'   => $e->getMessage(),
        ]);
    }
}

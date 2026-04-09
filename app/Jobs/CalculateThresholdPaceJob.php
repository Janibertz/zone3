<?php

namespace App\Jobs;

use App\Models\RunnerProfile;
use App\Models\User;
use App\Services\OpenAIService;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CalculateThresholdPaceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 90;

    public function __construct(public readonly int $userId) {}

    public function handle(OpenAIService $openAI, WebPushService $webPush): void
    {
        $user = User::find($this->userId);
        if (! $user) return;

        $last20 = $user->activities()
            ->where('type', 'Run')
            ->where('average_speed', '>', 0)
            ->where('distance', '>', 0)
            ->orderByDesc('start_date')
            ->limit(20)
            ->get()
            ->toArray();

        if (empty($last20)) {
            RunnerProfile::where('user_id', $this->userId)
                ->update(['threshold_pace_calculating' => false]);
            return;
        }

        $profile = RunnerProfile::firstOrCreate(
            ['user_id' => $this->userId],
            ['has_completed_setup' => false]
        );

        $thresholdPace = $openAI->calculateThresholdPaceWithAI($last20, $profile->threshold_heart_rate);

        if ($thresholdPace === null) {
            $profile->threshold_pace_calculating = false;
            $profile->save();
            return;
        }

        $mins = (int) $thresholdPace;
        $secs = (int) (($thresholdPace - $mins) * 60);
        $paceFormatted = sprintf('%d:%02d', $mins, $secs);

        $history = $profile->threshold_pace_history ?? [];
        $history[] = [
            'date'           => now()->format('d.m.Y'),
            'pace'           => round($thresholdPace, 4),
            'pace_formatted' => $paceFormatted,
        ];

        $profile->threshold_speed              = $thresholdPace;
        $profile->threshold_pace_calculated_at = now();
        $profile->threshold_pace_history       = array_slice($history, -30);
        $profile->pace_zones                   = $profile->calculatePaceZones();
        $profile->threshold_pace_calculating   = false;
        $profile->save();

        // Notify user if pace changed and notifications are enabled
        if ($user->push_notifications_enabled && $user->notify_threshold_pace) {
            $webPush->sendToUser(
                $user,
                'Schwellenpace aktualisiert 🏃',
                "Deine neue Schwellenpace: {$paceFormatted} min/km",
                '/profile'
            );
        }
    }

    public function failed(\Throwable $e): void
    {
        RunnerProfile::where('user_id', $this->userId)
            ->update(['threshold_pace_calculating' => false]);
    }
}

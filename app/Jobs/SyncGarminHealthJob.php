<?php

namespace App\Jobs;

use App\Models\GarminDailyMetric;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-only pull of Garmin recovery data (HRV, sleep, resting HR, Body Battery,
 * stress, steps, Training Readiness) via the fit-service, upserted into
 * garmin_daily_metrics. Never writes back to Garmin.
 */
class SyncGarminHealthJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 180;

    public function __construct(
        public readonly int $userId,
        public readonly int $days = 7,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user || empty($user->garmin_session)) {
            return;
        }

        $serviceUrl = config('services.fit.service_url');
        if (! $serviceUrl) {
            Log::warning('SyncGarminHealthJob: FIT_SERVICE_URL not set');
            return;
        }

        try {
            $response = Http::timeout($this->timeout - 10)
                ->post(rtrim($serviceUrl, '/') . '/garmin-health', [
                    'garmin_session' => $user->garmin_session,
                    'days'           => $this->days,
                ]);
        } catch (\Throwable $e) {
            Log::warning('SyncGarminHealthJob: fit-service unreachable', [
                'user_id' => $this->userId,
                'error'   => $e->getMessage(),
            ]);
            return;
        }

        // Expired session → clear tokens so the UI prompts a fresh login again
        if ($response->status() === 401) {
            $user->update(['garmin_email' => null, 'garmin_session' => null]);
            Log::info('SyncGarminHealthJob: garmin session expired, cleared', [
                'user_id' => $this->userId,
            ]);
            return;
        }

        if (! $response->successful()) {
            Log::warning('SyncGarminHealthJob: fit-service error', [
                'user_id' => $this->userId,
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);
            return;
        }

        $days = $response->json('days') ?? [];
        $upserted = 0;

        foreach ($days as $day) {
            $date = $day['date'] ?? null;
            if (! $date) {
                continue;
            }

            $values = array_intersect_key($day, array_flip([
                'hrv', 'resting_hr', 'sleep_hours', 'sleep_score',
                'body_battery_low', 'body_battery_high', 'stress_avg',
                'steps', 'training_readiness',
            ]));

            // Skip days that are entirely empty so we don't store rows of nulls.
            if (! collect($values)->filter(fn ($v) => $v !== null)->count()) {
                continue;
            }

            GarminDailyMetric::updateOrCreate(
                ['user_id' => $this->userId, 'date' => $date],
                $values,
            );
            $upserted++;
        }

        Log::info('SyncGarminHealthJob: done', [
            'user_id'  => $this->userId,
            'days'     => count($days),
            'upserted' => $upserted,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncGarminHealthJob failed', [
            'user_id' => $this->userId,
            'error'   => $e->getMessage(),
        ]);
    }
}

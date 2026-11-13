<?php

namespace App\Services\Polar;

use App\Models\GarminConnection;
use App\Models\GarminDailyMetric;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Erholungswerte von Polar in `garmin_daily_metrics` (Jan, 13.11.2026:
 * „auch Schlaf und HRV“, VO2max als VDOT-Quelle).
 *
 * - **Schlaf** (`/v3/users/sleep`, 28 Tage): Dauer = leicht + tief + REM,
 *   `sleep_score`. Das Datum ist Polars Datum der Nacht (Tag des Aufwachens).
 * - **Nightly Recharge** (`/v3/users/nightly-recharge`, 28 Tage): HRV als
 *   RMSSD in ms — dasselbe Maß wie Garmin, also eine Grundlinie. Der Puls
 *   der ersten vier Schlafstunden steht als Ruhepuls (liegt meist ein,
 *   zwei Schläge darüber; Polar liefert keinen Tages-Ruhepuls). Atemfrequenz.
 * - **VO2max** aus der Physical info, auf heute (wie bei COROS).
 *
 * Polar schickt für Schlaf zwar Webhooks, aber ein Abruf je Stunde reicht
 * und hält es bei einem Weg. Kommen schon Werte von Garmin oder COROS,
 * bleibt Polar draußen — zwei Uhren messen eine Nacht nie gleich.
 */
class PolarHealthImporter
{
    public const SOURCE = 'polar';

    public function __construct(private readonly PolarClient $client) {}

    public function reads(User $user): bool
    {
        return (bool) $user->polarConnection?->readsData()
            && ! $user->garminConnection?->allows(GarminConnection::HEALTH)
            && ! $user->corosConnection?->readsData();
    }

    /** @return array<string, int>|null geschriebene Tage je Spalte; null = nicht abgefragt */
    public function sync(User $user): ?array
    {
        if (! $this->reads($user)) {
            return null;
        }

        $connection = $user->polarConnection;
        $byDay      = [];

        $parts = [
            'sleep'    => fn () => $this->sleep($this->client->userData($connection, '/v3/users/sleep')),
            'recharge' => fn () => $this->recharge($this->client->userData($connection, '/v3/users/nightly-recharge')),
            'physical' => fn () => $this->physical($this->client->userData($connection, '/v3/users/physical-info')),
        ];

        $failed = 0;
        foreach ($parts as $part => $fetch) {
            try {
                foreach ($fetch() as $date => $values) {
                    $byDay[$date] = ($byDay[$date] ?? []) + $values;
                }
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Polar-Erholungswerte: Abruf fehlgeschlagen', ['user_id' => $user->id, 'part' => $part, 'error' => $e->getMessage()]);
            }
        }

        if ($failed === count($parts)) {
            return null;
        }

        $written = [];
        foreach ($byDay as $date => $values) {
            $values = array_filter($values, fn ($v) => $v !== null);
            if ($values === []) {
                continue;
            }

            // whereDate, nicht where: SQLite speichert das Datum mit Uhrzeit.
            $metric = GarminDailyMetric::where('user_id', $user->id)->whereDate('date', $date)->first()
                ?? new GarminDailyMetric(['user_id' => $user->id, 'date' => $date]);

            $metric->fill($values + ['source' => self::SOURCE])->save();

            foreach (array_keys($values) as $column) {
                $written[$column] = ($written[$column] ?? 0) + 1;
            }
        }

        return $written;
    }

    /** @return array<string, array> */
    public function sleep(array $data): array
    {
        $out = [];
        foreach ((array) ($data['nights'] ?? []) as $night) {
            if (empty($night['date'])) {
                continue;
            }
            $seconds = (int) ($night['light_sleep'] ?? 0) + (int) ($night['deep_sleep'] ?? 0) + (int) ($night['rem_sleep'] ?? 0);

            $out[$night['date']] = [
                'sleep_hours' => $seconds > 0 ? round($seconds / 3600, 2) : null,
                'sleep_score' => isset($night['sleep_score']) && $night['sleep_score'] > 0 ? (int) round($night['sleep_score']) : null,
            ];
        }

        return $out;
    }

    /** @return array<string, array> */
    public function recharge(array $data): array
    {
        $out = [];
        foreach ((array) ($data['recharges'] ?? []) as $night) {
            if (empty($night['date'])) {
                continue;
            }

            $out[$night['date']] = [
                'hrv'              => ($night['heart_rate_variability_avg'] ?? 0) > 0 ? (int) round($night['heart_rate_variability_avg']) : null,
                'resting_hr'       => ($night['heart_rate_avg'] ?? 0) > 0 ? (int) round($night['heart_rate_avg']) : null,
                'respiration_rate' => ($night['breathing_rate_avg'] ?? 0) > 0 ? round((float) $night['breathing_rate_avg'], 1) : null,
            ];
        }

        return $out;
    }

    /** @return array<string, array> */
    public function physical(array $data): array
    {
        $vo2 = $data['vo2_max'] ?? null;

        return $vo2 && $vo2 > 0 ? [now()->toDateString() => ['vo2max' => (float) $vo2]] : [];
    }

    /** Beim Trennen: nur was von Polar kam. */
    public function forget(User $user): void
    {
        GarminDailyMetric::where('user_id', $user->id)->where('source', self::SOURCE)->delete();
    }
}

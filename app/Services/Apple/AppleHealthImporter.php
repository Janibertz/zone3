<?php

namespace App\Services\Apple;

use App\Models\GarminDailyMetric;
use App\Models\User;

/**
 * Erholungswerte aus Apple Health in `garmin_daily_metrics` (source = apple).
 *
 * HealthKit gibt es nur auf dem iPhone; der Server kann nichts abfragen.
 * Die iOS-App liest beim Öffnen und beim Zurückkehren in die App und
 * schickt Tageswerte (`POST /health/apple/metrics`). Gelesen werden genau
 * die Arten, die Jan freigegeben hat: Schlafdauer, HRV, Ruhepuls, VO2max,
 * Blutsauerstoff, Atemfrequenz. Nie Zyklusdaten.
 *
 * Garmin und COROS haben Vorrang. Kommen dort Erholungswerte an, nimmt der
 * Server nichts von Apple an und räumt alte Apple-Zeilen weg — sonst stünde
 * eine Apple-HRV (SDNN) in derselben Grundlinie wie eine Garmin-HRV (RMSSD).
 */
class AppleHealthImporter
{
    public const SOURCE = 'apple';

    /** Spalte je Feld der App. */
    private const COLUMNS = [
        'sleep_min'   => 'sleep_hours',
        'hrv_ms'      => 'hrv',
        'resting_hr'  => 'resting_hr',
        'vo2max'      => 'vo2max',
        'spo2'        => 'spo2',
        'respiration' => 'respiration_rate',
    ];

    /**
     * @param  list<array<string, mixed>>  $days  wie `AppleHealthController::metrics()` sie prüft
     * @return array<string, int>|null geschriebene Tage je Spalte; null = nicht angenommen
     */
    public function store(User $user, array $days): ?array
    {
        if (! $user->apple_health_consent_at) {
            return null;
        }

        if ($user->receivesWatchHealthMetrics()) {
            $this->forget($user);

            return null;
        }

        $written = [];
        foreach ($days as $day) {
            $values = [];
            foreach (self::COLUMNS as $field => $column) {
                if (! isset($day[$field])) {
                    continue;
                }
                $values[$column] = match ($field) {
                    'sleep_min'  => round($day[$field] / 60, 2),
                    'resting_hr' => (int) round($day[$field]),
                    default      => round((float) $day[$field], 1),
                };
            }

            if ($values === []) {
                continue;
            }

            // whereDate, nicht where: SQLite speichert das Datum mit Uhrzeit.
            $metric = GarminDailyMetric::where('user_id', $user->id)->whereDate('date', $day['date'])->first();

            // Eine Zeile einer Uhr bleibt, wie sie ist (Vorrang).
            if ($metric && $metric->source !== null && $metric->source !== self::SOURCE) {
                continue;
            }

            ($metric ?? new GarminDailyMetric(['user_id' => $user->id, 'date' => $day['date']]))
                ->fill($values + ['source' => self::SOURCE])
                ->save();

            foreach (array_keys($values) as $column) {
                $written[$column] = ($written[$column] ?? 0) + 1;
            }
        }

        $user->forceFill(['apple_health_synced_at' => now()])->save();

        return $written;
    }

    /** Beim Widerruf oder wenn eine Uhr übernimmt: nur was von Apple kam. */
    public function forget(User $user): void
    {
        GarminDailyMetric::where('user_id', $user->id)->where('source', self::SOURCE)->delete();
    }
}

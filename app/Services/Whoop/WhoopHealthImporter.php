<?php

namespace App\Services\Whoop;

use App\Models\GarminConnection;
use App\Models\GarminDailyMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Erholungswerte von WHOOP in `garmin_daily_metrics` (`source = whoop`).
 *
 * - **Schlaf** (`/v2/activity/sleep`): Dauer = leicht + tief + REM, Atemfrequenz.
 *   Ein Nickerchen (`nap`) zählt nicht. Der Tag ist der Tag des Aufwachens
 *   in WHOOPs Ortszeit (`end` + `timezone_offset`), wie bei Apple Health.
 * - **Recovery** (`/v2/recovery`): HRV als RMSSD in ms (dasselbe Maß wie
 *   Garmin und Polar), Ruhepuls, Blutsauerstoff. Sie hängt an einem Schlaf
 *   (`sleep_id`) und bekommt dessen Tag.
 * - Nur `score_state = SCORED`; „wird noch berechnet“ kommt per Webhook nach.
 * - **Kein `sleep_score`:** WHOOPs „Sleep Performance“ ist ein Anteil am
 *   Schlafbedarf, kein Schlafwert wie bei Garmin — die Kachel mit Garmins
 *   Stufen (60/80/90) würde ihn falsch einordnen.
 * - **Recovery-Wert** (0–100 %) in eigener Spalte `recovery_score`, nicht als
 *   Body Battery (Jan, 13.11.2026): Kachel unter Erholung und Zeile für den
 *   Coach, aber kein Signal in `FatigueCheck` — WHOOP rechnet ihn großteils
 *   aus HRV und Ruhepuls, die dort schon zählen.
 *
 * Kommen schon Werte von Garmin, COROS oder Polar, bleibt WHOOP draußen —
 * zwei Geräte messen eine Nacht nie gleich.
 */
class WhoopHealthImporter
{
    public const SOURCE = 'whoop';

    public const BACKFILL_DAYS = 30;

    public const SYNC_DAYS = 3;

    public function __construct(private readonly WhoopClient $client) {}

    public function reads(User $user): bool
    {
        return (bool) $user->whoopConnection?->readsData()
            && ! $user->garminConnection?->allows(GarminConnection::HEALTH)
            && ! $user->corosConnection?->readsData()
            && ! $user->polarConnection?->readsData();
    }

    /** @return array<string, int>|null geschriebene Tage je Spalte; null = nicht abgefragt */
    public function sync(User $user): ?array
    {
        if (! $this->reads($user)) {
            return null;
        }

        $connection = $user->whoopConnection;
        // Einen Tag mehr, damit die Recovery ihren Schlaf findet.
        $since = now()->subDays(($connection->health_synced_at ? self::SYNC_DAYS : self::BACKFILL_DAYS) + 1);

        try {
            $sleeps     = $this->client->sleeps($connection, $since);
            $recoveries = $this->client->recoveries($connection, $since);
        } catch (Throwable $e) {
            Log::warning('WHOOP-Erholungswerte: Abruf fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            if ($e instanceof WhoopException && $e->revoked()) {
                $connection->update(['last_error' => 'WHOOP nimmt die Verbindung nicht mehr an', 'last_error_at' => now()]);
            }

            return null;
        }

        $written = $this->write($user, $this->byDay($sleeps, $recoveries));
        $connection->update(['health_synced_at' => now(), 'last_error' => null, 'last_error_at' => null]);

        return $written;
    }

    /**
     * Schlaf und Recovery je Tag des Aufwachens.
     *
     * @return array<string, array>
     */
    public function byDay(array $sleeps, array $recoveries): array
    {
        $days      = [];
        $sleepDays = [];

        foreach ($sleeps as $sleep) {
            if (! empty($sleep['nap']) || ($sleep['score_state'] ?? null) !== 'SCORED' || empty($sleep['end'])) {
                continue;
            }
            $date = self::wakeDay($sleep);
            if (! $date) {
                continue;
            }
            $sleepDays[(string) ($sleep['id'] ?? '')] = $date;

            $stages  = (array) ($sleep['score']['stage_summary'] ?? []);
            $asleep  = (int) ($stages['total_light_sleep_time_milli'] ?? 0)
                + (int) ($stages['total_slow_wave_sleep_time_milli'] ?? 0)
                + (int) ($stages['total_rem_sleep_time_milli'] ?? 0);
            $breath  = (float) ($sleep['score']['respiratory_rate'] ?? 0);

            // Bei zwei Hauptschlafphasen an einem Tag zählt die längere.
            $hours = $asleep > 0 ? round($asleep / 3_600_000, 2) : null;
            if ($hours !== null && ($days[$date]['sleep_hours'] ?? 0) >= $hours) {
                continue;
            }

            $days[$date] = array_merge($days[$date] ?? [], [
                'sleep_hours'      => $hours,
                'respiration_rate' => $breath > 0 ? round($breath, 1) : null,
            ]);
        }

        foreach ($recoveries as $recovery) {
            $date = $sleepDays[(string) ($recovery['sleep_id'] ?? '')] ?? null;
            if (! $date || ($recovery['score_state'] ?? null) !== 'SCORED') {
                continue;
            }
            $score = (array) ($recovery['score'] ?? []);

            $days[$date] = array_merge($days[$date] ?? [], [
                'hrv'        => ($score['hrv_rmssd_milli'] ?? 0) > 0 ? (int) round($score['hrv_rmssd_milli']) : null,
                'resting_hr' => ($score['resting_heart_rate'] ?? 0) > 0 ? (int) round($score['resting_heart_rate']) : null,
                'spo2'       => ($score['spo2_percentage'] ?? 0) > 0 ? round((float) $score['spo2_percentage'], 1) : null,
                'recovery_score' => is_numeric($score['recovery_score'] ?? null)
                    ? max(0, min(100, (int) round($score['recovery_score']))) : null,
            ]);
        }

        return $days;
    }

    /** Tag des Aufwachens in der Ortszeit, die WHOOP mitschickt. */
    public static function wakeDay(array $sleep): ?string
    {
        try {
            $end    = Carbon::parse($sleep['end'])->utc();
            $offset = (string) ($sleep['timezone_offset'] ?? '+00:00');

            return preg_match('/^[+-]\d{2}:\d{2}$/', $offset)
                ? $end->setTimezone($offset)->toDateString()
                : $end->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, int> */
    private function write(User $user, array $byDay): array
    {
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

    /** Beim Trennen: nur was von WHOOP kam. */
    public function forget(User $user): void
    {
        GarminDailyMetric::where('user_id', $user->id)->where('source', self::SOURCE)->delete();
    }
}

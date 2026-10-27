<?php

namespace App\Services\Garmin;

use App\Models\GarminConnection;
use App\Models\GarminDailyMetric;
use App\Models\GarminWebhookEvent;

/**
 * Erholungswerte aus einem Health-Push in `garmin_daily_metrics`.
 *
 * Dieselben Spalten wie der alte Abruf ueber den fit-service, damit
 * Dashboard, Plan und Tagesform-Vorschlag nichts davon merken. Ein Tag setzt
 * sich aus mehreren Summaries zusammen (Dailies, Sleeps, HRV, Stress), die
 * einzeln und zu verschiedenen Zeiten ankommen — darum schreibt jede nur ihre
 * eigenen Spalten und laesst die anderen stehen. Ein fehlender Wert bleibt
 * null ("keine Daten"), nie 0.
 *
 * Feldnamen nach der Health API Specification 1.2.2 (Stand 09/2025). Jeder
 * Push wird trotzdem 14 Tage roh mitgespeichert: kommt ein Wert nicht an,
 * steht dort, was Garmin wirklich geschickt hat.
 */
class GarminHealthImporter
{
    /** Schluessel im Push → welche Spalten daraus kommen. */
    public const TYPES = ['dailies', 'sleeps', 'hrv', 'stressDetails', 'userMetrics'];

    /**
     * @return array<string, int> je Summary-Typ die Zahl geschriebener Tage
     */
    public function import(array $payload): array
    {
        $written = [];

        foreach (self::TYPES as $type) {
            foreach ((array) ($payload[$type] ?? []) as $summary) {
                if (! is_array($summary)) {
                    continue;
                }

                $garminUserId = (string) ($summary['userId'] ?? '');
                $connection   = $garminUserId !== '' ? GarminConnection::where('garmin_user_id', $garminUserId)->first() : null;

                $event = GarminWebhookEvent::create([
                    'type'           => $type,
                    'garmin_user_id' => $garminUserId ?: null,
                    'user_id'        => $connection?->user_id,
                    'payload'        => json_encode($summary),
                ]);

                if (! $connection) {
                    $event->update(['outcome' => GarminWebhookEvent::OUTCOME_UNKNOWN_USER]);
                    continue;
                }

                // Hat der Athlet die Gesundheitsdaten in Garmin Connect
                // abgeschaltet, kommt nichts mehr an — und was trotzdem kommt,
                // wird nicht gespeichert.
                if (! $connection->allows(GarminConnection::HEALTH)) {
                    $event->update(['outcome' => GarminWebhookEvent::OUTCOME_NOT_PERMITTED]);
                    continue;
                }

                $date   = $summary['calendarDate'] ?? null;
                $values = $this->values($type, $summary);

                if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || $values === []) {
                    $event->update(['outcome' => GarminWebhookEvent::OUTCOME_NOTHING_TO_STORE]);
                    continue;
                }

                // whereDate, nicht where: SQLite speichert das Datum mit Uhrzeit.
                $metric = GarminDailyMetric::where('user_id', $connection->user_id)->whereDate('date', $date)->first()
                    ?? new GarminDailyMetric(['user_id' => $connection->user_id, 'date' => $date]);

                // Der Stress-Verlauf kann in Teilen kommen. Tiefster und
                // hoechster Wert gelten dann ueber alle Teile, nicht nur ueber
                // den letzten.
                if ($type === 'stressDetails' && $metric->exists) {
                    if ($metric->body_battery_low !== null) {
                        $values['body_battery_low'] = min($values['body_battery_low'], $metric->body_battery_low);
                    }
                    if ($metric->body_battery_high !== null) {
                        $values['body_battery_high'] = max($values['body_battery_high'], $metric->body_battery_high);
                    }
                }

                $metric->fill($values)->save();

                $event->update(['outcome' => GarminWebhookEvent::OUTCOME_STORED, 'note' => implode(',', array_keys($values))]);
                $written[$type] = ($written[$type] ?? 0) + 1;
            }
        }

        return $written;
    }

    /** Die Spalten, die dieser Summary-Typ belegt — nur die, die einen Wert haben. */
    public function values(string $type, array $s): array
    {
        $values = match ($type) {
            'dailies' => [
                'resting_hr'        => $this->positive($s['restingHeartRateInBeatsPerMinute'] ?? null),
                // -1 heisst bei Garmin "zu wenig Daten", nicht "kein Stress".
                'stress_avg'        => $this->positive($s['averageStressLevel'] ?? null),
                'steps'             => $this->positive($s['steps'] ?? null, allowZero: true),
            ],
            'sleeps' => [
                'sleep_hours' => $this->sleepHours($s),
                'sleep_score' => $this->positive($s['overallSleepScore']['value'] ?? null),
            ],
            'hrv' => [
                'hrv' => $this->positive($s['lastNightAvg'] ?? null, float: true),
            ],
            'stressDetails' => $this->bodyBattery($s['timeOffsetBodyBatteryValues'] ?? null),
            default => [],
        };

        return array_filter($values, fn ($v) => $v !== null);
    }

    /**
     * Geschlafen, ohne Wachphasen. `durationInSeconds` zaehlt laut
     * Spezifikation die Wachzeit nicht mit; fehlt es, die Summe der Phasen.
     */
    private function sleepHours(array $s): ?float
    {
        $phases = array_filter([
            $s['deepSleepDurationInSeconds'] ?? null,
            $s['lightSleepDurationInSeconds'] ?? null,
            $s['remSleepInSeconds'] ?? null,
        ], 'is_numeric');

        $seconds = $s['durationInSeconds'] ?? ($phases !== [] ? array_sum($phases) : null);

        return is_numeric($seconds) && $seconds > 0 ? round($seconds / 3600, 2) : null;
    }

    /** Tiefster und hoechster Body-Battery-Wert des Tages aus dem Verlauf. */
    private function bodyBattery(mixed $series): array
    {
        $levels = array_values(array_filter((array) $series, fn ($v) => is_numeric($v) && $v >= 0));

        return $levels === [] ? [] : [
            'body_battery_low'  => (int) min($levels),
            'body_battery_high' => (int) max($levels),
        ];
    }

    private function positive(mixed $value, bool $allowZero = false, bool $float = false): int|float|null
    {
        if (! is_numeric($value) || $value < 0 || (! $allowZero && $value == 0)) {
            return null;
        }

        return $float ? round((float) $value, 1) : (int) round($value);
    }
}

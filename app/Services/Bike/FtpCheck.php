<?php

namespace App\Services\Bike;

use App\Models\Activity;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Lag eine Fahrt über der eingetragenen FTP?
 *
 * Zone3 schätzt die FTP nicht selbst — dafür bräuchte es den Leistungsverlauf
 * jeder Fahrt, und Strava liefert pro Fahrt nur Durchschnitt und Normalized
 * Power. Was sich damit ehrlich sagen lässt, ist eine Untergrenze: wer
 * 40 Minuten lang 260 W Normalized Power fährt, hat keine FTP von 230 W.
 * Genau das meldet der Hinweis, und der Athlet entscheidet, ob er den Wert
 * übernimmt.
 */
class FtpCheck
{
    /** Kürzer ist eine Fahrt kein Beleg — 5 Minuten hält jeder über der FTP. */
    public const MIN_MINUTES = 20;

    /** Ab hier ist die gefahrene Leistung die FTP selbst, ohne Abschlag. */
    public const FULL_MINUTES = 60;

    /** 3 % Luft, sonst meldet jede gute Tagesform eine „neue" FTP. */
    public const MARGIN = 1.03;

    public const LOOKBACK_DAYS = 42;

    public const RIDE_TYPES = ['Ride', 'VirtualRide'];

    /**
     * Die FTP, die diese Fahrt mindestens belegt — oder null.
     *
     * 20 Minuten zählen mit 95 % (der klassische FTP-Test), ab 60 Minuten
     * mit 100 %, dazwischen linear. Gemessen wird an der Normalized Power,
     * sonst am Durchschnitt.
     */
    public function estimate(Activity $activity): ?int
    {
        if (! in_array($activity->type, self::RIDE_TYPES, true)) {
            return null;
        }

        // Geschätzte Watt (Rad ohne Leistungsmesser) sind keine Messung.
        // Ältere Fahrten kennen die Herkunft nicht — dann nur Zwift, wo die
        // Watt immer vom Trainer kommen.
        if ($activity->device_watts === false
            || ($activity->device_watts === null && $activity->type !== 'VirtualRide')) {
            return null;
        }

        $minutes = ($activity->moving_time ?? 0) / 60;
        $power   = $activity->weighted_average_watts ?: $activity->average_watts;

        if ($minutes < self::MIN_MINUTES || ! $power) {
            return null;
        }

        $share  = min(1, ($minutes - self::MIN_MINUTES) / (self::FULL_MINUTES - self::MIN_MINUTES));
        $factor = 0.95 + 0.05 * $share;

        return (int) round($power * $factor);
    }

    /**
     * Der Hinweis zu einer Fahrt, wenn sie über der FTP lag.
     *
     * @return array{estimate: int, current: int|null}|null
     */
    public function forActivity(Activity $activity, ?int $ftp): ?array
    {
        $estimate = $this->estimate($activity);

        if (! $estimate || ($ftp && $estimate <= $ftp * self::MARGIN)) {
            return null;
        }

        return ['estimate' => $estimate, 'current' => $ftp];
    }

    /**
     * Die stärkste Fahrt der letzten sechs Wochen, wenn sie über der FTP lag.
     *
     * Für das Profil: dort steht der Vorschlag neben dem Feld, mit einem
     * Knopf zum Übernehmen.
     *
     * @return array{estimate: int, current: int|null, activity_id: int, activity_name: string, date: string}|null
     */
    public function suggestionFor(User $user): ?array
    {
        $ftp  = $user->runnerProfile?->ftp;
        $best = null;

        $rides = Activity::where('user_id', $user->id)
            ->whereIn('type', self::RIDE_TYPES)
            ->where('start_date', '>=', CarbonImmutable::today()->subDays(self::LOOKBACK_DAYS))
            ->where('moving_time', '>=', self::MIN_MINUTES * 60)
            ->get(Activity::SUMMARY_COLUMNS);

        foreach ($rides as $ride) {
            $estimate = $this->estimate($ride);
            if ($estimate && (! $best || $estimate > $best['estimate'])) {
                $best = [
                    'estimate'      => $estimate,
                    'current'       => $ftp,
                    'activity_id'   => $ride->id,
                    'activity_name' => $ride->name,
                    'date'          => $ride->start_date->format('d.m.'),
                ];
            }
        }

        if (! $best || ($ftp && $best['estimate'] <= $ftp * self::MARGIN)) {
            return null;
        }

        return $best;
    }
}

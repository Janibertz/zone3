<?php

namespace App\Services;

use App\Models\GarminDailyMetric;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Sieht die letzte Woche nach Ermüdung aus? Dann kommt die Entlastung früher.
 *
 * Der 3:1-Rhythmus hängt am Renntag ({@see PeriodizationService}) und weiß
 * nichts davon, wie es dem Athleten geht. Jan (30.10.2026): „Entlastung
 * sollte sich doch auch nach den Garmin und Tagesform richten." Vier Signale
 * über die letzten sieben Tage, jedes gegen die eigene Grundlinie:
 *
 *   · HRV-Schnitt unter dem Normalbereich (Schnitt − Streuung der 60 Tage
 *     davor, wie der HRV-Bogen auf dem Dashboard)
 *   · Ruhepuls-Schnitt mindestens 5 Schläge über dem Schnitt davor
 *   · Energie in der Tagesform im Schnitt höchstens 4
 *   · Muskelkater im Schnitt mindestens 7
 *
 * Erst zwei Signale zusammen ziehen die Entlastung vor — eines allein ist
 * eine schlechte Woche, kein Muster. Andersherum fällt keine Entlastung aus,
 * weil die Werte gut sind: Ermüdung zeigt sich oft erst spät.
 */
class FatigueCheck
{
    public const SIGNALS_NEEDED = 2;

    private const RECENT_DAYS     = 7;
    private const BASELINE_DAYS   = 60;
    private const MIN_BASELINE    = 7;
    private const MIN_RECENT      = 3;
    private const RESTING_HR_RISE = 5;
    private const LOW_ENERGY      = 4;
    private const HIGH_SORENESS   = 7;

    /** @return list<string> die Signale, die zutreffen */
    public function signals(User $user, ?CarbonImmutable $today = null): array
    {
        $today  = $today ?? CarbonImmutable::today();
        $recent = $today->subDays(self::RECENT_DAYS - 1)->toDateString();

        $signals = [];

        $metrics = GarminDailyMetric::where('user_id', $user->id)
            ->whereDate('date', '>=', $today->subDays(self::BASELINE_DAYS + self::RECENT_DAYS)->toDateString())
            ->whereDate('date', '<=', $today->toDateString())
            ->get(['date', 'hrv', 'resting_hr']);

        [$now, $before] = $metrics->partition(fn ($m) => $m->date->toDateString() >= $recent);

        $hrvNow    = $now->pluck('hrv')->filter(fn ($v) => $v !== null);
        $hrvBefore = $before->pluck('hrv')->filter(fn ($v) => $v !== null);
        if ($hrvNow->count() >= self::MIN_RECENT && $hrvBefore->count() >= self::MIN_BASELINE) {
            $mean = $hrvBefore->avg();
            $sd   = sqrt($hrvBefore->map(fn ($v) => ($v - $mean) ** 2)->avg());
            if ($hrvNow->avg() < $mean - $sd) {
                $signals[] = 'HRV unter dem Normalbereich';
            }
        }

        $rhrNow    = $now->pluck('resting_hr')->filter(fn ($v) => $v !== null);
        $rhrBefore = $before->pluck('resting_hr')->filter(fn ($v) => $v !== null);
        if ($rhrNow->count() >= self::MIN_RECENT && $rhrBefore->count() >= self::MIN_BASELINE
            && $rhrNow->avg() >= $rhrBefore->avg() + self::RESTING_HR_RISE) {
            $signals[] = 'Ruhepuls erhöht';
        }

        $wellbeing = $user->wellbeingEntries()
            ->whereDate('date', '>=', $recent)
            ->whereDate('date', '<=', $today->toDateString())
            ->get(['energy_level', 'muscle_soreness']);

        if ($wellbeing->count() >= self::MIN_RECENT) {
            $energy = $wellbeing->pluck('energy_level')->filter(fn ($v) => $v !== null);
            if ($energy->isNotEmpty() && $energy->avg() <= self::LOW_ENERGY) {
                $signals[] = 'wenig Energie';
            }

            $soreness = $wellbeing->pluck('muscle_soreness')->filter(fn ($v) => $v !== null);
            if ($soreness->isNotEmpty() && $soreness->avg() >= self::HIGH_SORENESS) {
                $signals[] = 'starker Muskelkater';
            }
        }

        return $signals;
    }

    public function isFatigued(User $user, ?CarbonImmutable $today = null): bool
    {
        return count($this->signals($user, $today)) >= self::SIGNALS_NEEDED;
    }
}

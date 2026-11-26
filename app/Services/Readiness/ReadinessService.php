<?php

namespace App\Services\Readiness;

use App\Models\GarminDailyMetric;
use App\Models\User;
use App\Services\TrainingLoadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Readiness — wie bereit ist der Körper heute für Training? (seit 18.11.2026)
 *
 * Jan: Garmin zeigte Trainingsbereitschaft 1 und empfahl einen Ruhetag,
 * Zone3 schlug einen lockeren Lauf vor. Garmins Wert kommt über keine API
 * (Health API 1.2.2, Activity API 2.0), COROS liefert „Unknown“, Polar und
 * WHOOP haben eigene Werte mit anderer Rechnung. Darum rechnet Zone3 selbst
 * — für alle Uhren gleich, ohne Modellaufruf, sobald die Werte der Nacht da
 * sind. Auch bei WHOOP gilt dieser Wert, nicht dessen Recovery (Jan).
 *
 * Fünf Teile, jeder 0–100, gewichtet; was fehlt, fällt aus der Gewichtung:
 *
 *   · HRV letzte Nacht gegen den eigenen Normalbereich (60 Tage)   30
 *   · Schlaf letzte Nacht, dazu die drei Nächte davor               25
 *   · Belastung: akute gegen gewohnte Last (ATL/CTL)                20
 *   · Ruhepuls gegen den eigenen Schnitt                            15
 *   · Tagesform (Energie, Muskelkater, Stress), wenn eingetragen    10
 *
 * Ohne einen Wert der Uhr von heute gibt es keine Readiness: aus Tagesform
 * und Belastung allein wäre es eine Schätzung, die wie eine Messung aussieht.
 * Krank oder verletzt in der Tagesform deckelt den Wert auf 10, zwei klare
 * Warnsignale (HRV, Ruhepuls, Belastung) deckeln ihn auf 24.
 *
 * Die Belastung zählt nur Aktivitäten einer Uhr, nie reine Strava-Zeilen —
 * der Wert steht in Prompts (Strava-Policy §5.3).
 *
 * Stufen wie bei Garmin: 1–24 schlecht, 25–49 niedrig, 50–74 mäßig,
 * 75–94 hoch, 95–100 optimal.
 *
 * Über den Tag (seit 26.11.2026, Jan: „er kann ja steigen oder auch
 * fallen“): der Morgenwert rechnet die Belastung bis gestern. Der aktuelle
 * Wert zieht davon ab, was heute trainiert wurde (ab dem Ende der Einheit,
 * in zwölf Stunden zur Hälfte erholt — der Rest steht morgen in der
 * Belastung), und mit Garmin, wie weit die Body Battery unter dem Hoch des
 * Tages steht (lädt sie wieder, steigt der Wert). Angezeigt wird immer der
 * aktuelle; Vorschläge (readiness:check, Tagesvorschlag, Tagesform)
 * rechnen mit dem Morgenwert, damit sich der Plan nicht von selbst ändert.
 */
class ReadinessService
{
    public const WEIGHTS = [
        'hrv'       => 30,
        'sleep'     => 25,
        'load'      => 20,
        'rhr'       => 15,
        'wellbeing' => 10,
    ];

    /** Unter diesem Wert schlägt der Coach einen Ruhetag vor. */
    public const REST_BELOW = 25;

    /** Unter diesem Wert schlägt der Coach statt einer harten Einheit eine lockere vor. */
    public const EASY_BELOW = 50;

    public const LEVELS = [
        ['from' => 1,  'key' => 'poor',     'label' => 'Schlecht', 'tone' => 'danger',  'advice' => 'Heute besser Ruhetag'],
        ['from' => 25, 'key' => 'low',      'label' => 'Niedrig',  'tone' => 'warn',    'advice' => 'Heute nur locker'],
        ['from' => 50, 'key' => 'moderate', 'label' => 'Mäßig',    'tone' => 'sky',     'advice' => 'Training wie geplant, auf den Körper hören'],
        ['from' => 75, 'key' => 'high',     'label' => 'Hoch',     'tone' => 'success', 'advice' => 'Bereit für eine harte Einheit'],
        ['from' => 95, 'key' => 'prime',    'label' => 'Optimal',  'tone' => 'accent',  'advice' => 'Bestens erholt'],
    ];

    /** Belastung ohne vier Wochen Daten: akute Last mindestens doppelt so hoch wie die gewohnte … */
    public const LOAD_RAMP_FACTOR = 2.0;

    /** … und nicht nur ein einzelner Lauf nach Wochen Pause. */
    public const LOAD_MIN_ATL = 15.0;

    /** Eine Einheit zieht höchstens so viele Punkte ab: TSS × Faktor. */
    public const TRAINING_DROP_PER_TSS = 0.35;
    public const TRAINING_DROP_MAX     = 30;

    /** Alle Einheiten des Tages zusammen höchstens. */
    public const TRAINING_DROP_DAY_MAX = 40;

    /** In so vielen Stunden nach dem Ende ist die Hälfte wieder erholt. */
    public const TRAINING_HALF_RECOVERED_HOURS = 12;

    /** Punkte je Body-Battery-Punkt unter dem Hoch des Tages, höchstens. */
    public const BODY_BATTERY_FACTOR = 0.2;
    public const BODY_BATTERY_MAX    = 15;

    /** Älter als das zählt der Stand der Body Battery nicht mehr. */
    public const BODY_BATTERY_FRESH_HOURS = 6;

    private const BASELINE_DAYS = 60;
    private const MIN_BASELINE  = 7;

    /** @var array<string, array|null> */
    private array $memo = [];

    public function __construct(private readonly TrainingLoadService $load) {}

    /**
     * @return array{
     *     score: int, level: string, label: string, tone: string, advice: string,
     *     reasons: list<string>, parts: array<string, int>, date: string
     * }|null
     */
    public function forDay(User $user, ?CarbonImmutable $day = null, bool $morning = false): ?array
    {
        $day = ($day ?? CarbonImmutable::today())->startOfDay();
        $key = $user->id . ':' . $day->toDateString() . ($morning ? ':m' : '');

        return $this->memo[$key] ??= $this->compute($user, $day, $morning);
    }

    /**
     * Der Wert vom Morgen: ohne das, was heute trainiert wurde, und ohne die
     * Body Battery seither. Für alles, was einen Vorschlag schreibt.
     */
    public function morning(User $user, ?CarbonImmutable $day = null): ?array
    {
        return $this->forDay($user, $day, morning: true);
    }

    private function compute(User $user, CarbonImmutable $day, bool $morning): ?array
    {
        if (! $user->receivesHealthMetrics()) {
            return null;
        }

        $rows = GarminDailyMetric::where('user_id', $user->id)
            ->whereDate('date', '>=', $day->subDays(self::BASELINE_DAYS)->toDateString())
            ->whereDate('date', '<=', $day->toDateString())
            ->orderBy('date')
            ->get();

        $today = $rows->first(fn ($r) => $r->date->toDateString() === $day->toDateString());
        if (! $today) {
            return null;
        }
        $before = $rows->filter(fn ($r) => $r->date->toDateString() < $day->toDateString());

        $parts   = [];
        $reasons = [];
        $alarms  = 0; // klare Warnsignale: HRV, Ruhepuls, Belastung

        // HRV: eine Streuung über dem Schnitt ist gut, eine darunter schlecht.
        if ($today->hrv !== null && ($base = $this->baseline($before, 'hrv', 3.0))) {
            $z = ($today->hrv - $base['mean']) / $base['sd'];
            $parts['hrv'] = $this->clamp(70 + $z * 22);
            $pct = ($today->hrv - $base['mean']) / $base['mean'] * 100;
            if ($z <= -1) {
                $alarms++;
                $reasons[] = sprintf('HRV %d %% unter deinem Normalbereich', round(abs($pct)));
            }
        }

        if ($today->resting_hr !== null && ($base = $this->baseline($before, 'resting_hr', 1.5))) {
            $z = ($base['mean'] - $today->resting_hr) / $base['sd'];
            $parts['rhr'] = $this->clamp(70 + $z * 22);
            if ($today->resting_hr - $base['mean'] >= 3 && $z <= -1) {
                $alarms++;
                $reasons[] = sprintf('Ruhepuls %d Schläge über deinem Schnitt', round($today->resting_hr - $base['mean']));
            }
        }

        $sleepToday = $this->sleepScore($today);
        if ($sleepToday !== null) {
            $previous = $before->filter(fn ($r) => $r->date->toDateString() >= $day->subDays(3)->toDateString())
                ->map(fn ($r) => $this->sleepScore($r))
                ->filter(fn ($v) => $v !== null);
            $parts['sleep'] = $this->clamp($previous->isEmpty()
                ? $sleepToday
                : $sleepToday * 0.7 + $previous->avg() * 0.3);

            if ($today->sleep_hours !== null && $today->sleep_hours < 6) {
                $reasons[] = sprintf('kurze Nacht (%s h)', number_format($today->sleep_hours, 1, ',', ''));
            } elseif ($sleepToday < 50) {
                $reasons[] = 'schlechter Schlaf';
            } elseif ($previous->count() >= 2 && $previous->avg() < 50) {
                $reasons[] = 'mehrere kurze Nächte';
            }
        }

        // Ohne Wert der Uhr keine Readiness.
        if ($parts === []) {
            return null;
        }

        // Belastung nur für heute, und zwar bis gestern — was heute trainiert
        // wurde, zählt unten ab dem Ende der Einheit.
        if ($day->isToday()) {
            $load = $this->loadUntilYesterday($this->load->calculate($user->id, withoutStrava: true), $day);
            // Unter vier Wochen Uhr-Daten ist die gewohnte Last unterschätzt;
            // dann zählt die Belastung nur, wenn die akute klar darüber liegt
            // (Jan, 18.11.2026: Garmin sah Überbelastung, Zone3 gar nichts).
            $ramp = ($load['atl'] ?? 0) >= self::LOAD_MIN_ATL
                && ($load['atl'] ?? 0) >= self::LOAD_RAMP_FACTOR * ($load['ctl'] ?? 0);
            if (($load['enough_data'] ?? false) || $ramp) {
                $form = ($load['ctl'] - $load['atl']) / max($load['ctl'], 15.0);
                $parts['load'] = $this->clamp(75 + $form * 130);
                if ($form <= -0.25) {
                    $alarms++;
                    $reasons[] = 'hohe Belastung der letzten Tage';
                }
            }
        }

        $wellbeing = $user->wellbeingEntries()->whereDate('date', $day->toDateString())->first();
        if ($wellbeing) {
            $values = array_filter([
                $wellbeing->energy_level,
                $wellbeing->muscle_soreness !== null ? 11 - $wellbeing->muscle_soreness : null,
                $wellbeing->stress_level !== null ? 11 - $wellbeing->stress_level : null,
            ], fn ($v) => $v !== null);
            if ($values) {
                $avg = array_sum($values) / count($values);
                $parts['wellbeing'] = $this->clamp(($avg - 1) / 9 * 100);
                if ($avg <= 4) {
                    $reasons[] = 'deine Tagesform ist schwach';
                }
            }
        }

        $weight = 0;
        $sum    = 0.0;
        foreach ($parts as $part => $value) {
            $weight += self::WEIGHTS[$part];
            $sum    += $value * self::WEIGHTS[$part];
        }
        $base  = $sum / $weight;
        $today = ($day->isToday() && ! $morning) ? $this->intraday($user, $day, $today, $reasons) : [];
        $score = (int) max(1, min(100, round($base + array_sum($today))));

        // Zwei klare Warnsignale zugleich wiegt guter Schlaf nicht auf (Jan).
        if ($alarms >= 2) {
            $score = min($score, self::REST_BELOW - 1);
        }

        if ($wellbeing?->is_sick || $wellbeing?->is_injured) {
            $score = min($score, 10);
            array_unshift($reasons, $wellbeing->is_injured ? 'du bist verletzt' : 'du bist krank');
        }

        $level = self::levelFor($score);

        return [
            'score'   => $score,
            'level'   => $level['key'],
            'label'   => $level['label'],
            'tone'    => $level['tone'],
            'advice'  => $level['advice'],
            'reasons' => array_slice($reasons, 0, 3),
            'parts'   => $parts,
            // Was heute seit dem Morgen dazukam (negativ = zieht runter).
            'today'   => array_map(fn ($v) => (int) round($v), $today),
            'date'    => $day->toDateString(),
        ];
    }

    /**
     * Die Belastung, wie sie gestern Abend stand. Der Verlauf trägt je Tag
     * ATL/CTL; ohne Verlauf (oder ohne den gestrigen Tag) gilt der Stand.
     */
    private function loadUntilYesterday(array $load, CarbonImmutable $day): array
    {
        $yesterday = $day->subDay()->toDateString();
        foreach (array_reverse($load['history'] ?? []) as $row) {
            if (($row['date'] ?? null) === $yesterday) {
                return ['atl' => $row['atl'], 'ctl' => $row['ctl']] + $load;
            }
        }

        return $load;
    }

    /**
     * Was den Wert seit dem Morgen bewegt hat, in Punkten.
     *
     * @param  list<string>  $reasons
     * @return array<string, float>
     */
    private function intraday(User $user, CarbonImmutable $day, GarminDailyMetric $today, array &$reasons): array
    {
        $now = CarbonImmutable::now();
        $out = [];

        $battery = null;
        if ($today->body_battery_now !== null && $today->body_battery_high !== null && $today->body_battery_at
            && $today->body_battery_at->greaterThan($now->subHours(self::BODY_BATTERY_FRESH_HOURS))) {
            $battery = $today;
        }

        $drop = 0.0;
        foreach ($this->load->sessionsOn($user->id, $day, withoutStrava: true) as $session) {
            if ($session['end']->greaterThan($now)) {
                continue;
            }
            $hours = $session['end']->diffInMinutes($now) / 60;
            $left  = 1 - 0.5 * min(1, $hours / self::TRAINING_HALF_RECOVERED_HOURS);
            // Die Body Battery hat die Einheit schon mitgemessen, wenn ihr
            // Stand danach liegt — dann zählt das Training nur halb.
            if ($battery && $battery->body_battery_at->greaterThan($session['end'])) {
                $left *= 0.5;
            }
            $drop += min(self::TRAINING_DROP_MAX, $session['tss'] * self::TRAINING_DROP_PER_TSS) * $left;
        }
        if ($drop > 0) {
            $out['training'] = -min(self::TRAINING_DROP_DAY_MAX, $drop);
            if ($drop >= 8) {
                $reasons[] = 'Training heute';
            }
        }

        if ($battery) {
            $below = max(0, $battery->body_battery_high - $battery->body_battery_now);
            $shift = min(self::BODY_BATTERY_MAX, $below * self::BODY_BATTERY_FACTOR);
            if ($shift > 0) {
                $out['body_battery'] = -$shift;
                if ($shift >= 8) {
                    $reasons[] = sprintf('Body Battery bei %d', $battery->body_battery_now);
                }
            }
        }

        return $out;
    }

    /** @return array{from: int, key: string, label: string, tone: string, advice: string} */
    public static function levelFor(int $score): array
    {
        $found = self::LEVELS[0];
        foreach (self::LEVELS as $level) {
            if ($score >= $level['from']) {
                $found = $level;
            }
        }

        return $found;
    }

    /** „Readiness 18 (schlecht): HRV 22 % unter deinem Normalbereich, kurze Nacht." */
    public static function sentence(array $r): string
    {
        $text = sprintf('Readiness %d/100 (%s)', $r['score'], mb_strtolower($r['label']));

        return $r['reasons'] ? $text . ': ' . implode(', ', $r['reasons']) : $text;
    }

    /**
     * Die Zeile für einen Prompt — mit der Regel, die daraus folgt. Die
     * Regel steht im Prompt, weil das Modell sonst den Wert nennt und
     * trotzdem einen Tempolauf schreibt.
     */
    public static function promptLine(array $r): string
    {
        $rule = match (true) {
            $r['score'] < self::REST_BELOW => 'Daraus folgt: heute Ruhetag (type=rest) empfehlen, außer der Athlet verlangt ausdrücklich etwas anderes.',
            $r['score'] < self::EASY_BELOW => 'Daraus folgt: heute nichts Hartes, höchstens locker und eher kürzer.',
            default                        => 'Kein Grund, vom Plan abzuweichen.',
        };

        $source = isset($r['today']) && $r['today'] !== []
            ? 'Stand jetzt: Uhr-Werte der Nacht, Belastung, Training und Body Battery von heute, Tagesform'
            : 'aus den Uhr-Werten der Nacht, Belastung und Tagesform';

        return 'READINESS (Zone3-Wert ' . $source . ', 1–100): '
            . self::sentence($r) . '. ' . $rule;
    }

    /** Schlaf als 0–100: Garmins/Polars Schlafwert, sonst aus der Dauer. */
    private function sleepScore(GarminDailyMetric $row): ?float
    {
        if ($row->sleep_score !== null) {
            return (float) $row->sleep_score;
        }
        if ($row->sleep_hours === null) {
            return null;
        }

        // 4 h → 10, 5 h → 30, 6 h → 55, 7 h → 80, ab 8 h → 100
        $h = (float) $row->sleep_hours;
        $points = [[4, 10], [5, 30], [6, 55], [7, 80], [8, 100]];
        if ($h <= 4) return 10.0;
        if ($h >= 8) return 100.0;
        for ($i = 1; $i < count($points); $i++) {
            [$h1, $v1] = $points[$i];
            if ($h <= $h1) {
                [$h0, $v0] = $points[$i - 1];

                return $v0 + ($h - $h0) / ($h1 - $h0) * ($v1 - $v0);
            }
        }

        return 100.0;
    }

    /** @return array{mean: float, sd: float}|null */
    private function baseline(Collection $rows, string $field, float $minSd): ?array
    {
        $values = $rows->pluck($field)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
        if ($values->count() < self::MIN_BASELINE) {
            return null;
        }
        $mean = $values->avg();
        $sd   = sqrt($values->map(fn ($v) => ($v - $mean) ** 2)->avg());

        return ['mean' => $mean, 'sd' => max($sd, $minSd)];
    }

    private function clamp(float $v): int
    {
        return (int) max(0, min(100, round($v)));
    }
}

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
 * Krank oder verletzt in der Tagesform deckelt den Wert auf 10.
 *
 * Die Belastung zählt nur Aktivitäten einer Uhr, nie reine Strava-Zeilen —
 * der Wert steht in Prompts (Strava-Policy §5.3).
 *
 * Stufen wie bei Garmin: 1–24 schlecht, 25–49 niedrig, 50–74 mäßig,
 * 75–94 hoch, 95–100 optimal.
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
    public function forDay(User $user, ?CarbonImmutable $day = null): ?array
    {
        $day = ($day ?? CarbonImmutable::today())->startOfDay();
        $key = $user->id . ':' . $day->toDateString();

        return $this->memo[$key] ??= $this->compute($user, $day);
    }

    private function compute(User $user, CarbonImmutable $day): ?array
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

        // HRV: eine Streuung über dem Schnitt ist gut, eine darunter schlecht.
        if ($today->hrv !== null && ($base = $this->baseline($before, 'hrv', 3.0))) {
            $z = ($today->hrv - $base['mean']) / $base['sd'];
            $parts['hrv'] = $this->clamp(70 + $z * 22);
            $pct = ($today->hrv - $base['mean']) / $base['mean'] * 100;
            if ($z <= -1) {
                $reasons[] = sprintf('HRV %d %% unter deinem Normalbereich', round(abs($pct)));
            }
        }

        if ($today->resting_hr !== null && ($base = $this->baseline($before, 'resting_hr', 1.5))) {
            $z = ($base['mean'] - $today->resting_hr) / $base['sd'];
            $parts['rhr'] = $this->clamp(70 + $z * 22);
            if ($today->resting_hr - $base['mean'] >= 3 && $z <= -1) {
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

        // Belastung nur für heute — die Rechnung läuft bis jetzt.
        if ($day->isToday()) {
            $load = $this->load->calculate($user->id, withoutStrava: true);
            if ($load['enough_data'] ?? false) {
                $form = ($load['ctl'] - $load['atl']) / max($load['ctl'], 15.0);
                $parts['load'] = $this->clamp(75 + $form * 130);
                if ($form <= -0.25) {
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
        $score = (int) max(1, min(100, round($sum / $weight)));

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
            'date'    => $day->toDateString(),
        ];
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

        return 'READINESS (Zone3-Wert aus den Uhr-Werten der Nacht, Belastung und Tagesform, 1–100): '
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

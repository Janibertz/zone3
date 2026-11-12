<?php

namespace App\Services\LiveActivity;

use App\Models\Activity;
use App\Models\LiveActivity;
use App\Models\LiveActivityDevice;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\WellbeingEntry;
use App\Services\PaceFormat;
use App\Services\Push\ApnsClient;
use App\Services\Widget\WidgetPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\AIController;

/**
 * Live Activity „Heute steht an“ (Mockup „Zone3 Live Activities“, Jan am
 * 12.11.2026): morgens bzw. vor der üblichen Laufzeit die Einheit des Tages
 * auf Sperrbildschirm und Dynamic Island, nach dem Import „Erledigt“ mit den
 * echten Zahlen. Kein Countdown — Zone3 kennt die Uhrzeit des Trainings nicht.
 *
 * Apple lässt eine Activity nur 8 Stunden laufen. Darum startet sie
 * `LEAD_HOURS` vor der üblichen Startzeit (Median der Läufe an diesem
 * Wochentag, Jans Wahl), nicht zum Briefing. An jedem Tag mit einer Einheit
 * außer Ruhetagen — Lauf, Kraft, Rad (Jan).
 *
 * Alles läuft über den Server (`live-activities:tick`, jede Minute): die
 * App meldet nur die Tokens. Gepusht wird nur, wenn sich der Inhalt ändert.
 */
class LiveActivityService
{
    public const LEAD_HOURS = 6;

    /** Ohne Laufhistorie. */
    public const DEFAULT_START = '07:00';

    /** Nie früher — wer um 6 läuft, ist ab 5 abgedeckt. */
    public const EARLIEST = '05:00';

    /** Danach startet nichts mehr; der Tag ist so gut wie vorbei. */
    public const LATEST = '21:00';

    /** Erledigt bleibt so lange stehen (Apple erlaubt höchstens 4 h). */
    public const DONE_VISIBLE_HOURS = 3;

    /** Wie weit zurück die übliche Laufzeit gesucht wird. */
    public const HISTORY_DAYS = 56;

    public function __construct(private ApnsClient $apns, private WidgetPayload $payload) {}

    /** Ein Durchlauf: starten, aktualisieren, beenden. */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stats = ['started' => 0, 'updated' => 0, 'ended' => 0];

        if (! $this->apns->configured()) {
            return $stats;
        }

        $stats['ended'] += $this->endPastDays($now);
        $stats['started'] += $this->startDue($now);
        [$updated, $ended] = $this->updateRunning($now);
        $stats['updated'] += $updated;
        $stats['ended'] += $ended;

        LiveActivity::whereDate('day', '<', $now->subDays(7)->toDateString())->delete();

        return $stats;
    }

    /** Die App meldet ein Token (`POST /widget/{token}/live-activity`). */
    public function register(User $user, array $data): void
    {
        if ($data['kind'] === 'start') {
            LiveActivityDevice::updateOrCreate(
                ['start_token' => $data['token']],
                ['user_id' => $user->id],
            );

            return;
        }

        $activity = LiveActivity::firstOrNew(['activity_id' => $data['activity_id']]);
        $activity->fill([
            'user_id'    => $user->id,
            'day'        => $data['day'],
            'push_token' => $data['token'],
        ]);
        $activity->save();
    }

    /**
     * Was die Activity zeigt, oder null an einem Tag ohne Einheit.
     *
     * @return array<string, mixed>|null
     */
    public function state(User $user, CarbonImmutable $day): ?array
    {
        $items = array_values(array_filter(
            $this->payload->itemsOn($user, $day),
            fn ($i) => $i['type'] !== 'rest',
        ));
        // Ohne Einheit im Kalender: der Tagesvorschlag (ohne Plan oder vor
        // dem Planstart), sofern er heute schon geschrieben ist.
        if (! $items && ($suggestion = $this->payload->suggestionFor($user, $day))) {
            $items = [$suggestion];
        }
        if (! $items) {
            return null;
        }

        $planned = array_values(array_filter($items, fn ($i) => in_array($i['status'], ['planned', 'suggested'], true)));
        $base    = rtrim(config('app.url'), '/');

        if (! $planned) {
            $lead = collect($items)->firstWhere('run', true) ?? $items[0];

            return $this->doneState($lead, $base);
        }

        $lead = collect($planned)->first(fn ($i) => $i['run'] || $i['label'] === 'Rad') ?? $planned[0];
        $rest = array_values(array_filter($planned, fn ($i) => $i['id'] !== $lead['id']));
        $suggested = $lead['status'] === 'suggested';

        $foot = null;
        $url  = $base . $lead['url'];
        if (! WellbeingEntry::where('user_id', $user->id)->whereDate('date', $day->toDateString())->exists()) {
            $foot = 'Tagesform noch offen · Antippen zum Eintragen';
            $url  = $base . '/dashboard?tagesform=1';
        } elseif ($suggested) {
            $foot = 'Vorschlag vom Coach';
        } elseif ($rest) {
            $foot = 'Danach: ' . $rest[0]['label'] . ($this->amount($rest[0]) ? ' · ' . $this->amount($rest[0]) : '');
        }

        return [
            'done'    => false,
            'label'   => $lead['label'],
            'name'    => $this->name($lead),
            'accent'  => $lead['accent'],
            'zone'    => $lead['zone'],
            'title'   => $lead['title'],
            'metrics' => $this->plannedMetrics($lead),
            'band'    => $lead['band'],
            'foot'    => $foot,
            'url'     => $url,
        ];
    }

    /**
     * Wann die Activity an diesem Tag startet: übliche Startzeit minus
     * `LEAD_HOURS`, zwischen `EARLIEST` und `LATEST`.
     */
    public function startAt(User $user, CarbonImmutable $day): CarbonImmutable
    {
        $usual = $this->usualMinute($user, $day);

        $start = $usual === null
            ? $day->setTimeFromTimeString(self::DEFAULT_START)
            : $day->startOfDay()->addMinutes(max(0, $usual - self::LEAD_HOURS * 60));

        $earliest = $day->setTimeFromTimeString(self::EARLIEST);

        return $start->lessThan($earliest) ? $earliest : $start;
    }

    /**
     * Minute des Tages (Berliner Zeit), zu der dieser Athlet an diesem
     * Wochentag meist startet: Median der Aktivitäten der letzten acht
     * Wochen an diesem Wochentag (ab zwei), sonst über alle Tage (ab drei).
     */
    public function usualMinute(User $user, CarbonImmutable $day): ?int
    {
        $tz = config('app.timezone');

        $starts = Activity::where('user_id', $user->id)
            ->where('start_date', '>=', $day->subDays(self::HISTORY_DAYS)->startOfDay()->utc()->format('Y-m-d H:i:s'))
            ->where('start_date', '<', $day->startOfDay()->utc()->format('Y-m-d H:i:s'))
            ->pluck('start_date')
            // start_date hält die UTC-Uhrzeit (wie bei Strava).
            ->map(fn ($d) => CarbonImmutable::parse($d->format('Y-m-d H:i:s'), 'UTC')->setTimezone($tz));

        $sameDay = $starts->filter(fn ($d) => $d->dayOfWeekIso === $day->dayOfWeekIso);
        $pool    = $sameDay->count() >= 2 ? $sameDay : ($starts->count() >= 3 ? $starts : collect());

        if ($pool->isEmpty()) {
            return null;
        }

        $minutes = $pool->map(fn ($d) => $d->hour * 60 + $d->minute)->sort()->values();
        $n       = $minutes->count();

        return $n % 2 ? $minutes[intdiv($n, 2)] : intdiv($minutes[$n / 2 - 1] + $minutes[$n / 2], 2);
    }

    private function startDue(CarbonImmutable $now): int
    {
        $today  = $now->startOfDay();
        $latest = $today->setTimeFromTimeString(self::LATEST);
        if ($now->greaterThanOrEqualTo($latest)) {
            return 0;
        }

        $count = 0;
        $devices = LiveActivityDevice::with('user')
            ->where(fn ($q) => $q->whereNull('started_on')->orWhereDate('started_on', '<', $today->toDateString()))
            ->get();

        foreach ($devices->groupBy('user_id') as $userDevices) {
            $user = $userDevices->first()->user;
            if (! $user || $user->is_active === false || $user->waitlisted_at) {
                continue;
            }
            if ($now->lessThan($this->startAt($user, $today))) {
                continue;
            }

            $this->ensureSuggestion($user, $today);
            $state = $this->state($user, $today);
            if (! $state || $state['done']) {
                continue;
            }
            // Jedes iPhone des Athleten bekommt seine eigene.
            foreach ($userDevices as $device) {
                $result = $this->apns->liveActivity($device->start_token, [
                    'event'           => 'start',
                    'content-state'   => $state,
                    'attributes-type' => 'TrainingActivityAttributes',
                    'attributes'      => ['day' => $today->toDateString()],
                    'stale-date'      => $now->addHours(8)->timestamp,
                    'alert'           => [
                        'title' => 'Heute: ' . $state['name'],
                        'body'  => collect($state['metrics'])->map(fn ($m) => "{$m['v']} {$m['u']}")->implode(' · ') ?: $state['title'],
                    ],
                ]);

                if ($result === ApnsClient::GONE) {
                    $device->delete();
                } elseif ($result === ApnsClient::SENT) {
                    $device->forceFill(['started_on' => $today->toDateString()])->save();
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Ohne Plan oder vor dem Planstart steht heute nichts im Kalender. Dann
     * schreibt der Server den Tagesvorschlag selbst (kleines Modell), damit
     * die Activity etwas zeigen kann, bevor der Athlet die App öffnet
     * (Jan, 12.11.2026). Höchstens ein Versuch am Tag, auch wenn er nichts
     * liefert (Ruhetag, Budget, Fehler).
     */
    private function ensureSuggestion(User $user, CarbonImmutable $today): void
    {
        if (! $user->runnerProfile || ($user->activeTrainingPlan && ! $user->planStartsLater())) {
            return;
        }
        if ($this->payload->itemsOn($user, $today) || $this->payload->suggestionFor($user, $today)) {
            return;
        }
        if (! Cache::add("live-activity:suggestion:{$user->id}:{$today->toDateString()}", true, 86400)) {
            return;
        }

        try {
            app(AIController::class)->todayFor($user, requireWellbeing: false);
            $user->unsetRelation('runnerProfile');
        } catch (\Throwable $e) {
            Log::warning('Live Activity: Tagesvorschlag fehlgeschlagen', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return array{0: int, 1: int} */
    private function updateRunning(CarbonImmutable $now): array
    {
        $updated = $ended = 0;
        $today   = $now->startOfDay();

        $running = LiveActivity::with('user')
            ->whereNull('ended_at')
            ->whereDate('day', $today->toDateString())
            ->get();

        foreach ($running as $activity) {
            $user  = $activity->user;
            $state = $user ? $this->state($user, $today) : null;

            if (! $state) {
                $ended += (int) $this->end($activity, null, $now);
                continue;
            }

            $hash = hash('sha256', json_encode($state));
            if ($hash === $activity->state_hash) {
                continue;
            }

            if ($state['done']) {
                $ended += (int) $this->end($activity, $state, $now->addHours(self::DONE_VISIBLE_HOURS));
                continue;
            }

            $result = $this->apns->liveActivity($activity->push_token, [
                'event'         => 'update',
                'content-state' => $state,
                'stale-date'    => $now->addHours(8)->timestamp,
            ]);
            if ($result === ApnsClient::GONE) {
                $activity->forceFill(['ended_at' => $now])->save();
            } elseif ($result === ApnsClient::SENT) {
                $activity->forceFill(['state_hash' => $hash])->save();
                $updated++;
            }
        }

        return [$updated, $ended];
    }

    /** Was von gestern noch läuft, sofort wegnehmen. */
    private function endPastDays(CarbonImmutable $now): int
    {
        $count = 0;
        LiveActivity::whereNull('ended_at')
            ->whereDate('day', '<', $now->toDateString())
            ->get()
            ->each(function (LiveActivity $a) use ($now, &$count) {
                $count += (int) $this->end($a, null, $now);
            });

        return $count;
    }

    private function end(LiveActivity $activity, ?array $state, CarbonImmutable $dismissAt): bool
    {
        $aps = ['event' => 'end', 'dismissal-date' => $dismissAt->timestamp];
        if ($state) {
            $aps['content-state'] = $state;
        }

        $result = $this->apns->liveActivity($activity->push_token, $aps);

        if ($result === ApnsClient::ERROR) {
            Log::info('Live Activity: Ende nicht zugestellt, nächster Versuch', ['activity' => $activity->id]);
            return false;
        }

        $activity->forceFill([
            'ended_at'   => now(),
            'state_hash' => $state ? hash('sha256', json_encode($state)) : $activity->state_hash,
        ])->save();

        return true;
    }

    /** @param  array<string, mixed>  $item */
    private function doneState(array $item, string $base): array
    {
        $session = TrainingSession::with('activity')->find($item['id']);
        $act     = $session?->activity;
        $ride    = $item['label'] === 'Rad';

        $metrics = [];
        if ($act) {
            if ($act->distance > 0 && ! in_array($item['type'], ['strength', 'core', 'mobility'], true)) {
                $metrics[] = ['v' => self::num($act->distance / 1000), 'u' => 'km'];
            }
            if ($act->moving_time > 0) {
                $metrics[] = ['v' => self::clock((int) $act->moving_time), 'u' => 'Zeit'];
            }
            if ($item['run'] && ! $ride && $act->average_speed > 0) {
                $metrics[] = ['v' => PaceFormat::fromSpeed((float) $act->average_speed), 'u' => '/km'];
            }
        }
        if (! $metrics) {
            $metrics = $this->plannedMetrics($item);
        }

        $reviewed = filled($session?->coach_review);
        $what     = $item['run'] ? 'deinen Lauf' : 'deine Einheit';

        return [
            'done'    => true,
            'label'   => $item['label'],
            'name'    => $this->name($item),
            'accent'  => $item['accent'],
            'zone'    => $item['zone'],
            'title'   => $item['title'],
            'metrics' => $metrics,
            'band'    => $item['band'],
            'foot'    => $reviewed
                ? "Der Coach hat sich {$what} angesehen · Antippen"
                : "Der Coach sieht sich {$what} gerade an",
            'url'     => $base . ($act ? "/activities/{$act->id}" : $item['url']),
        ];
    }

    /** @param  array<string, mixed>  $item */
    private function plannedMetrics(array $item): array
    {
        $out = [];
        if ($item['km'] !== null) {
            $out[] = ['v' => self::num($item['km']), 'u' => 'km'];
        }
        if ($item['min'] !== null) {
            $out[] = $item['min'] < 90
                ? ['v' => (string) $item['min'], 'u' => 'min']
                : ['v' => intdiv($item['min'], 60) . ':' . str_pad((string) ($item['min'] % 60), 2, '0', STR_PAD_LEFT), 'u' => 'h'];
        }
        if ($item['pace'] !== null && $item['run']) {
            $out[] = ['v' => $item['pace'], 'u' => '/km'];
        }

        return array_slice($out, 0, 3);
    }

    /** @param  array<string, mixed>  $item */
    private function amount(array $item): ?string
    {
        if ($item['km'] !== null && $item['run']) {
            return self::num($item['km']) . ' km';
        }

        return $item['min'] !== null ? $item['min'] . ' min' : null;
    }

    /** @param  array<string, mixed>  $item */
    private function name(array $item): string
    {
        if ($item['label'] === 'Rad') {
            return 'Radeinheit';
        }

        return TrainingSession::TYPE_LABELS[$item['type']] ?? $item['label'];
    }

    /** 10,5 · 8 */
    public static function num(float $value): string
    {
        $r = round($value, 1);

        return $r == floor($r) ? (string) (int) $r : str_replace('.', ',', (string) $r);
    }

    /** 46:22 · 1:05:10 */
    public static function clock(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}

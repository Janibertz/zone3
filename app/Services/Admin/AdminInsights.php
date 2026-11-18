<?php

namespace App\Services\Admin;

use App\Models\Activity;
use App\Models\AiLog;
use App\Models\ContactMessage;
use App\Models\PlanRevision;
use App\Models\SupportTicket;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Workout;
use App\Services\AI\OpenAIClient;
use App\Services\Auth\Access;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Was der Admin-Bereich auf „Heute“, im Postfach und unter System zeigt
 * (Umbau 18.11.2026). Eine Stelle, damit Badge, Liste und Übersicht
 * dieselben Dinge zählen — sonst meldet die Tab-Leiste 4 und das Postfach 3.
 */
class AdminInsights
{
    /** Ziel bis Weihnachten (Instagram-Strategie, 11.11.2026). */
    public const TESTER_GOAL = 50;
    public const TESTER_DEADLINE = '2026-12-24';

    /** Ab so vielen Tagen ohne Aktivität gilt ein Athlet als still. */
    public const QUIET_DAYS = 5;

    /** Eine Anbindung, die so lange nichts geliefert hat, ist auffällig. */
    public const PROVIDER_SILENT_HOURS = 48;

    // ── Zählerei für die Leisten ──────────────────────────────────────

    /** @return array{inbox: int, system: bool, athletes: int} */
    public function nav(): array
    {
        return [
            'inbox'    => $this->inboxCount(),
            'system'   => $this->failedCount() > 0,
            'athletes' => User::count(),
        ];
    }

    public function inboxCount(): int
    {
        return User::whereNotNull('waitlisted_at')->count()
            + $this->ticketsNeedingAnswer()->count()
            + ContactMessage::whereNull('handled_at')->count()
            + $this->reportedWorkouts(open: true)->count();
    }

    public function failedCount(): int
    {
        return Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
    }

    // ── Postfach ──────────────────────────────────────────────────────

    /**
     * Alles, was eine Antwort braucht, als eine Liste. Jeder Eintrag behält
     * seinen eigenen Weg: Warteliste freigeben, Ticket beantworten, Kontakt
     * per Mail, Meldung lassen oder zurücknehmen.
     *
     * @return list<array<string, mixed>>
     */
    public function inbox(bool $open = true): array
    {
        $items = collect();

        if ($open) {
            foreach (User::whereNotNull('waitlisted_at')->orderByDesc('waitlisted_at')->get() as $u) {
                $items->push([
                    'kind'  => 'waitlist',
                    'key'   => "waitlist-{$u->id}",
                    'at'    => $u->waitlisted_at->toIso8601String(),
                    'title' => $u->name,
                    'user_id' => $u->id,
                    'text'  => collect([
                        $u->apple_sub ? 'Mit Apple angemeldet' : 'Link per Mail',
                        $u->signup_source ? "über {$u->signup_source}" : 'ohne Quelle',
                        $this->way($u),
                    ])->filter()->implode(' · '),
                ]);
            }
        }

        $tickets = SupportTicket::with(['user', 'replies'])
            ->whereIn('status', $open ? ['open', 'in_progress'] : ['resolved', 'closed'])
            ->latest('updated_at')
            ->limit($open ? 100 : 30)
            ->get();
        foreach ($tickets as $t) {
            $last = $t->replies->last();
            $items->push([
                'kind'    => 'ticket',
                'key'     => "ticket-{$t->id}",
                'id'      => $t->id,
                'at'      => ($last?->created_at ?? $t->created_at)->toIso8601String(),
                'title'   => ($t->user ? $this->short($t->user->name) . ': ' : '') . $t->subject,
                'user_id' => $t->user_id,
                'user_first' => $t->user ? $this->first($t->user->name) : null,
                'text'    => mb_strimwidth((string) ($last && ! $last->is_admin ? $last->message : $t->description), 0, 220, '…'),
                'context' => $t->user ? $this->ticketContext($t->user) : null,
                'answered' => (bool) $last?->is_admin,
                'label'   => $t->typeLabel(),
            ]);
        }

        $contacts = ContactMessage::query()
            ->when($open, fn ($q) => $q->whereNull('handled_at'), fn ($q) => $q->whereNotNull('handled_at'))
            ->latest()
            ->limit($open ? 100 : 30)
            ->get();
        foreach ($contacts as $c) {
            $items->push([
                'kind'    => 'contact',
                'key'     => "contact-{$c->id}",
                'id'      => $c->id,
                'at'      => $c->created_at->toIso8601String(),
                'title'   => $c->name,
                'email'   => $c->email,
                'user_id' => $c->user_id,
                'text'    => $c->message,
            ]);
        }

        foreach ($this->reportedWorkouts($open)->with(['user:id,name', 'reports' => fn ($q) => $q->latest()])->get() as $w) {
            $latest = $w->reports->first();
            $items->push([
                'kind'    => 'report',
                'key'     => "report-{$w->id}",
                'id'      => $w->id,
                'at'      => $latest?->created_at?->toIso8601String(),
                'title'   => "„{$w->name}“ von " . $this->short($w->user?->name ?? 'unbekannt'),
                'user_id' => $w->user_id,
                'public'  => (bool) $w->is_public,
                'text'    => collect([
                    $latest?->reason ? "„{$latest->reason}“" : null,
                    $w->reports->count() === 1 ? '1 Meldung' : $w->reports->count() . ' Meldungen',
                    "{$w->times_used}× getauscht",
                ])->filter()->implode(' · '),
            ]);
        }

        return $items->sortByDesc('at')->values()->all();
    }

    /** Tickets, bei denen das letzte Wort beim Athleten liegt. */
    public function ticketsNeedingAnswer(): Collection
    {
        return SupportTicket::with('replies')
            ->whereIn('status', ['open', 'in_progress'])
            ->get()
            ->filter(fn (SupportTicket $t) => ! ($t->replies->last()?->is_admin));
    }

    /**
     * Workouts mit Meldung. Offen = noch freigegeben und mindestens eine
     * Meldung, die niemand „gelassen“ hat.
     */
    public function reportedWorkouts(bool $open)
    {
        if ($open) {
            return Workout::where('is_public', true)
                ->whereHas('reports', fn ($q) => $q->whereNull('dismissed_at'));
        }

        return Workout::whereHas('reports')
            ->where(fn ($q) => $q->where('is_public', false)
                ->orWhereDoesntHave('reports', fn ($r) => $r->whereNull('dismissed_at')));
    }

    /** Ein Satz unter dem Ticket: was an den Verbindungen auffällt. */
    private function ticketContext(User $u): string
    {
        $parts = [];
        foreach ($this->connections($u) as $c) {
            if ($c['tone'] === 'warn') {
                $parts[] = "{$c['name']}: {$c['value']}";
            }
        }
        $parts[] = $this->way($u) ? $this->way($u) . ($u->seen_app_on || $u->seen_homescreen_on || $u->seen_browser_on ? ' · ' . $this->lastSeenLabel($u) : '') : 'noch nie geöffnet';

        return implode(' · ', array_filter($parts));
    }

    // ── Heute ─────────────────────────────────────────────────────────

    /**
     * „Braucht dich“: die Warteliste mit Freigeben direkt in der Zeile,
     * dazu Tickets, Kontakt, Meldungen und gruppierte Fehler.
     *
     * @return list<array<string, mixed>>
     */
    public function needsYou(): array
    {
        $rows = [];

        foreach (User::whereNotNull('waitlisted_at')->orderByDesc('waitlisted_at')->limit(5)->get() as $u) {
            $rows[] = [
                'kind'    => 'waitlist',
                'user_id' => $u->id,
                'title'   => $this->short($u->name) . ' wartet',
                'meta'    => 'Warteliste · ' . ($u->signup_source ? "über {$u->signup_source}" : 'ohne Quelle') . ' · ' . $this->ago($u->waitlisted_at),
            ];
        }

        $open = collect($this->inbox())->whereIn('kind', ['ticket', 'contact', 'report'])
            ->filter(fn ($i) => $i['kind'] !== 'ticket' || ! $i['answered'])
            ->take(4);
        foreach ($open as $i) {
            $rows[] = [
                'kind'  => $i['kind'],
                'id'    => $i['id'],
                'title' => $i['kind'] === 'contact' ? "Kontakt: {$i['title']}" : $i['title'],
                'meta'  => match ($i['kind']) {
                    'ticket'  => 'Hilfe & Feedback',
                    'contact' => 'Kontaktformular',
                    'report'  => 'Workout gemeldet',
                } . ' · ' . $this->ago(Carbon::parse($i['at'])),
            ];
        }

        foreach (array_slice($this->failureGroups(), 0, 2) as $g) {
            $rows[] = [
                'kind'  => 'failure',
                'title' => "{$g['label']} fehlgeschlagen",
                'meta'  => "{$g['count']}× seit " . Carbon::parse($g['first'])->format('H:i') . ' · ' . mb_strimwidth($g['reason'], 0, 60, '…'),
            ];
        }

        return $rows;
    }

    /**
     * Fehlgeschlagene Aufgaben, nach Aufgabe und Fehlerzeile gruppiert:
     * dieselbe Klasse viermal ist ein Fehler im Code, kein Pech.
     *
     * @return list<array<string, mixed>>
     */
    public function failureGroups(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')->orderBy('id')->get()
            ->map(function ($row) {
                $payload = json_decode($row->payload, true) ?: [];
                $job     = $payload['displayName'] ?? 'Unbekannt';

                return [
                    'job'    => $job,
                    'reason' => mb_substr(trim(strtok((string) $row->exception, "\n") ?: ''), 0, 200),
                    'uuid'   => $row->uuid,
                    'at'     => $row->failed_at,
                ];
            })
            ->groupBy(fn ($r) => $r['job'] . '|' . $r['reason'])
            ->map(fn (Collection $g) => [
                'job'    => $g->first()['job'],
                'label'  => $this->jobLabel($g->first()['job']),
                'reason' => $g->first()['reason'],
                'count'  => $g->count(),
                'first'  => Carbon::parse($g->first()['at'])->toIso8601String(),
                'last'   => Carbon::parse($g->last()['at'])->toIso8601String(),
                'uuids'  => $g->pluck('uuid')->values()->all(),
                'search' => class_basename($g->first()['job']),
            ])
            ->sortByDesc('last')
            ->values()
            ->all();
    }

    /** „App\Jobs\ImportPolarExerciseJob“ → „Import Polar Exercise“. */
    public function jobLabel(string $class): string
    {
        $base = preg_replace('/Job$/', '', class_basename($class));

        return trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $base)) ?: $class;
    }

    /**
     * Tester bis Weihnachten: Konten ohne Admin und ohne Warteliste.
     *
     * @return array<string, mixed>
     */
    public function testerGoal(): array
    {
        $testers  = $this->testers()->count();
        $thisWeek = $this->testers()->where('created_at', '>=', now()->startOfWeek())->count();
        $deadline = CarbonImmutable::parse(self::TESTER_DEADLINE)->endOfDay();
        $daysLeft = max(0, (int) ceil(now()->diffInDays($deadline, false)));
        $missing  = max(0, self::TESTER_GOAL - $testers);

        return [
            'count'     => $testers,
            'goal'      => self::TESTER_GOAL,
            'this_week' => $thisWeek,
            'days_left' => $daysLeft,
            'per_week'  => $daysLeft > 0 ? (int) ceil($missing / max(1, $daysLeft / 7)) : $missing,
            'funnel'    => $this->funnel(),
        ];
    }

    /** @return list<array{key: string, label: string, count: int}> */
    public function funnel(): array
    {
        $base = $this->testers();

        return [
            ['key' => 'registered', 'label' => 'Angemeldet', 'count' => (clone $base)->count()],
            ['key' => 'onboarded', 'label' => 'Onboarding fertig', 'count' => (clone $base)->whereNotNull('onboarding_completed_at')->count()],
            ['key' => 'plan', 'label' => 'Plan erstellt', 'count' => (clone $base)->whereHas('trainingPlans')->count()],
            ['key' => 'session', 'label' => 'Erste Einheit', 'count' => (clone $base)->whereHas('trainingSessions', fn ($q) => $q->where('status', 'completed'))->count()],
            ['key' => 'active', 'label' => 'Aktiv (7 Tage)', 'count' => (clone $base)->whereHas('activities', fn ($q) => $q->where('start_date', '>=', now()->subDays(7)))->count()],
        ];
    }

    /** Onboarding fertig, aber nie einen Plan gebaut. */
    public function onboardedWithoutPlan(): int
    {
        return $this->testers()->whereNotNull('onboarding_completed_at')->whereDoesntHave('trainingPlans')->count();
    }

    public function testers()
    {
        return User::where('is_admin', false)->whereNull('waitlisted_at');
    }

    /**
     * KI-Kosten heute und im Monat, mit Prognose gegen das Plattformlimit.
     *
     * @return array<string, mixed>
     */
    public function aiCosts(): array
    {
        $monthStart = now()->startOfMonth();
        $month      = (float) AiLog::where('created_at', '>=', $monthStart)->sum('cost_eur');
        $day        = (int) now()->day;
        $days       = (int) now()->daysInMonth;
        $limitUsd   = (float) config('services.openai.budget.monthly_usd', 15);

        return [
            'today'        => round((float) AiLog::whereDate('created_at', today())->sum('cost_eur'), 4),
            'today_budget' => (float) config('services.openai.budget.daily_eur', 0),
            'month'        => round($month, 4),
            'month_label'  => now()->locale('de')->isoFormat('MMMM'),
            'forecast'     => round($month / max(1, $day) * $days, 2),
            'limit_eur'    => round(AiLog::usdToEur($limitUsd), 2),
            'limit_usd'    => $limitUsd,
        ];
    }

    /**
     * Die ganze KI-Kostenseite: Tage, Wofür, Wer, Bremsen.
     *
     * @return array<string, mixed>
     */
    public function aiCostDetails(): array
    {
        $monthStart = now()->startOfMonth();
        $logs = AiLog::where('created_at', '>=', $monthStart)
            ->get(['user_id', 'call_type', 'cost_eur', 'created_at', 'status']);

        $perDay = [];
        for ($d = 1; $d <= now()->day; $d++) {
            $perDay[$d] = 0.0;
        }
        foreach ($logs as $l) {
            $perDay[(int) $l->created_at->day] = ($perDay[(int) $l->created_at->day] ?? 0) + (float) $l->cost_eur;
        }
        $peakDay = collect($perDay)->sortDesc()->keys()->first();

        $byType = $logs->groupBy('call_type')
            ->map(fn ($g, $t) => ['type' => $t, 'label' => AiLog::labelFor($t), 'cost' => round($g->sum('cost_eur'), 4), 'calls' => $g->count()])
            ->sortByDesc('cost')->values();
        $top  = $byType->take(4)->values();
        $rest = $byType->slice(4);

        $names = User::whereIn('id', $logs->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $byUser = $logs->whereNotNull('user_id')->groupBy('user_id')
            ->map(fn ($g, $id) => ['id' => (int) $id, 'name' => $names[$id] ?? "#{$id}", 'cost' => round($g->sum('cost_eur'), 4), 'calls' => $g->count()])
            ->sortByDesc('cost')->take(8)->values();

        $peakTypes = $peakDay ? $logs->filter(fn ($l) => (int) $l->created_at->day === $peakDay)
            ->groupBy('call_type')->map->count()->sortDesc() : collect();

        return $this->aiCosts() + [
            'days'       => collect($perDay)->map(fn ($c, $d) => ['day' => $d, 'cost' => round($c, 4)])->values()->all(),
            'peak'       => $peakDay && $perDay[$peakDay] > 0 ? [
                'date'  => now()->startOfMonth()->addDays($peakDay - 1)->format('d.m.'),
                'cost'  => round($perDay[$peakDay], 2),
                'type'  => AiLog::labelFor($peakTypes->keys()->first()),
                'calls' => $peakTypes->first(),
            ] : null,
            'by_type'    => $top->all(),
            'rest'       => $rest->isEmpty() ? null : ['types' => $rest->count(), 'cost' => round($rest->sum('cost'), 4)],
            'by_user'    => $byUser->all(),
            'blocked'    => Cache::get(OpenAIClient::BLOCK_KEY),
            'skipped_24h'=> AiLog::where('status', 'skipped')->where('created_at', '>=', now()->subDay())->count(),
            'errors_24h' => AiLog::where('status', 'error')->where('created_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * Athleten, die still geworden sind: lange keine Aktivität, nie eine,
     * oder eine Anbindung meldet einen Fehler.
     *
     * @return list<array<string, mixed>>
     */
    public function quiet(int $limit = 5): array
    {
        return collect($this->athleteRows())
            ->filter(fn ($r) => $r['quiet'])
            ->sortByDesc('quiet_days')
            ->take($limit)
            ->map(fn ($r) => [
                'id'      => $r['id'],
                'name'    => $r['name'],
                'initial' => $r['initial'],
                'color'   => $r['color'],
                'meta'    => $r['quiet_reason'],
            ])
            ->values()
            ->all();
    }

    // ── Athleten ──────────────────────────────────────────────────────

    /**
     * Eine Zeile je Konto, mit allem, was Liste und Filter brauchen.
     *
     * @return list<array<string, mixed>>
     */
    public function athleteRows(): array
    {
        $users = User::with([
            'garminConnection', 'corosConnection', 'polarConnection', 'whoopConnection',
            'intervalsConnection', 'stravaAccount', 'activeTrainingPlan.event',
        ])
            ->withCount('pushSubscriptions')
            ->withMax('activities', 'start_date')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return $users->map(fn (User $u) => $this->athleteRow($u))->all();
    }

    /** @return array<string, mixed> */
    public function athleteRow(User $u): array
    {
        $lastAt   = $u->activities_max_start_date ? Carbon::parse($u->activities_max_start_date) : null;
        $daysAgo  = $lastAt ? (int) $lastAt->copy()->startOfDay()->diffInDays(today()) : null;
        $plan     = $u->activeTrainingPlan;
        $devices  = $this->deviceNames($u);
        $errors   = collect($this->connections($u))->where('tone', 'warn')->where('kind', 'device');

        $quietReason = null;
        $isTester    = ! $u->is_admin && ! $u->waitlisted_at && $u->is_active;
        if ($isTester && $u->onboarding_completed_at) {
            if ($errors->isNotEmpty()) {
                $quietReason = $errors->first()['name'] . ' meldet einen Fehler';
            } elseif ($daysAgo !== null && $daysAgo >= self::QUIET_DAYS) {
                $quietReason = "{$daysAgo} Tage keine Aktivität" . ($devices ? ' · ' . $devices[0] : '');
            } elseif ($daysAgo === null && $u->onboarding_completed_at->lt(now()->subDays(3))) {
                $quietReason = 'Noch keine Aktivität · ' . ($devices ? $devices[0] : 'keine Uhr');
            }
        }

        $chips = [];
        foreach ($devices as $d) {
            $chips[] = ['label' => $d, 'tone' => 'plain'];
        }
        if (! $devices) {
            $chips[] = ['label' => 'keine Uhr', 'tone' => 'warn'];
        }
        if ($u->telegram_chat_id) {
            $chips[] = ['label' => 'Telegram', 'tone' => 'plain'];
        } elseif ($u->push_subscriptions_count > 0) {
            $chips[] = ['label' => 'Push', 'tone' => 'plain'];
        }
        if ($way = $this->way($u)) {
            $chips[] = ['label' => $way, 'tone' => 'plain'];
        }
        if (! $plan && $u->signup_source) {
            $chips[] = ['label' => "über {$u->signup_source}", 'tone' => 'plain'];
        }
        if ($u->is_admin) {
            array_unshift($chips, ['label' => 'Admin', 'tone' => 'danger']);
        }
        if (! $u->is_active) {
            array_unshift($chips, ['label' => 'deaktiviert', 'tone' => 'danger']);
        }

        return [
            'id'          => $u->id,
            'name'        => $u->name,
            'email'       => $u->email,
            'initial'     => mb_strtoupper(mb_substr($u->name, 0, 1)),
            'color'       => $this->color($u->id),
            'source'      => $u->signup_source,
            'created_at'  => $u->created_at?->toIso8601String(),
            'last_at'     => $lastAt?->toIso8601String(),
            'last_label'  => $lastAt ? $this->dayLabel($daysAgo) : 'nie',
            'last_tone'   => $daysAgo === null ? ($u->onboarding_completed_at ? 'warn' : 'plain') : ($daysAgo <= 1 ? 'success' : ($daysAgo >= self::QUIET_DAYS ? 'warn' : 'plain')),
            'plan_line'   => $this->planLine($u),
            'chips'       => $chips,
            'is_admin'    => (bool) $u->is_admin,
            'waiting'     => (bool) $u->waitlisted_at,
            'has_plan'    => (bool) $plan,
            'onboarded'   => (bool) $u->onboarding_completed_at,
            'active'      => $daysAgo !== null && $daysAgo < 7,
            'quiet'       => $quietReason !== null,
            'quiet_days'  => $daysAgo ?? 999,
            'quiet_reason'=> $quietReason,
        ];
    }

    /** „Halbmarathon 12.04. · Woche 3 von 20“ — oder was fehlt. */
    public function planLine(User $u): string
    {
        if ($u->waitlisted_at) {
            return 'Wartet auf Freigabe seit ' . $u->waitlisted_at->format('d.m.');
        }
        if (! $u->onboarding_completed_at) {
            return 'Onboarding läuft';
        }

        $plan  = $u->activeTrainingPlan;
        $event = $plan?->event;
        if (! $event) {
            $since = (int) $u->onboarding_completed_at->copy()->startOfDay()->diffInDays(today());

            return 'Onboarding fertig, kein Plan' . ($since > 0 ? " · seit {$since} " . ($since === 1 ? 'Tag' : 'Tagen') : '');
        }

        $name = $event->isOpen() ? $event->name : "{$event->name} {$event->event_date->format('d.m.')}";
        $progress = app(\App\Services\PeriodizationService::class)->progress($event);
        if ($progress && $progress['starts_in'] > 0 && $event->plan_start_date) {
            return "{$name} · Plan startet {$event->plan_start_date->format('d.m.')}";
        }
        if ($progress) {
            return "{$name} · Woche {$progress['week']} von {$progress['weeks']}";
        }

        return $name;
    }

    /**
     * Die Verbindungen eines Athleten mit dem letzten Lebenszeichen.
     *
     * @return list<array{name: string, value: string, tone: string, kind: string}>
     */
    public function connections(User $u): array
    {
        $rows = [];
        $dev  = function (string $name, $conn, ?Carbon $last, ?string $error) use (&$rows) {
            if (! $conn) {
                return;
            }
            $rows[] = [
                'name'  => $name,
                'kind'  => 'device',
                'tone'  => $error ? 'warn' : ($last && $last->gt(now()->subHours(self::PROVIDER_SILENT_HOURS)) ? 'success' : ($last ? 'warn' : 'plain')),
                'value' => $error ? mb_strimwidth($error, 0, 48, '…') : ($last ? 'Daten ' . $this->when($last) : 'verbunden, noch nichts'),
            ];
        };

        $lastActivity = fn (string $source) => ($at = $u->activities()->where('source', $source)->max('created_at')) ? Carbon::parse($at) : null;
        $lastMetric   = fn (string $source) => ($at = DB::table('garmin_daily_metrics')->where('user_id', $u->id)->where('source', $source)->max('updated_at')) ? Carbon::parse($at) : null;
        $latest       = fn (?Carbon ...$dates) => collect($dates)->filter()->sortDesc()->first();

        $g = $u->garminConnection;
        $dev('Garmin', $g, $g ? $latest($lastActivity(Activity::SOURCE_GARMIN), $lastMetric('garmin')) : null, $g?->refresh_error);
        $c = $u->corosConnection;
        $dev('COROS', $c, $c ? $latest($c->activities_synced_at ? Carbon::parse($c->activities_synced_at) : null, $c->health_synced_at ? Carbon::parse($c->health_synced_at) : null) : null, $c?->refresh_error);
        $p = $u->polarConnection;
        $dev('Polar', $p, $p ? $latest($lastActivity(Activity::SOURCE_POLAR), $lastMetric('polar')) : null, $p?->last_error);
        $w = $u->whoopConnection;
        $dev('WHOOP', $w, $w ? $latest($lastActivity(Activity::SOURCE_WHOOP), $lastMetric('whoop')) : null, $w?->last_error);
        $dev('Apple Health', $u->apple_health_consent_at, $u->apple_health_consent_at ? $latest($u->apple_health_synced_at ? Carbon::parse($u->apple_health_synced_at) : null, $lastActivity(Activity::SOURCE_APPLE)) : null, null);
        $i = $u->intervalsConnection;
        $dev('intervals.icu', $i, $i ? $latest($i->workouts_synced_at ? Carbon::parse($i->workouts_synced_at) : null, $i->activities_synced_at ? Carbon::parse($i->activities_synced_at) : null) : null, $i?->last_error);

        if ($g && $u->syncsPlanToGarmin()) {
            $rows[] = ['name' => 'Plan auf der Uhr', 'kind' => 'channel', 'tone' => 'success', 'value' => 'Garmin, 7 Tage'];
        } elseif ($u->apple_watch_sync && $u->seen_app_on) {
            $rows[] = ['name' => 'Plan auf der Uhr', 'kind' => 'channel', 'tone' => 'success', 'value' => 'Apple Watch, 7 Tage'];
        }

        if ($u->telegram_chat_id) {
            $rows[] = ['name' => 'Telegram', 'kind' => 'channel', 'tone' => 'success', 'value' => 'Briefing ' . ($u->wellbeing_reminder_time ? substr((string) $u->wellbeing_reminder_time, 0, 5) : '08:00')];
        }
        $subs = $u->pushSubscriptions()->get(['kind']);
        if ($subs->isNotEmpty()) {
            $app = $subs->where('kind', \App\Models\PushSubscription::KIND_APNS)->count();
            $rows[] = ['name' => 'Push', 'kind' => 'channel', 'tone' => $u->push_notifications_enabled ? 'success' : 'plain', 'value' => collect([
                $app ? 'iPhone-App' : null,
                $subs->count() - $app ? 'Browser' : null,
            ])->filter()->implode(' + ') . ($u->push_notifications_enabled ? '' : ' · aus')];
        }

        $s = $u->stravaAccount;
        if ($s) {
            $rows[] = ['name' => 'Strava', 'kind' => 'device', 'tone' => $s->sync_error ? 'warn' : 'success', 'value' => $s->sync_error ? mb_strimwidth($s->sync_error, 0, 48, '…') : ($s->last_synced_at ? 'Abgleich ' . $this->when(Carbon::parse($s->last_synced_at)) : 'verbunden')];
        } else {
            $rows[] = ['name' => 'Strava', 'kind' => 'off', 'tone' => 'off', 'value' => $u->strava_allowed ? 'freigeschaltet, nicht verbunden' : 'nicht freigeschaltet'];
        }

        return $rows;
    }

    /** @return list<string> */
    public function deviceNames(User $u): array
    {
        return array_values(array_filter([
            $u->garminConnection ? 'Garmin' : null,
            $u->corosConnection ? 'COROS' : null,
            $u->polarConnection ? 'Polar' : null,
            $u->whoopConnection ? 'WHOOP' : null,
            $u->apple_health_consent_at ? 'Apple Health' : null,
            $u->stravaAccount ? 'Strava' : null,
        ]));
    }

    /**
     * Die Zeitleiste „Was passiert ist“ auf der Athletenseite.
     *
     * @return list<array{at: string, title: string, meta: string, tone: string}>
     */
    public function timeline(User $u, int $limit = 25): array
    {
        $rows = collect();

        foreach ($u->activities()->latest('created_at')->limit($limit)->get(['id', 'name', 'type', 'distance', 'moving_time', 'source', 'created_at']) as $a) {
            $session = TrainingSession::where('activity_id', $a->id)->first(['title', 'status']);
            $km = $a->distance > 0 ? ' · ' . number_format($a->distance / 1000, 1, ',', '.') . ' km' : '';
            $rows->push([
                'at'    => $a->created_at,
                'title' => ($a->type === 'Run' ? 'Lauf' : ($a->name ?: $a->type)) . ' importiert' . $km,
                'meta'  => collect([Activity::SOURCE_LABELS[$a->source] ?? $a->source, $session ? "{$session->title} erledigt" : 'ungeplant'])->implode(' · '),
                'tone'  => 'plain',
            ]);
        }

        $costs = AiLog::where('user_id', $u->id)->where('call_type', 'coach_chat')->latest()->limit($limit)->get(['created_at', 'cost_eur']);
        foreach ($u->coachMessages()->where('role', 'assistant')->latest('created_at')->limit($limit)->get(['created_at', 'channel', 'kind', 'reply_to_id']) as $m) {
            $rows->push([
                'at'    => $m->created_at,
                'title' => 'Coach hat ' . ($m->reply_to_id ? 'geantwortet' : 'geschrieben'),
                'meta'  => collect([
                    $m->reply_to_id ? 'im Chat' : 'von sich aus',
                    $m->channel === 'telegram' ? 'Telegram' : ($m->channel === 'web' ? 'App' : null),
                ])->filter()->implode(' · '),
                'tone'  => 'plain',
            ]);
        }

        foreach ($u->wellbeingEntries()->latest('created_at')->limit(10)->get(['created_at', 'is_sick', 'is_injured']) as $w) {
            $rows->push([
                'at'    => $w->created_at,
                'title' => 'Tagesform eingetragen',
                'meta'  => $w->is_sick ? 'krank' : ($w->is_injured ? 'verletzt' : 'gesund'),
                'tone'  => $w->is_sick || $w->is_injured ? 'warn' : 'plain',
            ]);
        }

        foreach (PlanRevision::where('user_id', $u->id)->latest()->limit(10)->get(['created_at', 'triggered_by', 'corrections']) as $r) {
            $n = count($r->corrections ?? []);
            $rows->push([
                'at'    => $r->created_at,
                'title' => $r->triggered_by === 'initial' ? 'Plan gebaut' : 'Plan neu berechnet',
                'meta'  => ($this->triggerLabel($r->triggered_by)) . ' · ' . ($n === 0 ? 'keine Korrektur' : ($n === 1 ? '1 Korrektur vom Prüfer' : "{$n} Korrekturen vom Prüfer")),
                'tone'  => $n > 0 ? 'warn' : 'plain',
            ]);
        }

        foreach (\App\Models\SessionRecommendation::where('user_id', $u->id)->latest()->limit(10)->get(['created_at', 'status', 'source']) as $s) {
            $rows->push([
                'at'    => $s->created_at,
                'title' => 'Vorschlag ' . match ($s->source) {
                    'coach' => 'vom Coach',
                    'readiness' => 'nach der Readiness',
                    default => 'zur Tagesform',
                },
                'meta'  => match ($s->status) {
                    'accepted' => 'angenommen',
                    'rejected' => 'abgelehnt',
                    'expired'  => 'verfallen',
                    default    => 'offen',
                },
                'tone'  => 'plain',
            ]);
        }

        return $rows->filter(fn ($r) => $r['at'])
            ->sortByDesc('at')
            ->take($limit)
            ->map(fn ($r) => [
                'at'    => $r['at']->toIso8601String(),
                'label' => $r['at']->isToday() ? $r['at']->format('H:i') : ($r['at']->gt(now()->subDays(6)) ? $r['at']->locale('de')->isoFormat('dd') : $r['at']->format('d.m.')),
                'title' => $r['title'],
                'meta'  => $r['meta'],
                'tone'  => $r['tone'],
            ])
            ->values()
            ->all();
    }

    private function triggerLabel(?string $t): string
    {
        return match ($t) {
            'initial'      => 'erster Bau',
            'manual'       => 'von Hand',
            'skip'         => 'Einheit ausgelassen',
            'availability' => 'Woche angepasst',
            'wellbeing'    => 'Tagesform',
            'weekly'       => 'Wochenplanung',
            'threshold'    => 'Schwelle geändert',
            'gap'          => 'Lücke',
            'auto'         => 'ungeplante Aktivität',
            default        => (string) $t,
        };
    }

    /**
     * Readiness, VDOT und Wochenkilometer für die drei Kacheln oben auf
     * der Athletenseite.
     *
     * @return array<string, mixed>
     */
    public function kpis(User $u): array
    {
        $readiness = null;
        try {
            $readiness = app(\App\Services\Readiness\ReadinessService::class)->forDay($u);
        } catch (\Throwable) {
            $readiness = null;
        }

        $profile   = $u->runnerProfile;
        $threshold = $profile?->thresholdDisplay();

        $weekStart = now()->startOfWeek();
        $runKm = (float) $u->activities()->where('type', 'Run')->where('start_date', '>=', $weekStart)->sum('distance') / 1000;
        $planned = (float) $u->trainingSessions()
            ->whereBetween('planned_date', [$weekStart->toDateString(), now()->endOfWeek()->toDateString()])
            ->whereIn('type', \App\Models\TrainingSession::RUN_TYPES)
            ->whereNull('sport_type')
            ->where('status', '!=', 'skipped')
            ->sum('distance_km');

        return [
            'readiness' => $readiness ? ['score' => $readiness['score'], 'label' => $readiness['label'], 'tone' => $readiness['tone']] : null,
            'vdot'      => $profile?->vdot() ? round($profile->vdot(), 1) : null,
            'threshold' => $threshold['pace'] ?? null,
            'week_km'   => round($runKm, 1),
            'week_planned' => round($planned, 1),
        ];
    }

    /** Lücken im aktiven Plan, dieselbe Regel wie auf der Systemseite. @return list<string> */
    public function planHoles(User $u): array
    {
        $plan = $u->activeTrainingPlan;
        if (! $plan) {
            return [];
        }

        $dates = TrainingSession::where('training_plan_id', $plan->id)
            ->pluck('planned_date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->unique()->sort()->values();

        return \App\Services\SystemHealth::holes($dates, $plan->event?->plan_start_date?->format('Y-m-d'));
    }

    // ── System ────────────────────────────────────────────────────────

    /**
     * Jede Anbindung in einer Zeile: wie viele verbunden, wann zuletzt
     * etwas kam, ob ein Konto einen Fehler meldet.
     *
     * @return list<array<string, mixed>>
     */
    public function providers(): array
    {
        $max = fn (string $table, string $col = 'created_at') => Schema::hasTable($table) ? DB::table($table)->max($col) : null;
        $count = fn (string $table) => Schema::hasTable($table) ? DB::table($table)->count() : 0;
        $errors = fn (string $table, string $col) => Schema::hasTable($table) ? DB::table($table)->whereNotNull($col)->where($col, '!=', '')->count() : 0;
        $latest = fn (...$v) => collect($v)->filter()->map(fn ($x) => Carbon::parse($x))->sortDesc()->first();

        $rows = [
            ['key' => 'garmin', 'name' => 'Garmin', 'count' => $count('garmin_connections'), 'last' => $latest($max('garmin_webhook_events')), 'errors' => $errors('garmin_connections', 'refresh_error'), 'anchor' => null, 'what' => null],
            ['key' => 'telegram', 'name' => 'Telegram', 'count' => User::whereNotNull('telegram_chat_id')->count(), 'last' => $latest(DB::table('coach_messages')->where('channel', 'telegram')->max('created_at')), 'errors' => 0, 'anchor' => null, 'what' => null],
            ['key' => 'coros', 'name' => 'COROS', 'count' => $count('coros_connections'), 'last' => $latest($max('coros_connections', 'activities_synced_at'), $max('coros_connections', 'health_synced_at')), 'errors' => $errors('coros_connections', 'refresh_error'), 'anchor' => null, 'what' => 'Abruf'],
            ['key' => 'strava', 'name' => 'Strava', 'count' => $count('strava_accounts'), 'last' => $latest($max('strava_webhook_events'), $max('strava_accounts', 'last_synced_at')), 'errors' => $errors('strava_accounts', 'sync_error'), 'anchor' => 'strava', 'what' => null],
            ['key' => 'apple', 'name' => 'Apple Health', 'count' => User::whereNotNull('apple_health_consent_at')->count(), 'last' => $latest(User::max('apple_health_synced_at'), User::max('apple_activities_synced_at')), 'errors' => 0, 'anchor' => null, 'what' => null],
            ['key' => 'polar', 'name' => 'Polar', 'count' => $count('polar_connections'), 'last' => $latest($max('polar_webhook_events'), $max('polar_connections', 'activities_synced_at')), 'errors' => $errors('polar_connections', 'last_error'), 'anchor' => 'polar', 'what' => null],
            ['key' => 'whoop', 'name' => 'WHOOP', 'count' => $count('whoop_connections'), 'last' => $latest($max('whoop_webhook_events'), $max('whoop_connections', 'activities_synced_at'), $max('whoop_connections', 'health_synced_at')), 'errors' => $errors('whoop_connections', 'last_error'), 'anchor' => 'whoop', 'what' => null],
            ['key' => 'intervals', 'name' => 'intervals.icu', 'count' => $count('intervals_connections'), 'last' => $latest($max('intervals_webhook_events'), $max('intervals_connections', 'workouts_synced_at'), $max('intervals_connections', 'activities_synced_at')), 'errors' => $errors('intervals_connections', 'last_error'), 'anchor' => null, 'what' => null],
        ];

        return collect($rows)->map(function ($r) {
            $silent = $r['last'] && $r['last']->lt(now()->subHours(self::PROVIDER_SILENT_HOURS));
            $tone = $r['count'] === 0 ? 'off' : ($r['errors'] > 0 || $silent || ! $r['last'] ? 'warn' : 'success');
            $value = match (true) {
                $r['count'] === 0 => 'niemand verbunden',
                $r['errors'] > 0  => ($r['errors'] === 1 ? '1 Konto' : "{$r['errors']} Konten") . ' mit Fehler',
                ! $r['last']      => "noch nichts · {$r['count']}",
                default           => trim(($r['what'] ? "{$r['what']} " : '') . $this->ago($r['last'])) . " · {$r['count']}" . ($r['key'] === 'garmin' ? ($r['count'] === 1 ? ' Athlet' : ' Athleten') : ''),
            };

            return [
                'key'    => $r['key'],
                'name'   => $r['name'],
                'count'  => $r['count'],
                'tone'   => $tone,
                'value'  => $value,
                'anchor' => $r['anchor'],
            ];
        })->all();
    }

    /**
     * Was der Plan-Prüfer in den letzten sieben Tagen korrigieren musste,
     * nach Art gezählt. Die Texte des Validators tragen Datum und Zahlen;
     * zusammengefasst wird nach dem, was korrigiert wurde.
     *
     * @return array<string, mixed>
     */
    public function planChecker(): array
    {
        $revisions = PlanRevision::where('created_at', '>=', now()->subDays(7))->get(['corrections']);

        $counts = [];
        foreach ($revisions as $r) {
            foreach ($r->corrections ?? [] as $c) {
                $label = $this->correctionLabel((string) $c);
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        }
        arsort($counts);

        return [
            'revisions' => $revisions->count(),
            'items'     => collect($counts)->map(fn ($n, $l) => ['label' => $l, 'count' => $n])->values()->take(6)->all(),
            'total'     => array_sum($counts),
        ];
    }

    public function correctionLabel(string $text): string
    {
        $rules = [
            '/langer Lauf mit .* aus der Leiter/u'        => 'Langer Lauf an die Leiter angepasst',
            '/aus dem Wochenumfang/u'                     => 'Dauer an den Wochenumfang angepasst',
            '/Hauptteil.*gekürzt/u'                       => 'Harter Hauptteil gekürzt',
            '/wird lockerer Lauf/u'                       => 'Harte Einheit wurde locker',
            '/erlaubt sind .* gekürzt/u'                  => 'Einheit auf die erlaubte Dauer gekürzt',
            '/verfügbar → gekürzt/u'                      => 'Tag auf das Zeitfenster gekürzt',
            '/zweite harte Einheit/u'                     => 'Zweite harte Einheit entfernt',
            '/zweite[rn]? Lauf|ein Lauf pro Tag/u'        => 'Zweiter Lauf am Tag entfernt',
            '/fehlte \(stand als Ruhetag\)/u'             => 'Einheit statt Ruhetag eingesetzt',
            '/aus dem Gerüst fehlte/u'                    => 'Fehlende Einheit ergänzt',
            '/statt .* aus dem Gerüst → ersetzt/u'        => 'Falscher Lauftyp ersetzt',
            '/kein Eintrag geliefert → Ruhetag/u'         => 'Leerer Tag wurde Ruhetag',
            '/Ruhetag im Gerüst/u'                        => 'Ruhetag aus dem Gerüst wieder gesetzt',
            '/nicht verfügbar, aber/u'                    => 'Einheit an gesperrtem Tag entfernt',
            '/kein sinnvoller Lauf/u'                     => 'Zu kurzer Lauf wurde Ruhetag',
            '/außerhalb des Planungsfensters|hinter dem Renntag/u' => 'Tag außerhalb des Fensters verworfen',
            '/Radtag/u'                                   => 'Lauf am Radtag ersetzt',
            '/Unbekannter Typ/u'                          => 'Unbekannter Typ korrigiert',
            '/Kraft|Übung/u'                              => 'Kraftübungen korrigiert',
        ];
        foreach ($rules as $re => $label) {
            if (preg_match($re, $text)) {
                return $label;
            }
        }

        $text = preg_replace('/^[^:]{0,24}:\s*/u', '', $text);
        $text = preg_replace('/\d+([.,]\d+)?/u', '…', $text);

        return mb_strimwidth(trim(explode('→', $text)[0]), 0, 60, '…');
    }

    // ── Kleinkram ─────────────────────────────────────────────────────

    /** Wie der Athlet Zone3 zuletzt geöffnet hat. */
    public function way(User $u): ?string
    {
        $ways = array_filter([
            'App'        => $u->seen_app_on,
            'Homescreen' => $u->seen_homescreen_on,
            'Browser'    => $u->seen_browser_on,
        ]);
        if (! $ways) {
            return null;
        }
        arsort($ways);

        return array_key_first($ways);
    }

    public function lastSeenLabel(User $u): string
    {
        $last = collect([$u->seen_app_on, $u->seen_homescreen_on, $u->seen_browser_on])->filter()->map(fn ($d) => Carbon::parse($d))->sortDesc()->first();

        return $last ? $this->dayLabel((int) $last->copy()->startOfDay()->diffInDays(today())) : 'nie';
    }

    public function dayLabel(int $days): string
    {
        return match (true) {
            $days <= 0 => 'heute',
            $days === 1 => 'gestern',
            default => "vor {$days} Tagen",
        };
    }

    public function ago(Carbon $at): string
    {
        $min = (int) $at->diffInMinutes(now());

        return match (true) {
            $min < 1    => 'gerade eben',
            $min < 60   => "vor {$min} min",
            $min < 1440 => 'vor ' . intdiv($min, 60) . ' Std.',
            $min < 2880 => 'gestern',
            default     => 'vor ' . intdiv($min, 1440) . ' Tagen',
        };
    }

    /** „heute 06:40“, „gestern 21:10“, „12.11.“ */
    public function when(Carbon $at): string
    {
        return match (true) {
            $at->isToday()     => 'heute ' . $at->format('H:i'),
            $at->isYesterday() => 'gestern ' . $at->format('H:i'),
            default            => $at->format('d.m.'),
        };
    }

    /** „Anna Weber“ → „Anna W.“ */
    public function short(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name));
        if (count($parts) < 2) {
            return $name;
        }

        return $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
    }

    public function first(string $name): string
    {
        return preg_split('/\s+/u', trim($name))[0] ?? $name;
    }

    /** Eine feste Farbe je Konto, aus den Systemfarben. */
    public function color(int $id): string
    {
        $palette = ['#5856D6', '#D3204F', '#248A3D', '#0071E3', '#C45C00', '#8E44AD', '#00857A', '#B25000'];

        return $palette[$id % count($palette)];
    }

    /** Für die Mehr-Seite: der Zugangsmodus als Wort. */
    public function accessLabel(): string
    {
        return Access::mode() === 'open' ? 'offen' : 'Warteliste an';
    }
}

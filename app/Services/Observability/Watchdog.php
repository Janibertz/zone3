<?php

namespace App\Services\Observability;

use App\Models\PerfAlert;
use App\Models\PerfCommandRun;
use App\Models\PerfEvent;
use App\Models\User;
use App\Services\SystemHealth;
use App\Services\WebPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Meldet sich, wenn etwas kippt — und hoert wieder auf.
 *
 * Der Anlass steht in der Projektgeschichte: sechs Tage ohne Strava-Import,
 * bemerkt vom Athleten selbst, weil niemand hingesehen hat. Eine Ansicht im
 * Admin-Bereich hilft nur dem, der sie oeffnet.
 *
 * Zwei Entscheidungen tragen das hier:
 *
 * **Abklingzeit.** Der Watchdog laeuft alle 15 Minuten. Ohne `perf_alerts`
 * als Gedaechtnis schickte er denselben Satz viermal pro Stunde, und nach
 * einem Tag liest ihn niemand mehr. Loest sich ein Befund auf, wird die
 * Sperre zurueckgesetzt: kommt er wieder, ist das eine neue Nachricht wert.
 *
 * **Was hier NICHT geprueft wird.** Ob der Scheduler laeuft. Dieses
 * Kommando ist selbst geplant — stirbt der Scheduler, stirbt der Waechter
 * mit. Diese Frage beantwortet `SystemHealth` beim Seitenaufruf, wo sie
 * ankommt.
 */
class Watchdog
{
    public function __construct(
        private readonly SystemHealth $health,
        private readonly WebPushService $push,
    ) {}

    /**
     * @return list<array{key: string, message: string, notified: bool}>
     */
    public function run(bool $notify = true): array
    {
        $findings = $this->findings();
        $open     = collect($findings)->keyBy('key');
        $result   = [];

        foreach ($findings as $finding) {
            $alert = PerfAlert::firstOrNew(['key' => $finding['key']]);

            // Ein Befund, der wieder da ist, faengt von vorn an.
            if ($alert->exists && $alert->resolved_at) {
                $alert->last_notified_at = null;
                $alert->occurrences      = 0;
            }

            $mayNotify = $alert->mayNotify();

            $alert->message     = mb_substr($finding['message'], 0, 500);
            $alert->resolved_at = null;
            $alert->occurrences = (int) $alert->occurrences + 1;

            if ($notify && $mayNotify) {
                $this->notifyAdmins($finding['message']);
                $alert->last_notified_at = now();
            }

            $alert->save();

            $result[] = $finding + ['notified' => $notify && $mayNotify];
        }

        // Alles, was nicht mehr auffaellt, gilt als erledigt.
        PerfAlert::whereNull('resolved_at')
            ->when($open->isNotEmpty(), fn ($q) => $q->whereNotIn('key', $open->keys()->all()))
            ->update(['resolved_at' => now()]);

        return $result;
    }

    /**
     * Die offenen Befunde, ohne Nebenwirkung — auch fuer die Admin-Ansicht.
     *
     * @return list<array{key: string, message: string}>
     */
    public function findings(): array
    {
        return array_values(array_filter([
            $this->failedJobs(),
            $this->stalledQueue(),
            $this->serverErrors(),
            $this->silentWebhook(),
            ...$this->overdueCommands(),
        ]));
    }

    // ── Die einzelnen Fragen ─────────────────────────────────────────────

    private function failedJobs(): ?array
    {
        $failed = $this->health->failedJobs();

        if (($failed['total'] ?? 0) === 0) {
            return null;
        }

        $worst   = $failed['byClass'][0] ?? null;
        $topJob  = $worst['job'] ?? 'Unbekannt';

        return [
            'key'     => 'failed_jobs',
            'message' => "{$failed['total']} fehlgeschlagene Aufgabe(n), haeufigste: " . class_basename($topJob),
        ];
    }

    private function stalledQueue(): ?array
    {
        $limit = (int) config('observability.alerts.queue_stalled_minutes', 15);

        foreach ($this->health->queues() as $queue) {
            if ($queue['pending'] > 0 && $queue['waiting_min'] >= $limit) {
                return [
                    'key'     => 'queue_stalled:' . $queue['queue'],
                    'message' => "Queue „{$queue['queue']}\u{201C}: {$queue['pending']} Aufgabe(n) warten seit {$queue['waiting_min']} Minuten. Laeuft der Worker?",
                ];
            }
        }

        return null;
    }

    private function serverErrors(): ?array
    {
        $burst = (int) config('observability.alerts.server_error_burst', 5);

        $count = PerfEvent::where('type', PerfEvent::TYPE_REQUEST)
            ->where('created_at', '>=', now()->subHour())
            ->where('status', 'like', '5%')
            ->count();

        if ($count < $burst) {
            return null;
        }

        return [
            'key'     => 'server_errors',
            'message' => "{$count} Serverfehler in der letzten Stunde.",
        ];
    }

    /**
     * Kein Webhook seit zwei Tagen — als Frage, nicht als Alarm.
     *
     * Dass Strava nichts schickt, kann heissen, dass niemand gelaufen ist.
     * Genau deshalb ist die Schwelle lang und der Satz vorsichtig
     * formuliert: eine Warnung, die auch bei gesundem System erscheint,
     * bringt einem bei, Warnungen zu ignorieren.
     */
    private function silentWebhook(): ?array
    {
        if (! Schema::hasTable('strava_webhook_events') || ! Schema::hasTable('strava_accounts')) {
            return null;
        }

        $connected = DB::table('strava_accounts')
            ->whereNotNull('refresh_token')
            ->where('refresh_token', '!=', '')
            ->count();

        if ($connected === 0) {
            return null;
        }

        $hours  = (int) config('observability.alerts.webhook_silence_hours', 48);
        $latest = DB::table('strava_webhook_events')->max('created_at');

        if ($latest && $latest >= now()->subHours($hours)->toDateTimeString()) {
            return null;
        }

        return [
            'key'     => 'webhook_silent',
            'message' => "Seit {$hours} h kein Strava-Webhook. Wenn jemand gelaufen ist, pruefe die Subscription und Cloudflare.",
        ];
    }

    /**
     * Kommandos, die ueberfaellig sind.
     *
     * Die Achse, auf der sechs Tage Import-Ausfall unbemerkt blieben.
     *
     * @return list<array{key: string, message: string}>
     */
    private function overdueCommands(): array
    {
        return PerfCommandRun::all()
            ->filter(fn (PerfCommandRun $run) => $run->isOverdue())
            ->map(function (PerfCommandRun $run) {
                $minutes = (int) $run->last_run_at->diffInMinutes(now());

                return [
                    'key'     => 'command_overdue:' . $run->command,
                    'message' => "„{$run->command}\u{201C} lief zuletzt vor {$minutes} Minuten.",
                ];
            })
            ->values()
            ->all();
    }

    // ── Zustellung ───────────────────────────────────────────────────────

    private function notifyAdmins(string $message): void
    {
        $admins = User::where('is_admin', true)->get();

        foreach ($admins as $admin) {
            try {
                $this->push->sendToUser($admin, 'Zone3 System', $message, '/admin/performance');
            } catch (\Throwable $e) {
                Log::warning('Watchdog konnte nicht benachrichtigen', [
                    'user_id' => $admin->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Mail\MonthlyReviewMail;
use App\Models\User;
use App\Services\WebPushService;
use App\Services\WrappedService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendMonthlyReview extends Command
{
    protected $signature   = 'review:monthly {--month= : Force a specific month as YYYY-MM (defaults to last month)}';
    protected $description = 'Send the previous month\'s running review to users via push + email (combined opt-in).';

    public function handle(WrappedService $wrapped, WebPushService $webPush): int
    {
        // Default to the month that just finished (run on the 1st via scheduler).
        $target = $this->option('month')
            ? \Carbon\Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();
        $year  = (int) $target->year;
        $month = (int) $target->month;

        $users = User::where('notify_monthly_review', true)
            ->with(['pushSubscriptions'])
            ->get();

        $sent = 0;
        foreach ($users as $user) {
            $stats = $wrapped->generate($user, 'month', $year, $month);
            if (empty($stats['has_data'])) {
                continue; // no runs that month → nothing to recap
            }

            $reviewUrl   = route('wrapped.index');
            $settingsUrl = route('profile.edit');
            $firstName   = explode(' ', trim($user->name))[0] ?: 'Läufer';

            // ── Push (requires push enabled + an active subscription) ──
            if ($user->push_notifications_enabled && $user->pushSubscriptions->isNotEmpty()) {
                $webPush->sendToUser(
                    $user,
                    "Dein Rückblick: {$stats['period_label']} 🏃",
                    "{$stats['totals']['km']} km · {$stats['totals']['runs']} Läufe — tipp für deinen Monatsrückblick.",
                    '/rueckblick',
                );
            }

            // ── Email ──
            try {
                Mail::to($user->email)->send(new MonthlyReviewMail(
                    $stats, $stats['period_label'], $firstName, $reviewUrl, $settingsUrl,
                ));
            } catch (\Throwable $e) {
                $this->warn("Email an User #{$user->id} fehlgeschlagen: {$e->getMessage()}");
            }

            $sent++;
            $this->line("Rückblick {$stats['period_label']} an User #{$user->id} gesendet.");
        }

        $this->info("Fertig. {$sent} Rückblicke für {$target->format('Y-m')} versendet.");
        return self::SUCCESS;
    }
}

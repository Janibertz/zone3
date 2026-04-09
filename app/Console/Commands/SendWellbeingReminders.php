<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Console\Command;

class SendWellbeingReminders extends Command
{
    protected $signature   = 'push:wellbeing-reminders';
    protected $description = 'Send push notifications to users who have not entered wellbeing today and whose reminder time matches now.';

    public function handle(WebPushService $webPush): void
    {
        $now   = now()->format('H:i');
        $today = now()->toDateString();

        // Users who:
        // - have push enabled
        // - have a reminder time set that matches the current minute
        // - have NOT entered wellbeing today
        $users = User::where('push_notifications_enabled', true)
            ->whereNotNull('wellbeing_reminder_time')
            ->whereRaw("TIME_FORMAT(wellbeing_reminder_time, '%H:%i') = ?", [$now])
            ->whereDoesntHave('wellbeingEntries', fn ($q) => $q->where('date', $today))
            ->with('pushSubscriptions')
            ->get();

        foreach ($users as $user) {
            if ($user->pushSubscriptions->isEmpty()) continue;

            $webPush->sendToUser(
                $user,
                'Wie geht es dir heute? 💪',
                'Trage jetzt deine Tagesform ein — der KI-Plan passt sich automatisch an.',
                '/dashboard'
            );

            $this->line("Sent wellbeing reminder to user #{$user->id}");
        }

        $this->info("Done. Processed {$users->count()} users.");
    }
}

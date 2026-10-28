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

        // Ohne eingestellte Uhrzeit gilt dieselbe wie beim Telegram-Briefing
        // und im Profil (08:00) — vorher kam dann gar nichts. Wenige
        // Athleten: der Vergleich in PHP spart eine MySQL-eigene TIME_FORMAT-Abfrage.
        $users = User::where('push_notifications_enabled', true)
            ->whereDoesntHave('wellbeingEntries', fn ($q) => $q->whereDate('date', $today))
            // Wer Telegram verbunden hat, bekommt stattdessen das Briefing
            // (telegram:briefing) — zur selben Minute.
            ->whereNull('telegram_chat_id')
            ->with(['pushSubscriptions', 'coach'])
            ->get()
            ->filter(fn (User $u) => substr((string) ($u->wellbeing_reminder_time ?? SendTelegramBriefings::DEFAULT_TIME), 0, 5) === $now);

        foreach ($users as $user) {
            if ($user->pushSubscriptions->isEmpty()) continue;

            [$title, $body] = match ($user->coach?->specialty) {
                'motivator'  => ["Bereit für heute? 💪", $user->coach->name . " wartet auf deine Tagesform — lass uns den Tag rocken!"],
                'strategist' => ["Tagesform eintragen 📊", $user->coach->name . ": Deine Daten fehlen noch — Plan-Optimierung ausstehend."],
                'companion'  => ["Wie geht's dir heute? 🌿", $user->coach->name . " möchte wissen wie du dich fühlst. Kurz eintragen!"],
                default      => ["Wie geht es dir heute? 💪", "Trage jetzt deine Tagesform ein — dein Plan passt sich automatisch an."],
            };

            $webPush->sendToUser($user, $title, $body, '/dashboard?tagesform=1');

            $this->line("Sent wellbeing reminder to user #{$user->id}");
        }

        $this->info("Done. Processed {$users->count()} users.");
    }
}

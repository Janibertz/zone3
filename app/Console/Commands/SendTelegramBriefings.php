<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TelegramBriefing;
use App\Services\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Das Morgen-Briefing per Telegram: die Einheit des Tages und die Bitte um
 * den Check-in.
 *
 * Kommt zur selben Uhrzeit wie die Wellbeing-Erinnerung (`wellbeing_reminder_time`)
 * und ersetzt sie für alle mit Telegram — {@see SendWellbeingReminders}
 * lässt diese Athleten aus. Zwei Nachrichten zur selben Minute wären eine
 * zu viel.
 */
class SendTelegramBriefings extends Command
{
    protected $signature   = 'telegram:briefing {--user= : Nur diesen Athleten, sofort}';
    protected $description = 'Schickt Athleten mit Telegram zu ihrer Erinnerungszeit die Einheit des Tages.';

    /** Wer keine Uhrzeit eingestellt hat, bekommt dieselbe wie im Profil vorgeschlagen. */
    private const DEFAULT_TIME = '08:00';

    public function handle(TelegramClient $telegram, TelegramBriefing $briefing): int
    {
        if (! $telegram->configured()) {
            return self::SUCCESS;
        }

        $now   = now()->format('H:i');
        $today = now()->toDateString();
        $only  = $this->option('user');

        // Wenige Athleten — der Uhrzeitvergleich in PHP spart eine
        // datenbankabhängige TIME_FORMAT-Abfrage.
        $users = User::whereNotNull('telegram_chat_id')
            ->when($only, fn ($q) => $q->where('id', $only))
            ->get()
            ->filter(fn (User $u) => $only || substr((string) ($u->wellbeing_reminder_time ?? self::DEFAULT_TIME), 0, 5) === $now);

        foreach ($users as $user) {
            // Einmal am Tag, auch wenn der Scheduler eine Minute doppelt läuft.
            if (! $only && ! Cache::add("telegram.briefing.{$user->id}.{$today}", true, now()->endOfDay())) {
                continue;
            }

            $telegram->sendMessage((int) $user->telegram_chat_id, $briefing->today($user, morning: true));
            $this->line("Briefing an #{$user->id}");
        }

        return self::SUCCESS;
    }
}

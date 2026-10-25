<?php

namespace App\Console\Commands;

use App\Services\TelegramClient;
use Illuminate\Console\Command;

/**
 * Meldet die Webhook-URL bei Telegram an. Idempotent — `startup.sh` ruft es
 * bei jedem Deploy auf, damit URL und Geheimnis nach einer Änderung von
 * APP_URL oder APP_KEY nicht still auseinanderlaufen.
 */
class TelegramSetWebhook extends Command
{
    protected $signature   = 'telegram:set-webhook';
    protected $description = 'Registriert /telegram/webhook samt Geheimnis beim Telegram-Bot.';

    public function handle(TelegramClient $telegram): int
    {
        if (! $telegram->configured()) {
            $this->warn('TELEGRAM_BOT_TOKEN ist nicht gesetzt — nichts zu tun.');

            return self::SUCCESS;
        }

        $url = route('telegram.webhook');

        if ($telegram->setWebhook($url) === null) {
            $this->error("Telegram hat den Webhook {$url} abgelehnt — siehe Log.");

            return self::FAILURE;
        }

        $this->info("Webhook registriert: {$url}");

        return self::SUCCESS;
    }
}

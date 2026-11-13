<?php

namespace App\Console\Commands;

use App\Models\PolarWebhookEvent;
use App\Services\Polar\PolarClient;
use App\Services\Polar\PolarWebhook;
use Illuminate\Console\Command;
use Throwable;

/**
 * Stündlich: hält Zone3s einen Polar-Webhook eingerichtet und aktiv, und
 * räumt das Webhook-Protokoll nach 30 Tagen ab.
 */
class PolarWebhookCommand extends Command
{
    protected $signature = 'polar:webhook';

    protected $description = 'Richtet den Polar-Webhook ein bzw. prüft ihn';

    public function handle(PolarClient $client, PolarWebhook $webhook): int
    {
        PolarWebhookEvent::where('created_at', '<', now()->subDays(30))->delete();

        if (! $client->configured()) {
            $this->line('Polar nicht eingerichtet (POLAR_CLIENT_ID/POLAR_CLIENT_SECRET).');

            return self::SUCCESS;
        }

        try {
            $this->info('Polar-Webhook: ' . $webhook->ensure());
        } catch (Throwable $e) {
            report($e);
            $this->warn('Polar-Webhook: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

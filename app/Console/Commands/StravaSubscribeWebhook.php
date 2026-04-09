<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class StravaSubscribeWebhook extends Command
{
    protected $signature   = 'strava:subscribe-webhook';
    protected $description = 'Register or view the Strava push subscription for this app';

    public function handle(): int
    {
        $clientId     = config('services.strava.client_id');
        $clientSecret = config('services.strava.client_secret');
        $callbackUrl  = config('services.strava.webhook_callback_url', url('/strava/webhook'));
        $verifyToken  = config('services.strava.webhook_verify_token', 'zone3_webhook');

        if (! $clientId || ! $clientSecret) {
            $this->error('STRAVA_CLIENT_ID or STRAVA_CLIENT_SECRET not set.');
            return 1;
        }

        // Check existing subscriptions first
        $existing = Http::get('https://www.strava.com/api/v3/push_subscriptions', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if ($existing->successful() && count($existing->json()) > 0) {
            $this->info('Existing Strava webhook subscription found:');
            $this->line(json_encode($existing->json(), JSON_PRETTY_PRINT));
            return 0;
        }

        // Register new subscription
        $this->info("Registering webhook: {$callbackUrl}");

        $response = Http::asForm()->post('https://www.strava.com/api/v3/push_subscriptions', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'callback_url'  => $callbackUrl,
            'verify_token'  => $verifyToken,
        ]);

        if ($response->successful()) {
            $id = $response->json('id');
            $this->info("Webhook registered successfully. Subscription ID: {$id}");
            return 0;
        }

        $this->error('Failed to register webhook: ' . $response->body());
        return 1;
    }
}

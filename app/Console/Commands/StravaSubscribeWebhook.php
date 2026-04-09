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

        // Check existing subscriptions
        $existing = Http::get('https://www.strava.com/api/v3/push_subscriptions', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if ($existing->successful() && count($existing->json()) > 0) {
            $sub = $existing->json()[0];
            $existingUrl = $sub['callback_url'] ?? '';

            if ($existingUrl === $callbackUrl) {
                $this->info("Webhook already registered correctly (ID: {$sub['id']}).");
                $this->line("URL: {$existingUrl}");
                return 0;
            }

            $this->warn("Existing subscription (ID: {$sub['id']}) points to: {$existingUrl}");
            $this->info('Deleting old subscription...');

            $delete = Http::delete("https://www.strava.com/api/v3/push_subscriptions/{$sub['id']}", [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ]);

            if ($delete->successful() || $delete->status() === 204) {
                $this->info('Old subscription deleted.');
            } else {
                $this->error('Failed to delete old subscription: ' . $delete->body());
                return 1;
            }
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

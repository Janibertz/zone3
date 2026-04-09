<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    private function client(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject'    => config('services.webpush.subject'),
                'publicKey'  => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ]);
    }

    /**
     * Send a push notification to all subscriptions of a user.
     */
    public function sendToUser(User $user, string $title, string $body, string $url = '/dashboard'): void
    {
        $subscriptions = $user->pushSubscriptions;
        if ($subscriptions->isEmpty()) return;

        $client  = $this->client();
        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);
        $stale   = [];

        foreach ($subscriptions as $sub) {
            $result = $client->sendOneNotification(
                Subscription::create([
                    'endpoint'        => $sub->endpoint,
                    'keys' => [
                        'p256dh' => $sub->public_key,
                        'auth'   => $sub->auth_token,
                    ],
                ]),
                $payload
            );

            if ($result->isSubscriptionExpired()) {
                $stale[] = $sub->id;
            }
        }

        // Clean up expired subscriptions
        if ($stale) {
            PushSubscription::whereIn('id', $stale)->delete();
        }
    }

    /**
     * Send a test notification to the current user.
     */
    public function sendTest(User $user): void
    {
        $this->sendToUser(
            $user,
            'Zone3 Push funktioniert! 🎉',
            'Du erhältst ab jetzt Benachrichtigungen.',
            '/dashboard'
        );
    }
}

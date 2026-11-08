<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\ApnsClient;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function __construct(private readonly ApnsClient $apns)
    {
    }

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

        $stale = [];

        // iOS-App: APNs statt Web-Push
        foreach ($subscriptions->where('kind', PushSubscription::KIND_APNS) as $sub) {
            if ($this->apns->send($sub->endpoint, $title, $body, $url) === ApnsClient::GONE) {
                $stale[] = $sub->id;
            }
        }

        $web = $subscriptions->where('kind', '!=', PushSubscription::KIND_APNS);
        if ($web->isEmpty()) {
            if ($stale) PushSubscription::whereIn('id', $stale)->delete();
            return;
        }

        $client  = $this->client();
        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);

        foreach ($web as $sub) {
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

    public function broadcast(string $title, string $body, string $url = '/dashboard'): int
    {
        $sent = 0;
        User::where('push_notifications_enabled', true)
            ->whereHas('pushSubscriptions')
            ->each(function (User $user) use ($title, $body, $url, &$sent) {
                $this->sendToUser($user, $title, $body, $url);
                $sent++;
            });
        return $sent;
    }
}

<?php

namespace App\Services\Admin;

use App\Models\AdminPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\TelegramClient;
use App\Services\WebPushService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * „Push senden“ im Admin (18.11.2026). Wer Telegram verbunden hat, bekommt
 * die Nachricht dort und nur dort — Telegram ist für ihn der erste Weg
 * (Jan). Alle anderen bekommen eine Push-Nachricht, sofern sie Push an
 * haben und ein Gerät angemeldet ist.
 */
class AdminPushService
{
    /** Ziele, die eine Nachricht öffnen kann. */
    public const TARGETS = [
        '/dashboard' => 'Heute',
        '/plan'      => 'Plan',
        '/calendar'  => 'Kalender',
        '/activities'=> 'Aktivitäten',
        '/mehr'      => 'Mehr',
    ];

    /** Gruppen für „Gruppe“. */
    public const GROUPS = [
        'garmin'    => 'Garmin',
        'coros'     => 'COROS',
        'polar'     => 'Polar',
        'whoop'     => 'WHOOP',
        'apple'     => 'Apple Health',
        'strava'    => 'Strava',
        'telegram'  => 'Telegram',
        'app'       => 'iPhone-App',
        'no_plan'   => 'Ohne Plan',
        'quiet'     => 'Still geworden',
    ];

    public function __construct(
        private readonly WebPushService $push,
        private readonly TelegramClient $telegram,
        private readonly AdminInsights $insights,
    ) {}

    /** Telegram, Push oder nichts. */
    public function channel(User $u): ?string
    {
        if ($u->telegram_chat_id && $this->telegram->configured()) {
            return 'telegram';
        }
        if ($u->push_notifications_enabled && $u->pushSubscriptions()->exists()) {
            return 'push';
        }

        return null;
    }

    /** „über Telegram“, „über die iPhone-App (1 Gerät)“ … */
    public function channelLabel(User $u): string
    {
        return match ($this->channel($u)) {
            'telegram' => 'über Telegram',
            'push'     => (function () use ($u) {
                $subs = $u->pushSubscriptions()->get(['kind']);
                $app  = $subs->where('kind', PushSubscription::KIND_APNS)->count();
                $what = $app === $subs->count() ? 'die iPhone-App' : ($app === 0 ? 'den Browser' : 'App und Browser');

                return "über {$what} (" . ($subs->count() === 1 ? '1 Gerät' : $subs->count() . ' Geräte') . ')';
            })(),
            default    => 'gar nicht — weder Telegram noch Push',
        };
    }

    /**
     * Eine einzelne Nachricht ohne Eintrag im Verlauf, z. B. „Antwort auf
     * dein Ticket“. Gibt den Weg zurück, oder null.
     */
    public function notify(User $u, string $title, string $body, string $url): ?string
    {
        $channel = $this->channel($u);
        try {
            if ($channel === 'telegram') {
                $link = rtrim((string) config('app.url'), '/') . $url;
                $buttons = str_starts_with($link, 'https://') ? [[['text' => 'In Zone3 öffnen', 'url' => $link]]] : null;

                return $this->telegram->sendMessage((int) $u->telegram_chat_id, "**{$title}**\n\n{$body}", $buttons) ? 'telegram' : null;
            }
            if ($channel === 'push') {
                $this->push->sendToUser($u, $title, $body, $url);

                return 'push';
            }
        } catch (\Throwable $e) {
            Log::warning('Admin-Nachricht an einen Athleten fehlgeschlagen', ['user_id' => $u->id, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /** @return Collection<int, User> */
    public function audience(string $audience, ?string $group = null, ?int $userId = null): Collection
    {
        if ($audience === 'user') {
            return User::whereKey($userId)->get();
        }

        $base = User::where('is_active', true)->whereNull('waitlisted_at');
        if ($audience === 'all') {
            return $base->get();
        }

        return match ($group) {
            'garmin'   => $base->whereHas('garminConnection')->get(),
            'coros'    => $base->whereHas('corosConnection')->get(),
            'polar'    => $base->whereHas('polarConnection')->get(),
            'whoop'    => $base->whereHas('whoopConnection')->get(),
            'apple'    => $base->whereNotNull('apple_health_consent_at')->get(),
            'strava'   => $base->whereHas('stravaAccount')->get(),
            'telegram' => $base->whereNotNull('telegram_chat_id')->get(),
            'app'      => $base->whereHas('pushSubscriptions', fn ($q) => $q->where('kind', PushSubscription::KIND_APNS))->get(),
            'no_plan'  => $base->whereNotNull('onboarding_completed_at')->whereDoesntHave('trainingPlans', fn ($q) => $q->where('is_active', true))->get(),
            'quiet'    => User::whereIn('id', collect($this->insights->athleteRows())->where('quiet', true)->pluck('id'))->get(),
            default    => collect(),
        };
    }

    /** Wie viele eine Auswahl erreicht, nach Weg. @return array{total:int, telegram:int, push:int} */
    public function reach(Collection $users): array
    {
        $out = ['total' => $users->count(), 'telegram' => 0, 'push' => 0];
        foreach ($users as $u) {
            if ($c = $this->channel($u)) {
                $out[$c]++;
            }
        }

        return $out;
    }

    /**
     * Schicken und im Verlauf festhalten.
     *
     * @param  Collection<int, User>  $users
     */
    public function send(Collection $users, string $title, string $body, string $url, string $audience, string $label, ?User $by = null, ?int $targetId = null): AdminPush
    {
        $viaTelegram = 0;
        $viaPush     = 0;

        foreach ($users as $u) {
            try {
                $channel = $this->channel($u);
                if ($channel === 'telegram') {
                    $link = rtrim((string) config('app.url'), '/') . $url;
                    $buttons = str_starts_with($link, 'https://')
                        ? [[['text' => 'In Zone3 öffnen', 'url' => $link]]]
                        : null;
                    if ($this->telegram->sendMessage((int) $u->telegram_chat_id, "**{$title}**\n\n{$body}", $buttons)) {
                        $viaTelegram++;
                    }
                } elseif ($channel === 'push') {
                    $this->push->sendToUser($u, $title, $body, $url);
                    $viaPush++;
                }
            } catch (\Throwable $e) {
                Log::warning('Admin-Push an einen Athleten fehlgeschlagen', ['user_id' => $u->id, 'error' => $e->getMessage()]);
            }
        }

        Log::info('Admin-Push gesendet', ['audience' => $audience, 'label' => $label, 'telegram' => $viaTelegram, 'push' => $viaPush]);

        return AdminPush::create([
            'sent_by'        => $by?->id,
            'audience'       => $audience,
            'audience_label' => mb_substr($label, 0, 80),
            'target_user_id' => $targetId,
            'title'          => $title,
            'body'           => $body,
            'url'            => $url,
            'reached'        => $viaTelegram + $viaPush,
            'via_telegram'   => $viaTelegram,
            'via_push'       => $viaPush,
        ]);
    }
}

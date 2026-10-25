<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transport zur Telegram Bot API — nichts weiter.
 *
 * Was gesendet wird, entscheiden die Aufrufer. Ein Fehler hier wirft nicht:
 * eine Nachricht, die Telegram nicht annimmt, steht trotzdem im Verlauf, und
 * ein Job, der an einer Benachrichtigung scheitert, wuerde sonst erneut die
 * KI aufrufen.
 */
class TelegramClient
{
    private const API = 'https://api.telegram.org';

    /** Telegram nimmt höchstens 4096 Zeichen je Nachricht. */
    private const MAX_LENGTH = 4096;

    public function configured(): bool
    {
        return (bool) config('services.telegram.bot_token');
    }

    /**
     * Das Geheimnis, das Telegram bei jedem Webhook-Aufruf im Header
     * `X-Telegram-Bot-Api-Secret-Token` mitschickt. Ohne eigene Variable
     * aus APP_KEY abgeleitet; erlaubt sind A–Z, a–z, 0–9, _ und -.
     */
    public function webhookSecret(): string
    {
        return config('services.telegram.webhook_secret')
            ?: substr(hash_hmac('sha256', 'telegram-webhook', (string) config('app.key')), 0, 48);
    }

    /**
     * @param  array<int, array<int, array{text:string, callback_data?:string, url?:string}>>|null  $buttons  Zeilen von Inline-Knöpfen
     */
    public function sendMessage(int $chatId, string $text, ?array $buttons = null): bool
    {
        $payload = [
            'chat_id'                  => $chatId,
            'text'                     => self::toHtml($text),
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($buttons) {
            $payload['reply_markup'] = ['inline_keyboard' => $buttons];
        }

        return $this->call('sendMessage', $payload) !== null;
    }

    /** Knöpfe unter einer Nachricht entfernen, nachdem sie benutzt wurden. */
    public function clearButtons(int $chatId, int $messageId): void
    {
        $this->call('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }

    /** Beendet die Ladeanzeige am Knopf; ohne Antwort dreht sie weiter. */
    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackId,
            'text'              => $text,
        ]));
    }

    /** "Schreibt…" im Chat, solange der Coach nachdenkt. */
    public function typing(int $chatId): void
    {
        $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    }

    public function setWebhook(string $url): ?array
    {
        return $this->call('setWebhook', [
            'url'             => $url,
            'secret_token'    => $this->webhookSecret(),
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    /** Der Benutzername des Bots, für den t.me-Link im Profil. */
    public function botUsername(): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        return Cache::remember('telegram.bot_username', now()->addDay(), function () {
            return $this->call('getMe', [])['username'] ?? null;
        });
    }

    /**
     * Die Coach-Texte sind leichtes Markdown (**fett**). Telegram-HTML ist
     * strenger als Markdown und bricht an einem einzelnen `*` nicht ab —
     * deshalb erst alles escapen, dann nur das Fette zurückholen.
     */
    public static function toHtml(string $text): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        $html = preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $html);

        return mb_substr($html, 0, self::MAX_LENGTH);
    }

    private function call(string $method, array $payload): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::timeout(10)->post(
                self::API . '/bot' . config('services.telegram.bot_token') . '/' . $method,
                $payload,
            );

            if (! $response->successful() || ! $response->json('ok')) {
                Log::warning('Telegram-Aufruf abgelehnt', [
                    'method' => $method,
                    'status' => $response->status(),
                    'error'  => $response->json('description'),
                ]);

                return null;
            }

            $result = $response->json('result');

            return is_array($result) ? $result : ['ok' => true];
        } catch (\Throwable $e) {
            Log::warning('Telegram nicht erreichbar', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }
}

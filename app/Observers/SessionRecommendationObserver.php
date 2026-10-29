<?php

namespace App\Observers;

use App\Models\SessionRecommendation;
use App\Services\TelegramClient;

/**
 * Ein Vorschlag des Coaches kommt per Telegram mit zwei Knöpfen.
 *
 * Auf dem Dashboard wartet die Karte, bis der Athlet die App öffnet — und
 * das ist oft erst kurz vor dem Lauf. In Telegram ist die Entscheidung ein
 * Tipp. Die Knöpfe landen in {@see \App\Http\Controllers\TelegramWebhookController},
 * der dieselben Methoden aufruft wie das Dashboard.
 */
class SessionRecommendationObserver
{
    public function created(SessionRecommendation $recommendation): void
    {
        // Ein Vorschlag aus dem Chat kommt mit der Antwort des Coaches
        // ({@see CoachMessageObserver}) — sonst stünde er in Telegram vor dem
        // Text, der ihn erklärt, und aus dem Web-Chat käme er doppelt.
        if ($recommendation->source === SessionRecommendation::SOURCE_COACH) {
            return;
        }

        self::send($recommendation);
    }

    /** Der Vorschlag mit zwei Knöpfen, sofern der Athlet Telegram hat. */
    public static function send(SessionRecommendation $recommendation): void
    {
        $user = $recommendation->user;
        if (! $user?->hasTelegram() || ! $recommendation->isOpen()) {
            return;
        }

        $date = $recommendation->session?->planned_date;
        $when = ! $date || $date->isToday()
            ? 'heute'
            : $date->locale('de')->isoFormat('dddd, D.M.');

        $lines = [
            "**Vorschlag für {$when}**",
            'Geplant: ' . self::describe($recommendation->before ?? []),
            'Vorschlag: ' . self::describe($recommendation->after ?? []),
        ];

        if ($recommendation->reason) {
            $lines[] = '';
            $lines[] = $recommendation->reason;
        }

        app(TelegramClient::class)->sendMessage((int) $user->telegram_chat_id, implode("\n", $lines), [[
            ['text' => 'Annehmen', 'callback_data' => "rec:accept:{$recommendation->id}"],
            ['text' => 'Ablehnen', 'callback_data' => "rec:reject:{$recommendation->id}"],
        ]]);
    }

    /** "Tempolauf · 10 km · 55 min" — was eine Einheit ausmacht, in einer Zeile. */
    public static function describe(array $s): string
    {
        $parts = [$s['title'] ?? $s['type'] ?? 'Einheit'];

        if (! empty($s['distance_km'])) $parts[] = rtrim(rtrim(number_format((float) $s['distance_km'], 1, ',', ''), '0'), ',') . ' km';
        if (! empty($s['duration_min'])) $parts[] = (int) $s['duration_min'] . ' min';
        if (! empty($s['pace_target']))  $parts[] = $s['pace_target'];

        return implode(' · ', $parts);
    }
}

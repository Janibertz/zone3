<?php

namespace App\Http\Controllers;

use App\Services\TelegramClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profil → Verbindungen → Telegram.
 *
 * Verbunden wird über einen Einmal-Code im t.me-Link: Telegram öffnet den
 * Bot mit `/start <code>`, und der Webhook ordnet den Chat dem Konto zu.
 * So muss niemand eine Chat-ID abtippen, und ein fremder Chat kann sich
 * nicht an ein Konto hängen.
 */
class TelegramLinkController extends Controller
{
    public function store(Request $request, TelegramClient $telegram): Response|RedirectResponse
    {
        $username = $telegram->botUsername();

        if (! $username) {
            return back()->with('error', 'Telegram ist auf diesem Server noch nicht eingerichtet.');
        }

        $token = Str::random(40);
        $request->user()->forceFill(['telegram_link_token' => $token])->save();

        return Inertia::location("https://t.me/{$username}?start={$token}");
    }

    public function destroy(Request $request, TelegramClient $telegram): RedirectResponse
    {
        $user   = $request->user();
        $chatId = $user->telegram_chat_id;

        $user->forceFill([
            'telegram_chat_id'    => null,
            'telegram_link_token' => null,
            'telegram_linked_at'  => null,
        ])->save();

        if ($chatId) {
            $telegram->sendMessage((int) $chatId, 'Zone3 wurde getrennt. Du bekommst hier keine Nachrichten mehr.');
        }

        Log::info('Telegram getrennt', ['user_id' => $user->id, 'via' => 'profil']);

        return back()->with('success', 'Telegram getrennt.');
    }
}

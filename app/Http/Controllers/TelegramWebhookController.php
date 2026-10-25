<?php

namespace App\Http\Controllers;

use App\Jobs\CoachReplyJob;
use App\Models\CoachMessage;
use App\Models\SessionRecommendation;
use App\Models\User;
use App\Services\AI\CoachChatService;
use App\Services\TelegramBriefing;
use App\Services\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Alles, was Telegram an Zone3 schickt.
 *
 * Antwortet immer sofort mit 200 — Telegram stellt ein Update sonst erneut
 * zu, und der Coach antwortete zweimal. Die eigentliche Antwort entsteht in
 * {@see CoachReplyJob}.
 *
 * Anders als Strava signiert Telegram seine Aufrufe: jeder trägt das beim
 * `setWebhook` hinterlegte Geheimnis im Header. Ohne das gibt es 403.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TelegramBriefing $briefing,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! hash_equals($this->telegram->webhookSecret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            Log::warning('Telegram-Webhook ohne gültiges Geheimnis', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 403);
        }

        // Dasselbe Update zweimal (Telegram wiederholt nach Zeitüberschreitung)
        // darf nur einmal wirken.
        $updateId = $request->input('update_id');
        if ($updateId !== null && ! Cache::add("telegram.update.{$updateId}", true, now()->addDay())) {
            return response()->json(['ok' => true]);
        }

        if ($callback = $request->input('callback_query')) {
            $this->callback($callback);
        } elseif ($message = $request->input('message')) {
            $this->message($message);
        }

        return response()->json(['ok' => true]);
    }

    private function message(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $text   = trim((string) ($message['text'] ?? ''));

        if (! $chatId || $text === '') {
            return;
        }

        // Nur Einzelchats. In einer Gruppe läsen andere mit, was der Coach
        // über Ruhepuls und Schlaf schreibt.
        if (($message['chat']['type'] ?? 'private') !== 'private') {
            return;
        }

        if (str_starts_with($text, '/start')) {
            $this->start($chatId, trim(substr($text, 6)));

            return;
        }

        $user = User::where('telegram_chat_id', $chatId)->first();

        if (! $user) {
            $this->telegram->sendMessage($chatId, 'Dieser Chat ist noch mit keinem Zone3-Konto verbunden. Öffne Zone3 → Profil → Verbindungen → Telegram und tippe auf „Verbinden“.');

            return;
        }

        match (strtolower(strtok($text, ' @'))) {
            '/heute' => $this->telegram->sendMessage($chatId, $this->briefing->today($user)),
            '/stop'  => $this->unlink($user, $chatId),
            '/hilfe', '/help' => $this->telegram->sendMessage($chatId, self::HELP),
            default  => $this->ask($user, $chatId, $text),
        };
    }

    private const HELP = "Schreib mir einfach, wie bei einem Trainer — ich kann deinen Plan auch ändern.\n\n"
        . "/heute — was heute ansteht\n"
        . "/stop — Telegram von Zone3 trennen";

    /** `/start <code>` aus dem Profil-Link verknüpft Chat und Konto. */
    private function start(int $chatId, string $token): void
    {
        $user = $token !== '' ? User::where('telegram_link_token', $token)->first() : null;

        if (! $user) {
            $this->telegram->sendMessage($chatId, User::where('telegram_chat_id', $chatId)->exists()
                ? 'Du bist schon verbunden. ' . self::HELP
                : 'Der Link ist abgelaufen oder ungültig. Öffne Zone3 → Profil → Verbindungen → Telegram und tippe erneut auf „Verbinden“.');

            return;
        }

        // Ein Chat gehört zu genau einem Konto.
        User::where('telegram_chat_id', $chatId)->where('id', '!=', $user->id)
            ->update(['telegram_chat_id' => null, 'telegram_linked_at' => null]);

        $user->forceFill([
            'telegram_chat_id'    => $chatId,
            'telegram_link_token' => null,
            'telegram_linked_at'  => now(),
        ])->save();

        Log::info('Telegram verbunden', ['user_id' => $user->id]);

        $coach = $user->coach?->name ?? 'Dein Coach';
        $this->telegram->sendMessage($chatId, "Verbunden! {$coach} meldet sich ab jetzt hier: morgens mit deiner Einheit, nach jedem Lauf mit einem Review und mit Vorschlägen, wenn deine Tagesform es nahelegt.\n\n" . self::HELP);
    }

    private function unlink(User $user, int $chatId): void
    {
        $user->forceFill(['telegram_chat_id' => null, 'telegram_linked_at' => null])->save();

        Log::info('Telegram getrennt', ['user_id' => $user->id, 'via' => 'bot']);

        $this->telegram->sendMessage($chatId, 'Getrennt. Benachrichtigungen kommen wieder als Push in der App.');
    }

    private function ask(User $user, int $chatId, string $text): void
    {
        $limiter = app(CoachChatService::class)->forUser($user->id);
        if ($limiter->isRateLimited()) {
            $this->telegram->sendMessage($chatId, 'Für heute ist dein Kontingent an KI-Anfragen aufgebraucht. Morgen geht es weiter.');

            return;
        }

        $question = CoachMessage::create([
            'user_id' => $user->id,
            'role'    => 'user',
            'content' => mb_substr($text, 0, 2000),
            'channel' => CoachMessage::CHANNEL_TELEGRAM,
        ]);

        $this->telegram->typing($chatId);

        CoachReplyJob::dispatch($question->id);
    }

    /** Ein Knopf unter einem Vorschlag: `rec:accept:42` oder `rec:reject:42`. */
    private function callback(array $callback): void
    {
        $chatId    = (int) ($callback['message']['chat']['id'] ?? 0);
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $user      = User::where('telegram_chat_id', $chatId)->first();

        if (! $user || ! preg_match('/^rec:(accept|reject):(\d+)$/', (string) ($callback['data'] ?? ''), $m)) {
            $this->telegram->answerCallback((string) ($callback['id'] ?? ''));

            return;
        }

        $recommendation = SessionRecommendation::where('id', (int) $m[2])->where('user_id', $user->id)->first();

        if (! $recommendation || ! $recommendation->isOpen()) {
            $this->telegram->answerCallback((string) $callback['id'], 'Darüber ist schon entschieden.');
            $this->telegram->clearButtons($chatId, $messageId);

            return;
        }

        if ($m[1] === 'accept') {
            $reply = $recommendation->accept() ? 'Angenommen — die Einheit ist angepasst.' : 'Die Einheit gibt es nicht mehr.';
        } else {
            $recommendation->reject();
            $reply = 'Abgelehnt — alles bleibt wie geplant.';
        }

        $this->telegram->answerCallback((string) $callback['id']);
        $this->telegram->clearButtons($chatId, $messageId);
        $this->telegram->sendMessage($chatId, $reply);
    }
}

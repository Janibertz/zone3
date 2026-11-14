<?php

namespace App\Http\Controllers;

use App\Jobs\CoachReplyJob;
use App\Jobs\TelegramMediaJob;
use App\Models\CoachMessage;
use App\Models\SessionRecommendation;
use App\Models\TrainingSession;
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

        if (! $chatId) {
            return;
        }

        // Nur Einzelchats. In einer Gruppe läsen andere mit, was der Coach
        // über Ruhepuls und Schlaf schreibt.
        if (($message['chat']['type'] ?? 'private') !== 'private') {
            return;
        }

        // Sprache und Bilder werden zu Text (TelegramMediaJob), alles andere
        // bekommt einen Hinweis statt Stille. Vor dem 11.11.2026 verschwand
        // jede solche Nachricht ohne Antwort.
        if ($text === '') {
            if ($this->isMedia($message) && ($user = User::where('telegram_chat_id', $chatId)->first())) {
                $this->media($user, $chatId, $message);
            }

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

    private const MEDIA_HINT = "Sprachnachrichten und Fotos verstehe ich — Videos, Sticker und andere Dateien leider nicht. Schreib mir dazu bitte kurz als Text.";

    private const MEDIA_KEYS = ['photo', 'voice', 'audio', 'video', 'video_note', 'document', 'sticker', 'animation'];

    /** Länger hört der Coach nicht zu — fünf Minuten kosten etwa anderthalb Cent. */
    public const MAX_VOICE_SECONDS = 300;

    private function isMedia(array $message): bool
    {
        foreach (self::MEDIA_KEYS as $key) {
            if (isset($message[$key])) return true;
        }

        return false;
    }

    /**
     * Eine Sprachnachricht oder ein Bild an den Coach — über die Queue.
     *
     * Die Grenzen prüft der Webhook schon hier, damit eine Sperre sofort
     * gemeldet wird und nichts heruntergeladen wird, was nicht gelesen werden darf.
     */
    private function media(User $user, int $chatId, array $message): void
    {
        $caption   = trim((string) ($message['caption'] ?? '')) ?: null;
        $forwarded = isset($message['forward_origin']) || isset($message['forward_from']) || isset($message['forward_sender_name']);

        $job = match (true) {
            isset($message['voice']) || isset($message['audio']) => $this->voiceJob($user, $chatId, $message['voice'] ?? $message['audio'], $forwarded),
            isset($message['photo']) => $this->imageJob($user, $chatId, self::bestPhoto($message['photo']), 'image/jpeg', $caption),
            isset($message['document']) && in_array($message['document']['mime_type'] ?? '', self::IMAGE_MIMES, true)
                => $this->imageJob($user, $chatId, $message['document'], (string) $message['document']['mime_type'], $caption),
            default => null,
        };

        if ($job === false) {
            return; // schon beantwortet
        }

        if (! $job) {
            $this->telegram->sendMessage($chatId, self::MEDIA_HINT);

            return;
        }

        if ($reason = app(CoachChatService::class)->forUser($user->id)->chatBlockReason()) {
            Log::info('Telegram: Coach gesperrt', ['user_id' => $user->id, 'reason' => $reason]);
            $this->telegram->sendMessage($chatId, self::blockedMessage($reason));

            return;
        }

        $this->telegram->typing($chatId);

        dispatch($job);
    }

    /** OpenAI liest diese Bildformate; HEIC vom iPhone schickt Telegram als Foto (JPEG). */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private function voiceJob(User $user, int $chatId, array $voice, bool $forwarded): TelegramMediaJob|false|null
    {
        $seconds = (int) ($voice['duration'] ?? 0);
        if ($seconds > self::MAX_VOICE_SECONDS) {
            $this->telegram->sendMessage($chatId, 'Sprachnachrichten höre ich mir bis fünf Minuten an. Magst du es kürzer sagen?');

            return false;
        }

        if (empty($voice['file_id'])) {
            return null;
        }

        return new TelegramMediaJob($user->id, $chatId, CoachMessage::KIND_VOICE, (string) $voice['file_id'],
            (string) ($voice['mime_type'] ?? 'audio/ogg'), null, $seconds ?: null, $forwarded);
    }

    private function imageJob(User $user, int $chatId, ?array $file, string $mime, ?string $caption): ?TelegramMediaJob
    {
        if (empty($file['file_id']) || (int) ($file['file_size'] ?? 0) > TelegramClient::MAX_DOWNLOAD_BYTES) {
            return null;
        }

        return new TelegramMediaJob($user->id, $chatId, CoachMessage::KIND_IMAGE, (string) $file['file_id'], $mime, $caption);
    }

    /**
     * Telegram schickt ein Foto in mehreren Größen. Genommen wird die größte
     * bis 1600 px Kantenlänge: genug, um eine Uhr abzulesen, ohne dass das
     * Bildmodell für Pixel bezahlt wird, die nichts zeigen.
     */
    private static function bestPhoto(array $sizes): ?array
    {
        $sizes = array_values(array_filter($sizes, 'is_array'));
        usort($sizes, fn ($a, $b) => max($a['width'] ?? 0, $a['height'] ?? 0) <=> max($b['width'] ?? 0, $b['height'] ?? 0));

        $fitting = array_filter($sizes, fn ($s) => max($s['width'] ?? 0, $s['height'] ?? 0) <= 1600);

        return $fitting ? end($fitting) : ($sizes[0] ?? null);
    }

    private const HELP ="Schreib mir einfach, wie bei einem Trainer — ich kann deinen Plan auch ändern. Sprachnachrichten und Fotos (z. B. ein Screenshot deiner Uhr) verstehe ich auch.\n\n"
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
        if ($reason = $limiter->chatBlockReason()) {
            Log::info('Telegram: Coach gesperrt', ['user_id' => $user->id, 'reason' => $reason]);
            $this->telegram->sendMessage($chatId, self::blockedMessage($reason));

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

    /**
     * Die Notbremse und das Budget fuer alle sind nicht das Kontingent des
     * Athleten — dort hilft es nichts, wenn der Admin sein Limit hochsetzt.
     */
    public static function blockedMessage(string $reason): string
    {
        return str_starts_with($reason, 'Notbremse') || str_starts_with($reason, 'Tagesbudget fuer alle')
            ? 'Der Coach macht gerade eine Pause, die KI ist für heute ausgelastet. Versuch es später noch einmal.'
            : 'Für heute ist dein Kontingent an KI-Anfragen aufgebraucht. Morgen geht es weiter.';
    }

    /** Ein Knopf unter einem Vorschlag: `rec:accept:42` oder `rec:reject:42`. */
    private function callback(array $callback): void
    {
        $chatId    = (int) ($callback['message']['chat']['id'] ?? 0);
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $user      = User::where('telegram_chat_id', $chatId)->first();

        if ($user && preg_match('/^rv:(\d+):(\d+)$/', (string) ($callback['data'] ?? ''), $rv)) {
            $this->reviewAnswer($user, $callback, (int) $rv[1], (int) $rv[2]);

            return;
        }

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
            $reply = $recommendation->accept() ? 'Angenommen — die Einheit ist angepasst.' : 'Der Vorschlag ist überholt — die Einheit ist vorbei oder gibt es nicht mehr.';
        } else {
            $recommendation->reject();
            $reply = 'Abgelehnt — alles bleibt wie geplant.';
        }

        $this->telegram->answerCallback((string) $callback['id']);
        $this->telegram->clearButtons($chatId, $messageId);
        $this->telegram->sendMessage($chatId, $reply);
    }

    /**
     * Ein Knopf unter der Rückfrage nach einer Einheit: `rv:<Einheit>:<Nummer>`.
     * Die Antwort landet wie in der App an der Einheit und in den Notizen, und
     * der Coach antwortet darauf — so wird aus der Frage ein Gespräch.
     */
    private function reviewAnswer(User $user, array $callback, int $sessionId, int $index): void
    {
        $chatId    = (int) ($callback['message']['chat']['id'] ?? 0);
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $session   = TrainingSession::where('id', $sessionId)->where('user_id', $user->id)->first();
        $answer    = $session?->review_options[$index] ?? null;

        $this->telegram->clearButtons($chatId, $messageId);

        if (! $session || ! $answer || $session->review_feedback) {
            $this->telegram->answerCallback((string) ($callback['id'] ?? ''), 'Schon beantwortet.');

            return;
        }

        $session->answerReview($answer);
        $this->telegram->answerCallback((string) ($callback['id'] ?? ''), $answer);
        $this->ask($user, $chatId, "Zu deiner Frage „{$session->review_question}\u{201C}: {$answer}");
    }
}

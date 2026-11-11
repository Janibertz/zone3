<?php

namespace App\Jobs;

use App\Models\CoachMessage;
use App\Models\User;
use App\Services\AI\CoachMediaService;
use App\Services\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Eine Sprachnachricht oder ein Bild aus Telegram für den Coach lesbar machen.
 *
 * Läuft in der Queue, nicht im Webhook: Herunterladen und Abschreiben dauern
 * Sekunden, und Telegram stellt ein Update erneut zu, wenn die Antwort
 * ausbleibt. Danach geht es weiter wie bei einer getippten Nachricht:
 * eine CoachMessage, dann {@see CoachReplyJob}.
 *
 * Die Datei existiert nur im Speicher dieses Jobs.
 */
class TelegramMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 180;

    public function __construct(
        public readonly int $userId,
        public readonly int $chatId,
        public readonly string $kind,      // CoachMessage::KIND_VOICE | KIND_IMAGE
        public readonly string $fileId,
        public readonly string $mime,
        public readonly ?string $caption = null,
        public readonly ?int $seconds = null,
        public readonly bool $forwarded = false,
    ) {}

    public function handle(TelegramClient $telegram, CoachMediaService $media): void
    {
        $user = User::with('coach')->find($this->userId);
        if (! $user || (int) $user->telegram_chat_id !== $this->chatId) {
            return;
        }

        $media->forUser($user->id);

        $file = $telegram->downloadFile($this->fileId);
        if (! $file) {
            $telegram->sendMessage($this->chatId, 'Die Datei konnte ich nicht laden. Versuch es bitte noch einmal oder schreib mir kurz als Text.');

            return;
        }

        $telegram->typing($this->chatId);

        if ($this->kind === CoachMessage::KIND_VOICE) {
            $text = $media->transcribe($file['bytes'], self::filename($file['path'], $this->mime), $this->seconds);
            if ($text === null) {
                $this->failMessage($telegram, 'Deine Sprachnachricht konnte ich nicht verstehen.');

                return;
            }

            $question = CoachMessage::create([
                'user_id'       => $user->id,
                'role'          => 'user',
                'content'       => $text,
                'channel'       => CoachMessage::CHANNEL_TELEGRAM,
                'kind'          => CoachMessage::KIND_VOICE,
                'media_foreign' => $this->forwarded,
            ]);
        } else {
            $description = $media->describeImage($file['bytes'], $this->mime, $this->caption);
            if ($description === null) {
                $this->failMessage($telegram, 'Das Bild konnte ich nicht lesen.');

                return;
            }

            $question = CoachMessage::create([
                'user_id'       => $user->id,
                'role'          => 'user',
                'content'       => $this->caption ? mb_substr($this->caption, 0, 2000) : CoachMessage::IMAGE_PLACEHOLDER,
                'channel'       => CoachMessage::CHANNEL_TELEGRAM,
                'kind'          => CoachMessage::KIND_IMAGE,
                'media_text'    => $description,
                'media_foreign' => true,
            ]);
        }

        CoachReplyJob::dispatch($question->id);
    }

    /** Ohne Antwort sähe es aus, als wäre die Nachricht verschluckt. */
    private function failMessage(TelegramClient $telegram, string $what): void
    {
        $reason = app(\App\Services\AI\OpenAIClient::class)->forUser($this->userId)->blockReason('coach_chat');

        $telegram->sendMessage($this->chatId, $reason
            ? \App\Http\Controllers\TelegramWebhookController::blockedMessage($reason)
            : $what . ' Versuch es bitte noch einmal oder schreib mir kurz als Text.');
    }

    public function failed(\Throwable $e): void
    {
        Log::error('TelegramMediaJob failed', ['user_id' => $this->userId, 'kind' => $this->kind, 'error' => $e->getMessage()]);

        app(TelegramClient::class)->sendMessage($this->chatId, 'Da ist gerade etwas schiefgegangen. Schreib mir bitte kurz als Text.');
    }

    /** OpenAI erkennt das Format an der Endung; Telegram liefert Sprache als .oga. */
    private static function filename(string $path, string $mime): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $ext = match (true) {
            in_array($ext, ['oga', 'opus'], true) => 'ogg',
            $ext !== ''                           => $ext,
            str_contains($mime, 'mpeg')           => 'mp3',
            str_contains($mime, 'mp4')            => 'm4a',
            default                               => 'ogg',
        };

        return 'nachricht.' . $ext;
    }
}

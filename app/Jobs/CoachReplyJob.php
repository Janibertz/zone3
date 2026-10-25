<?php

namespace App\Jobs;

use App\Models\CoachMessage;
use App\Services\AI\CoachChatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Die Antwort des Coaches auf eine Nachricht — egal aus welchem Kanal.
 *
 * Lief vorher im Web-Request: `CoachChatController::send` rief OpenAI samt
 * Werkzeugen direkt auf, und weil `php artisan serve` einen Prozess hat,
 * stand waehrenddessen die ganze Seite. Telegram braucht es ohnehin so —
 * der Webhook muss sofort antworten, sonst schickt Telegram das Update
 * erneut.
 *
 * Die Antwort landet als CoachMessage mit `reply_to_id`. Den Rest erledigt
 * `CoachMessageObserver`: Telegram-Nachricht oder Web-Push. Der Web-Chat
 * fragt per `coach.reply` nach, ob sie schon da ist.
 */
class CoachReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 180;

    /** So viel Verlauf bekommt das Modell mit — wie vorher im Controller. */
    private const HISTORY = 19;

    public function __construct(
        public readonly int $messageId,
    ) {}

    public function handle(CoachChatService $chat): void
    {
        $question = CoachMessage::with('user.coach')->find($this->messageId);
        if (! $question || ! $question->user) return;

        // Schon beantwortet — ein zweiter Lauf desselben Jobs darf nicht
        // zweimal antworten.
        if (CoachMessage::where('reply_to_id', $question->id)->exists()) return;

        $user = $question->user;

        $history = CoachMessage::where('user_id', $user->id)
            ->where('id', '<', $question->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $chat->withCoach($user->coach?->personality_prompt)->forUser($user->id);

        $result  = $chat->chatWithCoachTools($user, $history, $question->content);
        $reply   = $result['reply'] ?? null;
        $actions = $result['actions'] ?? [];

        $this->answer($question, $reply ?: 'Entschuldigung, ich konnte gerade nicht antworten. Versuche es gleich nochmal.', $actions);
    }

    /**
     * Auch ein Absturz bekommt eine Antwort. Sonst wartet der Web-Chat
     * endlos, und in Telegram kommt schlicht nichts.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('CoachReplyJob failed', ['message_id' => $this->messageId, 'error' => $e->getMessage()]);

        $question = CoachMessage::find($this->messageId);
        if ($question && ! CoachMessage::where('reply_to_id', $question->id)->exists()) {
            $this->answer($question, 'Entschuldigung, ich konnte gerade nicht antworten. Versuche es gleich nochmal.', []);
        }
    }

    private function answer(CoachMessage $question, string $content, array $actions): void
    {
        CoachMessage::create([
            'user_id'     => $question->user_id,
            'role'        => 'assistant',
            'content'     => $content,
            'channel'     => $question->channel,
            'reply_to_id' => $question->id,
            'actions'     => $actions ?: null,
        ]);
    }
}

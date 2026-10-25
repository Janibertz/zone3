<?php

namespace App\Http\Controllers;

use App\Jobs\CoachReplyJob;
use App\Models\CoachMessage;
use App\Services\AI\CoachChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoachChatController extends Controller
{
    public function __construct(protected CoachChatService $chat) {}

    /** Wie viele Nachrichten der Chat beim Oeffnen zeigt. */
    private const HISTORY_LIMIT = 50;

    public function messages(Request $request): JsonResponse
    {
        // Erst die neuesten holen, dann fuer die Anzeige umdrehen.
        //
        // Vorher stand hier orderBy('created_at') aufsteigend mit demselben
        // Limit — das liefert die AELTESTEN fuenfzig. Wer laenger dabei ist,
        // sah beim Oeffnen deshalb immer denselben Verlauf von vor Monaten,
        // waehrend alles Neue zwar gespeichert, aber nie angezeigt wurde.
        $messages = CoachMessage::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['role', 'content', 'created_at'])
            ->reverse()
            ->values();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Den Gespraechsverlauf loeschen.
     *
     * Bewusst getrennt vom Gemerkten: der Verlauf ist die Unterhaltung, die
     * Notizen sind das, was der Coach ueber den Athleten gelernt hat. Wer
     * aufraeumen will, meint fast immer nur das Erste.
     */
    public function destroyMessages(Request $request): JsonResponse
    {
        $deleted = CoachMessage::where('user_id', $request->user()->id)->delete();

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    /** Das Gemerkte loeschen — der Coach vergisst, was er ueber dich weiss. */
    public function destroyNotes(Request $request): JsonResponse
    {
        $request->user()->runnerProfile?->update(['coach_notes' => null]);

        return response()->json(['success' => true]);
    }

    /**
     * Eine Nachricht an den Coach. Die Antwort entsteht in der Queue
     * ({@see CoachReplyJob}); der Chat fragt mit {@see reply()} nach.
     *
     * Vorher lief der ganze Modellaufruf samt Werkzeugen hier im Request —
     * bei einem einzigen Web-Prozess stand solange die ganze Seite.
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $user = $request->user();

        $this->chat->forUser($user->id);
        if ($this->chat->isRateLimited()) {
            return response()->json(['error' => 'rate_limited', 'message' => 'Tageslimit erreicht.'], 429);
        }

        $question = CoachMessage::create([
            'user_id' => $user->id,
            'role'    => 'user',
            'content' => trim($request->message),
            'channel' => CoachMessage::CHANNEL_WEB,
        ]);

        CoachReplyJob::dispatch($question->id);

        return response()->json(['pending' => true, 'message_id' => $question->id], 202);
    }

    /** Ist die Antwort auf diese Nachricht schon da? */
    public function reply(Request $request, CoachMessage $message): JsonResponse
    {
        abort_unless($message->user_id === $request->user()->id, 403);

        $reply = CoachMessage::where('reply_to_id', $message->id)->first();

        if (! $reply) {
            return response()->json(['pending' => true]);
        }

        return response()->json([
            'pending'       => false,
            'response'      => $reply->content,
            'actions_taken' => $reply->actions ?? [],
            'timestamp'     => $reply->created_at?->toIso8601String(),
        ]);
    }
}

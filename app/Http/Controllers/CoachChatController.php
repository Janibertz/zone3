<?php

namespace App\Http\Controllers;

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

    public function send(Request $request): JsonResponse
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $user       = $request->user();
        $userInput  = trim($request->message);

        // Fetch history BEFORE storing the new message (last 19 for context window)
        $history = CoachMessage::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(19)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        // Persist user message
        CoachMessage::create([
            'user_id' => $user->id,
            'role'    => 'user',
            'content' => $userInput,
        ]);

        // Generate coach response with tool use
        $this->chat->withCoach($user->coach?->personality_prompt)->forUser($user->id);

        if ($this->chat->isRateLimited()) {
            return response()->json(['error' => 'rate_limited', 'message' => 'Tageslimit erreicht.'], 429);
        }

        $result = $this->chat->chatWithCoachTools($user, $history, $userInput);
        $reply  = $result['reply'] ?? null;
        $actions = $result['actions'] ?? [];

        if ($reply) {
            CoachMessage::create([
                'user_id' => $user->id,
                'role'    => 'assistant',
                'content' => $reply,
            ]);
        }

        return response()->json([
            'response'       => $reply,
            'actions_taken'  => $actions,
            'timestamp'      => now()->toIso8601String(),
        ]);
    }
}

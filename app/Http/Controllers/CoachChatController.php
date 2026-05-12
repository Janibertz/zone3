<?php

namespace App\Http\Controllers;

use App\Models\CoachMessage;
use App\Services\OpenAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoachChatController extends Controller
{
    public function __construct(protected OpenAIService $openAI) {}

    public function messages(Request $request): JsonResponse
    {
        $messages = CoachMessage::where('user_id', $request->user()->id)
            ->orderBy('created_at')
            ->limit(50)
            ->get(['role', 'content', 'created_at']);

        return response()->json(['messages' => $messages]);
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

        // Generate coach response
        $this->openAI->withCoach($user->coach?->personality_prompt)->forUser($user->id);
        $reply = $this->openAI->chatWithCoach($user, $history, $userInput);

        if ($reply) {
            CoachMessage::create([
                'user_id' => $user->id,
                'role'    => 'assistant',
                'content' => $reply,
            ]);
        }

        return response()->json([
            'response' => $reply,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

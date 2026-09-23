<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\Ai\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    public function __construct(
        protected ChatbotService $chatbotService
    ) {}

    /**
     * Mengirim pesan ke chatbot dan menerima balasan.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'session_token' => 'required|string',
            'message' => 'required|string',
            'character' => 'nullable|string',
        ]);

        $sessionToken = $request->input('session_token');
        $messageText = $request->input('message');
        $character = $request->input('character');

        $conversation = $this->chatbotService->getOrCreateConversation($sessionToken, $character);
        $result = $this->chatbotService->handleMessage($conversation, $messageText);

        return response()->json([
            'success' => true,
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $result['bot_message']->id,
                'role' => $result['bot_message']->role,
                'content' => $result['bot_message']->content,
                'meta_payload' => $result['bot_message']->meta_payload,
                'created_at' => $result['bot_message']->created_at->toIso8601String(),
            ],
            'build_data' => $result['build_data'],
        ]);
    }

    /**
     * Mengambil riwayat percakapan berdasarkan token sesi.
     */
    public function getHistory(string $sessionToken): JsonResponse
    {
        $conversation = Conversation::where('session_token', $sessionToken)
            ->with(['messages' => function ($q) {
                $q->orderBy('created_at', 'asc');
            }])
            ->first();

        if (!$conversation) {
            return response()->json([
                'success' => true,
                'messages' => [],
            ]);
        }

        return response()->json([
            'success' => true,
            'conversation_id' => $conversation->id,
            'character_slug' => $conversation->character_slug,
            'target_content' => $conversation->target_content,
            'messages' => $conversation->messages->map(fn($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'meta_payload' => $m->meta_payload,
                'created_at' => $m->created_at->toIso8601String(),
            ]),
        ]);
    }
}

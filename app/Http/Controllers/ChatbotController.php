<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\Ai\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        try {
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
        } catch (\Throwable $e) {
            Log::error('[CHAT] Request failed', [
                'session_token' => $sessionToken,
                'character' => $character,
                'message_length' => strlen((string) $messageText),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error_code' => 'AI_REQUEST_FAILED',
                'message' => 'Layanan AI sedang tidak merespons. Silakan coba lagi dalam beberapa detik.',
            ], 502);
        }
    }

    /**
     * Mengirim balasan chatbot sebagai Server-Sent Events.
     */
    public function streamMessage(Request $request): StreamedResponse|JsonResponse
    {
        $request->validate([
            'session_token' => 'required|string',
            'message' => 'required|string',
            'character' => 'nullable|string',
        ]);

        $sessionToken = $request->input('session_token');
        $messageText = $request->input('message');
        $character = $request->input('character');

        try {
            $conversation = $this->chatbotService->getOrCreateConversation(
                $sessionToken,
                $character
            );
        } catch (\Throwable $e) {
            Log::error('[CHAT STREAM] Could not prepare conversation', [
                'exception' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'error_code' => 'CHAT_STREAM_FAILED',
                'message' => 'Percakapan tidak dapat disiapkan saat ini.',
            ], 502);
        }

        return response()->stream(function () use (
            $conversation,
            $messageText

        ) {
            set_time_limit(0);
            @ini_set('zlib.output_compression', '0');
            ob_implicit_flush(true);

            $sendEvent = static function (array|string $data): void {
                $encoded = is_string($data)
                    ? $data
                    : json_encode(
                        $data,
                        JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                            | JSON_INVALID_UTF8_SUBSTITUTE
                    );

                echo 'data: '.$encoded."\n\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();
            };

            // Commit the SSE response before waiting for the first NVIDIA token.
            echo ": stream-start\n\n";
            flush();

            try {
                $result = $this->chatbotService->streamMessage(
                    $conversation,
                    $messageText,
                    static function (string $token) use ($sendEvent): void {
                        $sendEvent([
                            'type' => 'chunk',
                            'content' => $token,
                        ]);
                    }
                );

                if (! empty($result['build_data']) && empty($result['build_data']['error'])) {
                    $sendEvent([
                        'type' => 'build_result',
                        'data' => $result['build_data'],
                    ]);
                }

                $sendEvent('[DONE]');
            } catch (\Throwable $e) {
                Log::error('[CHAT STREAM] Stream failed', [
                    'conversation_id' => $conversation->id,
                    'exception' => get_class($e),
                ]);

                $sendEvent([
                    'type' => 'error',
                    'message' => 'Stream chat terputus sebelum selesai.',
                ]);
                $sendEvent('[DONE]');
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
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

        if (! $conversation) {
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
            'messages' => $conversation->messages->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'meta_payload' => $m->meta_payload,
                'created_at' => $m->created_at->toIso8601String(),
            ]),
        ]);
    }
}

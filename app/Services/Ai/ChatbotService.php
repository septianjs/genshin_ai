<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Nvidia\NvidiaService;
use App\Services\QueryUnderstanding\EntityExtractor;
use Illuminate\Support\Facades\Log;

class ChatbotService
{
    public function __construct(
        protected RecommendationEngine $recommendationEngine,
        protected NvidiaService $nvidiaService,
        protected EntityExtractor $entityExtractor
    ) {}

    /**
     * Membuat atau mengambil conversation berdasarkan session token.
     */
    public function getOrCreateConversation(
        string $sessionToken,
        ?string $characterSlug = null
    ): Conversation {
        $conversation = Conversation::firstOrCreate(
            [
                'session_token' => $sessionToken,
            ],
            [
                'title' => $characterSlug
                    ? "Diskusi Build {$characterSlug}"
                    : 'Konsultasi Genshin Build',

                'character_slug' => $characterSlug,

                'target_content' => 'abyss',

                'patch_version' => '7.0',
            ]
        );

        /*
         * Jika conversation sudah ada tetapi frontend
         * mengirim character baru, update character aktif.
         */
        if (
            !empty($characterSlug)
            && $conversation->character_slug !== $characterSlug
        ) {
            $conversation->update([
                'character_slug' => $characterSlug,
            ]);

            $conversation->refresh();
        }

        return $conversation;
    }

    /**
     * ============================================================
     * CHAT NON-STREAMING
     * ============================================================
     *
     * Tetap dipertahankan supaya endpoint /api/chat/send
     * yang lama tidak langsung rusak.
     *
     * PERBEDAAN:
     * Sekarang chat TIDAK memanggil generateBuild().
     */
    public function handleMessage(
        Conversation $conversation,
        string $userMessageText
    ): array {

        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessageText,
        ]);

        $context = $this->prepareChatContext(
            $conversation,
            $userMessageText
        );

        $aiResponse = $this->nvidiaService->chat(
            $context['messages'],
            0.2,
            320
        );

        $replyContent =
            $aiResponse['content']
            ?? 'Maaf, saya tidak dapat memproses pertanyaan tersebut saat ini.';

        $metaPayload = [
            'character' => $context['target_character'],

            'model' =>
                $aiResponse['model']
                ?? null,

            'source' =>
                $aiResponse['source']
                ?? null,

            'status' =>
                $aiResponse['status']
                ?? null,

            'fallback_reason' =>
                $aiResponse['fallback_reason']
                ?? null,

            'chat_mode' => 'lightweight',

            'streaming' => false,

            'request_seconds' =>
                $aiResponse['request_seconds']
                ?? null,

            'total_seconds' =>
                $aiResponse['total_seconds']
                ?? null,
        ];

        $botMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $replyContent,
            'meta_payload' => $metaPayload,
            'tokens_used' =>
                $aiResponse['tokens_used']
                ?? null,
        ]);

        return [
            'user_message' => $userMessage,

            'bot_message' => $botMessage,

            /*
             * Chat biasa tidak menghasilkan build lengkap.
             */
            'build_data' => null,
        ];
    }

    /**
     * ============================================================
     * CHAT STREAMING
     * ============================================================
     *
     * $onToken akan dipanggil setiap kali NVIDIA
     * mengirim potongan jawaban.
     *
     * Controller bertugas mengubah potongan tersebut
     * menjadi SSE ke browser.
     */
    public function streamMessage(
        Conversation $conversation,
        string $userMessageText,
        callable $onToken,
        ?string $frontendCharacter = null
    ): array {

        $startTime = microtime(true);

        /*
         * Simpan pesan user terlebih dahulu.
         */
        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessageText,
        ]);

        /*
         * Kalau frontend mengirim karakter aktif,
         * gunakan sebagai konteks percakapan.
         */
        if (
            !empty($frontendCharacter)
            && $conversation->character_slug !== $frontendCharacter
        ) {
            $conversation->update([
                'character_slug' => $frontendCharacter,
            ]);

            $conversation->refresh();
        }

        /*
         * Siapkan prompt ringan.
         */
        $context = $this->prepareChatContext(
            $conversation,
            $userMessageText
        );

        Log::info(
            '[CHAT] ===== START STREAM =====',
            [
                'conversation_id' => $conversation->id,
                'character' => $context['target_character'],
                'message_chars' => strlen(
                    $userMessageText
                ),
                'history_count' => $context['history_count'],
                'prompt_chars' => $context['prompt_chars'],
            ]
        );

        $fullReply = '';

        /*
         * Panggil NVIDIA streaming.
         */
        $aiResponse = $this->nvidiaService->chatStream(
            $context['messages'],
            function (string $token) use (
                &$fullReply,
                $onToken
            ) {
                /*
                 * Simpan seluruh jawaban untuk database.
                 */
                $fullReply .= $token;

                /*
                 * Kirim token ke controller.
                 */
                $onToken($token);
            },
            0.2,
            320
        );

        /*
         * Dalam kondisi tertentu NVIDIA fallback dapat
         * mengembalikan content tanpa melewati callback.
         *
         * Hindari mengirim dua kali.
         */
        if (
            empty($fullReply)
            && !empty($aiResponse['content'])
        ) {
            $fullReply =
                $aiResponse['content'];

            $onToken($fullReply);
        }

        if (empty(trim($fullReply))) {
            $fullReply =
                'Maaf, Ava belum mendapatkan jawaban dari layanan AI.';
        }

        /*
         * Metadata chat.
         */
        $metaPayload = [
            'character' =>
                $context['target_character'],

            'model' =>
                $aiResponse['model']
                ?? null,

            'source' =>
                $aiResponse['source']
                ?? null,

            'status' =>
                $aiResponse['status']
                ?? null,

            'fallback_reason' =>
                $aiResponse['fallback_reason']
                ?? null,

            'chat_mode' =>
                'lightweight',

            'streaming' =>
                true,

            'finish_reason' =>
                $aiResponse['finish_reason']
                ?? null,

            'prompt_tokens' =>
                $aiResponse['prompt_tokens']
                ?? null,

            'completion_tokens' =>
                $aiResponse['completion_tokens']
                ?? null,

            'request_seconds' =>
                $aiResponse['request_seconds']
                ?? null,

            'total_seconds' =>
                $aiResponse['total_seconds']
                ?? null,

            'ttft_seconds' =>
                $aiResponse['ttft_seconds']
                ?? null,
        ];

        /*
         * Simpan jawaban Ava setelah streaming selesai.
         */
        $botMessage = Message::create([
            'conversation_id' => $conversation->id,

            'role' => 'assistant',

            'content' => $fullReply,

            'meta_payload' => $metaPayload,

            'tokens_used' =>
                $aiResponse['tokens_used']
                ?? null,
        ]);

        $totalDuration =
            microtime(true) - $startTime;

        Log::info(
            '[CHAT] ===== END STREAM =====',
            [
                'conversation_id' => $conversation->id,
                'assistant_message_id' => $botMessage->id,
                'content_chars' => strlen(
                    $fullReply
                ),
                'total_seconds' => round(
                    $totalDuration,
                    4
                ),
            ]
        );

        return [
            'user_message' => $userMessage,

            'bot_message' => $botMessage,

            'ai_response' => $aiResponse,

            'build_data' => null,

            'target_character' =>
                $context['target_character'],

            'total_seconds' =>
                round(
                    $totalDuration,
                    4
                ),
        ];
    }

    /**
     * ============================================================
     * PREPARE CHAT CONTEXT
     * ============================================================
     *
     * Membuat context yang jauh lebih kecil daripada
     * context RecommendationEngine.
     */
    protected function prepareChatContext(
        Conversation $conversation,
        string $userMessageText
    ): array {

        /*
         * Entity extraction tetap dipakai.
         *
         * Ini jauh lebih ringan daripada generateBuild().
         */
        $extracted = $this->entityExtractor->extract(
            $userMessageText
        );

        $targetSlug =
            $extracted['target_character']
            ?? $conversation->character_slug
            ?? 'furina';

        $constellation = (int) (
            $extracted['constellation']
            ?? 0
        );

        $contentMode =
            $extracted['content_mode']
            ?? $conversation->target_content
            ?? 'abyss';

        $team =
            !empty($extracted['team'])
                ? $extracted['team']
                : ($conversation->active_team ?? []);

        if (!is_array($team)) {
            $team = [];
        }

        /*
         * Jika user secara eksplisit menyebut karakter,
         * jadikan karakter tersebut sebagai karakter aktif.
         */
        if (!empty($extracted['target_character'])) {

            $conversation->update([
                'character_slug' =>
                    $extracted['target_character'],

                'target_content' =>
                    $contentMode,
            ]);

            $conversation->refresh();
        }

        /*
         * Ambil hanya 6 pesan terakhir.
         *
         * Jangan mengambil seluruh history karena
         * akan memperbesar prompt dan memperlambat inference.
         */
        $history = Message::where(
            'conversation_id',
            $conversation->id
        )
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->reverse()
            ->values();

        $messages = [];

        /*
         * System prompt dibuat pendek dan fokus.
         */
        $systemPrompt = <<<PROMPT
Kamu adalah Ava, AI assistant untuk website Genshin Impact Build AI.

Tugas:
- Membantu user memahami build karakter Genshin Impact.
- Menjawab pertanyaan tentang senjata, artefak, stat, ER, talent, constellation, team, reaction, rotation, dan mekanik karakter.
- Jawab dalam Bahasa Indonesia kecuali user menggunakan bahasa lain.
- Gunakan data dan konteks yang diberikan sistem.
- Jangan mengarang angka atau mekanik jika tidak ada dalam konteks.
- Jika informasi spesifik tidak tersedia, katakan bahwa informasi tersebut perlu diverifikasi.
- Jawaban harus langsung ke inti.
- Gunakan Markdown sederhana agar mudah dibaca.
- Gunakan heading pendek dan bullet list jika diperlukan.
- Jangan mengulang pertanyaan user.
- Jangan memberikan pembukaan panjang.

Karakter aktif: {$targetSlug}
Constellation: C{$constellation}
Mode konten: {$contentMode}

Tim aktif:
PROMPT;

        if (!empty($team)) {
            $systemPrompt .= "\n" . implode(
                ', ',
                array_map(
                    fn ($item) => (string) $item,
                    $team
                )
            );
        } else {
            $systemPrompt .= "\nBelum ditentukan.";
        }

        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt,
        ];

        /*
         * Masukkan history.
         *
         * Jangan masukkan pesan yang terlalu panjang.
         */
        foreach ($history as $historyMessage) {

            $content =
                (string) $historyMessage->content;

            /*
             * Batasi tiap pesan agar context tetap ringan.
             */
            if (mb_strlen($content) > 1800) {
                $content = mb_substr(
                    $content,
                    0,
                    1800
                ) . '...';
            }

            $role =
                $historyMessage->role === 'assistant'
                    ? 'assistant'
                    : 'user';

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        /*
         * Jika pesan user belum ada dalam history,
         * tambahkan sebagai user message.
         *
         * Dalam kondisi normal sudah ada karena Message::create()
         * dipanggil sebelum method ini.
         */
        if (
            empty($history)
            || $history->last()->role !== 'user'
            || $history->last()->content !== $userMessageText
        ) {
            $messages[] = [
                'role' => 'user',
                'content' => $userMessageText,
            ];
        }

        $promptChars = 0;

        foreach ($messages as $message) {
            $promptChars += strlen(
                (string) ($message['content'] ?? '')
            );
        }

        return [
            'messages' => $messages,

            'target_character' => $targetSlug,

            'constellation' => $constellation,

            'team' => $team,

            'content_mode' => $contentMode,

            'history_count' => $history->count(),

            'prompt_chars' => $promptChars,

            'extracted' => $extracted,
        ];
    }
}

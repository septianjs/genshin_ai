<?php

namespace App\Services\Ai;

use App\Models\BuildKnowledge;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Nvidia\NvidiaService;
use App\Services\QueryUnderstanding\EntityExtractor;
use App\Services\QueryUnderstanding\IntentClassifier;
use Illuminate\Support\Facades\Log;

class ChatbotService
{
    public function __construct(
        protected RecommendationEngine $recommendationEngine,
        protected NvidiaService $nvidiaService,
        protected EntityExtractor $entityExtractor,
        protected IntentClassifier $intentClassifier
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

                'patch_version' => config('services.genshin.target_patch', '7.0'),
            ]
        );

        // Keep the character established by chat history; the frontend sends its
        // current selector on every request, which may not match the discussion.
        if (
            ! empty($characterSlug)
            && $conversation->character_slug !== $characterSlug
            && ! $conversation->messages()->exists()
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
     * Build intents use the recommendation pipeline; other intents use chat.
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

        $intent = $this->resolveIntent($conversation, $userMessageText);
        if ($intent === IntentClassifier::INTENT_GREETING) {
            return $this->respondToGreeting($conversation, $userMessage, false);
        }
        if ($intent === IntentClassifier::INTENT_KNOWLEDGE_STATUS) {
            return $this->respondWithLocalKnowledgeStatus($conversation, $userMessage, false);
        }

        $context = $this->prepareChatContext(
            $conversation,
            $userMessageText
        );

        $aiResponse = $this->nvidiaService->chat(
            $context['messages'],
            0.2,
            800
        );

        $replyContent =
            $aiResponse['content']
            ?? 'Maaf, saya tidak dapat memproses pertanyaan tersebut saat ini.';

        $localKnowledgeReply = null;
        if (($aiResponse['status'] ?? null) === 'fallback') {
            $localKnowledgeReply = $this->localKnowledgeFallback(
                $intent,
                $context['target_character'],
                $context['content_mode']
            );
            $replyContent = $localKnowledgeReply
                ?? 'Maaf, layanan AI sedang tidak tersedia dan belum ada panduan lokal yang cocok. Silakan coba lagi nanti.';
        } else {
            if ($this->containsInternalReasoning($replyContent)) {
                $localKnowledgeReply = $this->localKnowledgeFallback(
                    $intent,
                    $context['target_character'],
                    $context['content_mode']
                );
                $replyContent = $localKnowledgeReply
                    ?? 'Maaf, jawaban belum berhasil disusun. Silakan coba ajukan pertanyaan lagi.';
            } else {
                $replyContent = $this->filterInternalReasoning($replyContent);
            }
        }

        $metaPayload = [
            'character' => $context['target_character'],

            'model' => $aiResponse['model']
                ?? null,

            'source' => $aiResponse['source']
                ?? null,

            'status' => $aiResponse['status']
                ?? null,

            'fallback_reason' => $aiResponse['fallback_reason']
                ?? null,

            'recommendation_source' => $localKnowledgeReply !== null
                ? 'local_knowledge'
                : ($aiResponse['source'] ?? null),

            'intent' => $intent,

            'chat_mode' => 'lightweight',

            'streaming' => false,

            'request_seconds' => $aiResponse['request_seconds']
                ?? null,

            'total_seconds' => $aiResponse['total_seconds']
                ?? null,
        ];

        $botMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $replyContent,
            'meta_payload' => $metaPayload,
            'tokens_used' => $aiResponse['tokens_used']
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
        callable $onToken
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

        $intent = $this->resolveIntent($conversation, $userMessageText);

        if ($intent === IntentClassifier::INTENT_GREETING) {
            return $this->respondToGreeting($conversation, $userMessage, true, $onToken);
        }
        if ($intent === IntentClassifier::INTENT_KNOWLEDGE_STATUS) {
            return $this->respondWithLocalKnowledgeStatus($conversation, $userMessage, true, $onToken);
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

        $generatedReply = '';

        /*
         * Kumpulkan output lebih dulu agar teks analisis internal tidak
         * sempat terkirim ke browser sebelum bisa diperiksa.
         */
        $aiResponse = $this->nvidiaService->chatStream(
            $context['messages'],
            function (string $token) use (&$generatedReply): void {
                $generatedReply .= $token;
            },
            0.2,
            800
        );

        // Fallback NVIDIA tertentu mengembalikan content tanpa callback.
        if (
            empty($generatedReply)
            && ! empty($aiResponse['content'])
        ) {
            $generatedReply =
                $aiResponse['content'];
        }

        if (empty(trim($generatedReply))) {
            $generatedReply =
                'Maaf, Ava belum mendapatkan jawaban dari layanan AI.';
        }

        $localKnowledgeReply = null;
        if (($aiResponse['status'] ?? null) === 'fallback') {
            $localKnowledgeReply = $this->localKnowledgeFallback(
                $intent,
                $context['target_character'],
                $context['content_mode']
            );
            $generatedReply = $localKnowledgeReply
                ?? 'Maaf, layanan AI sedang tidak tersedia dan belum ada panduan lokal yang cocok. Silakan coba lagi nanti.';
        } elseif ($this->containsInternalReasoning($generatedReply)) {
            $localKnowledgeReply = $this->localKnowledgeFallback(
                $intent,
                $context['target_character'],
                $context['content_mode']
            );
            $generatedReply = $localKnowledgeReply
                ?? 'Maaf, jawaban belum berhasil disusun. Silakan coba ajukan pertanyaan lagi.';
        }

        $fullReply = $this->filterInternalReasoning($generatedReply);
        $onToken($fullReply);

        /*
         * Metadata chat.
         */
        $metaPayload = [
            'character' => $context['target_character'],

            'model' => $aiResponse['model']
                ?? null,

            'source' => $aiResponse['source']
                ?? null,

            'status' => $aiResponse['status']
                ?? null,

            'fallback_reason' => $aiResponse['fallback_reason']
                ?? null,

            'recommendation_source' => $localKnowledgeReply !== null
                ? 'local_knowledge'
                : ($aiResponse['source'] ?? null),

            'intent' => $intent,

            'chat_mode' => 'lightweight',

            'streaming' => true,

            'finish_reason' => $aiResponse['finish_reason']
                ?? null,

            'prompt_tokens' => $aiResponse['prompt_tokens']
                ?? null,

            'completion_tokens' => $aiResponse['completion_tokens']
                ?? null,

            'request_seconds' => $aiResponse['request_seconds']
                ?? null,

            'total_seconds' => $aiResponse['total_seconds']
                ?? null,

            'ttft_seconds' => $aiResponse['ttft_seconds']
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

            'tokens_used' => $aiResponse['tokens_used']
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

            'target_character' => $context['target_character'],

            'total_seconds' => round(
                $totalDuration,
                4
            ),
        ];
    }

    /**
     * Fail closed when a model returns internal analysis instead of an answer.
     */
    protected function containsInternalReasoning(string $content): bool
    {
        $reasoningPatterns = [
            '/^\s*here(?:\'|’)s\s+(?:a\s+)?thinking process\b/iu',
            '/^\s*(?:thinking process|internal reasoning)\s*:/iu',
            '/^\s*(?:let me think|let me analyze|let\'s think step by step)\b/iu',
            '/^\s*\d+[.)]\s*(?:\*\*)?(?:analyze user input|check system\/context constraints|determine response strategy)\b/iu',
        ];

        foreach ($reasoningPatterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function filterInternalReasoning(string $content): string
    {
        if ($this->containsInternalReasoning($content)) {
            Log::warning('[CHAT] Model returned internal reasoning; response suppressed.', [
                'content_chars' => mb_strlen($content),
            ]);

            return 'Maaf, jawaban belum berhasil disusun. Silakan coba ajukan pertanyaan lagi.';
        }

        return $content;
    }

    protected function respondToGreeting(
        Conversation $conversation,
        Message $userMessage,
        bool $streaming,
        ?callable $onToken = null
    ): array {
        $reply = 'Hai! 👋 Ada yang bisa saya bantu? Saya bisa membantu build karakter, senjata, artefak, team composition, ER, rotasi, reaction, atau mekanik Genshin Impact.';
        if ($onToken !== null) {
            $onToken($reply);
        }

        $botMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
            'meta_payload' => [
                'intent' => IntentClassifier::INTENT_GREETING,
                'recommendation_source' => 'local',
                'chat_mode' => 'greeting',
                'streaming' => $streaming,
            ],
        ]);

        return [
            'user_message' => $userMessage,
            'bot_message' => $botMessage,
            'ai_response' => [
                'content' => $reply,
                'status' => 'success',
                'source' => 'local',
            ],
            'build_data' => null,
        ];
    }

    protected function respondWithLocalKnowledgeStatus(
        Conversation $conversation,
        Message $userMessage,
        bool $streaming,
        ?callable $onToken = null
    ): array {
        $patch = (string) config('services.genshin.target_patch', '7.0');
        $characterNames = Character::query()
            ->whereHas('buildKnowledge', function ($query) use ($patch) {
                $query->where('patch_version', $patch)
                    ->where('category', '!=', 'character_overview');
            })
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values();

        $reply = $characterNames->isEmpty()
            ? "Belum ada panduan build lokal untuk patch {$patch}."
            : "Panduan build lokal tersedia untuk patch {$patch}:\n"
                .$characterNames->map(fn (string $name) => "- {$name}")->implode("\n");

        if ($onToken !== null) {
            $onToken($reply);
        }

        $botMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
            'meta_payload' => [
                'intent' => IntentClassifier::INTENT_KNOWLEDGE_STATUS,
                'recommendation_source' => 'local_knowledge',
                'chat_mode' => 'knowledge_status',
                'streaming' => $streaming,
                'patch_version' => $patch,
            ],
        ]);

        return [
            'user_message' => $userMessage,
            'bot_message' => $botMessage,
            'ai_response' => [
                'content' => $reply,
                'status' => 'success',
                'source' => 'local_knowledge',
            ],
            'build_data' => null,
        ];
    }

    protected function resolveIntent(Conversation $conversation, string $message): string
    {
        $intent = $this->intentClassifier->classify($message);
        if ($intent !== IntentClassifier::INTENT_GENERAL
            || ! preg_match('/^(?:kalau|bagaimana dengan|gimana dengan|terus(?:\s+kalau|\s+untuk)?|untuk)\b/i', trim($message))) {
            return $intent;
        }

        $previousAssistantMessage = $conversation->messages()
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        $previousMessageWasBuild = ($previousAssistantMessage?->meta_payload['intent'] ?? null)
            === IntentClassifier::INTENT_BUILD
            || preg_match('/^\s*#\s*Build\b/i', $previousAssistantMessage?->content ?? '') === 1;

        if (! $previousMessageWasBuild) {
            return $intent;
        }

        $entities = $this->entityExtractor->extract($message);

        return $entities['target_character'] !== null
            ? IntentClassifier::INTENT_BUILD
            : $intent;
    }

    protected function localGuideUnavailableMessage(string $characterSlug): string
    {
        $character = Character::query()->where('slug', $characterSlug)->first();
        $characterName = $character?->name ?? ucwords(str_replace('-', ' ', $characterSlug));

        return "Layanan AI tidak memberikan jawaban build yang valid untuk {$characterName}, dan panduan build lokal karakter ini belum tersedia. Coba lagi saat layanan AI tersedia.";
    }

    protected function localKnowledgeFallback(
        string $intent,
        string $characterSlug,
        string $contentMode
    ): ?string {
        if ($intent === IntentClassifier::INTENT_BUILD) {
            return $this->recommendationEngine->buildLocalRecommendation($characterSlug, $contentMode);
        }

        $categories = match ($intent) {
            IntentClassifier::INTENT_WEAPON_QUESTION,
            IntentClassifier::INTENT_WEAPON_COMPARE => ['weapons_ranking'],
            IntentClassifier::INTENT_ARTIFACT_QUESTION => ['artifact_priorities'],
            IntentClassifier::INTENT_TEAM_SYNERGY => ['team_synergies'],
            IntentClassifier::INTENT_ROTATION => ['rotation'],
            IntentClassifier::INTENT_MECHANICS => ['role_and_reactions'],
            default => null,
        };

        if ($categories === null || $categories === []) {
            return null;
        }

        $character = Character::query()->where('slug', $characterSlug)->first();
        if ($character === null) {
            return null;
        }

        $knowledge = BuildKnowledge::query()
            ->where('character_id', $character->id)
            ->where('patch_version', $character->patch_version)
            ->whereIn('category', $categories)
            ->where(function ($query) use ($contentMode) {
                $query->where('target_content', $contentMode)
                    ->orWhere('target_content', 'universal');
            })
            ->orderByRaw('CASE WHEN target_content = ? THEN 0 ELSE 1 END', [$contentMode])
            ->orderByDesc('id')
            ->first();

        if ($knowledge === null) {
            return null;
        }

        return "## {$knowledge->title}\n{$knowledge->content}";
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

        $team = ! empty($extracted['team'])
            ? $extracted['team']
            : ($conversation->active_team ?? []);

        if (! is_array($team)) {
            $team = [];
        }

        $explicitTargetCharacter = ! empty($extracted['target_character']);

        /*
         * A newly explicit target character must replace stale session context.
         * This prevents the previous session's team/character from silently
         * leaking into the next chat turn.
         */
        if ($explicitTargetCharacter) {
            $team = $extracted['team'] ?? [];

            $conversation->update([
                'character_slug' => $extracted['target_character'],
                'target_content' => $contentMode,
                'active_team' => $team,
            ]);

            $conversation->refresh();
        } elseif ($extracted['team'] !== []) {
            $conversation->update([
                'active_team' => $team,
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

ATURAN TERPENTING:
- JANGAN pernah menampilkan proses berpikir, analisis internal, atau chain-of-thought.
- JANGAN menulis "Here's a thinking process", "Let me analyze", "Step 1", "Step 2", dll.
- JANGAN menjelaskan bagaimana kamu memproses pertanyaan.
- Langsung berikan JAWABAN FINAL saja.

Tugas:
- Membantu user memahami build karakter Genshin Impact.
- Menjawab pertanyaan tentang senjata, artefak, stat, ER, talent, constellation, team, reaction, rotation, dan mekanik karakter.
- Jawab sesuai maksud pesan terbaru. Jangan membuat rekomendasi build hanya karena ada karakter aktif; buat build hanya jika user memintanya.
- Jawab dalam Bahasa Indonesia kecuali user menggunakan bahasa lain.
- Gunakan data dan konteks yang diberikan sistem.
- Jangan mengarang angka atau mekanik jika tidak ada dalam konteks.
- Jika informasi spesifik tidak tersedia, katakan bahwa informasi tersebut perlu diverifikasi.
- Jawaban harus langsung ke inti, padat, dan to-the-point.
- Gunakan Markdown sederhana agar mudah dibaca.
- Gunakan heading pendek dan bullet list jika diperlukan.
- Jangan mengulang pertanyaan user.
- Jangan memberikan pembukaan panjang.

Karakter aktif: {$targetSlug}
Constellation: C{$constellation}
Mode konten: {$contentMode}

Tim aktif:
PROMPT;

        if (! empty($team)) {
            $systemPrompt .= "\n".implode(
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
                ).'...';
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
            $history->isEmpty()
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

<?php

namespace App\Services\Ai;

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

                'patch_version' => config(
                    'services.genshin.target_patch',
                    '7.0'
                ),
            ]
        );

        /*
         * Character dari selector frontend hanya boleh menetapkan
         * karakter pada conversation yang benar-benar masih kosong.
         *
         * Setelah conversation memiliki pesan, karakter baru harus
         * ditentukan dari pesan user.
         */
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
     * NON STREAMING
     * ============================================================
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

        $intent = $this->resolveIntent(
            $conversation,
            $userMessageText
        );

        if ($intent === IntentClassifier::INTENT_GREETING) {
            return $this->respondToGreeting(
                $conversation,
                $userMessage,
                false
            );
        }

        /*
         * ========================================================
         * BUILD HARUS MASUK KE RECOMMENDATION ENGINE
         * ========================================================
         *
         * Ini bug utama sebelumnya.
         *
         * Pertanyaan:
         * "build untuk kazuha"
         *
         * tidak boleh diproses sebagai chat biasa.
         */
        if ($intent === IntentClassifier::INTENT_BUILD) {
            return $this->handleBuildRequest(
                $conversation,
                $userMessage,
                $userMessageText
            );
        }

        /*
         * Pertanyaan selain build tetap merupakan chatbot biasa.
         */
        $context = $this->prepareChatContext(
            $conversation,
            $userMessageText
        );

        $aiResponse = $this->nvidiaService->chat(
            $context['messages'],
            0.2,
            800
        );

        $replyContent = trim(
            (string) (
                $aiResponse['content']
                ?? ''
            )
        );

        if (
            ($aiResponse['status'] ?? null) === 'fallback'
        ) {
            $replyContent = $this->buildChatUnavailableMessage($context);
        } elseif (
            $this->containsInternalReasoning(
                $replyContent
            )
        ) {
            $replyContent = 'Maaf, jawaban belum berhasil disusun. Silakan coba ajukan pertanyaan lagi.';
        }

        $replyContent =
            $this->filterInternalReasoning(
                $replyContent
            );

        if ($replyContent === '') {
            $replyContent =
                'Maaf, jawaban belum berhasil disusun.';
        }

        $metaPayload = [
            'character' =>
                $context['target_character'],

            'character_name' =>
                $context['character_name'],

            'model' =>
                $aiResponse['model'] ?? null,

            'source' =>
                $aiResponse['source'] ?? null,

            'status' =>
                $aiResponse['status'] ?? null,

            'fallback_reason' =>
                $aiResponse['fallback_reason'] ?? null,

            'recommendation_source' =>
                $aiResponse['source'] ?? null,

            'intent' =>
                $intent,

            'chat_mode' =>
                'lightweight',

            'streaming' =>
                false,

            'request_seconds' =>
                $aiResponse['request_seconds'] ?? null,

            'total_seconds' =>
                $aiResponse['total_seconds'] ?? null,
        ];

        $botMessage = Message::create([
            'conversation_id' => $conversation->id,

            'role' => 'assistant',

            'content' => $replyContent,

            'meta_payload' => $metaPayload,

            'tokens_used' =>
                $aiResponse['tokens_used'] ?? null,
        ]);

        return [
            'user_message' =>
                $userMessage,

            'bot_message' =>
                $botMessage,

            /*
             * Chat biasa tidak menghasilkan build.
             */
            'build_data' =>
                null,
        ];
    }

    /**
     * ============================================================
     * BUILD REQUEST
     * ============================================================
     *
     * Semua permintaan build eksplisit diarahkan ke
     * RecommendationEngine.
     */
    protected function handleBuildRequest(
        Conversation $conversation,
        Message $userMessage,
        string $userMessageText
    ): array {
        $context =
            $this->resolveBuildContext(
                $conversation,
                $userMessageText
            );

        $targetSlug =
            $context['target_character'];

        if ($targetSlug === null) {
            $reply =
                'Sebutkan karakter yang ingin dibuatkan build. Contoh: "build untuk Kazuha".';

            $botMessage = Message::create([
                'conversation_id' => $conversation->id,

                'role' => 'assistant',

                'content' => $reply,

                'meta_payload' => [
                    'intent' =>
                        IntentClassifier::INTENT_BUILD,

                    'status' =>
                        'missing_character',

                    'chat_mode' =>
                        'build',
                ],
            ]);

            return [
                'user_message' =>
                    $userMessage,

                'bot_message' =>
                    $botMessage,

                'build_data' =>
                    null,
            ];
        }

        Log::info(
            '[CHAT BUILD] Routing explicit build request',
            [
                'conversation_id' =>
                    $conversation->id,

                'target_character' =>
                    $targetSlug,

                'constellation' =>
                    $context['constellation'],

                'team' =>
                    $context['team'],

                'content_mode' =>
                    $context['content_mode'],
            ]
        );

        $buildResult =
            $this->recommendationEngine->generateBuild(
                $targetSlug,

                $context['constellation'],

                $context['team'],

                $context['content_mode'],

                $userMessageText,

                $context['extracted']['role'] ?? null
            );

        /*
         * Jika character tidak ditemukan.
         */
        if (
            ! empty($buildResult['error'])
        ) {
            $reply =
                $buildResult['message']
                ?? 'Build tidak dapat dibuat.';

            $botMessage = Message::create([
                'conversation_id' =>
                    $conversation->id,

                'role' =>
                    'assistant',

                'content' =>
                    $reply,

                'meta_payload' => [
                    'intent' =>
                        IntentClassifier::INTENT_BUILD,

                    'status' =>
                        'error',

                    'character' =>
                        $targetSlug,

                    'chat_mode' =>
                        'build',
                ],
            ]);

            return [
                'user_message' =>
                    $userMessage,

                'bot_message' =>
                    $botMessage,

                'build_data' =>
                    $buildResult,
            ];
        }

        $reply =
            trim(
                (string) (
                    $buildResult['ai_recommendation']
                    ?? ''
                )
            );

        if ($reply === '') {
            $reply =
                "Build {$buildResult['character']['name']} berhasil diproses.";
        }

        $botMessage = Message::create([
            'conversation_id' =>
                $conversation->id,

            'role' =>
                'assistant',

            'content' =>
                $reply,

            'meta_payload' => [
                'intent' =>
                    IntentClassifier::INTENT_BUILD,

                'character' =>
                    $buildResult['character']['slug']
                    ?? $targetSlug,

                'character_name' =>
                    $buildResult['character']['name']
                    ?? null,

                'model' =>
                    $buildResult['model']
                    ?? null,

                'status' =>
                    $buildResult['status']
                    ?? null,

                'source' =>
                    $buildResult['source']
                    ?? null,

                'recommendation_source' =>
                    $buildResult['recommendation_source']
                    ?? null,

                'fallback_reason' =>
                    $buildResult['fallback_reason']
                    ?? null,

                'recommendation_cards' =>
                    $buildResult['recommendation_cards']
                    ?? null,

                'chat_mode' =>
                    'build',

                'streaming' =>
                    false,
            ],

            'tokens_used' =>
                $buildResult['tokens_used']
                ?? null,
        ]);

        return [
            'user_message' =>
                $userMessage,

            'bot_message' =>
                $botMessage,

            /*
             * Sekarang frontend dapat menerima hasil Generate Build
             * dari chatbot.
             */
            'build_data' =>
                $buildResult,
        ];
    }

    /**
     * ============================================================
     * STREAMING
     * ============================================================
     */
    public function streamMessage(
        Conversation $conversation,
        string $userMessageText,
        callable $onToken
    ): array {
        $startTime =
            microtime(true);

        $userMessage = Message::create([
            'conversation_id' =>
                $conversation->id,

            'role' =>
                'user',

            'content' =>
                $userMessageText,
        ]);

        $intent =
            $this->resolveIntent(
                $conversation,
                $userMessageText
            );

        if (
            $intent ===
            IntentClassifier::INTENT_GREETING
        ) {
            return $this->respondToGreeting(
                $conversation,
                $userMessage,
                true,
                $onToken
            );
        }

        /*
         * BUILD pada streaming tetap menggunakan pipeline build.
         *
         * GenerateBuild memang non-streaming karena hasilnya
         * membutuhkan data karakter, mechanics, dan NVIDIA.
         */
        if (
            $intent ===
            IntentClassifier::INTENT_BUILD
        ) {
            $result =
                $this->handleBuildRequest(
                    $conversation,
                    $userMessage,
                    $userMessageText
                );

            $reply =
                $result['bot_message']->content
                ?? '';

            if ($reply !== '') {
                $onToken($reply);
            }

            $result['target_character'] =
                $result['build_data']['character']['slug']
                ?? null;

            $result['total_seconds'] =
                round(
                    microtime(true) -
                    $startTime,
                    4
                );

            return $result;
        }

        /*
         * ========================================================
         * CHAT BIASA
         * ========================================================
         */
        $context =
            $this->prepareChatContext(
                $conversation,
                $userMessageText
            );

        Log::info(
            '[CHAT] ===== START STREAM =====',
            [
                'conversation_id' =>
                    $conversation->id,

                'character' =>
                    $context['target_character'],

                'intent' =>
                    $intent,

                'message_chars' =>
                    strlen($userMessageText),

                'history_count' =>
                    $context['history_count'],

                'prompt_chars' =>
                    $context['prompt_chars'],
            ]
        );

        $generatedReply = '';

        $aiResponse =
            $this->nvidiaService->chatStream(
                $context['messages'],
                function (string $token)
                use (&$generatedReply): void {
                    $generatedReply .=
                        $token;
                },
                0.2,
                800
            );

        if (
            empty($generatedReply)
            && ! empty(
                $aiResponse['content']
            )
        ) {
            $generatedReply =
                $aiResponse['content'];
        }

        $generatedReply =
            trim($generatedReply);

        if (
            ($aiResponse['status'] ?? null)
            === 'fallback'
        ) {
            $generatedReply = $this->buildChatUnavailableMessage($context);
        } elseif (
            $this->containsInternalReasoning(
                $generatedReply
            )
        ) {
            $generatedReply = 'Maaf, jawaban belum berhasil disusun. Silakan coba ajukan pertanyaan lagi.';
        }

        $fullReply =
            $this->filterInternalReasoning(
                $generatedReply
            );

        if ($fullReply === '') {
            $fullReply =
                'Maaf, jawaban belum berhasil disusun.';
        }

        $onToken($fullReply);

        $metaPayload = [
            'character' =>
                $context['target_character'],

            'character_name' =>
                $context['character_name'],

            'model' =>
                $aiResponse['model'] ?? null,

            'source' =>
                $aiResponse['source'] ?? null,

            'status' =>
                $aiResponse['status'] ?? null,

            'fallback_reason' =>
                $aiResponse['fallback_reason'] ?? null,

            'recommendation_source' =>
                $aiResponse['source'] ?? null,

            'intent' =>
                $intent,

            'chat_mode' =>
                'lightweight',

            'streaming' =>
                true,

            'finish_reason' =>
                $aiResponse['finish_reason'] ?? null,

            'prompt_tokens' =>
                $aiResponse['prompt_tokens'] ?? null,

            'completion_tokens' =>
                $aiResponse['completion_tokens'] ?? null,

            'request_seconds' =>
                $aiResponse['request_seconds'] ?? null,

            'total_seconds' =>
                $aiResponse['total_seconds'] ?? null,

            'ttft_seconds' =>
                $aiResponse['ttft_seconds'] ?? null,
        ];

        $botMessage = Message::create([
            'conversation_id' =>
                $conversation->id,

            'role' =>
                'assistant',

            'content' =>
                $fullReply,

            'meta_payload' =>
                $metaPayload,

            'tokens_used' =>
                $aiResponse['tokens_used'] ?? null,
        ]);

        $totalDuration =
            microtime(true) -
            $startTime;

        return [
            'user_message' =>
                $userMessage,

            'bot_message' =>
                $botMessage,

            'ai_response' =>
                $aiResponse,

            'build_data' =>
                null,

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
     * RESOLVE BUILD CONTEXT
     * ============================================================
     */
    protected function resolveBuildContext(
        Conversation $conversation,
        string $message
    ): array {
        $extracted =
            $this->entityExtractor->extract(
                $message
            );

        /*
         * PENTING:
         *
         * Tidak ada lagi:
         *
         * ?? 'furina'
         */
        $targetSlug =
            $extracted['target_character']
            ?? $conversation->character_slug
            ?? null;

        $constellation =
            (int) (
                $extracted['constellation']
                ?? 0
            );

        $contentMode =
            $extracted['content_mode']
            ?? $conversation->target_content
            ?? 'abyss';

        $team =
            ! empty($extracted['team'])
                ? $extracted['team']
                : (
                    $conversation->active_team
                    ?? []
                );

        if (! is_array($team)) {
            $team = [];
        }

        /*
         * Jika user menyebut karakter baru secara eksplisit,
         * karakter lama tidak boleh mengambil alih.
         */
        if (
            ! empty(
                $extracted['target_character']
            )
        ) {
            $targetSlug =
                strtolower(
                    trim(
                        $extracted['target_character']
                    )
                );

            /*
             * EntityExtractor sudah mengembalikan team
             * berdasarkan karakter lain yang disebut.
             */
            $team =
                is_array(
                    $extracted['team']
                    ?? null
                )
                    ? $extracted['team']
                    : [];

            $conversation->update([
                'character_slug' =>
                    $targetSlug,

                'target_content' =>
                    $contentMode,

                'active_team' =>
                    $team,
            ]);

            $conversation->refresh();
        }

        return [
            'target_character' =>
                $targetSlug,

            'constellation' =>
                $constellation,

            'team' =>
                $team,

            'content_mode' =>
                $contentMode,

            'extracted' =>
                $extracted,
        ];
    }

    /**
     * ============================================================
     * INTENT
     * ============================================================
     */
    protected function resolveIntent(
        Conversation $conversation,
        string $message
    ): string {
        $intent =
            $this->intentClassifier->classify(
                $message
            );

        /*
         * Jangan mengubah pertanyaan biasa menjadi BUILD
         * hanya karena conversation sebelumnya adalah build.
         */
        if (
            $intent !==
            IntentClassifier::INTENT_GENERAL
            ||
            ! preg_match(
                '/^(?:kalau|bagaimana dengan|gimana dengan|terus(?:\s+kalau|\s+untuk)?|untuk)\b/i',
                trim($message)
            )
        ) {
            return $intent;
        }

        $previousAssistantMessage =
            $conversation
                ->messages()
                ->where('role', 'assistant')
                ->latest('id')
                ->first();

        $previousMessageWasBuild =
            (
                $previousAssistantMessage
                    ?->meta_payload['intent']
                ?? null
            )
            ===
            IntentClassifier::INTENT_BUILD;

        if (
            ! $previousMessageWasBuild
        ) {
            return $intent;
        }

        $entities =
            $this->entityExtractor->extract(
                $message
            );

        /*
         * Follow-up build hanya jika karakter memang
         * disebut pada pesan follow-up.
         */
        return ! empty(
            $entities['target_character']
        )
            ? IntentClassifier::INTENT_BUILD
            : $intent;
    }

    /**
     * ============================================================
     * CHAT CONTEXT
     * ============================================================
     */
    protected function prepareChatContext(
        Conversation $conversation,
        string $userMessageText
    ): array {
        $extracted =
            $this->entityExtractor->extract(
                $userMessageText
            );

        /*
         * Tidak ada default Furina.
         */
        $targetSlug =
            $extracted['target_character']
            ?? $conversation->character_slug
            ?? null;

        $contentMode =
            $extracted['content_mode']
            ?? $conversation->target_content
            ?? 'abyss';

        $team =
            ! empty($extracted['team'])
                ? $extracted['team']
                : (
                    $conversation->active_team
                    ?? []
                );

        if (! is_array($team)) {
            $team = [];
        }

        $character = null;

        if (
            ! empty($targetSlug)
        ) {
            $character =
                Character::query()
                    ->where(
                        'slug',
                        $targetSlug
                    )
                    ->first();
        }

        /*
         * Karakter eksplisit selalu menggantikan context lama.
         */
        if (
            ! empty(
                $extracted['target_character']
            )
        ) {
            $targetSlug =
                strtolower(
                    trim(
                        $extracted['target_character']
                    )
                );

            $team =
                is_array(
                    $extracted['team']
                    ?? null
                )
                    ? $extracted['team']
                    : [];

            $conversation->update([
                'character_slug' =>
                    $targetSlug,

                'target_content' =>
                    $contentMode,

                'active_team' =>
                    $team,
            ]);

            $conversation->refresh();

            $character =
                Character::query()
                    ->where(
                        'slug',
                        $targetSlug
                    )
                    ->first();
        }

        $history =
            Message::where(
                'conversation_id',
                $conversation->id
            )
                ->orderByDesc(
                    'created_at'
                )
                ->limit(6)
                ->get()
                ->reverse()
                ->values();

        $characterName =
            $character?->name
            ?? (
                $targetSlug !== null
                    ? ucwords(
                        str_replace(
                            '-',
                            ' ',
                            $targetSlug
                        )
                    )
                    : null
            );

        $characterFacts =
            $character !== null
                ? implode(
                    "\n",
                    [
                        "Nama: {$character->name}",
                        "Slug: {$character->slug}",
                        "Element: {$character->vision}",
                        "Weapon: {$character->weapon_type}",
                        "Rarity: {$character->rarity}★",
                        "Patch: {$character->patch_version}",
                    ]
                )
                : 'Data karakter belum ditemukan.';

        $systemPrompt = <<<PROMPT
Kamu adalah Ava, AI assistant untuk website Genshin Impact Build AI.

ATURAN:
- Jawab pertanyaan user secara langsung.
- Jangan menampilkan chain-of-thought atau proses berpikir internal.
- Jangan menampilkan "thinking process", "let me analyze", atau analisis internal.
- Gunakan karakter yang disebut dalam pesan terbaru sebagai target utama.
- Jangan mengganti karakter terbaru dengan karakter dari percakapan sebelumnya.
- Jangan pernah mengasumsikan Furina atau karakter lain jika user tidak menyebutkannya.
- Pertanyaan tentang artefak, senjata, ER, talent, reaction, rotation, atau mekanik adalah pertanyaan CHAT, bukan Generate Build.
- Generate Build hanya dijalankan jika user secara eksplisit meminta build.
- Jangan mengarang angka atau mekanik spesifik yang tidak tersedia.
- Jika angka tertentu tidak tersedia, katakan bahwa angka tersebut perlu diverifikasi.
- Jawab dalam Bahasa Indonesia.
- Gunakan Markdown sederhana.
- Jawaban harus langsung ke inti.

TARGET KARAKTER:
{$characterName}

DATA KARAKTER:
{$characterFacts}

MODE:
{$contentMode}

TIM:
PROMPT;

        if (
            $team !== []
        ) {
            $systemPrompt .=
                "\n"
                .implode(
                    ', ',
                    array_map(
                        fn ($item) =>
                            (string) $item,
                        $team
                    )
                );
        } else {
            $systemPrompt .=
                "\nBelum ditentukan.";
        }

        $messages = [
            [
                'role' =>
                    'system',

                'content' =>
                    $systemPrompt,
            ],
        ];

        foreach (
            $history
            as $historyMessage
        ) {
            $content =
                (string)
                $historyMessage->content;

            if (
                mb_strlen($content)
                > 1800
            ) {
                $content =
                    mb_substr(
                        $content,
                        0,
                        1800
                    )
                    .'...';
            }

            $messages[] = [
                'role' =>
                    $historyMessage->role === 'assistant'
                        ? 'assistant'
                        : 'user',

                'content' =>
                    $content,
            ];
        }

        if (
            $history->isEmpty()
            ||
            $history->last()->role
                !== 'user'
            ||
            $history->last()->content
                !== $userMessageText
        ) {
            $messages[] = [
                'role' =>
                    'user',

                'content' =>
                    $userMessageText,
            ];
        }

        $promptChars = 0;

        foreach (
            $messages
            as $message
        ) {
            $promptChars +=
                strlen(
                    (string)
                    (
                        $message['content']
                        ?? ''
                    )
                );
        }

        return [
            'messages' =>
                $messages,

            'target_character' =>
                $targetSlug,

            'character_name' =>
                $characterName,

            'character' =>
                $character,

            'content_mode' =>
                $contentMode,

            'team' =>
                $team,

            'history_count' =>
                $history->count(),

            'prompt_chars' =>
                $promptChars,

            'extracted' =>
                $extracted,
        ];
    }

    /**
     * ============================================================
     * UNAVAILABLE MESSAGE
     * ============================================================
     */
    protected function buildChatUnavailableMessage(
        array $context
    ): string {
        $character =
            $context['character_name']
            ?? null;

        if (
            $character !== null
        ) {
            return
                "Maaf, layanan AI sedang tidak tersedia untuk sementara. "
                ."Belum ada jawaban AI untuk pertanyaan {$character}.";
        }

        return
            'Maaf, layanan AI sedang tidak tersedia untuk sementara.';
    }

    /**
     * ============================================================
     * GREETING
     * ============================================================
     */
    protected function respondToGreeting(
        Conversation $conversation,
        Message $userMessage,
        bool $streaming,
        ?callable $onToken = null
    ): array {
        $reply =
            'Hai! 👋 Ada yang bisa saya bantu? '
            .'Saya bisa membantu build karakter, senjata, '
            .'artefak, team composition, ER, rotasi, reaction, '
            .'atau mekanik Genshin Impact.';

        if (
            $onToken !== null
        ) {
            $onToken($reply);
        }

        $botMessage = Message::create([
            'conversation_id' =>
                $conversation->id,

            'role' =>
                'assistant',

            'content' =>
                $reply,

            'meta_payload' => [
                'intent' =>
                    IntentClassifier::INTENT_GREETING,

                'recommendation_source' =>
                    'local',

                'chat_mode' =>
                    'greeting',

                'streaming' =>
                    $streaming,
            ],
        ]);

        return [
            'user_message' =>
                $userMessage,

            'bot_message' =>
                $botMessage,

            'ai_response' => [
                'content' =>
                    $reply,

                'status' =>
                    'success',

                'source' =>
                    'local',
            ],

            'build_data' =>
                null,
        ];
    }

    /**
     * ============================================================
     * INTERNAL REASONING FILTER
     * ============================================================
     */
    protected function containsInternalReasoning(
        string $content
    ): bool {
        $patterns = [
            '/here(?:\'|’)s\s+(?:a\s+)?thinking process\b/iu',

            '/(?:thinking process|internal reasoning)\s*:/iu',

            '/(?:let me think|let me analyze|let\'s think step by step)\b/iu',

            '/\b(?:analyze user input|check system\/context constraints|determine response strategy)\b/iu',
        ];

        foreach (
            $patterns
            as $pattern
        ) {
            if (
                preg_match(
                    $pattern,
                    $content
                ) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    protected function filterInternalReasoning(
        string $content
    ): string {
        if (
            $this->containsInternalReasoning(
                $content
            )
        ) {
            Log::warning(
                '[CHAT] Model returned internal reasoning.',
                [
                    'content_chars' =>
                        mb_strlen($content),
                ]
            );

            return
                'Maaf, jawaban belum berhasil disusun. '
                .'Silakan coba ajukan pertanyaan lagi.';
        }

        return $content;
    }
}
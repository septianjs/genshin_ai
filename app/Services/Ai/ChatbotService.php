<?php

namespace App\Services\Ai;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Nvidia\NvidiaService;
use App\Services\QueryUnderstanding\EntityExtractor;

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
        return Conversation::firstOrCreate(
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
    }

    /**
     * Memproses pesan user.
     */
    public function handleMessage(
        Conversation $conversation,
        string $userMessageText
    ): array {

        /**
         * Simpan pesan user.
         */
        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessageText,
        ]);

        /**
         * Ekstraksi entity dari pertanyaan user.
         */
        $extracted = $this->entityExtractor->extract(
            $userMessageText
        );

        /**
         * Ambil karakter dari hasil entity extraction.
         *
         * Jika tidak ditemukan, gunakan karakter yang
         * sedang aktif pada conversation.
         *
         * Furina digunakan sebagai fallback terakhir
         * agar sistem tidak crash.
         */
        $targetSlug =
            $extracted['target_character']
            ?? $conversation->character_slug
            ?? 'furina';

        /**
         * Pastikan constellation berupa integer.
         */
        $constellation = (int) (
            $extracted['constellation']
            ?? 0
        );

        /**
         * Ambil team dari entity extractor.
         *
         * Jika tidak ditemukan, gunakan active_team
         * dari conversation.
         */
        $team = !empty($extracted['team'])
            ? $extracted['team']
            : ($conversation->active_team ?? []);

        /**
         * Pastikan team selalu array.
         */
        if (!is_array($team)) {
            $team = [];
        }

        /**
         * Content mode.
         *
         * Default: abyss.
         */
        $contentMode =
            $extracted['content_mode']
            ?? $conversation->target_content
            ?? 'abyss';

        /**
         * Jika user secara eksplisit menyebut karakter baru,
         * update karakter aktif pada conversation.
         */
        if (!empty($extracted['target_character'])) {

            $conversation->update([
                'character_slug' =>
                    $extracted['target_character'],

                'target_content' =>
                    $contentMode,
            ]);

            /**
             * Refresh object conversation agar data terbaru
             * tersedia jika digunakan setelah update.
             */
            $conversation->refresh();
        }

        /**
         * Generate rekomendasi build.
         */
        $buildData = $this->recommendationEngine->generateBuild(
            $targetSlug,
            $constellation,
            $team,
            $contentMode,
            $userMessageText
        );

        /**
         * Ambil response AI.
         */
        $replyContent =
            $buildData['ai_recommendation']
            ?? 'Maaf, saya tidak dapat memproses rekomendasi saat ini.';

        /**
         * Jangan hardcode 300 token.
         *
         * Coba ambil token dari hasil RecommendationEngine.
         * Jika tidak ada, gunakan null.
         */
        $tokensUsed =
            $buildData['tokens_used']
            ?? null;

        /**
         * Simpan metadata yang relevan.
         */
        $metaPayload = [
            'character' =>
                $buildData['character']
                ?? null,

            'mechanics' =>
                $buildData['mechanics']
                ?? null,

            'model' =>
                $buildData['model']
                ?? null,

            'source' =>
                $buildData['source']
                ?? null,

            'status' =>
                $buildData['status']
                ?? null,

            'fallback_reason' =>
                $buildData['fallback_reason']
                ?? null,
        ];

        /**
         * Simpan response assistant.
         */
        $botMessage = Message::create([
            'conversation_id' => $conversation->id,

            'role' => 'assistant',

            'content' => $replyContent,

            'meta_payload' => $metaPayload,

            'tokens_used' => $tokensUsed,
        ]);

        /**
         * Return hasil lengkap.
         */
        return [
            'user_message' => $userMessage,

            'bot_message' => $botMessage,

            'build_data' => $buildData,
        ];
    }
}

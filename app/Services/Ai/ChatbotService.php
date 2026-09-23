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
     * Mengambil atau membuat percakapan berdasarkan token sesi.
     */
    public function getOrCreateConversation(string $sessionToken, ?string $characterSlug = null): Conversation
    {
        return Conversation::firstOrCreate(
            ['session_token' => $sessionToken],
            [
                'title' => $characterSlug ? "Diskusi Build {$characterSlug}" : 'Konsultasi Genshin Build',
                'character_slug' => $characterSlug,
                'target_content' => 'abyss',
                'patch_version' => '7.0',
            ]
        );
    }

    /**
     * Memproses pesan masuk dari pengguna dan menghasilkan balasan asisten.
     */
    public function handleMessage(Conversation $conversation, string $userMessageText): array
    {
        // 1. Simpan pesan User ke database
        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessageText,
        ]);

        // 2. Query Understanding
        $extracted = $this->entityExtractor->extract($userMessageText);

        $targetSlug = $extracted['target_character'] ?? ($conversation->character_slug ?? 'furina');
        $constellation = $extracted['constellation'] ?? 0;
        $team = !empty($extracted['team']) ? $extracted['team'] : ($conversation->active_team ?? []);
        $contentMode = $extracted['content_mode'] ?? ($conversation->target_content ?? 'abyss');

        // Update context sesi jika ada karakter baru yang dideteksi
        if ($extracted['target_character']) {
            $conversation->update([
                'character_slug' => $extracted['target_character'],
                'target_content' => $contentMode,
            ]);
        }

        // 3. Hasilkan respon build & analisis
        $buildData = $this->recommendationEngine->generateBuild(
            $targetSlug,
            $constellation,
            $team,
            $contentMode,
            $userMessageText
        );

        $replyContent = $buildData['ai_recommendation'] ?? 'Maaf, saya tidak dapat memproses rekomendasi saat ini.';

        // 4. Simpan balasan Bot ke database
        $botMessage = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $replyContent,
            'meta_payload' => [
                'character' => $buildData['character'] ?? null,
                'mechanics' => $buildData['mechanics'] ?? null,
                'model' => $buildData['model'] ?? null,
            ],
            'tokens_used' => 300,
        ]);

        return [
            'user_message' => $userMessage,
            'bot_message' => $botMessage,
            'build_data' => $buildData,
        ];
    }
}

<?php

namespace App\Services\Ai;

use App\Models\Character;
use App\Services\Genshin\GenshinApiService;
use App\Services\Mechanics\ConstellationImpactService;
use App\Services\Mechanics\ContentModeService;
use App\Services\Mechanics\ElementalReactionService;
use App\Services\Mechanics\ElementalResonanceService;
use App\Services\Nvidia\NvidiaService;
use App\Services\QueryUnderstanding\EntityExtractor;
use App\Services\QueryUnderstanding\IntentClassifier;
use App\Services\Rag\ContextBuilder;
use App\Services\Rag\VectorStoreService;

class RecommendationEngine
{
    public function __construct(
        protected GenshinApiService $genshinService,
        protected EntityExtractor $entityExtractor,
        protected IntentClassifier $intentClassifier,
        protected ElementalResonanceService $resonanceService,
        protected ElementalReactionService $reactionService,
        protected ConstellationImpactService $constellationService,
        protected ContentModeService $contentModeService,
        protected VectorStoreService $vectorStoreService,
        protected ContextBuilder $contextBuilder,
        protected NvidiaService $nvidiaService
    ) {}

    /**
     * Menghasilkan rekomendasi build lengkap berdasarkan parameter terstruktur.
     */
    public function generateBuild(
        string $characterSlug,
        int $constellation = 0,
        array $teammateSlugs = [],
        string $contentMode = 'abyss',
        ?string $customQuery = null
    ): array {
        $character = $this->genshinService->getCharacter($characterSlug);

        if (!$character) {
            return [
                'error' => true,
                'message' => "Karakter '{$characterSlug}' tidak ditemukan dalam database lokal.",
            ];
        }

        // Kumpulkan rekan tim dan elemen mereka
        $teammates = [];
        $allVisions = [$character->vision];

        foreach ($teammateSlugs as $tSlug) {
            $tSlug = trim($tSlug);
            if ($tSlug && $tSlug !== $characterSlug) {
                $tm = $this->genshinService->getCharacter($tSlug);
                if ($tm) {
                    $teammates[] = $tm;
                    $allVisions[] = $tm->vision;
                }
            }
        }

        // Jalankan evaluasi mekanik
        $resonances = $this->resonanceService->evaluateResonances($allVisions);
        $reactions = $this->reactionService->evaluateReactions($allVisions);
        $constellationImpact = $this->constellationService->analyzeImpact($character, $constellation);
        $contentProfile = $this->contentModeService->getProfile($contentMode);

        $mechanicsData = [
            'resonances' => $resonances,
            'reactions' => $reactions,
            'constellation' => $constellationImpact,
            'content_profile' => $contentProfile,
        ];

        // Cari RAG theorycraft chunks yang relevan
        $ragQuery = "Build guide {$character->name} C{$constellation} {$contentMode}";
        $ragChunks = $this->vectorStoreService->searchSimilar($character, $ragQuery, $contentMode, 3);

        // Susun System Prompt
        $systemPrompt = $this->contextBuilder->buildSystemPrompt($character, $mechanicsData, $ragChunks);

        // Susun pesan User
        $userPrompt = $customQuery ?? "Tolong berikan rekomendasi build lengkap untuk {$character->name} C{$constellation} dalam tim dengan mode {$contentProfile['name']}.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        // Panggil LLM Nemotron
        $aiResponse = $this->nvidiaService->chat($messages);

        return [
            'character' => [
                'slug' => $character->slug,
                'name' => $character->name,
                'vision' => $character->vision,
                'weapon_type' => $character->weapon_type,
                'rarity' => $character->rarity,
                'icon_url' => $character->icon_url,
                'constellation' => $constellation,
            ],
            'teammates' => array_map(fn($t) => [
                'slug' => $t->slug,
                'name' => $t->name,
                'vision' => $t->vision,
                'icon_url' => $t->icon_url,
            ], $teammates),
            'mechanics' => [
                'active_resonances' => $resonances['summary_buffs'],
                'triggered_reactions' => $reactions['stat_recommendations'],
                'constellation_notes' => $constellationImpact['gameplay_notes'],
                'role_shift' => $constellationImpact['role_shift'],
                'content_profile' => $contentProfile,
            ],
            'ai_recommendation' => $aiResponse['content'],
            'model' => $aiResponse['model'],
            'status' => $aiResponse['status'] ?? 'success',
        ];
    }

    /**
     * Memproses teks bebas pengguna melalui Query Understanding lalu menghasilkan build.
     */
    public function processFreeTextQuery(string $rawQuery): array
    {
        $extracted = $this->entityExtractor->extract($rawQuery);
        $intent = $this->intentClassifier->classify($rawQuery);

        $targetSlug = $extracted['target_character'] ?? 'furina'; // Fallback cerdas jika tidak ada nama karakter spesifik
        $constellation = $extracted['constellation'] ?? 0;
        $team = $extracted['team'] ?? [];
        $contentMode = $extracted['content_mode'] ?? 'abyss';

        $buildResult = $this->generateBuild($targetSlug, $constellation, $team, $contentMode, $rawQuery);
        $buildResult['query_understanding'] = [
            'intent' => $intent,
            'extracted_entities' => $extracted,
        ];

        return $buildResult;
    }
}

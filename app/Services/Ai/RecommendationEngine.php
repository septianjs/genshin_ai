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
use Illuminate\Support\Facades\Log;

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
        $totalStart = microtime(true);

        Log::info('[BUILD] ===== START generateBuild =====', [
            'character' => $characterSlug,
            'constellation' => $constellation,
            'teammates' => $teammateSlugs,
            'content_mode' => $contentMode,
            'has_custom_query' => $customQuery !== null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 1. Ambil karakter utama
        |--------------------------------------------------------------------------
        */
        $start = microtime(true);

        $character = $this->genshinService->getCharacter($characterSlug);

        $duration = microtime(true) - $start;

        Log::info('[BUILD] getCharacter', [
            'duration_seconds' => round($duration, 4),
            'found' => $character !== null,
            'character' => $character?->name,
        ]);

        if (!$character) {
            Log::warning('[BUILD] Character not found', [
                'character_slug' => $characterSlug,
            ]);

            return [
                'error' => true,
                'message' => "Karakter '{$characterSlug}' tidak ditemukan dalam database lokal.",
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Kumpulkan rekan tim dan elemen
        |--------------------------------------------------------------------------
        */
        $start = microtime(true);

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

        $duration = microtime(true) - $start;

        Log::info('[BUILD] teammates', [
            'duration_seconds' => round($duration, 4),
            'requested_count' => count($teammateSlugs),
            'found_count' => count($teammates),
            'visions' => $allVisions,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 3. Evaluasi mekanik
        |--------------------------------------------------------------------------
        */
        $mechanicsStart = microtime(true);

        // Resonance
        $start = microtime(true);

        $resonances = $this->resonanceService->evaluateResonances($allVisions);

        Log::info('[BUILD] resonance evaluation', [
            'duration_seconds' => round(microtime(true) - $start, 4),
        ]);

        // Reactions
        $start = microtime(true);

        $reactions = $this->reactionService->evaluateReactions($allVisions);

        Log::info('[BUILD] reaction evaluation', [
            'duration_seconds' => round(microtime(true) - $start, 4),
        ]);

        // Constellation
        $start = microtime(true);

        $constellationImpact = $this->constellationService->analyzeImpact(
            $character,
            $constellation
        );

        Log::info('[BUILD] constellation evaluation', [
            'duration_seconds' => round(microtime(true) - $start, 4),
        ]);

        // Content mode
        $start = microtime(true);

        $contentProfile = $this->contentModeService->getProfile($contentMode);

        Log::info('[BUILD] content profile', [
            'duration_seconds' => round(microtime(true) - $start, 4),
        ]);

        Log::info('[BUILD] mechanics TOTAL', [
            'duration_seconds' => round(microtime(true) - $mechanicsStart, 4),
        ]);

        $mechanicsData = [
            'resonances' => $resonances,
            'reactions' => $reactions,
            'constellation' => $constellationImpact,
            'content_profile' => $contentProfile,
        ];

        /*
        |--------------------------------------------------------------------------
        | 4. RAG / Vector Search
        |--------------------------------------------------------------------------
        */
        $ragQuery = "Build guide {$character->name} C{$constellation} {$contentMode}";

        Log::info('[RAG] Starting similarity search', [
            'query' => $ragQuery,
            'top_k' => 3,
        ]);

        $start = microtime(true);

        $ragChunks = $this->vectorStoreService->searchSimilar(
            $character,
            $ragQuery,
            $contentMode,
            3
        );

        $ragDuration = microtime(true) - $start;

        Log::info('[RAG] searchSimilar completed', [
            'duration_seconds' => round($ragDuration, 4),
            'chunks_count' => is_array($ragChunks) ? count($ragChunks) : null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 5. Susun System Prompt
        |--------------------------------------------------------------------------
        */
        $start = microtime(true);

        $systemPrompt = $this->contextBuilder->buildSystemPrompt(
            $character,
            $mechanicsData,
            $ragChunks
        );

        $promptDuration = microtime(true) - $start;

        Log::info('[PROMPT] System prompt built', [
            'duration_seconds' => round($promptDuration, 4),
            'system_prompt_chars' => strlen($systemPrompt),
            'system_prompt_tokens_estimate' => (int) ceil(strlen($systemPrompt) / 4),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 6. Susun User Prompt
        |--------------------------------------------------------------------------
        */
        $start = microtime(true);

        $userPrompt = $customQuery
            ?? "Tolong berikan rekomendasi build lengkap untuk {$character->name} C{$constellation} dalam tim dengan mode {$contentProfile['name']}.";

        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $userPrompt,
            ],
        ];

        $messageDuration = microtime(true) - $start;

        Log::info('[PROMPT] Messages prepared', [
            'duration_seconds' => round($messageDuration, 4),
            'user_prompt_chars' => strlen($userPrompt),
            'total_message_chars' => strlen($systemPrompt) + strlen($userPrompt),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 7. Panggil NVIDIA Nemotron
        |--------------------------------------------------------------------------
        */
        Log::info('[NVIDIA] Starting chat request', [
            'character' => $character->name,
            'model_expected' => config('services.nvidia.model'),
            'message_count' => count($messages),
        ]);

        $start = microtime(true);

        $aiResponse = $this->nvidiaService->chat($messages);

        $nvidiaDuration = microtime(true) - $start;

        Log::info('[NVIDIA] Chat request completed', [
            'duration_seconds' => round($nvidiaDuration, 4),
            'status' => $aiResponse['status'] ?? null,
            'model' => $aiResponse['model'] ?? null,
            'source' => $aiResponse['source'] ?? null,
            'tokens_used' => $aiResponse['tokens_used'] ?? null,
            'has_content' => !empty($aiResponse['content']),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 8. Total waktu
        |--------------------------------------------------------------------------
        */
        $totalDuration = microtime(true) - $totalStart;

        Log::info('[BUILD] ===== END generateBuild =====', [
            'total_duration_seconds' => round($totalDuration, 4),
            'character' => $character->name,
            'rag_duration_seconds' => round($ragDuration, 4),
            'prompt_duration_seconds' => round($promptDuration, 4),
            'nvidia_duration_seconds' => round($nvidiaDuration, 4),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 9. Return hasil
        |--------------------------------------------------------------------------
        */
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

            'teammates' => array_map(
                fn($t) => [
                    'slug' => $t->slug,
                    'name' => $t->name,
                    'vision' => $t->vision,
                    'icon_url' => $t->icon_url,
                ],
                $teammates
            ),

            'mechanics' => [
                'active_resonances' => $resonances['summary_buffs'],
                'triggered_reactions' => $reactions['stat_recommendations'],
                'constellation_notes' => $constellationImpact['gameplay_notes'],
                'role_shift' => $constellationImpact['role_shift'],
                'content_profile' => $contentProfile,
            ],

            'ai_recommendation' => $aiResponse['content'] ?? '',

            'model' => $aiResponse['model'] ?? null,

            'status' => $aiResponse['status'] ?? 'success',

            /*
             * Diteruskan supaya ChatbotService bisa menyimpan
             * informasi diagnostik dari NVIDIA.
             */
            'tokens_used' => $aiResponse['tokens_used'] ?? null,

            'source' => $aiResponse['source'] ?? null,

            'fallback_reason' => $aiResponse['fallback_reason'] ?? null,

            /*
             * Informasi profiling internal.
             * Bisa digunakan untuk debugging/performance monitoring.
             */
            'performance' => [
                'total_seconds' => round($totalDuration, 4),
                'rag_seconds' => round($ragDuration, 4),
                'prompt_seconds' => round($promptDuration, 4),
                'nvidia_seconds' => round($nvidiaDuration, 4),
            ],
        ];
    }

    /**
     * Memproses teks bebas pengguna melalui Query Understanding
     * lalu menghasilkan build.
     */
    public function processFreeTextQuery(string $rawQuery): array
    {
        $totalStart = microtime(true);

        Log::info('[QUERY] ===== START processFreeTextQuery =====', [
            'query' => $rawQuery,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 1. Entity Extraction
        |--------------------------------------------------------------------------
        */
        $start = microtime(true);

        $extracted = $this->entityExtractor->extract($rawQuery);

        Log::info('[QUERY] Entity extraction completed', [
            'duration_seconds' => round(microtime(true) - $start, 4),
            'extracted' => $extracted,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 2. Intent Classification
        |--------------------------------------------------------------------------
        */
        $start = microtime(true);

        $intent = $this->intentClassifier->classify($rawQuery);

        Log::info('[QUERY] Intent classification completed', [
            'duration_seconds' => round(microtime(true) - $start, 4),
            'intent' => $intent,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 3. Ambil parameter hasil extraction
        |--------------------------------------------------------------------------
        */
        $targetSlug = $extracted['target_character'] ?? 'furina';
        $constellation = $extracted['constellation'] ?? 0;
        $team = $extracted['team'] ?? [];
        $contentMode = $extracted['content_mode'] ?? 'abyss';

        Log::info('[QUERY] Parsed parameters', [
            'target_character' => $targetSlug,
            'constellation' => $constellation,
            'team' => $team,
            'content_mode' => $contentMode,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 4. Generate Build
        |--------------------------------------------------------------------------
        */
        $start = microtime(true);

        $buildResult = $this->generateBuild(
            $targetSlug,
            $constellation,
            $team,
            $contentMode,
            $rawQuery
        );

        Log::info('[QUERY] generateBuild completed', [
            'duration_seconds' => round(microtime(true) - $start, 4),
        ]);

        /*
        |--------------------------------------------------------------------------
        | 5. Tambahkan query understanding
        |--------------------------------------------------------------------------
        */
        $buildResult['query_understanding'] = [
            'intent' => $intent,
            'extracted_entities' => $extracted,
        ];

        $totalDuration = microtime(true) - $totalStart;

        Log::info('[QUERY] ===== END processFreeTextQuery =====', [
            'total_duration_seconds' => round($totalDuration, 4),
        ]);

        return $buildResult;
    }
}

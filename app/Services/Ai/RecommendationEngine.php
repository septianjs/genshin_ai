<?php

namespace App\Services\Ai;

use App\Models\Character;
use App\Models\BuildKnowledge;
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
     * Generate build lengkap.
     *
     * Method ini adalah satu-satunya pipeline utama untuk Generate Build.
     */
    public function generateBuild(
        string $characterSlug,
        int $constellation = 0,
        array $teammateSlugs = [],
        string $contentMode = 'abyss',
        ?string $customQuery = null,
        bool $useLocalKnowledge = true
    ): array {
        $totalStart =
            microtime(true);

        $characterSlug =
            strtolower(
                trim($characterSlug)
            );

        Log::info(
            '[BUILD] ===== START generateBuild =====',
            [
                'character' =>
                    $characterSlug,

                'constellation' =>
                    $constellation,

                'teammates' =>
                    $teammateSlugs,

                'content_mode' =>
                    $contentMode,
            ]
        );

        /*
         * ========================================================
         * 1. CHARACTER
         * ========================================================
         */
        $start =
            microtime(true);

        $character =
            $this->genshinService
                ->getCharacter(
                    $characterSlug
                );

        Log::info(
            '[BUILD] getCharacter',
            [
                'duration_seconds' =>
                    round(
                        microtime(true)
                        - $start,
                        4
                    ),

                'found' =>
                    $character !== null,

                'character' =>
                    $character?->name,
            ]
        );

        if (
            $character === null
        ) {
            return [
                'error' =>
                    true,

                'message' =>
                    "Karakter '{$characterSlug}' tidak ditemukan dalam database lokal.",
            ];
        }

        /*
         * ========================================================
         * 2. TEAM
         * ========================================================
         */
        $teammates = [];
        $allVisions = [
            $character->vision,
        ];

        foreach (
            $teammateSlugs
            as $teammateSlug
        ) {
            $teammateSlug =
                strtolower(
                    trim(
                        (string)
                        $teammateSlug
                    )
                );

            if (
                $teammateSlug === ''
                ||
                $teammateSlug ===
                    $character->slug
            ) {
                continue;
            }

            $teammate =
                $this->genshinService
                    ->getCharacter(
                        $teammateSlug
                    );

            if (
                $teammate !== null
            ) {
                $teammates[] =
                    $teammate;

                $allVisions[] =
                    $teammate->vision;
            }
        }

        /*
         * ========================================================
         * 3. MECHANICS
         * ========================================================
         */
        $resonances =
            $this->resonanceService
                ->evaluateResonances(
                    $allVisions
                );

        $reactions =
            $this->reactionService
                ->evaluateReactions(
                    $allVisions
                );

        $constellationImpact =
            $this->constellationService
                ->analyzeImpact(
                    $character,
                    $constellation
                );

        $contentProfile =
            $this->contentModeService
                ->getProfile(
                    $contentMode
                );

        $mechanicsData = [
            'resonances' =>
                $resonances,

            'reactions' =>
                $reactions,

            'constellation' =>
                $constellationImpact,

            'content_profile' =>
                $contentProfile,

            'team' =>
                array_map(
                    fn (
                        Character $teammate
                    ) => [
                        'name' =>
                            $teammate->name,

                        'vision' =>
                            $teammate->vision,

                        'weapon_type' =>
                            $teammate->weapon_type,
                    ],
                    $teammates
                ),
        ];

        /*
         * ========================================================
         * 4. RAG
         * ========================================================
         *
         * RAG adalah grounding tambahan.
         *
         * Tidak adanya knowledge lokal TIDAK membuat build gagal.
         */
        $teamContext =
            implode(
                ', ',
                array_map(
                    fn (
                        Character $teammate
                    ) =>
                        "{$teammate->name} {$teammate->vision}",
                    $teammates
                )
            );

        $ragQuery =
            trim(
                implode(
                    ' ',
                    array_filter([
                        "Build guide {$character->name}",
                        $character->vision,
                        $character->weapon_type,
                        "C{$constellation}",
                        $contentMode,

                        $teamContext !== ''
                            ? "team {$teamContext}"
                            : '',

                        $customQuery ?? '',
                    ])
                )
            );

        $ragStart =
            microtime(true);

        $ragChunks =
            $this->vectorStoreService
                ->searchSimilar(
                    $character,
                    $ragQuery,
                    $contentMode,
                    3,
                    array_map(
                        fn (
                            Character $teammate
                        ) =>
                            $teammate->id,
                        $teammates
                    )
                );

        $ragDuration =
            microtime(true)
            - $ragStart;

        $buildKnowledgeAvailable =
            collect(
                $ragChunks
            )->contains(
                fn (
                    BuildKnowledge $chunk
                ) =>
                    $chunk->category
                    !== 'character_overview'
            );

        /*
         * ========================================================
         * 5. SYSTEM PROMPT
         * ========================================================
         */
        $promptStart =
            microtime(true);

        $systemPrompt =
            $this->contextBuilder
                ->buildSystemPrompt(
                    $character,
                    $mechanicsData,
                    $ragChunks
                );

        $promptDuration =
            microtime(true)
            - $promptStart;

        /*
         * ========================================================
         * 6. USER PROMPT
         * ========================================================
         */
        $userPrompt =
            $customQuery
            ??
            "Tolong berikan rekomendasi build lengkap untuk {$character->name} C{$constellation} dalam mode {$contentProfile['name']}.";

        $messages = [
            [
                'role' =>
                    'system',

                'content' =>
                    $systemPrompt,
            ],

            [
                'role' =>
                    'user',

                'content' =>
                    $userPrompt,
            ],
        ];

        /*
         * ========================================================
         * 7. NVIDIA
         * ========================================================
         */
        $nvidiaStart =
            microtime(true);

        $aiResponse =
            $this->nvidiaService
                ->chat(
                    $messages,
                    0.2,
                    1000
                );

        $nvidiaDuration =
            microtime(true)
            - $nvidiaStart;

        $localRecommendation =
            null;

        /*
         * NVIDIA fallback hanya mencoba knowledge lokal.
         *
         * Tetapi jika tidak tersedia, jangan berpura-pura
         * bahwa AI berhasil.
         */
        if (
            ($aiResponse['status'] ?? null)
            === 'fallback'
        ) {
            if (
                $useLocalKnowledge
            ) {
                $localRecommendation =
                    $this->buildLocalRecommendation(
                        $character->slug,
                        $contentMode
                    );

                if (
                    $localRecommendation !== null
                ) {
                    $aiResponse['content'] =
                        $localRecommendation;

                    $aiResponse['source'] =
                        'local';

                    $aiResponse['fallback_reason'] =
                        $aiResponse['fallback_reason']
                        ?? 'LOCAL_KNOWLEDGE_FALLBACK';
                } else {
                    /*
                     * Tidak boleh menghasilkan build palsu.
                     */
                    $aiResponse['content'] =
                        "Layanan AI NVIDIA sedang tidak tersedia. "
                        ."Data build lokal khusus {$character->name} "
                        ."juga belum tersedia.";
                }
            }
        }

        $totalDuration =
            microtime(true)
            - $totalStart;

        /*
         * ========================================================
         * 8. RESULT
         * ========================================================
         */
        return [
            'character' => [
                'slug' =>
                    $character->slug,

                'name' =>
                    $character->name,

                'vision' =>
                    $character->vision,

                'weapon_type' =>
                    $character->weapon_type,

                'rarity' =>
                    $character->rarity,

                'icon_url' =>
                    $character->icon_url,

                'constellation' =>
                    $constellation,
            ],

            'teammates' =>
                array_map(
                    fn (
                        Character $teammate
                    ) => [
                        'slug' =>
                            $teammate->slug,

                        'name' =>
                            $teammate->name,

                        'vision' =>
                            $teammate->vision,

                        'icon_url' =>
                            $teammate->icon_url,
                    ],
                    $teammates
                ),

            'mechanics' => [
                'active_resonances' =>
                    $resonances[
                        'summary_buffs'
                    ] ?? [],

                'triggered_reactions' =>
                    $reactions[
                        'stat_recommendations'
                    ] ?? [],

                'constellation_notes' =>
                    $constellationImpact[
                        'gameplay_notes'
                    ] ?? [],

                'role_shift' =>
                    $constellationImpact[
                        'role_shift'
                    ] ?? null,

                'content_profile' =>
                    $contentProfile,
            ],

            'ai_recommendation' =>
                $aiResponse['content']
                ?? '',

            'model' =>
                $aiResponse['model']
                ?? null,

            'status' =>
                $aiResponse['status']
                ?? 'success',

            'tokens_used' =>
                $aiResponse['tokens_used']
                ?? null,

            'source' =>
                $aiResponse['source']
                ?? null,

            'fallback_reason' =>
                $aiResponse['fallback_reason']
                ?? null,

            'recommendation_source' =>
                $localRecommendation !== null
                    ? 'local_knowledge'
                    : (
                        ($aiResponse['status'] ?? null)
                        === 'fallback'
                            ? 'ai_unavailable'
                            : (
                                $aiResponse['source']
                                ?? null
                            )
                    ),

            'knowledge_available' =>
                $buildKnowledgeAvailable,

            'knowledge_categories' =>
                collect(
                    $ragChunks
                )
                    ->pluck('category')
                    ->unique()
                    ->values()
                    ->all(),

            'performance' => [
                'total_seconds' =>
                    round(
                        $totalDuration,
                        4
                    ),

                'rag_seconds' =>
                    round(
                        $ragDuration,
                        4
                    ),

                'prompt_seconds' =>
                    round(
                        $promptDuration,
                        4
                    ),

                'nvidia_seconds' =>
                    round(
                        $nvidiaDuration,
                        4
                    ),
            ],
        ];
    }

    /**
     * ============================================================
     * LOCAL BUILD
     * ============================================================
     */
    public function buildLocalRecommendation(
        string $characterSlug,
        string $contentMode = 'abyss'
    ): ?string {
        $character =
            Character::query()
                ->where(
                    'slug',
                    $characterSlug
                )
                ->orderByDesc(
                    'patch_version'
                )
                ->first();

        if (
            $character === null
        ) {
            return null;
        }

        $knowledge =
            BuildKnowledge::query()
                ->where(
                    'character_id',
                    $character->id
                )
                ->where(
                    'patch_version',
                    $character->patch_version
                )
                ->where(
                    'category',
                    '!=',
                    'character_overview'
                )
                ->where(
                    function ($query)
                    use ($contentMode) {
                        $query
                            ->where(
                                'target_content',
                                $contentMode
                            )
                            ->orWhere(
                                'target_content',
                                'universal'
                            );
                    }
                )
                ->get();

        if (
            $knowledge->isEmpty()
        ) {
            return null;
        }

        $categoryOrder = [
            'role_and_reactions' =>
                0,

            'weapons_ranking' =>
                1,

            'artifact_priorities' =>
                2,

            'er_breakpoints' =>
                3,

            'team_synergies' =>
                4,

            'rotation' =>
                5,
        ];

        $knowledge =
            $knowledge->sortBy(
                fn (
                    BuildKnowledge $chunk
                ) =>
                    $categoryOrder[
                        $chunk->category
                    ]
                    ?? 99
            );

        $recommendation =
            "# Build {$character->name}\n\n";

        $recommendation .=
            "Rekomendasi lokal untuk mode "
            ."{$contentMode} "
            ."(data patch "
            ."{$character->patch_version})."
            ."\n\n";

        foreach (
            $knowledge
            as $chunk
        ) {
            $heading =
                match (
                    $chunk->category
                ) {
                    'role_and_reactions' =>
                        'Peran dan Reaksi',

                    'weapons_ranking' =>
                        'Senjata',

                    'artifact_priorities' =>
                        'Artefak dan Stat',

                    'er_breakpoints' =>
                        'Energy Recharge',

                    'team_synergies' =>
                        'Rekomendasi Tim',

                    'rotation' =>
                        'Rotasi',

                    default =>
                        ucfirst(
                            str_replace(
                                '_',
                                ' ',
                                $chunk->category
                            )
                        ),
                };

            $recommendation .=
                "## {$heading}\n"
                .$chunk->content
                ."\n\n";
        }

        return trim(
            $recommendation
        );
    }

    /**
     * ============================================================
     * FREE TEXT
     * ============================================================
     *
     * Dipakai oleh endpoint Generate Build.
     *
     * Jangan gunakan method ini sebagai chatbot umum.
     */
    public function processFreeTextQuery(
        string $rawQuery
    ): array {
        $extracted =
            $this->entityExtractor
                ->extract(
                    $rawQuery
                );

        $intent =
            $this->intentClassifier
                ->classify(
                    $rawQuery
                );

        /*
         * Jangan lagi default ke Furina.
         */
        $targetSlug =
            $extracted['target_character']
            ?? null;

        /*
         * Endpoint build hanya menerima permintaan build.
         */
        if (
            $intent !==
            IntentClassifier::INTENT_BUILD
        ) {
            return [
                'error' =>
                    true,

                'message' =>
                    'Pertanyaan ini bukan permintaan Generate Build. '
                    .'Gunakan chatbot untuk pertanyaan umum.',

                'query_understanding' => [
                    'intent' =>
                        $intent,

                    'extracted_entities' =>
                        $extracted,
                ],
            ];
        }

        if (
            empty($targetSlug)
        ) {
            return [
                'error' =>
                    true,

                'message' =>
                    'Karakter untuk Generate Build belum ditentukan.',

                'query_understanding' => [
                    'intent' =>
                        $intent,

                    'extracted_entities' =>
                        $extracted,
                ],
            ];
        }

        $constellation =
            (int) (
                $extracted['constellation']
                ?? 0
            );

        $team =
            $extracted['team']
            ?? [];

        $contentMode =
            $extracted['content_mode']
            ?? 'abyss';

        $buildResult =
            $this->generateBuild(
                $targetSlug,
                $constellation,
                $team,
                $contentMode,
                $rawQuery
            );

        $buildResult[
            'query_understanding'
        ] = [
            'intent' =>
                $intent,

            'extracted_entities' =>
                $extracted,
        ];

        return $buildResult;
    }
}
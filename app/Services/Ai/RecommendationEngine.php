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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
        ?string $preferredRole = null
    ): array {
        $totalStart =
            microtime(true);

        $characterSlug =
            strtolower(
                trim($characterSlug)
            );

        $preferredRole = trim((string) $preferredRole);

        if ($preferredRole === '' && $customQuery !== null) {
            $preferredRole = trim((string) ($this->entityExtractor->extract($customQuery)['role'] ?? ''));
        }

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

        $teamCandidates = Character::query()
            ->where('patch_version', $character->patch_version)
            ->where('is_validated', true)
            ->where('id', '!=', $character->id)
            ->orderBy('name')
            ->get([
                'slug',
                'name',
                'vision',
                'weapon_type',
                'icon_url',
            ])
            ->map(fn (Character $candidate) => [
                'slug' => $candidate->slug,
                'name' => $candidate->name,
                'vision' => $candidate->vision,
                'weapon_type' => $candidate->weapon_type,
                'icon_url' => $candidate->icon_url,
            ])
            ->all();

        $catalogCache = Cache::store('file');
        $weaponCatalog = $catalogCache->get('build_weapon_catalog');
        if (! is_array($weaponCatalog) || $weaponCatalog === []) {
            $weaponCatalog = $this->genshinService->getWeaponCatalog();

            if ($weaponCatalog !== []) {
                $catalogCache->put('build_weapon_catalog', $weaponCatalog, 21600);
            }
        }

        $expectedWeaponType = $this->normalizeWeaponType($character->weapon_type);
        $weaponCandidates = array_values(array_filter(
            is_array($weaponCatalog) ? $weaponCatalog : [],
            fn (array $weapon): bool => $this->normalizeWeaponType(
                (string) ($weapon['type'] ?? $weapon['weapon_type'] ?? '')
            ) === $expectedWeaponType
        ));

        $artifactCandidates = $catalogCache->get('build_artifact_catalog');
        if (! is_array($artifactCandidates) || $artifactCandidates === []) {
            $artifactCandidates = $this->genshinService->getArtifactCatalog();

            if ($artifactCandidates !== []) {
                $catalogCache->put('build_artifact_catalog', $artifactCandidates, 21600);
            }
        }

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
         * 4. SYSTEM PROMPT
         * ========================================================
         */
        $promptStart =
            microtime(true);

        $systemPrompt =
            $this->contextBuilder
                ->buildSystemPrompt(
                    $character,
                    $mechanicsData,
                    $teamCandidates,
                    $preferredRole !== '' ? $preferredRole : null,
                    $weaponCandidates,
                    $artifactCandidates
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
                    1800
                );

        $nvidiaDuration =
            microtime(true)
            - $nvidiaStart;

        $unsupportedWeapons = $this->unsupportedWeaponNames(
            (string) ($aiResponse['content'] ?? ''),
            is_array($weaponCatalog) ? $weaponCatalog : [],
            $weaponCandidates
        );
        $responseHasInternalReasoning = $this->containsInternalReasoning((string) ($aiResponse['content'] ?? ''));

        if ($responseHasInternalReasoning || $unsupportedWeapons !== []) {
            Log::warning('[BUILD] Model output needs a factual retry.', [
                'character' => $character->slug,
                'internal_reasoning' => $responseHasInternalReasoning,
                'unsupported_weapons' => $unsupportedWeapons,
            ]);

            $retryMessages = $messages;
            $retryMessages[0]['content'] .= "\n\nOUTPUT CONTRACT: Return only the user-facing final build. Never include analysis, reasoning, planning, or phrases such as 'let me think'. Start with the build role and recommendations.";
            $retryMessages[1]['content'] .= "\n\nReturn only the finished recommendation. Include artifacts, main stats, substats, weapons, team, and rotation.";

            if ($unsupportedWeapons !== []) {
                $retryMessages[0]['content'] .= "\n\nWEAPON TYPE CORRECTION: The previous answer named these incompatible weapons: "
                    .implode(', ', $unsupportedWeapons)
                    .". Select weapons only from the supplied compatible weapon catalog.";
            }

            $retryStart = microtime(true);
            $aiResponse = $this->nvidiaService->chat(
                $retryMessages,
                0.1,
                2000
            );
            $nvidiaDuration += microtime(true) - $retryStart;
        }

        if (
            ($aiResponse['status'] ?? null)
            === 'fallback'
        ) {
            $aiResponse['content'] =
                "Layanan AI NVIDIA sedang tidak tersedia. Rekomendasi build {$character->name} belum dapat dibuat; silakan coba lagi saat layanan tersedia.";
        }

        if ($this->containsInternalReasoning((string) ($aiResponse['content'] ?? ''))) {
            Log::warning('[BUILD] Model returned internal reasoning; replacing it with safe output.', [
                'character' => $character->slug,
            ]);

            $aiResponse['status'] = 'fallback';
            $aiResponse['fallback_reason'] = 'INTERNAL_REASONING_FILTERED';
            $aiResponse['content'] = 'Maaf, jawaban belum berhasil disusun. Silakan coba ajukan pertanyaan lagi.';
        }

        $unsupportedWeapons = $this->unsupportedWeaponNames(
            (string) ($aiResponse['content'] ?? ''),
            is_array($weaponCatalog) ? $weaponCatalog : [],
            $weaponCandidates
        );
        if ($unsupportedWeapons !== []) {
            Log::warning('[BUILD] Model retry still included incompatible weapon types.', [
                'character' => $character->slug,
                'unsupported_weapons' => $unsupportedWeapons,
            ]);

            $aiResponse['status'] = 'fallback';
            $aiResponse['fallback_reason'] = 'INVALID_WEAPON_TYPE';
            $aiResponse['content'] = "AI belum berhasil memilih senjata yang cocok untuk tipe {$character->weapon_type}. Silakan coba generate ulang.";
        }

        $recommendationCards = null;

        if (($aiResponse['status'] ?? null) !== 'fallback') {
            $recommendationCards = $this->parseBuildRecommendation(
                (string) ($aiResponse['content'] ?? ''),
                $character,
                $teamCandidates,
                $weaponCandidates,
                $artifactCandidates
            );

            if ($recommendationCards === null) {
                Log::warning('[BUILD] Model output did not match the validated ranked-build schema; retrying once.', [
                    'character' => $character->slug,
                ]);

                $retryMessages = $messages;
                $retryMessages[0]['content'] .= "\n\nJSON RETRY: Output exactly one valid JSON object using the required schema. Use only the exact IDs in the supplied weapon/artifact catalogs and only teammate slugs in the database roster. Include exactly four ranked artifacts, four ranked weapons, and four ranked teams. Do not include Markdown or prose outside JSON.";
                $retryStart = microtime(true);
                $aiResponse = $this->nvidiaService->chat($retryMessages, 0.1, 3200);
                $nvidiaDuration += microtime(true) - $retryStart;

                if (($aiResponse['status'] ?? null) !== 'fallback') {
                    $recommendationCards = $this->parseBuildRecommendation(
                        (string) ($aiResponse['content'] ?? ''),
                        $character,
                        $teamCandidates,
                        $weaponCandidates,
                        $artifactCandidates
                    );
                }
            }

            if ($recommendationCards === null && ($aiResponse['status'] ?? null) !== 'fallback') {
                $aiResponse['status'] = 'fallback';
                $aiResponse['fallback_reason'] = 'INVALID_BUILD_RESPONSE';
                $aiResponse['content'] = 'AI belum menghasilkan rekomendasi terstruktur yang bisa diverifikasi. Silakan coba sekali lagi.';
            } elseif ($recommendationCards !== null) {
                $aiResponse['content'] = $this->formatBuildRecommendation(
                    $character->name,
                    $recommendationCards
                );
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
                ($aiResponse['status'] ?? null) === 'fallback'
                    ? 'ai_unavailable'
                    : ($aiResponse['source'] ?? null),

            'recommendation_cards' =>
                $recommendationCards,

            'performance' => [
                'total_seconds' =>
                    round(
                        $totalDuration,
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
     * FREE TEXT
     * ============================================================
     *
     * Dipakai oleh endpoint Generate Build.
     *
     * Jangan gunakan method ini sebagai chatbot umum.
     */
    protected function containsInternalReasoning(string $content): bool
    {
        return preg_match(
            '/here(?:\'|’)s\s+(?:a\s+)?thinking process\b|thinking process\s*:|internal reasoning\s*:|let me think|let me analyze|let\'s think step by step|analyze user input|check system\/context constraints|determine response strategy/iu',
            $content
        ) === 1;
    }

    protected function normalizeWeaponType(string $weaponType): string
    {
        return strtolower(preg_replace('/[^a-z]/i', '', $weaponType) ?? '');
    }

    protected function parseBuildRecommendation(
        string $content,
        Character $character,
        array $teamCandidates,
        array $weaponCandidates,
        array $artifactCandidates
    ): ?array {
        $jsonStart = strpos($content, '{');
        $jsonEnd = strrpos($content, '}');

        if ($jsonStart === false || $jsonEnd === false || $jsonEnd < $jsonStart) {
            return null;
        }

        $payload = json_decode(substr($content, $jsonStart, $jsonEnd - $jsonStart + 1), true);
        if (! is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $weaponById = collect($weaponCandidates)->keyBy('id');
        $artifactById = collect($artifactCandidates)->keyBy('id');
        $teamBySlug = collect($teamCandidates)->keyBy('slug');

        $artifacts = collect($payload['artifacts'] ?? [])
            ->take(4)
            ->map(function (array $item) use ($artifactById): ?array {
                $artifact = $artifactById->get((string) ($item['id'] ?? ''));
                if ($artifact === null) {
                    return null;
                }

                return [
                    'rank' => max(1, min(4, (int) ($item['rank'] ?? 0))),
                    'id' => (string) $artifact['id'],
                    'image_id' => (string) $artifact['id'],
                    'name' => (string) $artifact['name'],
                    'rarity' => $artifact['max_rarity'] ?? null,
                    'two_piece_bonus' => $artifact['2-piece_bonus'] ?? null,
                    'four_piece_bonus' => $artifact['4-piece_bonus'] ?? null,
                    'main_stats' => is_array($item['main_stats'] ?? null) ? $item['main_stats'] : [],
                    'substats' => array_values(array_filter($item['substats'] ?? [], 'is_string')),
                    'reason' => trim((string) ($item['reason'] ?? '')),
                ];
            })
            ->filter()
            ->sortBy('rank')
            ->values();

        $weapons = collect($payload['weapons'] ?? [])
            ->take(4)
            ->map(function (array $item) use ($weaponById): ?array {
                $weapon = $weaponById->get((string) ($item['id'] ?? ''));
                if ($weapon === null) {
                    return null;
                }

                return [
                    'rank' => max(1, min(4, (int) ($item['rank'] ?? 0))),
                    'id' => (string) $weapon['id'],
                    'image_id' => (string) $weapon['id'],
                    'name' => (string) $weapon['name'],
                    'weapon_type' => (string) ($weapon['type'] ?? $weapon['weapon_type'] ?? ''),
                    'rarity' => $weapon['rarity'] ?? null,
                    'base_attack' => $weapon['baseAttack'] ?? $weapon['base_attack'] ?? null,
                    'secondary_stat' => $weapon['subStat'] ?? $weapon['secondary_stat'] ?? null,
                    'passive_name' => $weapon['passiveName'] ?? $weapon['passive_name'] ?? null,
                    'passive_description' => $weapon['passiveDesc'] ?? $weapon['passive_description'] ?? null,
                    'reason' => trim((string) ($item['reason'] ?? '')),
                ];
            })
            ->filter()
            ->sortBy('rank')
            ->values();

        $teams = collect($payload['teams'] ?? [])
            ->take(4)
            ->map(function (array $team) use ($teamBySlug, $character): ?array {
                $slugs = array_values(array_unique(array_filter(
                    $team['teammate_slugs'] ?? [],
                    fn ($slug) => is_string($slug) && $slug !== $character->slug && $teamBySlug->has($slug)
                )));

                if ($slugs === []) {
                    return null;
                }

                $members = [[
                    'slug' => $character->slug,
                    'name' => $character->name,
                    'vision' => $character->vision,
                    'weapon_type' => $character->weapon_type,
                    'icon_url' => $character->icon_url,
                ]];

                foreach (array_slice($slugs, 0, 3) as $slug) {
                    $member = $teamBySlug->get($slug);
                    $members[] = [
                        'slug' => $member['slug'],
                        'name' => $member['name'],
                        'vision' => $member['vision'],
                        'weapon_type' => $member['weapon_type'],
                        'icon_url' => $member['icon_url'] ?? null,
                    ];
                }

                return [
                    'rank' => max(1, min(4, (int) ($team['rank'] ?? 0))),
                    'members' => $members,
                    'reason' => trim((string) ($team['reason'] ?? '')),
                ];
            })
            ->filter()
            ->sortBy('rank')
            ->values();

        if ($artifacts->count() !== 4 || $weapons->count() !== 4 || $teams->count() !== 4) {
            Log::warning('[BUILD] Structured recommendation failed catalog validation.', [
                'character' => $character->slug,
                'catalog_weapon_candidates' => count($weaponCandidates),
                'catalog_artifact_candidates' => count($artifactCandidates),
                'roster_team_candidates' => count($teamCandidates),
                'valid_weapon_recommendations' => $weapons->count(),
                'valid_artifact_recommendations' => $artifacts->count(),
                'valid_team_recommendations' => $teams->count(),
            ]);

            return null;
        }

        return [
            'role_analysis' => trim((string) ($payload['role_analysis'] ?? '')),
            'artifacts' => $artifacts->all(),
            'weapons' => $weapons->all(),
            'teams' => $teams->all(),
            'stat_priorities' => array_values(array_filter($payload['stat_priorities'] ?? [], 'is_string')),
            'rotation' => array_values(array_filter($payload['rotation'] ?? [], 'is_string')),
        ];
    }

    protected function formatBuildRecommendation(string $characterName, array $recommendation): string
    {
        $lines = [
            "# {$characterName} Build",
            '',
            '## Peran dan Analisis Kit',
            $recommendation['role_analysis'] ?: 'Build disusun berdasarkan kit dan role yang diminta.',
            '',
            '## Artefak (peringkat 1–4)',
        ];

        foreach ($recommendation['artifacts'] as $artifact) {
            $lines[] = "{$artifact['rank']}. {$artifact['name']} — {$artifact['reason']}";
        }

        $lines[] = '';
        $lines[] = '## Senjata (peringkat 1–4)';
        foreach ($recommendation['weapons'] as $weapon) {
            $lines[] = "{$weapon['rank']}. {$weapon['name']} — {$weapon['reason']}";
        }

        $lines[] = '';
        $lines[] = '## Tim (peringkat 1–4)';
        foreach ($recommendation['teams'] as $team) {
            $lines[] = $team['rank'].'. '.implode(', ', array_column($team['members'], 'name'))
                .' — '.$team['reason'];
        }

        $lines[] = '';
        $lines[] = '## Prioritas Stat';
        foreach ($recommendation['stat_priorities'] as $priority) {
            $lines[] = '- '.$priority;
        }

        $lines[] = '';
        $lines[] = '## Rotasi';
        foreach ($recommendation['rotation'] as $step => $action) {
            $lines[] = ($step + 1).'. '.$action;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array<string, mixed>> $weaponCatalog
     * @param array<array<string, mixed>> $compatibleWeapons
     * @return array<string>
     */
    protected function unsupportedWeaponNames(
        string $content,
        array $weaponCatalog,
        array $compatibleWeapons
    ): array {
        if ($content === '' || $weaponCatalog === [] || $compatibleWeapons === []) {
            return [];
        }

        $normalizeName = static fn (string $name): string => trim(
            preg_replace('/[^a-z0-9]+/i', ' ', mb_strtolower($name)) ?? ''
        );
        $normalizedContent = ' '.$normalizeName($content).' ';
        $compatibleIds = array_fill_keys(array_filter(array_map(
            static fn (array $weapon): string => (string) ($weapon['id'] ?? ''),
            $compatibleWeapons
        )), true);
        $unsupported = [];

        foreach ($weaponCatalog as $weapon) {
            $name = trim((string) ($weapon['name'] ?? ''));
            $id = (string) ($weapon['id'] ?? '');
            $normalizedName = $normalizeName($name);

            if (
                $normalizedName !== ''
                && ! isset($compatibleIds[$id])
                && str_contains($normalizedContent, ' '.$normalizedName.' ')
            ) {
                $unsupported[] = $name;
            }
        }

        return array_values(array_unique($unsupported));
    }

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
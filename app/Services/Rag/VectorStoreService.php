<?php

namespace App\Services\Rag;

use App\Models\BuildKnowledge;
use App\Models\Character;
use App\Services\Nvidia\NvidiaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VectorStoreService
{
    public function __construct(
        protected NvidiaService $nvidiaService,
        protected SimilarityEngine $similarityEngine
    ) {}

    /**
     * Menyimpan atau memperbarui chunk panduan theorycraft beserta vektor embedding-nya.
     */
    public function storeKnowledge(
        Character $character,
        string $category,
        string $title,
        string $content,
        string $targetContent = 'universal',
        string $patchVersion = '7.0',
        ?string $source = null
    ): BuildKnowledge {
        $knowledge = BuildKnowledge::firstOrNew([
            'character_id' => $character->id,
            'category' => $category,
            'patch_version' => $patchVersion,
            'target_content' => $targetContent,
        ]);

        $contentChanged = ! $knowledge->exists
            || $knowledge->title !== $title
            || $knowledge->content !== $content
            || $knowledge->embedding === null;

        if ($contentChanged) {
            $knowledge->embedding = $this->nvidiaService->generateEmbedding($content);
        }

        $knowledge->title = $title;
        $knowledge->content = $content;
        $knowledge->source = $source;
        $knowledge->save();

        return $knowledge;
    }

    /**
     * RAG untuk pipeline Generate Build.
     *
     * Method lama dipertahankan supaya pipeline Generate Build
     * yang sudah berjalan tidak berubah perilakunya.
     *
     * @return array<BuildKnowledge>
     */
    public function searchSimilar(
        Character $character,
        string $query,
        string $targetContent = 'universal',
        int $topK = 3,
        array $teamCharacterIds = []
    ): array {
        $patchVersion = $character->patch_version
            ?: (string) config('services.genshin.target_patch', 'unknown');

        $knowledgeChunks = BuildKnowledge::where('patch_version', $patchVersion)
            ->where(function ($query) use ($teamCharacterIds, $character) {
                $query->where('character_id', $character->id);

                if ($teamCharacterIds !== []) {
                    $query->orWhere(function ($teamQuery) use ($teamCharacterIds) {
                        $teamQuery->whereIn('character_id', $teamCharacterIds)
                            ->where('category', 'team_synergies');
                    });
                }
            })
            ->where(function ($query) use ($targetContent) {
                $query->where('target_content', $targetContent)
                    ->orWhere('target_content', 'universal');
            })
            ->get();

        if ($knowledgeChunks->isEmpty()) {
            return [];
        }

        $queryEmbedding = $this->getCachedQueryEmbedding($query);

        if (! $queryEmbedding || ! is_array($queryEmbedding)) {
            Log::info('[RAG] Menggunakan fallback chunk lokal karena embedding tidak tersedia', [
                'character' => $character->name,
                'count' => min($knowledgeChunks->count(), $topK),
            ]);

            return $knowledgeChunks
                ->take($topK)
                ->all();
        }

        return $this->rankKnowledgeChunks(
            $knowledgeChunks,
            $queryEmbedding,
            $topK
        );
    }

    /**
     * ============================================================
     * CHATBOT RAG
     * ============================================================
     *
     * Berbeda dengan searchSimilar(), chatbot tidak boleh hanya
     * mencari knowledge karakter seperti pipeline Generate Build.
     *
     * Chatbot perlu:
     *
     * 1. Knowledge karakter yang sedang ditanyakan.
     * 2. Knowledge kategori yang relevan.
     * 3. Character overview sebagai fakta dasar.
     * 4. Knowledge team jika memang ada tim.
     *
     * Tujuannya agar pertanyaan seperti:
     *
     * "Artefak Kazuha apa?"
     *
     * tetap dapat memperoleh konteks karakter Kazuha walaupun
     * belum ada artikel artifact_priorities khusus Kazuha.
     *
     * @return array<BuildKnowledge>
     */
    public function searchChatSimilar(
        ?Character $character,
        string $query,
        string $targetContent = 'universal',
        int $topK = 5,
        array $teamCharacterIds = [],
        ?string $intent = null
    ): array {
        $patchVersion = $character?->patch_version
            ?: (string) config('services.genshin.target_patch', 'unknown');

        /*
         * Tentukan kategori yang paling relevan berdasarkan intent.
         */
        $preferredCategories = $this->categoriesForChatIntent($intent);

        /*
         * Ambil knowledge pada patch yang sesuai.
         *
         * Chatbot sengaja tidak membatasi query hanya ke satu
         * kategori/character karena karakter bisa saja belum memiliki
         * panduan spesifik untuk topik yang ditanyakan.
         */
        $knowledgeQuery = BuildKnowledge::query()
            ->where('patch_version', $patchVersion)
            ->where(function ($query) use ($targetContent) {
                $query->where('target_content', $targetContent)
                    ->orWhere('target_content', 'universal');
            });

        /*
         * Jika karakter diketahui, karakter tersebut menjadi prioritas.
         *
         * Character overview tetap boleh dipakai sebagai fakta dasar.
         */
        if ($character !== null) {
            $knowledgeQuery->where(function ($query) use ($character, $teamCharacterIds) {
                $query->where('character_id', $character->id);

                if ($teamCharacterIds !== []) {
                    $query->orWhere(function ($teamQuery) use ($teamCharacterIds) {
                        $teamQuery
                            ->whereIn('character_id', $teamCharacterIds)
                            ->where('category', 'team_synergies');
                    });
                }
            });
        } elseif ($teamCharacterIds !== []) {
            $knowledgeQuery->whereIn('character_id', $teamCharacterIds);
        }

        $knowledgeChunks = $knowledgeQuery->get();

        if ($knowledgeChunks->isEmpty()) {
            Log::info('[CHAT RAG] Tidak ada knowledge karakter pada patch aktif', [
                'character' => $character?->name,
                'patch' => $patchVersion,
                'intent' => $intent,
            ]);

            return [];
        }

        /*
         * Jika ada kategori yang jelas sesuai intent, beri prioritas
         * pada kategori tersebut.
         *
         * Jangan membuang kategori lain karena character_overview
         * masih penting sebagai grounding.
         */
        if ($preferredCategories !== []) {
            $knowledgeChunks = $knowledgeChunks
                ->sortByDesc(function (BuildKnowledge $chunk) use ($preferredCategories) {
                    if (in_array($chunk->category, $preferredCategories, true)) {
                        return 3;
                    }

                    if ($chunk->category === 'character_overview') {
                        return 2;
                    }

                    return 1;
                })
                ->values();
        }

        /*
         * Satu embedding query saja.
         *
         * Ini penting untuk menghindari beberapa request embedding
         * dalam satu pertanyaan chatbot.
         */
        $queryEmbedding = $this->getCachedQueryEmbedding($query);

        if (! $queryEmbedding || ! is_array($queryEmbedding)) {
            $fallback = $this->fallbackChatRanking(
                $knowledgeChunks,
                $preferredCategories,
                $topK
            );

            Log::info('[CHAT RAG] Fallback local ranking digunakan', [
                'character' => $character?->name,
                'intent' => $intent,
                'count' => count($fallback),
            ]);

            return $fallback;
        }

        /*
         * Cosine similarity digunakan untuk memilih chunk yang
         * benar-benar dekat dengan pertanyaan user.
         */
        $ranked = $this->rankKnowledgeChunks(
            $knowledgeChunks,
            $queryEmbedding,
            max($topK * 2, 8)
        );

        /*
         * Pastikan character_overview tetap tersedia jika ada.
         *
         * Ini membantu pertanyaan karakter yang belum memiliki
         * theorycraft khusus.
         */
        if ($character !== null) {
            $overview = $knowledgeChunks
                ->first(fn (BuildKnowledge $chunk) => $chunk->category === 'character_overview');

            if ($overview !== null && ! collect($ranked)->contains(
                fn (BuildKnowledge $chunk) => $chunk->id === $overview->id
            )) {
                $ranked[] = $overview;
            }
        }

        /*
         * Prioritaskan lagi kategori sesuai intent setelah similarity.
         */
        usort($ranked, function (BuildKnowledge $a, BuildKnowledge $b) use ($preferredCategories) {
            $scoreA = $this->chatCategoryPriority($a, $preferredCategories);
            $scoreB = $this->chatCategoryPriority($b, $preferredCategories);

            return $scoreB <=> $scoreA;
        });

        /*
         * Hilangkan duplicate ID.
         */
        $result = [];
        $seen = [];

        foreach ($ranked as $chunk) {
            if (isset($seen[$chunk->id])) {
                continue;
            }

            $seen[$chunk->id] = true;
            $result[] = $chunk;

            if (count($result) >= $topK) {
                break;
            }
        }

        Log::info('[CHAT RAG] Similarity search completed', [
            'character' => $character?->name,
            'intent' => $intent,
            'patch' => $patchVersion,
            'candidate_count' => $knowledgeChunks->count(),
            'result_count' => count($result),
            'top_k' => $topK,
        ]);

        return $result;
    }

    /**
     * Mengambil embedding query dengan cache.
     */
    protected function getCachedQueryEmbedding(string $query): ?array
    {
        $query = trim($query);

        if ($query === '') {
            return null;
        }

        $cacheKey = 'rag_query_emb_'
            .md5(
                $this->nvidiaService->getEmbeddingModel()
                .'_'
                .$query
            );

        $embedding = Cache::remember(
            $cacheKey,
            86400,
            function () use ($query) {
                return $this->nvidiaService->generateEmbedding($query);
            }
        );

        return is_array($embedding)
            ? $embedding
            : null;
    }

    /**
     * Mapping intent chatbot ke kategori knowledge.
     */
    protected function categoriesForChatIntent(?string $intent): array
    {
        return match ($intent) {
            'artifact_question' => [
                'artifact_priorities',
                'character_overview',
            ],

            'weapon_question',
            'weapon_compare' => [
                'weapons_ranking',
                'character_overview',
            ],

            'team_synergy' => [
                'team_synergies',
                'role_and_reactions',
                'character_overview',
            ],

            'rotation' => [
                'rotation',
                'team_synergies',
                'character_overview',
            ],

            'mechanics' => [
                'role_and_reactions',
                'character_overview',
            ],

            'build' => [
                'artifact_priorities',
                'weapons_ranking',
                'team_synergies',
                'rotation',
                'role_and_reactions',
                'character_overview',
            ],

            default => [
                'character_overview',
            ],
        };
    }

    /**
     * Ranking tambahan berdasarkan kategori intent.
     */
    protected function chatCategoryPriority(
        BuildKnowledge $chunk,
        array $preferredCategories
    ): int {
        $category = (string) $chunk->category;

        $index = array_search(
            $category,
            $preferredCategories,
            true
        );

        if ($index !== false) {
            /*
             * Kategori pertama mendapat prioritas tertinggi.
             */
            return 100 - ($index * 10);
        }

        /*
         * Overview tetap penting sebagai grounding karakter.
         */
        if ($category === 'character_overview') {
            return 40;
        }

        return 10;
    }

    /**
     * Fallback ranking tanpa embedding.
     */
    protected function fallbackChatRanking(
        $knowledgeChunks,
        array $preferredCategories,
        int $topK
    ): array {
        $ranked = $knowledgeChunks
            ->sortByDesc(function (BuildKnowledge $chunk) use ($preferredCategories) {
                return $this->chatCategoryPriority(
                    $chunk,
                    $preferredCategories
                );
            })
            ->values();

        return $ranked
            ->take($topK)
            ->all();
    }

    /**
     * Ranking cosine similarity umum.
     *
     * @param iterable<BuildKnowledge> $knowledgeChunks
     * @return array<BuildKnowledge>
     */
    protected function rankKnowledgeChunks(
        iterable $knowledgeChunks,
        array $queryEmbedding,
        int $limit
    ): array {
        $queryDim = count($queryEmbedding);
        $scoredChunks = [];

        foreach ($knowledgeChunks as $chunk) {
            $chunkEmbedding = $chunk->embedding;

            if (
                is_array($chunkEmbedding)
                && count($chunkEmbedding) === $queryDim
            ) {
                $score = $this->similarityEngine->cosineSimilarity(
                    $queryEmbedding,
                    $chunkEmbedding
                );
            } else {
                /*
                 * Embedding lama/mock dengan dimensi berbeda tidak boleh
                 * membuat knowledge hilang sepenuhnya.
                 */
                $score = 0.5;
            }

            $scoredChunks[] = [
                'score' => $score,
                'chunk' => $chunk,
            ];
        }

        usort(
            $scoredChunks,
            fn ($a, $b) => $b['score'] <=> $a['score']
        );

        return array_slice(
            array_column($scoredChunks, 'chunk'),
            0,
            $limit
        );
    }
}
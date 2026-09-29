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
     * Mencari dokumen knowledge yang paling relevan menggunakan Cosine Similarity.
     * Menggunakan cache embedding dan fallback aman agar tidak menghambat pipeline build.
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

        // 2. Ambil query embedding dengan cache agar query yang sama tidak memicu HTTP request berulang
        $cacheKey = 'rag_query_emb_'.md5($this->nvidiaService->getEmbeddingModel().'_'.$query);
        $queryEmbedding = Cache::remember($cacheKey, 86400, function () use ($query) {
            return $this->nvidiaService->generateEmbedding($query);
        });

        // 3. Jika embedding gagal atau tidak tersedia, fallback ke chunk lokal teratas agar RAG tidak kosong
        if (! $queryEmbedding || ! is_array($queryEmbedding)) {
            Log::info('[RAG] Menggunakan fallback chunk lokal karena embedding tidak tersedia', [
                'character' => $character->name,
                'count' => min($knowledgeChunks->count(), $topK),
            ]);

            return $knowledgeChunks->take($topK)->all();
        }

        $queryDim = count($queryEmbedding);
        $scoredChunks = [];

        foreach ($knowledgeChunks as $chunk) {
            $chunkEmbedding = $chunk->embedding;
            if (is_array($chunkEmbedding) && count($chunkEmbedding) === $queryDim) {
                $score = $this->similarityEngine->cosineSimilarity($queryEmbedding, $chunkEmbedding);
                $scoredChunks[] = [
                    'score' => $score,
                    'chunk' => $chunk,
                ];
            } else {
                // Dimensi tidak sama persis (misal seeded dengan mock vector), tetap berikan bobot dasar agar tidak hilang
                $scoredChunks[] = [
                    'score' => 0.5,
                    'chunk' => $chunk,
                ];
            }
        }

        // Urutkan dari score tertinggi ke terendah
        usort($scoredChunks, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice(array_column($scoredChunks, 'chunk'), 0, $topK);
    }
}

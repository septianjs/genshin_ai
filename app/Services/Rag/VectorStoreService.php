<?php

namespace App\Services\Rag;

use App\Models\BuildKnowledge;
use App\Models\Character;
use App\Services\Nvidia\NvidiaService;

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
        string $patchVersion = '7.0'
    ): BuildKnowledge {
        $embedding = $this->nvidiaService->generateEmbedding($content);

        return BuildKnowledge::updateOrCreate(
            [
                'character_id' => $character->id,
                'category' => $category,
                'patch_version' => $patchVersion,
                'target_content' => $targetContent,
            ],
            [
                'title' => $title,
                'content' => $content,
                'embedding' => $embedding,
            ]
        );
    }

    /**
     * Mencari dokumen knowledge yang paling relevan menggunakan Cosine Similarity.
     *
     * @param Character $character
     * @param string $query
     * @param string $targetContent
     * @param int $topK
     * @return array<BuildKnowledge>
     */
    public function searchSimilar(Character $character, string $query, string $targetContent = 'universal', int $topK = 3): array
    {
        $queryEmbedding = $this->nvidiaService->generateEmbedding($query);
        if (!$queryEmbedding) {
            return [];
        }

        // Ambil knowledge chunks milik karakter ini (pre-filtering cepat)
        $knowledgeChunks = BuildKnowledge::where('character_id', $character->id)
            ->where(function ($q) use ($targetContent) {
                $q->where('target_content', $targetContent)
                  ->orWhere('target_content', 'universal');
            })
            ->get();

        $scoredChunks = [];

        foreach ($knowledgeChunks as $chunk) {
            $chunkEmbedding = $chunk->embedding;
            if (is_array($chunkEmbedding) && !empty($chunkEmbedding)) {
                $score = $this->similarityEngine->cosineSimilarity($queryEmbedding, $chunkEmbedding);
                $scoredChunks[] = [
                    'score' => $score,
                    'chunk' => $chunk,
                ];
            }
        }

        // Urutkan dari score tertinggi ke terendah
        usort($scoredChunks, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice(array_column($scoredChunks, 'chunk'), 0, $topK);
    }
}

<?php

namespace App\Services\Rag;

class SimilarityEngine
{
    /**
     * Menghitung Cosine Similarity antara dua vektor float.
     * Nilai berkisar antara -1 hingga 1 (semakin mendekati 1, semakin mirip).
     *
     * @param array<float> $vecA
     * @param array<float> $vecB
     * @return float
     */
    public function cosineSimilarity(array $vecA, array $vecB): float
    {
        $count = count($vecA);
        if ($count === 0 || $count !== count($vecB)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] * $vecA[$i];
            $normB += $vecB[$i] * $vecB[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }
}

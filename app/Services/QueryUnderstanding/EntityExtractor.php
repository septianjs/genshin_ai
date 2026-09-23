<?php

namespace App\Services\QueryUnderstanding;

use App\Models\Character;

class EntityExtractor
{
    public function __construct(
        protected AliasNormalizer $normalizer
    ) {}

    /**
     * Mengekstrak seluruh entitas penting dari teks query bebas pengguna.
     *
     * @param string $query
     * @return array
     */
    public function extract(string $query): array
    {
        $lower = strtolower($query);

        // 1. Ekstraksi Konstelasi (C0 - C6)
        $constellation = 0;
        if (preg_match('/\b[cC]([0-6])\b/', $query, $cMatches)) {
            $constellation = (int) $cMatches[1];
        } elseif (preg_match('/konstelasi\s*([0-6])/i', $query, $cMatches)) {
            $constellation = (int) $cMatches[1];
        }

        // 2. Ekstraksi Target Konten (Abyss, Theater, Overworld, Boss)
        $contentMode = 'abyss'; // default
        foreach (config('genshin_aliases.content_modes', []) as $term => $mode) {
            if (str_contains($lower, $term)) {
                $contentMode = $mode;
                break;
            }
        }

        // 3. Ekstraksi Karakter yang Terdeteksi dalam Query
        $detectedCharacters = [];
        $knownAliases = config('genshin_aliases.characters', []);

        // Cek alias komunitas terlebih dahulu
        foreach ($knownAliases as $alias => $officialSlug) {
            if (preg_match('/\b' . preg_quote($alias, '/') . '\b/i', $query)) {
                $detectedCharacters[] = $officialSlug;
            }
        }

        // Cek database karakter lokal yang sudah ada
        $allSlugs = Character::pluck('slug')->toArray();
        foreach ($allSlugs as $slug) {
            $slugClean = str_replace('-', ' ', $slug);
            if (preg_match('/\b' . preg_quote($slug, '/') . '\b/i', $query) ||
                preg_match('/\b' . preg_quote($slugClean, '/') . '\b/i', $query)) {
                $detectedCharacters[] = $slug;
            }
        }

        $detectedCharacters = array_values(array_unique($detectedCharacters));

        // Karakter pertama adalah target utama, sisanya adalah rekan tim
        $targetCharacter = !empty($detectedCharacters) ? $detectedCharacters[0] : null;
        $team = array_slice($detectedCharacters, 1, 3);

        return [
            'raw_query' => $query,
            'target_character' => $targetCharacter,
            'constellation' => $constellation,
            'team' => $team,
            'content_mode' => $contentMode,
            'all_detected_characters' => $detectedCharacters,
        ];
    }
}

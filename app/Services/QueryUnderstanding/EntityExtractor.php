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
            if (preg_match('/\b'.preg_quote($alias, '/').'\b/i', $query)) {
                $detectedCharacters[] = $officialSlug;
            }
        }

        // Cek database karakter lokal yang sudah ada
        $allSlugs = Character::pluck('slug')->toArray();
        foreach ($allSlugs as $slug) {
            $slugClean = str_replace('-', ' ', $slug);
            if (preg_match('/\b'.preg_quote($slug, '/').'\b/i', $query) ||
                preg_match('/\b'.preg_quote($slugClean, '/').'\b/i', $query)) {
                $detectedCharacters[] = $slug;
            }
        }

        $detectedCharacters = array_values(array_unique($detectedCharacters));

        // Karakter pertama adalah target utama, sisanya adalah rekan tim
        $targetCharacter = ! empty($detectedCharacters) ? $detectedCharacters[0] : null;
        $team = array_slice($detectedCharacters, 1, 3);
        $role = preg_match('/\b(main\s*dps|sub\s*dps|dps|support|healer|shielder|buffer)\b/i', $query, $roleMatch)
            ? strtolower(preg_replace('/\s+/', ' ', $roleMatch[1]))
            : null;
        $topic = match (true) {
            preg_match('/\b(artefak|artifact|artifacts)\b/i', $query) === 1
                && preg_match('/\b(main\s*stat|sub\s*stat|substat|stats?|crit|er)\b/i', $query) === 1 => 'artifact_stats',
            preg_match('/\b(artefak|artifact|artifacts)\b/i', $query) === 1 => 'artifact_set',
            preg_match('/\b(senjata|weapon|weapons)\b/i', $query) === 1
                && preg_match('/\b(stats?|passive|pasif|base\s*attack|substat)\b/i', $query) === 1 => 'weapon_stats',
            preg_match('/\b(senjata|weapon|weapons)\b/i', $query) === 1 => 'weapon_recommendation',
            preg_match('/\b(tim|team|sinergi|cocok)\b/i', $query) === 1 => 'team_synergy',
            preg_match('/\b(rotasi|rotation|combo|kombo)\b/i', $query) === 1 => 'rotation',
            preg_match('/\b(vaporize|vape|melt|overload|superconduct|electro-charged|swirl|crystallize|bloom|hyperbloom|burgeon|burning|shatter|reaction|reaksi)\b/i', $query) === 1 => 'reaction',
            preg_match('/\b(mekanik|mechanic|kemampuan|ability|talent|skill|elemental\s+mastery)\b/i', $query) === 1 => 'mechanics',
            default => null,
        };

        return [
            'raw_query' => $query,
            'target_character' => $targetCharacter,
            'role' => $role,
            'topic' => $topic,
            'constellation' => $constellation,
            'team' => $team,
            'content_mode' => $contentMode,
            'all_detected_characters' => $detectedCharacters,
        ];
    }
}

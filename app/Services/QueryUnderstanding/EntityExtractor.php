<?php

namespace App\Services\QueryUnderstanding;

use App\Models\Character;
use Illuminate\Support\Collection;

class EntityExtractor
{
    public function __construct(
        protected AliasNormalizer $normalizer
    ) {}

    /**
     * Mengekstrak seluruh entitas penting dari query user.
     *
     * Prinsip:
     * - Karakter yang disebut user diprioritaskan.
     * - Nama resmi, slug, alias, dan typo ringan didukung.
     * - Typo yang terlalu jauh tidak boleh dipaksa menjadi karakter lain.
     * - Tidak pernah menentukan karakter berdasarkan conversation sebelumnya.
     */
    public function extract(string $query): array
    {
        $query = trim($query);
        $lower = mb_strtolower($query);

        /*
         * ============================================================
         * 1. CONSTELLATION
         * ============================================================
         */
        $constellation = 0;

        if (
            preg_match(
                '/\b[cC]\s*\(\s*([0-6])\s*\)\b/u',
                $query,
                $matches
            )
        ) {
            $constellation = (int) $matches[1];
        } elseif (
            preg_match(
                '/\b(?:konstelasi|constellation)\s*([0-6])\b/iu',
                $query,
                $matches
            )
        ) {
            $constellation = (int) $matches[1];
        }

        /*
         * ============================================================
         * 2. CONTENT MODE
         * ============================================================
         */
        $contentMode = 'abyss';

        foreach (
            config('genshin_aliases.content_modes', [])
            as $term => $mode
        ) {
            if ($this->containsPhrase($lower, $term)) {
                $contentMode = $mode;
                break;
            }
        }

        /*
         * ============================================================
         * 3. CHARACTER RESOLUTION
         * ============================================================
         *
         * Karakter hanya berasal dari query saat ini.
         * Tidak ada fallback ke conversation sebelumnya.
         */
        $characterResolution = $this->resolveCharacters($query);

        $detectedCharacters =
            $characterResolution['characters'];

        $targetCharacter =
            $characterResolution['target_character'];

        /*
         * Karakter setelah target dianggap sebagai teammate.
         */
        $team = array_slice(
            $detectedCharacters,
            1,
            3
        );

        /*
         * ============================================================
         * 4. ROLE
         * ============================================================
         */
        $role = null;

        if (
            preg_match(
                '/\b(main\s+dps|main\s+damage|sub\s+dps|sub\s+damage|dps|support|healer|shielder|buffer|driver)\b/iu',
                $query,
                $roleMatch
            )
        ) {
            $role = mb_strtolower(
                preg_replace(
                    '/\s+/',
                    ' ',
                    trim($roleMatch[1])
                )
            );
        }

        /*
         * ============================================================
         * 5. TOPIC
         * ============================================================
         */
        $topic = $this->detectTopic($query);

        /*
         * ============================================================
         * 6. ENTITY TYPE
         * ============================================================
         */
        $entityType = $this->detectEntityType(
            $query,
            $targetCharacter,
            $topic
        );

        /*
         * ============================================================
         * 7. RESULT
         * ============================================================
         */
        return [
            'raw_query' => $query,

            'target_character' => $targetCharacter,

            'character_detected' =>
                $targetCharacter !== null,

            'character_resolution_confidence' =>
                $characterResolution['confidence'],

            'character_resolution_method' =>
                $characterResolution['method'],

            'all_detected_characters' =>
                $detectedCharacters,

            'team' => $team,

            'role' => $role,

            'topic' => $topic,

            'entity_type' => $entityType,

            'constellation' => $constellation,

            'content_mode' => $contentMode,

            'unresolved_character_candidates' =>
                $characterResolution['unresolved_candidates'],
        ];
    }

    /**
     * ============================================================
     * CHARACTER RESOLUTION
     * ============================================================
     *
     * Urutan:
     *
     * 1. Alias exact
     * 2. Official slug exact
     * 3. Official name exact
     * 4. Normalized slug/name
     * 5. Fuzzy typo
     *
     * Fuzzy tidak boleh agresif.
     */
    protected function resolveCharacters(string $query): array
    {
        $lower = mb_strtolower($query);

        /*
         * Ambil karakter dari database.
         */
        $characters = Character::query()
            ->orderBy('name')
            ->get([
                'id',
                'slug',
                'name',
                'patch_version',
            ]);

        if ($characters->isEmpty()) {
            return [
                'characters' => [],
                'target_character' => null,
                'confidence' => 0.0,
                'method' => 'none',
                'unresolved_candidates' => [],
            ];
        }

        $matches = [];

        /*
         * ============================================================
         * A. ALIAS EXACT
         * ============================================================
         */
        $aliases = config(
            'genshin_aliases.characters',
            []
        );

        foreach ($aliases as $alias => $officialSlug) {
            $alias = mb_strtolower(trim($alias));

            if ($alias === '') {
                continue;
            }

            if (! $this->containsPhrase($lower, $alias)) {
                continue;
            }

            $character = $characters->first(
                fn (Character $item) =>
                    mb_strtolower((string) $item->slug) ===
                    mb_strtolower((string) $officialSlug)
            );

            if ($character === null) {
                continue;
            }

            $matches[] = [
                'character' => $character,
                'position' => $this->findPhrasePosition(
                    $lower,
                    $alias
                ),
                'confidence' => 1.0,
                'method' => 'alias_exact',
                'matched_text' => $alias,
            ];
        }

        /*
         * ============================================================
         * B. OFFICIAL NAME / SLUG EXACT
         * ============================================================
         */
        foreach ($characters as $character) {
            $slug = mb_strtolower(
                trim((string) $character->slug)
            );

            $name = mb_strtolower(
                trim((string) $character->name)
            );

            /*
             * Official name.
             */
            if (
                $name !== ''
                && $this->containsPhrase($lower, $name)
            ) {
                $matches[] = [
                    'character' => $character,
                    'position' => $this->findPhrasePosition(
                        $lower,
                        $name
                    ),
                    'confidence' => 1.0,
                    'method' => 'name_exact',
                    'matched_text' => $name,
                ];
            }

            /*
             * Official slug.
             */
            if (
                $slug !== ''
                && $this->containsPhrase($lower, $slug)
            ) {
                $matches[] = [
                    'character' => $character,
                    'position' => $this->findPhrasePosition(
                        $lower,
                        $slug
                    ),
                    'confidence' => 1.0,
                    'method' => 'slug_exact',
                    'matched_text' => $slug,
                ];
            }

            /*
             * Slug dengan "-" dianggap sebagai spasi.
             *
             * Contoh:
             * hu-tao -> hu tao
             */
            $slugWithSpaces = str_replace(
                '-',
                ' ',
                $slug
            );

            if (
                $slugWithSpaces !== $slug
                && $this->containsPhrase(
                    $lower,
                    $slugWithSpaces
                )
            ) {
                $matches[] = [
                    'character' => $character,
                    'position' => $this->findPhrasePosition(
                        $lower,
                        $slugWithSpaces
                    ),
                    'confidence' => 1.0,
                    'method' => 'slug_normalized_exact',
                    'matched_text' => $slugWithSpaces,
                ];
            }
        }

        /*
         * ============================================================
         * C. FUZZY TYPO MATCH
         * ============================================================
         */
        $fuzzyMatches = $this->resolveFuzzyCharacters(
            $query,
            $characters,
            $matches
        );

        $matches = array_merge(
            $matches,
            $fuzzyMatches['matches']
        );

        /*
         * ============================================================
         * D. DEDUPLICATE
         * ============================================================
         */
        $unique = [];

        foreach ($matches as $match) {
            /** @var Character $character */
            $character = $match['character'];

            $key = mb_strtolower(
                (string) $character->slug
            );

            if (
                ! isset($unique[$key])
                || $match['confidence'] >
                    $unique[$key]['confidence']
            ) {
                $unique[$key] = $match;
            }
        }

        $matches = array_values($unique);

        /*
         * ============================================================
         * E. SORT
         * ============================================================
         *
         * Karakter yang muncul lebih dahulu dalam query
         * menjadi target utama.
         */
        usort(
            $matches,
            function (array $a, array $b): int {
                $positionCompare =
                    ($a['position'] ?? PHP_INT_MAX)
                    <=>
                    ($b['position'] ?? PHP_INT_MAX);

                if ($positionCompare !== 0) {
                    return $positionCompare;
                }

                return
                    $b['confidence']
                    <=>
                    $a['confidence'];
            }
        );

        /*
         * ============================================================
         * F. EXTRACT VALID CHARACTERS
         * ============================================================
         */
        $detectedCharacters = [];

        foreach ($matches as $match) {
            $slug = (string) $match['character']->slug;

            if (! in_array(
                $slug,
                $detectedCharacters,
                true
            )) {
                $detectedCharacters[] = $slug;
            }

            if (count($detectedCharacters) >= 4) {
                break;
            }
        }

        /*
         * ============================================================
         * G. TARGET CHARACTER
         * ============================================================
         *
         * Exact match selalu diterima.
         *
         * Fuzzy match:
         * - harus melewati threshold
         * - harus cukup berbeda dari kandidat kedua
         * - harus memenuhi pemeriksaan prefix untuk typo panjang
         */
        $bestMatch = $matches[0] ?? null;

        $targetCharacter = null;
        $confidence = 0.0;
        $method = 'none';

        if ($bestMatch !== null) {
            $candidateConfidence =
                (float) $bestMatch['confidence'];

            $candidateMethod =
                (string) $bestMatch['method'];

            /*
             * Exact match langsung valid.
             */
            if (
                $candidateConfidence >= 1.0
                || in_array(
                    $candidateMethod,
                    [
                        'alias_exact',
                        'name_exact',
                        'slug_exact',
                        'slug_normalized_exact',
                    ],
                    true
                )
            ) {
                $targetCharacter =
                    $bestMatch['character']->slug;

                $confidence =
                    $candidateConfidence;

                $method =
                    $candidateMethod;
            } elseif (
                $candidateMethod === 'fuzzy_typo'
            ) {
                /*
                 * Cari kandidat fuzzy terbaik berikutnya.
                 */
                $secondBestScore = 0.0;

                foreach (
                    array_slice($matches, 1)
                    as $otherMatch
                ) {
                    if (
                        ($otherMatch['method'] ?? null)
                        !== 'fuzzy_typo'
                    ) {
                        continue;
                    }

                    $otherScore =
                        (float) $otherMatch['confidence'];

                    if (
                        $otherScore >
                        $secondBestScore
                    ) {
                        $secondBestScore =
                            $otherScore;
                    }
                }

                $margin =
                    $candidateConfidence -
                    $secondBestScore;

                /*
                 * Untuk nama panjang, threshold 0.60
                 * masih diperbolehkan jika:
                 *
                 * - kandidat cukup dekat
                 * - kandidat kedua tidak terlalu dekat
                 * - prefix cocok
                 *
                 * Ini menangani:
                 *
                 * nuevillter
                 * ->
                 * neuvillette
                 *
                 * yang sebelumnya mendapatkan sekitar
                 * 0.6212.
                 */
                $matchedText =
                    (string) (
                        $bestMatch['matched_text']
                        ?? ''
                    );

                $candidateSlug =
                    (string) (
                        $bestMatch['character']->slug
                        ?? ''
                    );

                $isLongTypo =
                    mb_strlen(
                        $this->normalizeForComparison(
                            $matchedText
                        )
                    ) >= 8;

                $prefixValid =
                    $this->hasStrongPrefixSimilarity(
                        $matchedText,
                        $candidateSlug
                    );

                $minimumScore =
                    $isLongTypo
                        ? 0.60
                        : 0.66;

                /*
                 * Margin minimum.
                 *
                 * Jika kandidat kedua hampir sama kuat,
                 * jangan memilih secara paksa.
                 */
                $minimumMargin =
                    $isLongTypo
                        ? 0.06
                        : 0.08;

                $accepted =
                    $candidateConfidence >=
                        $minimumScore
                    && $prefixValid
                    && (
                        $secondBestScore <= 0.0
                        || $margin >= $minimumMargin
                    );

                if ($accepted) {
                    $targetCharacter =
                        $bestMatch['character']->slug;

                    $confidence =
                        $candidateConfidence;

                    $method =
                        'fuzzy_typo';
                }
            }
        }

        /*
         * ============================================================
         * H. JIKA TARGET FUZZY TIDAK VALID
         * ============================================================
         *
         * Jangan biarkan kandidat fuzzy ambigu masuk ke
         * all_detected_characters.
         *
         * Exact match tetap dipertahankan.
         */
        if (
            $targetCharacter === null
            && $bestMatch !== null
            && $bestMatch['method'] === 'fuzzy_typo'
        ) {
            $detectedCharacters = [];
        }

        /*
         * Jika target berhasil ditemukan melalui fuzzy,
         * pastikan target berada di posisi pertama.
         */
        if ($targetCharacter !== null) {
            $detectedCharacters = array_values(
                array_filter(
                    $detectedCharacters,
                    fn (string $slug) =>
                        $slug !== $targetCharacter
                )
            );

            array_unshift(
                $detectedCharacters,
                $targetCharacter
            );

            $detectedCharacters = array_slice(
                $detectedCharacters,
                0,
                4
            );
        }

        return [
            'characters' =>
                $detectedCharacters,

            'target_character' =>
                $targetCharacter,

            'confidence' =>
                $confidence,

            'method' =>
                $method,

            'unresolved_candidates' =>
                $fuzzyMatches['unresolved_candidates'],
        ];
    }

    /**
     * ============================================================
     * FUZZY CHARACTER RESOLUTION
     * ============================================================
     */
    protected function resolveFuzzyCharacters(
        string $query,
        Collection $characters,
        array $existingMatches
    ): array {
        $queryLower = mb_strtolower($query);

        /*
         * Pecah query menjadi token.
         */
        $tokens = preg_split(
            '/[^\p{L}\p{N}\-]+/u',
            $queryLower,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (! is_array($tokens)) {
            return [
                'matches' => [],
                'unresolved_candidates' => [],
            ];
        }

        /*
         * Jangan fuzzy-match token terlalu pendek.
         */
        $tokens = array_values(
            array_filter(
                $tokens,
                fn (string $token) =>
                    mb_strlen($token) >= 5
            )
        );

        if ($tokens === []) {
            return [
                'matches' => [],
                'unresolved_candidates' => [],
            ];
        }

        /*
         * Karakter yang sudah ditemukan exact tidak perlu
         * diproses fuzzy lagi.
         */
        $existingSlugs = [];

        foreach ($existingMatches as $match) {
            $existingSlugs[] =
                mb_strtolower(
                    (string) $match['character']->slug
                );
        }

        $fuzzyMatches = [];
        $unresolved = [];

        foreach ($tokens as $token) {
            /*
             * Kata umum tidak boleh menjadi kandidat karakter.
             */
            if ($this->isCommonQueryWord($token)) {
                continue;
            }

            /*
             * Simpan semua kandidat untuk token ini.
             */
            $candidateScores = [];

            foreach ($characters as $character) {
                $characterSlug =
                    mb_strtolower(
                        (string) $character->slug
                    );

                if (
                    in_array(
                        $characterSlug,
                        $existingSlugs,
                        true
                    )
                ) {
                    continue;
                }

                $candidateNames = [
                    mb_strtolower(
                        trim((string) $character->name)
                    ),

                    mb_strtolower(
                        trim((string) $character->slug)
                    ),

                    mb_strtolower(
                        str_replace(
                            '-',
                            '',
                            trim((string) $character->slug)
                        )
                    ),

                    mb_strtolower(
                        str_replace(
                            ' ',
                            '',
                            trim((string) $character->name)
                        )
                    ),
                ];

                $bestCharacterScore = 0.0;

                foreach ($candidateNames as $candidate) {
                    $candidate = trim($candidate);

                    if ($candidate === '') {
                        continue;
                    }

                    $score = $this->fuzzyScore(
                        $token,
                        $candidate
                    );

                    if (
                        $score >
                        $bestCharacterScore
                    ) {
                        $bestCharacterScore =
                            $score;
                    }
                }

                if ($bestCharacterScore > 0) {
                    $candidateScores[] = [
                        'character' => $character,
                        'score' => $bestCharacterScore,
                    ];
                }
            }

            /*
             * Urutkan kandidat berdasarkan score tertinggi.
             */
            usort(
                $candidateScores,
                function (
                    array $a,
                    array $b
                ): int {
                    return
                        $b['score']
                        <=>
                        $a['score'];
                }
            );

            $bestCandidate =
                $candidateScores[0] ?? null;

            $secondCandidate =
                $candidateScores[1] ?? null;

            if ($bestCandidate === null) {
                continue;
            }

            $bestCharacter =
                $bestCandidate['character'];

            $bestScore =
                (float) $bestCandidate['score'];

            $secondScore =
                $secondCandidate !== null
                    ? (float) $secondCandidate['score']
                    : 0.0;

            $position =
                $this->findPhrasePosition(
                    $queryLower,
                    $token
                );

            /*
             * Candidate cukup kuat untuk menjadi fuzzy match.
             *
             * Threshold dasar dibuat 0.60 untuk nama panjang,
             * karena typo seperti nuevillter dapat berada
             * di sekitar 0.62.
             */
            $tokenLength =
                mb_strlen(
                    $this->normalizeForComparison($token)
                );

            $minimumScore =
                $tokenLength >= 8
                    ? 0.60
                    : 0.66;

            /*
             * Pastikan token memiliki kemiripan prefix.
             *
             * Ini sangat membantu mencegah:
             *
             * artifact -> karakter acak
             * weapon   -> karakter acak
             * stats    -> karakter acak
             */
            $prefixValid =
                $this->hasStrongPrefixSimilarity(
                    $token,
                    (string) $bestCharacter->slug
                );

            /*
             * Kandidat kedua digunakan untuk mengetahui
             * apakah hasil fuzzy ambigu.
             */
            $margin =
                $bestScore -
                $secondScore;

            $minimumMargin =
                $tokenLength >= 8
                    ? 0.06
                    : 0.08;

            /*
             * Jangan menerima kandidat fuzzy yang terlalu
             * dekat dengan kandidat lain.
             */
            $isStrongEnough =
                $bestScore >= $minimumScore
                && $prefixValid
                && (
                    $secondScore <= 0.0
                    || $margin >= $minimumMargin
                );

            if ($isStrongEnough) {
                $fuzzyMatches[] = [
                    'character' => $bestCharacter,
                    'position' => $position,
                    'confidence' => $bestScore,
                    'method' => 'fuzzy_typo',
                    'matched_text' => $token,
                ];
            } elseif (
                $bestScore >= 0.55
            ) {
                /*
                 * Simpan kandidat yang lumayan dekat sebagai
                 * unresolved untuk debugging.
                 */
                $unresolved[] = [
                    'token' => $token,
                    'candidate' => $bestCharacter->slug,
                    'score' => round(
                        $bestScore,
                        4
                    ),
                ];
            }
        }

        return [
            'matches' =>
                $fuzzyMatches,

            'unresolved_candidates' =>
                $unresolved,
        ];
    }

    /**
     * ============================================================
     * FUZZY SCORE
     * ============================================================
     *
     * Menggabungkan:
     * - Levenshtein
     * - similar_text
     *
     * sehingga typo ringan tetap dapat ditemukan.
     */
    protected function fuzzyScore(
        string $input,
        string $candidate
    ): float {
        $input =
            $this->normalizeForComparison($input);

        $candidate =
            $this->normalizeForComparison($candidate);

        if (
            $input === ''
            || $candidate === ''
        ) {
            return 0.0;
        }

        if ($input === $candidate) {
            return 1.0;
        }

        $maxLength = max(
            mb_strlen($input),
            mb_strlen($candidate)
        );

        if ($maxLength === 0) {
            return 0.0;
        }

        /*
         * Nama yang terlalu berbeda panjangnya biasanya
         * bukan typo dari karakter yang sama.
         */
        $lengthDifference =
            abs(
                mb_strlen($input)
                -
                mb_strlen($candidate)
            );

        /*
         * Untuk nama panjang, toleransi panjang sedikit
         * lebih besar.
         */
        if (
            $lengthDifference >
            max(4, (int) floor($maxLength * 0.45))
        ) {
            return 0.0;
        }

        /*
         * levenshtein PHP bekerja pada byte.
         * Nama karakter Genshin yang digunakan mayoritas ASCII.
         */
        $distance = levenshtein(
            $input,
            $candidate
        );

        $levenshteinScore =
            1 -
            (
                $distance /
                $maxLength
            );

        similar_text(
            $input,
            $candidate,
            $similarityPercent
        );

        $similarity =
            $similarityPercent / 100;

        /*
         * Levenshtein lebih dominan karena lebih stabil
         * untuk typo karakter.
         */
        $score =
            ($levenshteinScore * 0.65)
            +
            ($similarity * 0.35);

        /*
         * Bonus kecil jika prefix kuat.
         */
        if (
            $this->hasStrongPrefixSimilarity(
                $input,
                $candidate
            )
        ) {
            $score += 0.03;
        }

        return max(
            0.0,
            min(
                1.0,
                $score
            )
        );
    }

    /**
     * ============================================================
     * PREFIX SIMILARITY
     * ============================================================
     *
     * Mencegah kata umum berubah menjadi karakter hanya karena
     * skor fuzzy kebetulan cukup tinggi.
     */
    protected function hasStrongPrefixSimilarity(
        string $input,
        string $candidate
    ): bool {
        $input =
            $this->normalizeForComparison($input);

        $candidate =
            $this->normalizeForComparison($candidate);

        if (
            $input === ''
            || $candidate === ''
        ) {
            return false;
        }

        /*
         * Untuk input pendek, gunakan 2 karakter.
         * Untuk input panjang, gunakan 3 karakter.
         */
        $prefixLength =
            min(
                3,
                mb_strlen($input),
                mb_strlen($candidate)
            );

        if ($prefixLength < 2) {
            return false;
        }

        return mb_substr(
            $input,
            0,
            $prefixLength
        ) ===
        mb_substr(
            $candidate,
            0,
            $prefixLength
        );
    }

    /**
     * ============================================================
     * NORMALIZE FOR COMPARISON
     * ============================================================
     */
    protected function normalizeForComparison(
        string $value
    ): string {
        $value = mb_strtolower(
            trim($value)
        );

        $value = str_replace(
            [
                '-',
                '_',
                ' ',
                "'",
                '"',
                '`',
            ],
            '',
            $value
        );

        return $value;
    }

    /**
     * ============================================================
     * CONTAINS PHRASE
     * ============================================================
     *
     * Memastikan phrase benar-benar merupakan kata/phrase,
     * bukan substring sembarang.
     */
    protected function containsPhrase(
        string $haystack,
        string $needle
    ): bool {
        $needle = mb_strtolower(
            trim($needle)
        );

        if ($needle === '') {
            return false;
        }

        /*
         * Untuk phrase seperti:
         *
         * hu-tao
         * hu tao
         *
         * boundary dibuat fleksibel.
         */
        $pattern =
            '/(?<![\p{L}\p{N}])'
            . preg_quote($needle, '/')
            . '(?![\p{L}\p{N}])/iu';

        return preg_match(
            $pattern,
            $haystack
        ) === 1;
    }

    /**
     * ============================================================
     * FIND PHRASE POSITION
     * ============================================================
     */
    protected function findPhrasePosition(
        string $haystack,
        string $needle
    ): int {
        $position = mb_stripos(
            $haystack,
            $needle
        );

        return $position === false
            ? PHP_INT_MAX
            : $position;
    }

    /**
     * ============================================================
     * COMMON QUERY WORD
     * ============================================================
     *
     * Kata-kata ini tidak boleh dianggap nama karakter
     * oleh fuzzy matching.
     */
    protected function isCommonQueryWord(
        string $token
    ): bool {
        $words = [
            'build',
            'buat',
            'buatkan',
            'untuk',
            'dengan',
            'tanpa',
            'pakai',
            'pake',
            'gunakan',
            'kasih',
            'berikan',
            'tolong',
            'rekomendasi',
            'rekomendasikan',
            'terbaik',
            'terbagus',
            'bagaimana',
            'gimana',
            'apakah',
            'siapa',
            'kenapa',
            'mengapa',

            'character',
            'characters',
            'karakter',

            'artifact',
            'artifacts',
            'artefak',

            'weapon',
            'weapons',
            'senjata',

            'stats',
            'statistik',
            'stat',
            'mainstat',
            'substat',

            'passive',
            'pasif',
            'talent',
            'ability',
            'skill',

            'mechanic',
            'mechanics',
            'mekanik',

            'rotation',
            'rotasi',
            'combo',
            'kombo',

            'team',
            'tim',
            'sinergi',
            'party',
            'partai',
            'komposisi',

            'abyss',
            'spiral',
            'theater',
            'theatre',
            'overworld',
            'story',
            'boss',
            'domain',

            'damage',
            'dps',
            'support',
            'healer',
            'shielder',
            'buffer',
            'driver',

            'main',
            'sub',

            'crit',
            'critical',
            'energy',
            'recharge',
            'elemental',
            'reaction',
            'reaksi',

            'vaporize',
            'vape',
            'melt',
            'overload',
            'superconduct',
            'electro',
            'charged',
            'swirl',
            'bloom',
            'hyperbloom',
            'burgeon',
            'burning',
            'shatter',
            'crystallize',

            'constellation',
            'konstelasi',

            'best',
            'good',
            'bagus',
            'terbaik',

            'level',
            'damage',
            'rotation',
            'rotations',
        ];

        return in_array(
            mb_strtolower($token),
            $words,
            true
        );
    }

    /**
     * ============================================================
     * TOPIC
     * ============================================================
     */
    protected function detectTopic(
        string $query
    ): ?string {
        $hasArtifact =
            preg_match(
                '/\b(artefak|artifact|artifacts)\b/iu',
                $query
            ) === 1;

        $hasWeapon =
            preg_match(
                '/\b(senjata|weapon|weapons)\b/iu',
                $query
            ) === 1;

        $hasStats =
            preg_match(
                '/\b(main\s+stat|mainstat|sub\s+stat|substat|stats?|statistik|crit|er|energy\s+recharge|recharge)\b/iu',
                $query
            ) === 1;

        if (
            $hasArtifact
            && $hasStats
        ) {
            return 'artifact_stats';
        }

        if ($hasArtifact) {
            return 'artifact_set';
        }

        if (
            $hasWeapon
            && preg_match(
                '/\b(stats?|statistik|passive|pasif|base\s+attack|base\s+atk|substat)\b/iu',
                $query
            ) === 1
        ) {
            return 'weapon_stats';
        }

        if ($hasWeapon) {
            return 'weapon_recommendation';
        }

        if (
            preg_match(
                '/\b(tim|team|sinergi|komposisi|party|partai|cocok)\b/iu',
                $query
            ) === 1
        ) {
            return 'team_synergy';
        }

        if (
            preg_match(
                '/\b(rotasi|rotation|combo|kombo|urutan|step)\b/iu',
                $query
            ) === 1
        ) {
            return 'rotation';
        }

        if (
            preg_match(
                '/\b(vaporize|vape|melt|overload|superconduct|electro-charged|swirl|crystallize|bloom|hyperbloom|burgeon|burning|shatter|reaction|reaksi)\b/iu',
                $query
            ) === 1
        ) {
            return 'reaction';
        }

        if (
            preg_match(
                '/\b(mekanik|mechanic|kemampuan|ability|talent|skill|elemental\s+mastery)\b/iu',
                $query
            ) === 1
        ) {
            return 'mechanics';
        }

        return null;
    }

    /**
     * ============================================================
     * ENTITY TYPE
     * ============================================================
     */
    protected function detectEntityType(
        string $query,
        ?string $targetCharacter,
        ?string $topic
    ): string {
        /*
         * Artifact lebih spesifik daripada character.
         */
        if (
            in_array(
                $topic,
                [
                    'artifact_stats',
                    'artifact_set',
                ],
                true
            )
        ) {
            return 'artifact';
        }

        /*
         * Weapon lebih spesifik daripada character.
         */
        if (
            in_array(
                $topic,
                [
                    'weapon_stats',
                    'weapon_recommendation',
                ],
                true
            )
        ) {
            return 'weapon';
        }

        /*
         * Jika karakter ditemukan, character menjadi
         * entity utama.
         */
        if ($targetCharacter !== null) {
            return 'character';
        }

        return 'unknown';
    }
}
<?php

namespace App\Services\Ingestion;

use JsonException;

/**
 * Membaca file panduan build (JSON atau Markdown) dari project lain dan
 * mengubahnya menjadi chunk seragam:
 *
 *   ['character' => string|array, 'category' => string, 'title' => ?string,
 *    'content' => string, 'target' => string]
 *
 * Kelas ini tidak menyentuh database. Pencocokan karakter dilakukan oleh command.
 */
class KnowledgeDatasetParser
{
    public const TARGETS = ['abyss', 'theater', 'overworld', 'universal'];

    /** Kategori yang dipahami RAG di project ini. */
    private const CATEGORY_ALIASES = [
        'role_and_reactions' => 'role_and_reactions',
        'role' => 'role_and_reactions',
        'roles' => 'role_and_reactions',
        'peran' => 'role_and_reactions',
        'reactions' => 'role_and_reactions',
        'reaksi' => 'role_and_reactions',

        'weapons_ranking' => 'weapons_ranking',
        'weapon_ranking' => 'weapons_ranking',
        'weapons' => 'weapons_ranking',
        'weapon' => 'weapons_ranking',
        'senjata' => 'weapons_ranking',

        'artifact_priorities' => 'artifact_priorities',
        'artifact_priority' => 'artifact_priorities',
        'artifacts' => 'artifact_priorities',
        'artifact' => 'artifact_priorities',
        'artifact_sets' => 'artifact_priorities',
        'artefak' => 'artifact_priorities',
        'stats' => 'artifact_priorities',
        'main_stats' => 'artifact_priorities',
        'substats' => 'artifact_priorities',

        'er_breakpoints' => 'er_breakpoints',
        'er' => 'er_breakpoints',
        'energy_recharge' => 'er_breakpoints',

        'team_synergies' => 'team_synergies',
        'synergies' => 'team_synergies',
        'synergy' => 'team_synergies',
        'teams' => 'team_synergies',
        'team' => 'team_synergies',
        'team_comps' => 'team_synergies',
        'tim' => 'team_synergies',

        'rotation' => 'rotation',
        'rotations' => 'rotation',
        'rotasi' => 'rotation',

        'character_overview' => 'character_overview',
        'overview' => 'character_overview',
    ];

    /** Key yang berisi data karakter/metadata, bukan panduan. Dilewati tanpa peringatan. */
    private const META_KEYS = [
        'character', 'slug', 'name', 'id', 'title', 'patch', 'patch_version', 'version',
        'vision', 'vision_key', 'element', 'rarity', 'weapon', 'weapon_type', 'description',
        'icon_url', 'target', 'target_content', 'mode', 'content_mode', 'source', 'category',
        'type', 'skilltalents', 'passivetalents', 'constellations', 'constellation_data',
        'skill_data', 'ascension_materials', 'synced_at', 'created_at', 'updated_at',
        'is_validated', 'content', 'text', 'body',
    ];

    /** Key pembungkus: isinya diproses ulang. */
    private const WRAPPER_KEYS = [
        'characters', 'character_guides', 'builds', 'guides', 'knowledge', 'data', 'chunks', 'entries',
    ];

    private array $chunks = [];

    private array $issues = [];

    private string $stem = '';

    /**
     * @return array{chunks: array<int, array>, issues: array{malformed: int, unknown_categories: array<string,int>, errors: array<int,string>}}
     */
    public function parseFile(string $path): array
    {
        $this->chunks = [];
        $this->issues = ['malformed' => 0, 'unknown_categories' => [], 'errors' => []];
        $this->stem = pathinfo($path, PATHINFO_FILENAME);

        $text = @file_get_contents($path);
        if ($text === false) {
            $this->issues['errors'][] = "Tidak bisa membaca file: {$path}";

            return $this->result();
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            try {
                $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->issues['errors'][] = basename($path).': JSON tidak valid ('.$exception->getMessage().')';

                return $this->result();
            }

            $this->expand($data, null);
        } else {
            $this->parseMarkdown($text);
        }

        return $this->result();
    }

    public function categoryFor(string $label): ?string
    {
        $key = $this->normKey($label);

        if (isset(self::CATEGORY_ALIASES[$key])) {
            return self::CATEGORY_ALIASES[$key];
        }

        foreach (explode('_', $key) as $token) {
            if (isset(self::CATEGORY_ALIASES[$token])) {
                return self::CATEGORY_ALIASES[$token];
            }
        }

        return null;
    }

    public function normalizeTarget(?string $raw): string
    {
        $value = strtolower(trim((string) $raw));

        if (in_array($value, self::TARGETS, true)) {
            return $value;
        }

        return match (true) {
            str_contains($value, 'abyss') => 'abyss',
            str_contains($value, 'theater'), str_contains($value, 'theatre') => 'theater',
            str_contains($value, 'overworld'), str_contains($value, 'open world') => 'overworld',
            default => 'universal',
        };
    }

    private function result(): array
    {
        return ['chunks' => $this->chunks, 'issues' => $this->issues];
    }

    /**
     * Menelusuri struktur JSON apa pun secara rekursif.
     */
    private function expand(mixed $node, ?string $hint): void
    {
        if (! is_array($node)) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $item) {
                $this->expand($item, $hint);
            }

            return;
                // Format build_rules.json: character + main_stats/substats_priority/artifact.
        if ($this->isBuildRule($node)) {
            $this->expandBuildRule($node);

            return;
        }
        }

        // Satu chunk lengkap: punya isi + kategori/judul.
        if ($this->hasKey($node, ['content', 'text', 'body'])
            && $this->has($node, ['category', 'type', 'section', 'topic', 'title'])) {
            $chunk = $this->toChunk($node, $hint);
            if ($chunk === null) {
                $this->issues['malformed']++;
            } elseif ($chunk !== false) {
                $this->chunks[] = $chunk;
            }

            return;
        }

        $label = $hint ?? $this->pick($node, ['character', 'slug', 'name', 'id']);

        if ($label === null) {
            $this->expandUnlabelled($node);

            return;
        }

        $this->expandSections($node, $label);
    }

    /**
     * Objek tanpa identitas karakter: bisa peta {karakter: ...}, pembungkus,
     * atau file satu karakter yang namanya ada di nama file.
     */
    private function expandUnlabelled(array $node): void
    {
        $looksLikeSections = false;
        foreach (array_keys($node) as $key) {
            $nk = $this->normKey((string) $key);
            if (! in_array($nk, self::META_KEYS, true)
                && ! in_array($nk, self::WRAPPER_KEYS, true)
                && $this->categoryFor($nk) !== null) {
                $looksLikeSections = true;
                break;
            }
        }

        if ($looksLikeSections && $this->stem !== '') {
            $this->expandSections($node, $this->stem);

            return;
        }

        foreach ($node as $key => $value) {
            $nk = $this->normKey((string) $key);
            if (in_array($nk, self::META_KEYS, true)) {
                continue;
            }

            $this->expand($value, in_array($nk, self::WRAPPER_KEYS, true) ? null : (string) $key);
        }
    }

    private function expandSections(array $node, string $label): void
    {
        $target = $this->pick($node, ['target_content', 'target', 'mode', 'content_mode']);

        foreach ($node as $key => $value) {
            $nk = $this->normKey((string) $key);

            if (in_array($nk, self::WRAPPER_KEYS, true) && is_array($value)) {
                $this->expand($value, $label);

                continue;
            }

            if (in_array($nk, self::META_KEYS, true)) {
                continue;
            }

            $category = $this->categoryFor($nk);
            if ($category === null) {
                $this->issues['unknown_categories'][(string) $key] =
                    ($this->issues['unknown_categories'][(string) $key] ?? 0) + 1;

                continue;
            }

            $content = $this->stringify($value);
            if ($content === '') {
                continue;
            }

            $this->chunks[] = [
                'character' => $label,
                'category' => $category,
                'title' => null,
                'content' => $content,
                'target' => $this->normalizeTarget($target),
            ];
        }
    }
        private function isBuildRule(array $node): bool
    {
        return $this->hasKey($node, ['main_stats', 'substats_priority'])
            && $this->pick($node, ['character', 'slug', 'name']) !== null;
    }

    /**
     * Mengubah satu record build_rules.json menjadi beberapa chunk RAG.
     * Field opsional tambahan: weapons, energy_recharge, team_synergies, rotation.
     */
    private function expandBuildRule(array $rule): void
    {
        $character = (string) $this->pick($rule, ['character', 'slug', 'name']);
        $team = $this->pick($rule, ['team_type', 'team']);
        $role = $this->pick($rule, ['role']);
        $target = $this->normalizeTarget($this->pick($rule, ['target_content', 'target', 'mode']));
        $prefix = $team !== null ? "[{$team}] " : '';

        $artifact = $this->pickRaw($rule, ['artifact', 'artifacts']);
        $sets = is_array($artifact)
            ? $this->listOf($artifact['recommended_sets'] ?? $artifact['sets'] ?? [])
            : $this->listOf($artifact);

        $lines = [];
        if ($sets !== []) {
            $lines[] = 'Set artefak: '.implode(', ', $sets).'.';
        }

        $main = $this->pickRaw($rule, ['main_stats']);
        if (is_array($main)) {
            foreach ($main as $slot => $stats) {
                $stats = $this->listOf($stats);
                if ($stats !== []) {
                    $lines[] = ucfirst((string) $slot).': '.implode(' / ', $stats).'.';
                }
            }
        }

        $subs = $this->listOf($this->pickRaw($rule, ['substats_priority', 'substats']));
        if ($subs !== []) {
            $lines[] = 'Prioritas substat: '.implode(' > ', $subs).'.';
        }

        $notes = $this->pick($rule, ['artifact_notes', 'notes']);
        if ($notes !== null) {
            $lines[] = $notes;
        }

        if ($lines !== []) {
            $this->addChunk($character, 'artifact_priorities', $prefix.implode(' ', $lines), $target);
        }

        $roleLines = [];
        if ($role !== null) {
            $roleLines[] = "Peran: {$role}.";
        }

        $talents = $this->listOf($this->pickRaw($rule, ['talent_priority']));
        if ($talents !== []) {
            $roleLines[] = 'Prioritas talent: '.implode(' > ', $talents).'.';
        }

        if ($roleLines !== []) {
            $this->addChunk($character, 'role_and_reactions', $prefix.implode(' ', $roleLines), $target);
        }

        $optional = [
            'weapons_ranking' => ['weapons', 'weapon_recommendations'],
            'er_breakpoints' => ['energy_recharge', 'er_breakpoints', 'er'],
            'team_synergies' => ['team_synergies', 'synergies', 'teams'],
            'rotation' => ['rotation'],
        ];

        foreach ($optional as $category => $keys) {
            $text = $this->stringify($this->pickRaw($rule, $keys));
            if ($text !== '') {
                $this->addChunk($character, $category, $prefix.$text, $target);
            }
        }
    }

    private function addChunk(string $character, string $category, string $content, string $target, ?string $title = null): void
    {
        $this->chunks[] = [
            'character' => $character,
            'category' => $category,
            'title' => $title,
            'content' => $content,
            'target' => $target,
        ];
    }

    /** @return array<int, string> */
    private function listOf(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_scalar($value)) {
            return [trim((string) $value)];
        }

        $items = [];
        foreach ((array) $value as $item) {
            foreach ($this->listOf($item) as $text) {
                if ($text !== '') {
                    $items[] = $text;
                }
            }
        }

        return $items;
    }
    /**
     * @return array|null|false array = chunk valid, null = rusak, false = kategori tidak dikenal (sudah dicatat)
     */
    private function toChunk(array $item, ?string $hint): array|null|false
    {
        $content = $this->stringify($this->pickRaw($item, ['content', 'text', 'body']));
        $title = $this->pick($item, ['title', 'name']);
        $categoryRaw = $this->pick($item, ['category', 'type', 'section', 'topic']) ?? $title;

        if ($content === '' || $categoryRaw === null) {
            return null;
        }

        $category = $this->categoryFor($categoryRaw);
        if ($category === null) {
            $this->issues['unknown_categories'][$categoryRaw] =
                ($this->issues['unknown_categories'][$categoryRaw] ?? 0) + 1;

            return false;
        }

        return [
            'character' => $hint ?? $this->pick($item, ['character', 'slug', 'id']) ?? $this->stem,
            'category' => $category,
            'title' => $title,
            'content' => $content,
            'target' => $this->normalizeTarget($this->pick($item, ['target_content', 'target', 'mode', 'content_mode'])),
        ];
    }

    private function parseMarkdown(string $text): void
    {
        $meta = [];
        if (preg_match('/\A---\R(.*?)\R---\R?/s', $text, $front)) {
            foreach (preg_split('/\R/', $front[1]) as $line) {
                if (preg_match('/^\s*([A-Za-z_ -]+?)\s*:\s*(.+?)\s*$/', $line, $kv)) {
                    $meta[$this->normKey($kv[1])] = trim($kv[2], " \t\"'");
                }
            }
            $text = substr($text, strlen($front[0]));
        }

        $h1 = preg_match('/^#\s+(.+)$/m', $text, $heading) ? trim($heading[1]) : null;

        $candidates = array_values(array_filter([
            $meta['character'] ?? $meta['slug'] ?? $meta['name'] ?? null,
            $this->stem,
            $h1,
        ], fn ($value) => $value !== null && $value !== ''));

        $target = $this->normalizeTarget($meta['target'] ?? $meta['target_content'] ?? $meta['mode'] ?? null);

        $parts = preg_split('/^#{2,3}\s+(.+)$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 1; $i < count($parts); $i += 2) {
            $headingText = trim($parts[$i]);
            $body = trim($parts[$i + 1] ?? '');
            if ($body === '') {
                continue;
            }

            $category = $this->categoryFor($headingText);
            if ($category === null) {
                $this->issues['unknown_categories'][$headingText] =
                    ($this->issues['unknown_categories'][$headingText] ?? 0) + 1;

                continue;
            }

            $this->chunks[] = [
                'character' => $candidates,
                'category' => $category,
                'title' => $headingText,
                'content' => $body,
                'target' => $target,
            ];
        }
    }

    /** Nilai skalar pertama yang cocok, sebagai string; null jika tidak ada. */
    private function pick(array $item, array $keys): ?string
    {
        $value = $this->pickRaw($item, $keys);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function pickRaw(array $item, array $keys): mixed
    {
        foreach ($item as $key => $value) {
            if (in_array($this->normKey((string) $key), $keys, true)) {
                return $value;
            }
        }

        return null;
    }

    private function hasKey(array $item, array $keys): bool
    {
        foreach (array_keys($item) as $key) {
            if (in_array($this->normKey((string) $key), $keys, true)) {
                return true;
            }
        }

        return false;
    }

    private function has(array $item, array $keys): bool
    {
        $value = $this->pickRaw($item, $keys);

        return $value !== null && $value !== '' && $value !== [];
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return '';
        }

        $lines = [];
        foreach ($value as $key => $item) {
            $text = is_array($item) ? $this->inline($item) : trim((string) $item);
            if ($text === '') {
                continue;
            }

            $lines[] = array_is_list($value) ? "- {$text}" : "- {$key}: {$text}";
        }

        return implode("\n", $lines);
    }

    private function inline(array $value): string
    {
        $parts = [];
        foreach ($value as $key => $item) {
            $text = is_array($item) ? $this->inline($item) : trim((string) $item);
            if ($text === '') {
                continue;
            }

            $parts[] = is_int($key) ? $text : "{$key}: {$text}";
        }

        return implode('; ', $parts);
    }

    private function normKey(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($value)), '_');
    }
}
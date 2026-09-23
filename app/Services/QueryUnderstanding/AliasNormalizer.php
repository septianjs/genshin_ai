<?php

namespace App\Services\QueryUnderstanding;

class AliasNormalizer
{
    protected array $characterAliases;
    protected array $artifactAliases;
    protected array $contentModeAliases;

    public function __construct()
    {
        $this->characterAliases = config('genshin_aliases.characters', []);
        $this->artifactAliases = config('genshin_aliases.artifacts', []);
        $this->contentModeAliases = config('genshin_aliases.content_modes', []);
    }

    /**
     * Menormalisasi sebutan nama karakter menjadi slug resmi.
     */
    public function normalizeCharacter(string $nameOrSlug): string
    {
        $cleaned = strtolower(trim($nameOrSlug));
        return $this->characterAliases[$cleaned] ?? str_replace(' ', '-', $cleaned);
    }

    /**
     * Menormalisasi sebutan artefak.
     */
    public function normalizeArtifact(string $artifactName): string
    {
        $cleaned = strtolower(trim($artifactName));
        return $this->artifactAliases[$cleaned] ?? str_replace(' ', '-', $cleaned);
    }

    /**
     * Menormalisasi mode konten.
     */
    public function normalizeContentMode(string $mode): string
    {
        $cleaned = strtolower(trim($mode));
        return $this->contentModeAliases[$cleaned] ?? 'abyss';
    }
}

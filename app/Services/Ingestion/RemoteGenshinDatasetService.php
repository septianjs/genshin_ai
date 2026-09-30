<?php

namespace App\Services\Ingestion;

use App\Models\Character;
use App\Models\GenshinArtifact;
use App\Models\GenshinWeapon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class RemoteGenshinDatasetService
{
    private const CHARACTER_URL =
        'https://raw.githubusercontent.com/septianjs/genshin_build/main/data/raw/characters.json';

    private const ARTIFACT_URL =
        'https://raw.githubusercontent.com/septianjs/genshin_build/main/data/raw/artifacts.json';

    private const WEAPON_URL =
        'https://raw.githubusercontent.com/septianjs/genshin_build/main/data/raw/weapons.json';

    /**
     * Download JSON dari GitHub.
     */
    protected function downloadJson(string $url): array
    {
        $response = Http::timeout(60)
            ->connectTimeout(20)
            ->retry(
                3,
                1000,
                throw: false
            )
            ->acceptJson()
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Gagal mengambil dataset:\n"
                . "URL: {$url}\n"
                . "HTTP: {$response->status()}\n"
                . "Response: "
                . mb_substr(
                    $response->body(),
                    0,
                    500
                )
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException(
                "Dataset bukan JSON array/object yang valid: {$url}"
            );
        }

        return $json;
    }

    /**
     * Normalisasi root dataset.
     *
     * Mendukung:
     *
     * [
     *   {...},
     *   {...}
     * ]
     *
     * maupun:
     *
     * {
     *   "data": [...]
     * }
     *
     * maupun object keyed:
     *
     * {
     *   "albedo": {...},
     *   "kazuha": {...}
     * }
     */
    protected function normalizeCollection(array $data): array
    {
        /*
         * Root list.
         */
        if (array_is_list($data)) {
            return $data;
        }

        /*
         * Dataset dibungkus dengan "data".
         */
        foreach ([
            'data',
            'items',
            'characters',
            'artifacts',
            'weapons',
        ] as $key) {
            if (
                isset($data[$key])
                && is_array($data[$key])
            ) {
                return $this->normalizeCollection(
                    $data[$key]
                );
            }
        }

        /*
         * Object keyed.
         */
        $result = [];

        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (! isset($value['name'])) {
                $value['name'] = $key;
            }

            $result[] = $value;
        }

        return $result;
    }

    /**
     * Import seluruh dataset.
     */
    public function sync(
        string $patchVersion = '7.0',
        bool $dryRun = false,
        bool $onlyCharacters = false,
        bool $onlyArtifacts = false,
        bool $onlyWeapons = false
    ): array {
        $result = [
            'characters' => [
                'downloaded' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
            ],

            'artifacts' => [
                'downloaded' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
            ],

            'weapons' => [
                'downloaded' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
            ],
        ];

        /*
         * Kalau --only tidak diberikan,
         * import semuanya.
         */
        $importAll =
            ! $onlyCharacters
            && ! $onlyArtifacts
            && ! $onlyWeapons;

        /*
         * CHARACTER
         */
        if ($importAll || $onlyCharacters) {
            $characters =
                $this->normalizeCollection(
                    $this->downloadJson(
                        self::CHARACTER_URL
                    )
                );

            $result['characters']['downloaded'] =
                count($characters);

            foreach ($characters as $index => $data) {
                try {
                    $this->importCharacter(
                        $data,
                        $patchVersion,
                        $dryRun
                    );

                    $result['characters']['imported']++;
                } catch (\Throwable $e) {
                    $result['characters']['failed']++;

                    Log::error(
                        'Character import failed',
                        [
                            'index' => $index,
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }
        }

        /*
         * ARTIFACT
         */
        if ($importAll || $onlyArtifacts) {
            $artifacts =
                $this->normalizeCollection(
                    $this->downloadJson(
                        self::ARTIFACT_URL
                    )
                );

            $result['artifacts']['downloaded'] =
                count($artifacts);

            foreach ($artifacts as $index => $data) {
                try {
                    $this->importArtifact(
                        $data,
                        $patchVersion,
                        $dryRun
                    );

                    $result['artifacts']['imported']++;
                } catch (\Throwable $e) {
                    $result['artifacts']['failed']++;

                    Log::error(
                        'Artifact import failed',
                        [
                            'index' => $index,
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }
        }

        /*
         * WEAPON
         */
        if ($importAll || $onlyWeapons) {
            $weapons =
                $this->normalizeCollection(
                    $this->downloadJson(
                        self::WEAPON_URL
                    )
                );

            $result['weapons']['downloaded'] =
                count($weapons);

            foreach ($weapons as $index => $data) {
                try {
                    $this->importWeapon(
                        $data,
                        $patchVersion,
                        $dryRun
                    );

                    $result['weapons']['imported']++;
                } catch (\Throwable $e) {
                    $result['weapons']['failed']++;

                    Log::error(
                        'Weapon import failed',
                        [
                            'index' => $index,
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }
        }

        return $result;
    }

    /**
     * ============================================================
     * CHARACTER
     * ============================================================
     */
    protected function importCharacter(
        array $data,
        string $patchVersion,
        bool $dryRun
    ): void {
        $name =
            $this->stringValue(
                $data['name']
                ?? $data['character']
                ?? null
            );

        if ($name === null) {
            throw new RuntimeException(
                'Character tidak memiliki name.'
            );
        }

        /*
         * Dataset lama mungkin memiliki id.
         * Jika ada, prioritaskan id.
         */
        $slug =
            $this->makeSlug(
                $data['id']
                ?? $name
            );

        if ($dryRun) {
            return;
        }

        Character::updateOrCreate(
            [
                'slug' => $slug,
            ],
            [
                'name' =>
                    $name,

                'title' =>
                    $this->stringValue(
                        $data['title']
                        ?? null
                    ),

                'vision' =>
                    $this->stringValue(
                        $data['vision']
                        ?? $data['element']
                        ?? 'Unknown'
                    ),

                'weapon_type' =>
                    $this->stringValue(
                        $data['weapon']
                        ?? $data['weapon_type']
                        ?? 'Unknown'
                    ),

                'rarity' =>
                    $this->integerValue(
                        $data['rarity']
                        ?? 5,
                        5
                    ),

                'description' =>
                    $this->stringValue(
                        $data['description']
                        ?? null
                    ),

                'skill_data' =>
                    $data['skillTalents']
                    ?? $data['skills']
                    ?? null,

                'constellation_data' =>
                    $data['constellations']
                    ?? null,

                'ascension_materials' =>
                    $data['ascensionMaterials']
                    ?? $data['ascension_materials']
                    ?? null,

                'icon_url' =>
                    $this->stringValue(
                        $data['icon']
                        ?? $data['icon_url']
                        ?? null
                    ),

                'patch_version' =>
                    $patchVersion,

                'is_validated' =>
                    true,

                'synced_at' =>
                    now(),
            ]
        );
    }

    /**
     * ============================================================
     * ARTIFACT
     * ============================================================
     */
    protected function importArtifact(
        array $data,
        string $patchVersion,
        bool $dryRun
    ): void {
        $name =
            $this->stringValue(
                $data['name']
                ?? $data['setName']
                ?? $data['artifact']
                ?? null
            );

        if ($name === null) {
            throw new RuntimeException(
                'Artifact tidak memiliki name.'
            );
        }

        $slug =
            $this->makeSlug(
                $data['id']
                ?? $data['key']
                ?? $name
            );

        /*
         * Dataset bisa menggunakan beberapa nama field.
         */
        $twoPiece =
            $this->extractBonus(
                $data,
                [
                    '2-piece',
                    '2_piece',
                    'two_piece',
                    'twoPiece',
                    'bonus_2',
                ]
            );

        $fourPiece =
            $this->extractBonus(
                $data,
                [
                    '4-piece',
                    '4_piece',
                    'four_piece',
                    'fourPiece',
                    'bonus_4',
                ]
            );

        $rarity =
            $this->stringValue(
                $data['rarity']
                ?? $data['max_rarity']
                ?? null
            );

        if ($dryRun) {
            return;
        }

        GenshinArtifact::updateOrCreate(
            [
                'slug' => $slug,
            ],
            [
                'name' =>
                    $name,

                'rarity' =>
                    $rarity,

                'two_piece_bonus' =>
                    $twoPiece,

                'four_piece_bonus' =>
                    $fourPiece,

                'raw_data' =>
                    $data,

                'source' =>
                    self::ARTIFACT_URL,

                'patch_version' =>
                    $patchVersion,

                'is_validated' =>
                    true,
            ]
        );
    }

    /**
     * ============================================================
     * WEAPON
     * ============================================================
     */
    protected function importWeapon(
        array $data,
        string $patchVersion,
        bool $dryRun
    ): void {
        $name =
            $this->stringValue(
                $data['name']
                ?? $data['weapon']
                ?? null
            );

        if ($name === null) {
            throw new RuntimeException(
                'Weapon tidak memiliki name.'
            );
        }

        $slug =
            $this->makeSlug(
                $data['id']
                ?? $data['key']
                ?? $name
            );

        $weaponType =
            $this->stringValue(
                $data['type']
                ?? $data['weapon_type']
                ?? $data['weaponType']
                ?? null
            );

        $rarity =
            $this->integerValue(
                $data['rarity']
                ?? null,
                null
            );

        $baseAttack =
            $this->stringValue(
                $data['baseAttack']
                ?? $data['base_attack']
                ?? $data['atk']
                ?? null
            );

        $secondaryStat =
            $this->stringValue(
                $data['secondary']
                ?? $data['secondaryStat']
                ?? $data['subStat']
                ?? $data['sub_stat']
                ?? null
            );

        $passiveName =
            $this->stringValue(
                $data['passiveName']
                ?? $data['passive_name']
                ?? $data['passive']['name']
                ?? null
            );

        $passiveDescription =
            $this->stringValue(
                $data['passiveDescription']
                ?? $data['passive_description']
                ?? $data['passive']['description']
                ?? null
            );

        if ($dryRun) {
            return;
        }

        GenshinWeapon::updateOrCreate(
            [
                'slug' => $slug,
            ],
            [
                'name' =>
                    $name,

                'weapon_type' =>
                    $weaponType,

                'rarity' =>
                    $rarity,

                'base_attack' =>
                    $baseAttack,

                'secondary_stat' =>
                    $secondaryStat,

                'passive_name' =>
                    $passiveName,

                'passive_description' =>
                    $passiveDescription,

                'raw_data' =>
                    $data,

                'source' =>
                    self::WEAPON_URL,

                'patch_version' =>
                    $patchVersion,

                'is_validated' =>
                    true,
            ]
        );
    }

    /**
     * ============================================================
     * HELPERS
     * ============================================================
     */

    protected function makeSlug(
        mixed $value
    ): string {
        $value =
            is_string($value)
                ? $value
                : (string) $value;

        $slug =
            Str::slug(
                trim($value)
            );

        if ($slug === '') {
            throw new RuntimeException(
                'Tidak dapat membuat slug.'
            );
        }

        return $slug;
    }

    protected function stringValue(
        mixed $value
    ): ?string {
        if (
            $value === null
            || is_array($value)
            || is_object($value)
        ) {
            return null;
        }

        $value =
            trim(
                (string) $value
            );

        return $value === ''
            ? null
            : $value;
    }

    protected function integerValue(
        mixed $value,
        ?int $default = null
    ): ?int {
        if (
            $value === null
            || $value === ''
        ) {
            return $default;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        /*
         * Contoh:
         * "5★"
         */
        if (
            preg_match(
                '/(\d+)/',
                (string) $value,
                $matches
            )
        ) {
            return (int) $matches[1];
        }

        return $default;
    }

    protected function extractBonus(
        array $data,
        array $keys
    ): ?string {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (is_array($value)) {
                return collect($value)
                    ->map(
                        fn ($item) =>
                            is_scalar($item)
                                ? (string) $item
                                : json_encode(
                                    $item,
                                    JSON_UNESCAPED_UNICODE
                                )
                    )
                    ->implode("\n");
            }

            return $this->stringValue($value);
        }

        return null;
    }
}
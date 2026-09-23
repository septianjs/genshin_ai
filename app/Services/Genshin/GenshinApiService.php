<?php

namespace App\Services\Genshin;

use App\Models\Character;
use App\Services\Ingestion\GameDataValidator;
use App\Services\Ingestion\ThirdPartyGenshinClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class GenshinApiService
{
    public function __construct(
        protected ThirdPartyGenshinClient $client,
        protected GameDataValidator $validator
    ) {}

    /**
     * Mengambil daftar karakter lokal dari database.
     * Jika database masih kosong, lakukan sinkronisasi otomatis untuk batch awal.
     */
    public function getAllCharacters(string $patchVersion = '7.0'): Collection
    {
        $characters = Character::where('patch_version', $patchVersion)
            ->orderBy('rarity', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        return $characters;
    }

    /**
     * Mengambil detail satu karakter berdasarkan slug.
     */
    public function getCharacter(string $slug, string $patchVersion = '7.0'): ?Character
    {
        $character = Character::where('slug', $slug)
            ->where('patch_version', $patchVersion)
            ->first();

        // Jika belum ada di lokal, coba sinkronkan on-demand
        if (!$character) {
            $character = $this->syncCharacter($slug, $patchVersion);
        }

        return $character;
    }

    /**
     * Melakukan sinkronisasi satu karakter dari API pihak ketiga ke database lokal.
     */
    public function syncCharacter(string $slug, string $patchVersion = '7.0'): ?Character
    {
        $rawData = $this->client->getCharacterDetails($slug);

        if (!$rawData) {
            return null;
        }

        $validated = $this->validator->validateAndSanitize($rawData, $patchVersion);

        if (!$validated) {
            Log::warning("GenshinApiService: Data karakter {$slug} gagal divalidasi.");
            return null;
        }

        return Character::updateOrCreate(
            ['slug' => $validated['slug'], 'patch_version' => $patchVersion],
            $validated
        );
    }

    /**
     * Melakukan sinkronisasi massal seluruh karakter.
     *
     * @param int|null $limit Batasan jumlah karakter yang disinkronkan (opsional untuk testing)
     * @return array Ringkasan hasil sinkronisasi ['total' => int, 'success' => int, 'failed' => int]
     */
    public function syncAllCharacters(?int $limit = null, string $patchVersion = '7.0'): array
    {
        $slugs = $this->client->getCharacterSlugs();

        if (empty($slugs)) {
            return ['total' => 0, 'success' => 0, 'failed' => 0];
        }

        if ($limit && $limit > 0) {
            $slugs = array_slice($slugs, 0, $limit);
        }

        $success = 0;
        $failed = 0;

        foreach ($slugs as $slug) {
            $result = $this->syncCharacter($slug, $patchVersion);
            if ($result) {
                $success++;
            } else {
                $failed++;
            }
        }

        return [
            'total' => count($slugs),
            'success' => $success,
            'failed' => $failed,
        ];
    }
}

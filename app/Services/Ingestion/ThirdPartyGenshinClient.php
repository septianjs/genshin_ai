<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ThirdPartyGenshinClient
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.genshin.third_party_url', 'https://genshin.jmp.blue'), '/');
    }

    /**
     * Mengambil daftar seluruh slug karakter dari API pihak ketiga.
     *
     * @return array<string>
     */
    public function getCharacterSlugs(): array
    {
        try {
            $response = Http::timeout(10)->get("{$this->baseUrl}/characters");

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            Log::warning("ThirdPartyGenshinClient: Gagal mengambil daftar karakter. Status: {$response->status()}");
            return [];
        } catch (\Exception $e) {
            Log::error("ThirdPartyGenshinClient: Exception saat fetch character list: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Mengambil detail karakter mentah dari API pihak ketiga.
     */
    public function getCharacterDetails(string $slug): ?array
    {
        try {
            $response = Http::timeout(10)->get("{$this->baseUrl}/characters/{$slug}");

            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data)) {
                    // Tambahkan URL icon jika belum ada
                    $data['icon_url'] = "{$this->baseUrl}/characters/{$slug}/icon-big";
                    return $data;
                }
            }

            Log::warning("ThirdPartyGenshinClient: Gagal mengambil data karakter {$slug}. Status: {$response->status()}");
            return null;
        } catch (\Exception $e) {
            Log::error("ThirdPartyGenshinClient: Exception saat fetch detail karakter {$slug}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getWeaponCatalog(): array
    {
        return $this->getItemCatalog('weapons');
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getArtifactCatalog(): array
    {
        return $this->getItemCatalog('artifacts');
    }

    /**
     * @return array<array<string, mixed>>
     */
    protected function getItemCatalog(string $entity): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(12)
                ->acceptJson()
                ->get("{$this->baseUrl}/{$entity}/all");

            if (! $response->successful()) {
                Log::warning("ThirdPartyGenshinClient: Gagal mengambil katalog {$entity}.", [
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();

            if (! is_array($data)) {
                return [];
            }

            if (isset($data['data']) && is_array($data['data'])) {
                $data = $data['data'];
            }

            return array_values(array_filter(
                $data,
                fn ($item): bool => is_array($item)
                    && ! empty($item['name'])
            ));
        } catch (\Throwable $e) {
            Log::warning("ThirdPartyGenshinClient: Katalog {$entity} tidak dapat diakses.", [
                'exception' => get_class($e),
            ]);

            return [];
        }
    }
}

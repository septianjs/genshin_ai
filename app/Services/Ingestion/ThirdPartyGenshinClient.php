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
}

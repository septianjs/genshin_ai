<?php

namespace App\Services\Ingestion;

class GameDataValidator
{
    /**
     * Memvalidasi dan membersihkan data mentah karakter dari API pihak ketiga.
     *
     * @param array $rawData
     * @param string $patchVersion
     * @return array|null Array yang sudah disanitasi atau null jika data esensial tidak valid
     */
    public function validateAndSanitize(array $rawData, string $patchVersion = '7.0'): ?array
    {
        // Validasi field primer yang wajib ada
        $slug = isset($rawData['id']) ? trim($rawData['id']) : (isset($rawData['name']) ? strtolower(str_replace(' ', '-', trim($rawData['name']))) : null);
        $name = isset($rawData['name']) ? trim($rawData['name']) : null;

        if (!$slug || !$name) {
            return null;
        }

        // Normalisasi Vision / Elemen
        $rawVision = $rawData['vision'] ?? ($rawData['vision_key'] ?? 'UNKNOWN');
        $vision = strtoupper(trim($rawVision));

        // Normalisasi Tipe Senjata
        $rawWeapon = $rawData['weapon_type'] ?? ($rawData['weapon'] ?? 'SWORD');
        $weaponType = strtoupper(trim($rawWeapon));

        // Normalisasi Rarity
        $rarity = isset($rawData['rarity']) ? (int) $rawData['rarity'] : 5;
        if ($rarity < 1 || $rarity > 5) {
            $rarity = 5;
        }

        // Sanitasi Skill Data (Talenta Skill, Burst, Pasif)
        $skillData = [
            'skillTalents' => is_array($rawData['skillTalents'] ?? null) ? $rawData['skillTalents'] : [],
            'passiveTalents' => is_array($rawData['passiveTalents'] ?? null) ? $rawData['passiveTalents'] : [],
        ];

        // Sanitasi Konstelasi (C1 s/d C6)
        $constellationData = is_array($rawData['constellations'] ?? null) ? $rawData['constellations'] : [];

        // Sanitasi Material Ascension
        $ascensionMaterials = is_array($rawData['ascension_materials'] ?? null) ? $rawData['ascension_materials'] : [];

        return [
            'slug' => $slug,
            'name' => $name,
            'title' => isset($rawData['title']) ? trim($rawData['title']) : null,
            'vision' => $vision,
            'weapon_type' => $weaponType,
            'rarity' => $rarity,
            'description' => isset($rawData['description']) ? trim($rawData['description']) : '',
            'skill_data' => $skillData,
            'constellation_data' => $constellationData,
            'ascension_materials' => $ascensionMaterials,
            'icon_url' => $rawData['icon_url'] ?? null,
            'patch_version' => $patchVersion,
            'is_validated' => true,
            'synced_at' => now(),
        ];
    }
}

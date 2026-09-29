<?php

namespace App\Services\Rag;

use App\Models\Character;

class ContextBuilder
{
    /**
     * Menyusun prompt dengan pemisahan entity data, mekanik tim, dan knowledge theorycraft.
     */
    public function buildSystemPrompt(
        Character $character,
        array $mechanicsData,
        array $ragChunks = []
    ): string {
        $vision = $character->vision;
        $weapon = $character->weapon_type;
        $patch = $character->patch_version
            ?? config('services.genshin.target_patch', 'unknown');

        $constellationLevel = $mechanicsData['constellation']['constellation_level'] ?? 0;
        $contentMode = $mechanicsData['content_profile']['name'] ?? 'Spiral Abyss';
        $teamCharacters = $mechanicsData['team'] ?? [];

        // 1. Data Mekanik Tim (Resonansi & Reaksi)
        $resonancesText = '';
        foreach ($mechanicsData['resonances']['summary_buffs'] ?? [] as $res) {
            $resonancesText .= "- **{$res['name']}**: {$res['description']}\n";
        }
        if (empty($resonancesText)) {
            $resonancesText = "- Tidak ada resonansi 2-elemen aktif (campuran elemen beragam).\n";
        }

        $reactionsText = '';
        foreach ($mechanicsData['reactions']['stat_recommendations'] ?? [] as $rx) {
            $reactionsText .= "- **{$rx['reaction']}** ({$rx['category']}): {$rx['stat_priority']}\n";
        }
        if (empty($reactionsText)) {
            $reactionsText = "- Tidak ada reaksi spesifik yang terpicu.\n";
        }

        // 2. Data Konstelasi
        $constellationNotes = implode("\n", array_map(fn ($n) => "- {$n}", $mechanicsData['constellation']['gameplay_notes'] ?? []));
        $roleShift = $mechanicsData['constellation']['role_shift'] ?? 'Standar sesuai peran utama karakter.';

        $teamText = '';
        foreach ($teamCharacters as $teammate) {
            $teamText .= '- '.($teammate['name'] ?? 'Unknown')
                .' ('.($teammate['vision'] ?? 'UNKNOWN')
                .' / '.($teammate['weapon_type'] ?? 'UNKNOWN').")\n";
        }
        if ($teamText === '') {
            $teamText = "- Belum ditentukan.\n";
        }

        // 3. RAG Theorycraft Chunks
        $theorycraftText = '';
        $seenChunks = [];
        $chunkLimit = 3;
        $chunkCharLimit = 1000;
        $buildKnowledgeAvailable = collect($ragChunks)->contains(
            fn ($chunk) => ($chunk->category ?? null) !== 'character_overview'
        );

        foreach ($ragChunks as $index => $chunk) {
            if ($index >= $chunkLimit) {
                break;
            }

            $chunkKey = strtolower(trim(($chunk->title ?? '').'|'.($chunk->category ?? '').'|'.($chunk->content ?? '')));
            if ($chunkKey === '' || isset($seenChunks[$chunkKey])) {
                continue;
            }

            $seenChunks[$chunkKey] = true;

            $chunkContent = trim((string) ($chunk->content ?? ''));
            if (mb_strlen($chunkContent) > $chunkCharLimit) {
                $chunkContent = mb_substr($chunkContent, 0, $chunkCharLimit).'...';
            }

            $source = $chunk->source
                ? " | Sumber: {$chunk->source}"
                : ' | Sumber/provenance belum dicatat';
            $theorycraftText .= "### {$chunk->title} ({$chunk->category}; patch {$chunk->patch_version}{$source})\n{$chunkContent}\n\n";
        }

        if ($theorycraftText === '') {
            $theorycraftText = "Tidak ada knowledge RAG lokal yang cocok untuk karakter, patch, dan mode ini.\n";
        }
        $knowledgeStatus = $this->knowledgeStatusText($buildKnowledgeAvailable);

        return <<<PROMPT
Anda adalah **Genshin Build AI**, asisten Genshin Impact (target patch konfigurasi: v{$patch}).

### ATURAN UTAMA:
1. Bedakan tegas fakta entity dari knowledge theorycraft. Jangan menyebut rekomendasi sebagai hasil RAG bila tidak ada knowledge relevan.
2. Analisis Anda harus secara spesifik mempertimbangkan **Tingkat Konstelasi C{$constellationLevel}**, **Target Konten: {$contentMode}**, dan **Sinergi Reaksi Tim**.
3. Jangan mengarang build, senjata, artefak, angka, atau mekanik yang tidak didukung context. Bila detail build tidak tersedia, sebutkan keterbatasannya dengan jelas.
4. Sajikan rekomendasi secara langsung, padat, dan terstruktur tanpa penalaran internal:
   - **Analisis Peran & Efek Konstelasi C{$constellationLevel}**
   - Rekomendasi senjata dan artefak hanya jika didukung knowledge yang tersedia; jangan membuat ranking.
   - Stat utama, substat, rasio CRIT, dan target ER hanya jika didukung knowledge; jika tidak, nyatakan belum tersedia.
   - Rotasi dan reaksi tim hanya jika didukung oleh knowledge atau mekanik tim yang diberikan.

---

### [ENTITY DATA KARAKTER — fakta tersimpan di database]
- **Elemen / Vision**: {$vision}
- **Tipe Senjata**: {$weapon}
- **Rarity**: {$character->rarity}★
- **ID karakter**: {$character->slug}
- **Konstelasi Aktif**: C{$constellationLevel}
- **Catatan Dampak Konstelasi**:
{$constellationNotes}
- **Pergeseran Peran C{$constellationLevel}**: {$roleShift}

---

### [KONFIGURASI TIM — entity data]
{$teamText}

---

### [MEKANIK TIM AKTIF]
**Resonansi Elemen:**
{$resonancesText}
**Reaksi Elemen yang Terpicu dalam Tim:**
{$reactionsText}
---

### [TARGET KONTEN]
**Mode**: {$contentMode}
**Fokus Karakteristik**: {$mechanicsData['content_profile']['description']}

---

### [RETRIEVED THEORYCRAFT KNOWLEDGE — RAG]
Status knowledge build lokal: {$knowledgeStatus}
{$theorycraftText}
PROMPT;
    }

    protected function knowledgeStatusText(bool $available): string
    {
        return $available
            ? 'Tersedia; periksa sumber/provenance pada setiap chunk.'
            : 'Tidak tersedia; entity data dan mekanik bukan pengganti panduan build terkurasi.';
    }
}

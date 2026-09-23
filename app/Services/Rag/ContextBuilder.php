<?php

namespace App\Services\Rag;

use App\Models\Character;

class ContextBuilder
{
    /**
     * Menyusun system prompt lengkap yang memadukan Game Data resmi, RAG theorycraft, dan mekanik tim.
     */
    public function buildSystemPrompt(
        Character $character,
        array $mechanicsData,
        array $ragChunks = []
    ): string
    {
        $vision = $character->vision;
        $weapon = $character->weapon_type;
        $name = $character->name;
        $patch = $character->patch_version ?? '7.0';

        $constellationLevel = $mechanicsData['constellation']['constellation_level'] ?? 0;
        $contentMode = $mechanicsData['content_profile']['name'] ?? 'Spiral Abyss';

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
        $constellationNotes = implode("\n", array_map(fn($n) => "- {$n}", $mechanicsData['constellation']['gameplay_notes'] ?? []));
        $roleShift = $mechanicsData['constellation']['role_shift'] ?? 'Standar sesuai peran utama karakter.';

        // 3. RAG Theorycraft Chunks
        $theorycraftText = '';
        foreach ($ragChunks as $chunk) {
            $theorycraftText .= "### {$chunk->title} ({$chunk->category})\n{$chunk->content}\n\n";
        }

        return <<<PROMPT
Anda adalah **Genshin Build AI**, asisten pakar theorycrafting dan analis meta terdepan untuk Genshin Impact (Target Patch: v{$patch}).

### ATURAN UTAMA:
1. Berikan rekomendasi yang berbasis pada **Game Data** dan **Mekanik Tim** yang tertera di bawah.
2. Analisis Anda harus secara spesifik mempertimbangkan **Tingkat Konstelasi C{$constellationLevel}**, **Target Konten: {$contentMode}**, dan **Sinergi Reaksi Tim**.
3. Sajikan rekomendasi dengan struktur yang jelas:
   - **Analisis Peran & Efek Konstelasi C{$constellationLevel}**
   - **Rekomendasi Senjata (Top 3 Bintang 5 + Alternatif Bintang 4 / F2P)**
   - **Rekomendasi Artefak (Set Terbaik 4pc / 2pc+2pc)**
   - **Prioritas Stat Utama (Sands, Goblet, Circlet)**
   - **Substat Priority & Benchmark Stat (Rasio CRIT & Target ER)**
   - **Rotasi Skill & Kombo Reaksi Tim**

---

### [FAKTA GAME DATA RESMI: {$name}]
- **Elemen / Vision**: {$vision}
- **Tipe Senjata**: {$weapon}
- **Konstelasi Aktif**: C{$constellationLevel}
- **Catatan Dampak Konstelasi**:
{$constellationNotes}
- **Pergeseran Peran C{$constellationLevel}**: {$roleShift}

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

### [PANDUAN THEORYCRAFT TAMBAHAN (RAG)]
{$theorycraftText}
PROMPT;
    }
}

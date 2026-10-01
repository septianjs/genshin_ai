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
        array $ragChunks = [],
        array $teamCandidates = []
    ): string {
        $vision = $character->vision;
        $weapon = $character->weapon_type;
        $patch = $character->patch_version
            ?? config('services.genshin.target_patch', 'unknown');

        $constellationLevel = $mechanicsData['constellation']['constellation_level'] ?? 0;
        $characterKitText = $this->characterKitText($character, (int) $constellationLevel);
        $contentMode = $mechanicsData['content_profile']['name'] ?? 'Spiral Abyss';
        $teamCharacters = $mechanicsData['team'] ?? [];
        $teamCandidatesText = $this->teamCandidateText($teamCandidates);

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
3. Analisis kit karakter yang diberikan untuk menentukan pola damage, role, scaling, kebutuhan energi, dan stat yang mendukung kit. Jangan hanya mengulang retrieved knowledge.
4. Gunakan retrieved knowledge sebagai referensi, bukan satu-satunya sumber. Bedakan fakta kit dari rekomendasi hasil analisis; tandai perkiraan sebagai perkiraan dan jangan mengklaimnya sebagai data terkurasi.
5. Jangan mengarang angka talent, efek senjata/artefak, atau mekanik yang tidak ada di context. Target ER boleh berupa kisaran awal yang diberi label perkiraan dan harus dijelaskan sebagai bergantung pada tim/rotasi. Jangan membuat ranking mutlak tanpa dasar.
6. Sajikan rekomendasi dalam Bahasa Indonesia dengan bagian berikut:
    - **Peran dan Analisis Kit**: simpulkan role, scaling, dan pola damage dari deskripsi skill/konstelasi yang tersedia.
    - **Artefak**: set utama dan alternatif, main stat Sands/Goblet/Circlet, serta substat berurutan.
    - **Senjata**: beberapa opsi yang sesuai tipe senjata, dengan alasan singkat; jangan mengarang efek atau ranking yang tidak didukung.
    - **Tim yang Direkomendasikan**: jika user belum menetapkan tim, pilih sampai tiga karakter hanya dari roster kandidat lokal di bawah. Jelaskan role tiap anggota, reaksi, dan sinerginya. Jika roster kosong, katakan bahwa kandidat lokal tidak tersedia dan jangan mengarang nama karakter.
    - **Stat Prioritas**: stat yang dicari dan alasan berdasarkan scaling/reaksi. Angka target ER atau rasio hanya boleh berupa estimasi yang diberi label dan dijelaskan bergantung pada tim/rotasi.
    - **Rotasi Singkat**: urutan aksi yang sesuai kit dan tim.
7. Jika user sudah memilih rekan tim, evaluasi pilihan tersebut terlebih dahulu; boleh berikan alternatif dari roster dengan alasan. Bedakan fakta database, panduan lokal, dan inferensi model.
8. Database karakter tidak menyimpan lembar stat tempur lengkap. Jangan mengaku membaca base stat atau membuat angka stat spesifik yang tidak diberikan.

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

### [KIT KARAKTER — TALENT DAN KONSTELASI DARI DATABASE]
{$characterKitText}

---

### [KONFIGURASI TIM — entity data]
{$teamText}

### [ROSTER KARAKTER LOKAL — KANDIDAT TIM]
{$teamCandidatesText}

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

    protected function teamCandidateText(array $candidates): string
    {
        if ($candidates === []) {
            return 'Roster karakter tervalidasi pada patch ini tidak tersedia.';
        }

        return collect($candidates)
            ->map(function (array $candidate): string {
                return '- '.($candidate['name'] ?? 'Unknown')
                    .' ('.($candidate['vision'] ?? 'UNKNOWN')
                    .' / '.($candidate['weapon_type'] ?? 'UNKNOWN').')';
            })
            ->implode("\n");
    }

    protected function characterKitText(Character $character, int $constellationLevel): string
    {
        $talents = collect($character->skill_data ?? [])
            ->filter(fn ($skill) => is_array($skill))
            ->take(4)
            ->map(function (array $skill): string {
                $name = trim((string) ($skill['name'] ?? 'Talent'));
                $unlock = trim((string) ($skill['unlock'] ?? ''));
                $description = preg_replace('/\s+/u', ' ', trim((string) ($skill['description'] ?? ''))) ?? '';

                if (mb_strlen($description) > 500) {
                    $description = mb_substr($description, 0, 500).'...';
                }

                $upgrades = collect($skill['upgrades'] ?? [])
                    ->filter(fn ($upgrade) => is_array($upgrade))
                    ->take(5)
                    ->map(fn (array $upgrade) => trim((string) ($upgrade['name'] ?? '').' '.(string) ($upgrade['value'] ?? '')))
                    ->filter()
                    ->implode('; ');

                $line = '- '.($unlock !== '' ? "{$unlock}: " : '').$name;

                if ($description !== '') {
                    $line .= ". {$description}";
                }

                if ($upgrades !== '') {
                    $line .= " | Data upgrade: {$upgrades}";
                }

                return $line;
            });

        $constellations = collect($character->constellation_data ?? [])
            ->filter(fn ($constellation) => is_array($constellation)
                && (int) ($constellation['level'] ?? 0) > 0
                && (int) $constellation['level'] <= $constellationLevel)
            ->map(function (array $constellation): string {
                $level = (int) ($constellation['level'] ?? 0);
                $name = trim((string) ($constellation['name'] ?? "C{$level}"));
                $description = preg_replace('/\s+/u', ' ', trim((string) ($constellation['description'] ?? ''))) ?? '';

                if (mb_strlen($description) > 350) {
                    $description = mb_substr($description, 0, 350).'...';
                }

                return "- C{$level} {$name}: {$description}";
            });

        $lines = $talents->all();

        if ($constellationLevel > 0 && $constellations->isNotEmpty()) {
            $lines[] = 'Konstelasi yang terbuka hingga C'.$constellationLevel.':'
                ."\n".$constellations->implode("\n");
        }

        return $lines !== []
            ? implode("\n", $lines)
            : 'Data talent karakter tidak tersedia; jangan menyimpulkan mekanik kit yang spesifik.';
    }

    protected function knowledgeStatusText(bool $available): string
    {
        return $available
            ? 'Tersedia sebagai referensi terkurasi, tetapi bukan batas analisis.'
            : 'Tidak tersedia; susun analisis build dari kit karakter dan tandai rekomendasi sebagai inferensi.';
    }
}

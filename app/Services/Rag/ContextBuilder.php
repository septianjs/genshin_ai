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
        array $teamCandidates = [],
        ?string $preferredRole = null,
        array $weaponCandidates = [],
        array $artifactCandidates = []
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
        $weaponCandidatesText = $this->weaponCandidateText($weaponCandidates);
        $artifactCandidatesText = $this->artifactCandidateText($artifactCandidates);
        $preferredRole = trim((string) $preferredRole);
        $roleInstruction = $preferredRole !== ''
            ? "Role eksperimen yang diminta user: {$preferredRole}. Anda wajib membuat rekomendasi untuk role ini, sekalipun berbeda dari role populer atau panduan lokal. Jelaskan trade-off dan cara memaksimalkan role tersebut; jangan menggantinya dengan build standar."
            : 'Tidak ada role eksperimen khusus. Pilih role berdasarkan kit karakter, tetapi tetap berikan build lengkap.';

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

        return <<<PROMPT
Anda adalah **Genshin Build AI**, asisten Genshin Impact (target patch konfigurasi: v{$patch}).

### ATURAN UTAMA:
1. Selalu hasilkan rekomendasi build best-effort. Jangan menolak atau membatasi build hanya karena role tidak populer.
2. Analisis Anda harus secara spesifik mempertimbangkan **Tingkat Konstelasi C{$constellationLevel}**, **Target Konten: {$contentMode}**, dan **Sinergi Reaksi Tim**.
3. {$roleInstruction}
4. Analisis kit karakter yang diberikan untuk menentukan pola damage, role, scaling, kebutuhan energi, dan stat yang mendukung build yang diminta. Jangan hanya mengulang retrieved knowledge.
5. Gunakan pengetahuan Genshin yang Anda miliki untuk mengusulkan artefak dan senjata; data database karakter adalah fakta kit/identitas, bukan daftar build yang membatasi pilihan.
6. Jangan mengarang angka talent, efek senjata/artefak, atau mekanik yang tidak ada di context. Target ER boleh berupa kisaran awal yang diberi label perkiraan dan harus dijelaskan sebagai bergantung pada tim/rotasi. Jangan membuat ranking mutlak tanpa dasar.
7. Validasi setiap rekomendasi terhadap deskripsi kit: jangan menyebut skill/Burst menghasilkan damage jika data hanya menyebut heal atau utility; jangan memilih bonus Burst DMG untuk damage pribadi bila Burst tidak memberi damage. Bedakan bonus set yang hanya menguntungkan rekan tim.
8. Jangan mengarang rarity, secondary stat, atau passive senjata/artefak. Pastikan senjata cocok dengan tipe senjata karakter. Jika detail item tidak dapat dipastikan, berikan nama opsi tanpa mengarang efek numerik.
9. Normal/Charged Attack Catalyst memberikan damage elemental sesuai kit/infusion, bukan Physical DMG secara default. Jangan merekomendasikan Physical DMG untuk serangan Catalyst tanpa sumber Physical damage/infusion yang disebutkan.
10. Elemental conversion tidak otomatis mengubah kategori damage menjadi Elemental Burst DMG. Cocokkan bonus artefak dengan kategori damage yang benar-benar disebut pada deskripsi talent; misalnya normal attack yang dikonversi menjadi Electro tetap perlu dinilai sebagai kategori serangan yang dinyatakan kit.
11. Sajikan rekomendasi dalam Bahasa Indonesia dengan bagian berikut:
    - **Peran dan Analisis Kit**: simpulkan role, scaling, dan pola damage dari deskripsi skill/konstelasi yang tersedia.
    - **Artefak**: pilih hanya dari katalog artefak aktual di bawah; set utama dan alternatif, main stat Sands/Goblet/Circlet, serta substat berurutan. Cocokkan bonus set dengan jenis damage kit.
    - **Senjata**: pilih hanya dari katalog yang cocok dengan tipe senjata karakter di bawah; jangan menyebut senjata di luar katalog atau mengubah rarity/passive-nya.
    - **Tim yang Direkomendasikan**: jika user belum menetapkan tim, pilih sampai tiga karakter hanya dari roster kandidat lokal di bawah. Jelaskan role tiap anggota, reaksi, dan sinerginya. Jika roster kosong, katakan bahwa kandidat lokal tidak tersedia dan jangan mengarang nama karakter.
    - **Stat Prioritas**: stat yang dicari dan alasan berdasarkan scaling/reaksi. Angka target ER atau rasio hanya boleh berupa estimasi yang diberi label dan dijelaskan bergantung pada tim/rotasi.
    - **Rotasi Singkat**: urutan aksi yang sesuai kit dan tim.
12. Jika user sudah memilih rekan tim, evaluasi pilihan tersebut terlebih dahulu; boleh berikan alternatif dari roster dengan alasan. Bedakan fakta database dan inferensi model.
13. Untuk build eksperimen, tetap pilih artefak, senjata, main stat, substat, tim, dan rotasi yang paling masuk akal berdasarkan scaling/kit. Tandai bagian yang merupakan kompromi atau estimasi, bukan meniadakan rekomendasi.
14. Database karakter tidak menyimpan lembar stat tempur lengkap. Jangan mengaku membaca base stat atau membuat angka stat spesifik yang tidak diberikan.
15. Kembalikan hanya satu objek JSON valid tanpa Markdown fence atau teks sebelum/sesudahnya. Isi tepat 4 artefak, 4 senjata, dan 4 tim berbeda; rank masing-masing 1 sampai 4. Salin ID senjata/artefak dan slug teammate persis dari katalog/roster di bawah. Jangan pernah mengusulkan senjata di luar tipe {$weapon}.

Struktur JSON wajib:
{"role_analysis":"...","artifacts":[{"rank":1,"id":"artifact-id","main_stats":{"sands":"...","goblet":"...","circlet":"..."},"substats":["..."],"reason":"..."}],"weapons":[{"rank":1,"id":"weapon-id","reason":"..."}],"teams":[{"rank":1,"teammate_slugs":["character-slug"],"reason":"..."}],"stat_priorities":["..."],"rotation":["..."]}

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

### [ROSTER KARAKTER DALAM DATABASE — KANDIDAT TIM]
{$teamCandidatesText}

### [KATALOG SENJATA AKTUAL — TIPE {$weapon}]
{$weaponCandidatesText}

### [KATALOG ARTEFAK AKTUAL]
{$artifactCandidatesText}

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

### DASAR REKOMENDASI
Gunakan data karakter, talent, konstelasi, mekanik tim, dan pengetahuan Genshin Anda untuk menyusun rekomendasi lengkap. Jangan batasi rekomendasi pada build populer.
PROMPT;
    }

    protected function teamCandidateText(array $candidates): string
    {
        if ($candidates === []) {
            return 'Roster karakter tervalidasi pada patch ini tidak tersedia.';
        }

        return collect($candidates)
            ->map(function (array $candidate): string {
                return '- '.($candidate['slug'] ?? 'unknown-slug')
                    .' | '.($candidate['name'] ?? 'Unknown')
                    .' ('.($candidate['vision'] ?? 'UNKNOWN')
                    .' / '.($candidate['weapon_type'] ?? 'UNKNOWN').')';
            })
            ->implode("\n");
    }

    protected function weaponCandidateText(array $weapons): string
    {
        if ($weapons === []) {
            return 'Katalog senjata untuk tipe ini tidak tersedia saat ini. Jangan mengarang nama, rarity, atau passive senjata.';
        }

        return collect($weapons)
            ->take(40)
            ->map(function (array $weapon): string {
                $details = array_filter([
                    isset($weapon['rarity']) ? $weapon['rarity'].'★' : null,
                    $weapon['subStat'] ?? $weapon['secondary_stat'] ?? null,
                    $weapon['passiveName'] ?? $weapon['passive_name'] ?? null,
                    $weapon['passiveDesc'] ?? $weapon['passive_description'] ?? null,
                ]);

                $line = '- '.($weapon['id'] ?? 'unknown-id')
                    .' | '.($weapon['name'] ?? 'Unknown weapon');

                if ($details !== []) {
                    $line .= ': '.implode(' | ', $details);
                }

                return mb_strlen($line) > 420
                    ? mb_substr($line, 0, 420).'...'
                    : $line;
            })
            ->implode("\n");
    }

    protected function artifactCandidateText(array $artifacts): string
    {
        if ($artifacts === []) {
            return 'Katalog artefak tidak tersedia saat ini. Jangan mengarang nama atau bonus set.';
        }

        return collect($artifacts)
            ->take(60)
            ->map(function (array $artifact): string {
                $twoPiece = trim((string) ($artifact['2-piece_bonus'] ?? ''));
                $fourPiece = trim((string) ($artifact['4-piece_bonus'] ?? ''));
                $line = '- '.($artifact['id'] ?? 'unknown-id')
                    .' | '.($artifact['name'] ?? 'Unknown set');

                if (isset($artifact['max_rarity'])) {
                    $line .= ' ('.$artifact['max_rarity'].'★)';
                }

                if ($twoPiece !== '') {
                    $line .= ' | 2pc: '.$twoPiece;
                }

                if ($fourPiece !== '') {
                    $line .= ' | 4pc: '.$fourPiece;
                }

                return mb_strlen($line) > 520
                    ? mb_substr($line, 0, 520).'...'
                    : $line;
            })
            ->implode("\n");
    }

    protected function characterKitText(Character $character, int $constellationLevel): string
    {
        $talents = collect($character->skill_data ?? [])
            ->filter(fn ($skill) => is_array($skill))
            ->map(function (array $skill): string {
                $name = trim((string) ($skill['name'] ?? 'Talent'));
                $unlock = trim((string) ($skill['unlock'] ?? ''));
                $description = preg_replace('/\s+/u', ' ', trim((string) ($skill['description'] ?? ''))) ?? '';

                if (mb_strlen($description) > 1000) {
                    $description = mb_substr($description, 0, 1000).'...';
                }

                $upgrades = collect($skill['upgrades'] ?? [])
                    ->filter(fn ($upgrade) => is_array($upgrade))
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

}

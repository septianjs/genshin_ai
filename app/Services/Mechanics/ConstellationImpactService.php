<?php

namespace App\Services\Mechanics;

use App\Models\Character;

class ConstellationImpactService
{
    /**
     * Menganalisis dampak tingkat konstelasi (C0 - C6) pada karakter target.
     *
     * @param Character $character
     * @param int $constellationLevel Tingkat konstelasi (0 s/d 6)
     * @return array
     */
    public function analyzeImpact(Character $character, int $constellationLevel = 0): array
    {
        $slug = $character->slug;
        $level = max(0, min(6, $constellationLevel));

        $impacts = [
            'constellation_level' => $level,
            'role_shift' => null,
            'er_adjustment_percent' => 0,
            'recommended_artifact_shift' => null,
            'gameplay_notes' => [],
        ];

        // Analisis spesifik untuk karakter populer
        switch ($slug) {
            case 'furina':
                if ($level >= 2) {
                    $impacts['role_shift'] = 'Fanfare stacking instan + Max HP bonus hingga +140%. Fokus stat artefak beralih lebih agresif ke CRIT DMG dan Hydro DMG.';
                    $impacts['gameplay_notes'][] = 'C2 Furina menghasilkan damage personal yang sangat masif tanpa harus menunggu lama tumpukan Fanfare.';
                }
                if ($level >= 4) {
                    $impacts['er_adjustment_percent'] = -30;
                    $impacts['gameplay_notes'][] = 'C4 memberi regenerasi 4 energi setiap 5 detik saat skill aktif, menurunkan kebutuhan ER sekitar 30%.';
                }
                if ($level >= 6) {
                    $impacts['role_shift'] = 'On-Field Hydro DPS & Tim Healer mandiri. Tidak memerlukan healer terpisah di tim.';
                }
                break;

            case 'raiden':
                if ($level >= 2) {
                    $impacts['role_shift'] = 'Mengabaikan 60% DEF lawan saat Burst. Berubah total menjadi Main Hypercarry DPS tertinggi.';
                    $impacts['gameplay_notes'][] = 'C2 Raiden sangat optimal dimainkan dalam komposisi Hyper Raiden (Kazuha, Bennett, Kujou Sara C6).';
                }
                break;

            case 'hu-tao':
                if ($level >= 1) {
                    $impacts['gameplay_notes'][] = 'C1 membuat Charged Attack tidak mengonsumsi stamina. Beralih dari jump-cancel ke dash-cancel beruntun untuk DPS dan rotasi lebih tinggi.';
                }
                break;

            case 'faruzan':
                if ($level < 6) {
                    $impacts['er_adjustment_percent'] = +60;
                    $impacts['recommended_artifact_shift'] = 'Wajib Favonius Bow + 2pc Emblem dengan total ER 250%–300%.';
                } else {
                    $impacts['er_adjustment_percent'] = -40;
                    $impacts['recommended_artifact_shift'] = 'Kebutuhan ER turun ke ~180%. Dapat menggunakan set 4pc Tenacity of the Millelith (TotM) untuk buff ATK tim.';
                    $impacts['gameplay_notes'][] = 'C6 memberi partikel energi off-field dan +40% Anemo CRIT DMG.';
                }
                break;

            case 'bennett':
                if ($level >= 1) {
                    $impacts['gameplay_notes'][] = 'C1 menghapus batas 70% HP untuk mendapatkan buff ATK penuh dari Burst.';
                }
                if ($level >= 6) {
                    $impacts['gameplay_notes'][] = 'PERINGATAN C6: Memberi Pyro Infusion pada senjata melee. Sangat bagus untuk tim Pyro (Xiangling, Gaming, Arlecchino), tetapi dapat mengganggu DPS Fisik (Eula) atau Ayaka.';
                }
                break;

            case 'kazuha':
                if ($level >= 2) {
                    $impacts['gameplay_notes'][] = 'C2 memberi +200 Elemental Mastery di dalam Burst. Sangat melipatgandakan damage reaksi tim Vaporize, Melt, dan Aggravate.';
                }
                break;
        }

        // Ambil data teks konstelasi resmi dari database
        $constellations = $character->constellation_data ?? [];
        $activeConstellationDetails = array_slice($constellations, 0, $level);

        $impacts['unlocked_constellations'] = $activeConstellationDetails;

        return $impacts;
    }
}

<?php

namespace App\Services\Mechanics;

class ElementalResonanceService
{
    public function __construct(
        protected MechanicsRepository $repository
    ) {}

    /**
     * Menganalisis resonansi elemen aktif berdasarkan array elemen atau objek karakter.
     *
     * @param array<string> $visions Array string elemen (e.g. ['HYDRO', 'HYDRO', 'ANEMO', 'DENDRO'])
     * @return array Hasil evaluasi resonansi yang aktif beserta buff dan pengaruh statnya
     */
    public function evaluateResonances(array $visions): array
    {
        $normalizedVisions = array_map('strtoupper', array_map('trim', $visions));
        $elementCounts = array_count_values($normalizedVisions);
        $totalCharacters = count($normalizedVisions);

        $rules = $this->repository->getResonances();
        $activeResonances = [];

        // Cek resonansi 2 elemen sejenis
        foreach ($elementCounts as $element => $count) {
            if ($count >= 2 && isset($rules[$element])) {
                $activeResonances[$element] = $rules[$element];
            }
        }

        // Cek Protective Canopy (4 elemen berbeda dalam tim penuh 4 karakter)
        if ($totalCharacters >= 4 && count($elementCounts) >= 4 && isset($rules['UNIQUE'])) {
            $activeResonances['UNIQUE'] = $rules['UNIQUE'];
        }

        return [
            'active_resonances' => $activeResonances,
            'element_counts' => $elementCounts,
            'summary_buffs' => $this->summarizeBuffs($activeResonances),
        ];
    }

    protected function summarizeBuffs(array $activeResonances): array
    {
        $buffs = [];
        foreach ($activeResonances as $key => $resonance) {
            $buffs[] = [
                'name' => $resonance['name'],
                'description' => $resonance['description'],
                'influences' => $resonance['stat_influences'] ?? [],
            ];
        }
        return $buffs;
    }
}

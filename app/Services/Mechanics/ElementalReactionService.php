<?php

namespace App\Services\Mechanics;

class ElementalReactionService
{
    public function __construct(
        protected MechanicsRepository $repository
    ) {}

    /**
     * Menganalisis reaksi elemen apa saja yang dapat dipicu oleh kombinasi elemen dalam tim.
     *
     * @param array<string> $visions Array elemen (misal: ['DENDRO', 'HYDRO', 'ELECTRO', 'ANEMO'])
     * @return array Daftar reaksi yang terpicu beserta panduan prioritas statnya
     */
    public function evaluateReactions(array $visions): array
    {
        $normalizedVisions = array_unique(array_map('strtoupper', array_map('trim', $visions)));
        $allReactions = $this->repository->getReactions();
        $triggeredReactions = [];

        // Cek Vaporize (Hydro + Pyro)
        if (in_array('HYDRO', $normalizedVisions) && in_array('PYRO', $normalizedVisions)) {
            $triggeredReactions['vaporize'] = $allReactions['vaporize'];
        }

        // Cek Melt (Pyro + Cryo)
        if (in_array('PYRO', $normalizedVisions) && in_array('CRYO', $normalizedVisions)) {
            $triggeredReactions['melt'] = $allReactions['melt'];
        }

        // Cek Hyperbloom (Dendro + Hydro + Electro)
        if (in_array('DENDRO', $normalizedVisions) && in_array('HYDRO', $normalizedVisions) && in_array('ELECTRO', $normalizedVisions)) {
            $triggeredReactions['hyperbloom'] = $allReactions['hyperbloom'];
        }

        // Cek Burgeon (Dendro + Hydro + Pyro)
        if (in_array('DENDRO', $normalizedVisions) && in_array('HYDRO', $normalizedVisions) && in_array('PYRO', $normalizedVisions)) {
            $triggeredReactions['burgeon'] = $allReactions['burgeon'];
        }

        // Cek Swirl (Anemo + Pyro/Hydro/Electro/Cryo)
        if (in_array('ANEMO', $normalizedVisions) && (
            in_array('PYRO', $normalizedVisions) || in_array('HYDRO', $normalizedVisions) ||
            in_array('ELECTRO', $normalizedVisions) || in_array('CRYO', $normalizedVisions)
        )) {
            $triggeredReactions['swirl'] = $allReactions['swirl'];
        }

        // Cek Quicken / Aggravate / Spread (Dendro + Electro)
        if (in_array('DENDRO', $normalizedVisions) && in_array('ELECTRO', $normalizedVisions)) {
            $triggeredReactions['quicken_aggravate'] = $allReactions['quicken_aggravate'];
            $triggeredReactions['quicken_spread'] = $allReactions['quicken_spread'];
        }

        // Cek Frozen (Hydro + Cryo)
        if (in_array('HYDRO', $normalizedVisions) && in_array('CRYO', $normalizedVisions)) {
            $triggeredReactions['frozen'] = $allReactions['frozen'];
        }

        // Cek Superconduct (Cryo + Electro)
        if (in_array('CRYO', $normalizedVisions) && in_array('ELECTRO', $normalizedVisions)) {
            $triggeredReactions['superconduct'] = $allReactions['superconduct'];
        }

        return [
            'triggered_reactions' => $triggeredReactions,
            'stat_recommendations' => $this->extractStatRecommendations($triggeredReactions),
        ];
    }

    protected function extractStatRecommendations(array $reactions): array
    {
        $recommendations = [];
        foreach ($reactions as $key => $reaction) {
            $recommendations[] = [
                'reaction' => $reaction['name'],
                'category' => $reaction['category'],
                'stat_priority' => $reaction['stat_priority'] ?? '',
            ];
        }
        return $recommendations;
    }
}

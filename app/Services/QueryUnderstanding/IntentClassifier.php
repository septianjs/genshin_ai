<?php

namespace App\Services\QueryUnderstanding;

class IntentClassifier
{
    public const INTENT_BUILD = 'BUILD_RECOMMENDATION';
    public const INTENT_WEAPON_COMPARE = 'WEAPON_COMPARISON';
    public const INTENT_TEAM_SYNERGY = 'TEAM_SYNERGY';
    public const INTENT_ROTATION = 'ROTATION_GUIDE';
    public const INTENT_GENERAL = 'GENERAL_CHAT';

    /**
     * Mengklasifikasikan intensi dari query pengguna.
     */
    public function classify(string $query): string
    {
        $lower = strtolower($query);

        if (preg_match('/\b(rotasi|urutan|combo|kombo|step)\b/i', $lower)) {
            return self::INTENT_ROTATION;
        }

        if (preg_match('/\b(banding|bandingkan|bagus|bagusan|mending|vs|versus|pilih mana)\b/i', $lower)) {
            return self::INTENT_WEAPON_COMPARE;
        }

        if (preg_match('/\b(sinergi|tim|komposisi|partai|party|cocok.*tim)\b/i', $lower)) {
            return self::INTENT_TEAM_SYNERGY;
        }

        if (preg_match('/\b(build|artefak|senjata|stat|sands|goblet|circlet|er|crit|substat|rekomendasi)\b/i', $lower)) {
            return self::INTENT_BUILD;
        }

        return self::INTENT_BUILD; // Default untuk Genshin Build AI
    }
}

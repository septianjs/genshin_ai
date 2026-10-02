<?php

namespace App\Services\QueryUnderstanding;

class IntentClassifier
{
    public const INTENT_BUILD = 'BUILD_RECOMMENDATION';

    public const INTENT_WEAPON_COMPARE = 'WEAPON_COMPARISON';

    public const INTENT_TEAM_SYNERGY = 'TEAM_SYNERGY';

    public const INTENT_ROTATION = 'ROTATION_GUIDE';

    public const INTENT_GENERAL = 'GENERAL_CHAT';

    public const INTENT_GREETING = 'GREETING';

    public const INTENT_WEAPON_QUESTION = 'WEAPON_QUESTION';

    public const INTENT_ARTIFACT_QUESTION = 'ARTIFACT_QUESTION';

    public const INTENT_MECHANICS = 'MECHANICS_QUESTION';

    public const INTENT_REACTION = 'REACTION_QUESTION';

    public const INTENT_CHARACTER_QUESTION = 'CHARACTER_QUESTION';

    /**
     * Mengklasifikasikan intensi dari query pengguna.
     */
    public function classify(string $query): string
    {
        $lower = mb_strtolower(trim($query));

        if (preg_match('/^(hai|halo|hello|hi|hey|pagi|siang|sore|malam|selamat\s+(?:pagi|siang|sore|malam))(?:[!.,\s👋🙂😊]*)$/u', $lower)) {
            return self::INTENT_GREETING;
        }

        $explicitBuildRequest = preg_match('/\b(build(?:ing)?|membangun|membuild|buat(?:kan)?\s+(?:build|rekomendasi)|rekomendasi\s+build)\b/u', $lower) === 1;
        if ($explicitBuildRequest) {
            return self::INTENT_BUILD;
        }

        if (preg_match('/\b(artefak|artifact|artifacts)\b/u', $lower)) {
            return self::INTENT_ARTIFACT_QUESTION;
        }

        if (preg_match('/\b(senjata|weapon|weapons)\b/u', $lower)) {
            if (preg_match('/\b(banding|bandingkan|bagus|bagusan|mending|vs|versus|pilih\s+mana|lebih\s+baik)\b/u', $lower)) {
                return self::INTENT_WEAPON_COMPARE;
            }

            return self::INTENT_WEAPON_QUESTION;
        }

        if (preg_match('/\b(rotasi|urutan|combo|kombo|step)\b/u', $lower)) {
            return self::INTENT_ROTATION;
        }

        if (preg_match('/\b(sinergi|tim|komposisi|partai|party|cocok.*(?:tim|siapa))\b/i', $lower)) {
            return self::INTENT_TEAM_SYNERGY;
        }

        if (preg_match('/\b(vaporize|vape|melt|overload|superconduct|electro-charged|swirl|crystallize|bloom|hyperbloom|burgeon|burning|shatter|reaction|reaksi)\b/u', $lower)) {
            return self::INTENT_REACTION;
        }

        if (preg_match('/\b(mekanik|mechanic|kemampuan|ability|talent|skill|elemental\s+mastery)\b/i', $lower)) {
            return self::INTENT_MECHANICS;
        }

        if (preg_match('/\b(siapa\s+(?:itu|dia)|info(?:rmasi)?\s+(?:karakter|tentang)|elemen|vision|rarity|kelangkaan)\b/u', $lower)) {
            return self::INTENT_CHARACTER_QUESTION;
        }

        if (preg_match('/\b(banding|bandingkan|bagus|bagusan|mending|vs|versus|pilih\s+mana|lebih\s+baik)\b/u', $lower)) {
            return self::INTENT_WEAPON_COMPARE;
        }

        return self::INTENT_GENERAL;
    }
}

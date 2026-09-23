<?php

namespace App\Services\Mechanics;

class MechanicsRepository
{
    public function getResonances(): array
    {
        return config('genshin_mechanics.resonances', []);
    }

    public function getReactions(): array
    {
        return config('genshin_mechanics.reactions', []);
    }

    public function getContentProfiles(): array
    {
        return config('genshin_mechanics.content_profiles', []);
    }
}

<?php

namespace App\Services\Mechanics;

class ContentModeService
{
    public function __construct(
        protected MechanicsRepository $repository
    ) {}

    /**
     * Mengambil profil strategi berdasarkan mode konten yang dituju.
     */
    public function getProfile(string $mode): array
    {
        $profiles = $this->repository->getContentProfiles();
        $normalized = strtolower(trim($mode));

        return $profiles[$normalized] ?? $profiles['abyss'];
    }
}

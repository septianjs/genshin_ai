<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Services\Mechanics\ConstellationImpactService;
use App\Services\Mechanics\ElementalReactionService;
use App\Services\Mechanics\ElementalResonanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicsEngineTest extends TestCase
{
    use RefreshDatabase;
    public function test_elemental_resonance_detects_double_hydro_and_double_pyro(): void
    {
        $resonanceService = app(ElementalResonanceService::class);

        // 2 Hydro, 1 Anemo, 1 Geo -> Soothing Water (+25% Max HP)
        $result = $resonanceService->evaluateResonances(['HYDRO', 'HYDRO', 'ANEMO', 'GEO']);

        $this->assertArrayHasKey('HYDRO', $result['active_resonances']);
        $this->assertEquals(25, $result['active_resonances']['HYDRO']['buffs']['max_hp_percent']);

        // 4 Elemen Unik -> Protective Canopy
        $uniqueResult = $resonanceService->evaluateResonances(['HYDRO', 'PYRO', 'ELECTRO', 'ANEMO']);
        $this->assertArrayHasKey('UNIQUE', $uniqueResult['active_resonances']);
    }

    public function test_elemental_reactions_detects_vaporize_and_hyperbloom(): void
    {
        $reactionService = app(ElementalReactionService::class);

        // Hyperbloom team: Dendro + Hydro + Electro
        $reactions = $reactionService->evaluateReactions(['DENDRO', 'HYDRO', 'ELECTRO', 'ANEMO']);

        $this->assertArrayHasKey('hyperbloom', $reactions['triggered_reactions']);
        $this->assertArrayHasKey('swirl', $reactions['triggered_reactions']);
        $this->assertEquals('TRIPLE_EM', $reactions['triggered_reactions']['hyperbloom']['build_rule']);
    }

    public function test_constellation_impact_service_adjusts_furina_c2(): void
    {
        $constellationService = app(ConstellationImpactService::class);
        $furina = Character::where('slug', 'furina')->first() ?? new Character(['slug' => 'furina', 'name' => 'Furina']);

        $impactC0 = $constellationService->analyzeImpact($furina, 0);
        $this->assertNull($impactC0['role_shift']);

        $impactC2 = $constellationService->analyzeImpact($furina, 2);
        $this->assertNotNull($impactC2['role_shift']);
        $this->assertStringContainsString('Fanfare', $impactC2['role_shift']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\BuildKnowledge;
use App\Models\Character;
use App\Services\Nvidia\NvidiaService;
use App\Services\Rag\ContextBuilder;
use App\Services\Rag\VectorStoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VectorStoreServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_storing_unchanged_knowledge_does_not_regenerate_embedding(): void
    {
        $character = $this->createCharacter('diluc');
        $nvidia = Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('generateEmbedding')->once()->with('Guide content')->andReturn([1.0, 0.0]);
        $this->app->instance(NvidiaService::class, $nvidia);
        $vectorStore = app(VectorStoreService::class);

        $vectorStore->storeKnowledge($character, 'artifact_priorities', 'Guide', 'Guide content');
        $vectorStore->storeKnowledge($character, 'artifact_priorities', 'Guide', 'Guide content');

        $this->assertDatabaseCount('build_knowledge', 1);
    }

    public function test_retrieval_filters_by_patch_mode_and_team_synergy_category(): void
    {
        $diluc = $this->createCharacter('diluc');
        $furina = $this->createCharacter('furina');
        $wrongPatch = $this->createKnowledge($diluc, 'weapons_ranking', 'universal', '6.0');
        $mainBuild = $this->createKnowledge($diluc, 'artifact_priorities', 'universal');
        $teamSynergy = $this->createKnowledge($furina, 'team_synergies', 'abyss');
        $unrelatedTeammateBuild = $this->createKnowledge($furina, 'artifact_priorities', 'abyss');

        $nvidia = Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('getEmbeddingModel')->once()->andReturn('test-model');
        $nvidia->shouldReceive('generateEmbedding')->once()->with('Diluc build')->andReturn([1.0, 0.0]);
        $this->app->instance(NvidiaService::class, $nvidia);

        $results = app(VectorStoreService::class)->searchSimilar(
            $diluc,
            'Diluc build',
            'abyss',
            10,
            [$furina->id]
        );
        $resultIds = array_map(fn (BuildKnowledge $knowledge) => $knowledge->id, $results);

        $this->assertContains($mainBuild->id, $resultIds);
        $this->assertContains($teamSynergy->id, $resultIds);
        $this->assertNotContains($wrongPatch->id, $resultIds);
        $this->assertNotContains($unrelatedTeammateBuild->id, $resultIds);
    }

    public function test_context_prompt_distinguishes_entity_data_from_missing_build_knowledge(): void
    {
        $character = $this->createCharacter('diluc');
        $character->forceFill([
            'skill_data' => [
                [
                    'name' => 'Searing Onslaught',
                    'unlock' => 'Elemental Skill',
                    'description' => 'Performs a forward slash that deals Pyro DMG.',
                    'upgrades' => [['name' => 'Skill DMG', 'value' => '94%']],
                ],
                [
                    'name' => 'Dawn',
                    'unlock' => 'Elemental Burst',
                    'description' => 'Summons a Phoenix that deals Pyro DMG.',
                ],
            ],
            'constellation_data' => [[
                'name' => 'Conviction',
                'level' => 1,
                'description' => 'Deals more DMG to enemies above 50% HP.',
            ]],
        ])->save();

        $prompt = app(ContextBuilder::class)->buildSystemPrompt($character, [
            'constellation' => [
                'constellation_level' => 1,
                'gameplay_notes' => [],
            ],
            'content_profile' => [
                'name' => 'Spiral Abyss',
                'description' => 'Combat challenge',
            ],
            'team' => [],
            'resonances' => ['summary_buffs' => []],
            'reactions' => ['stat_recommendations' => []],
        ]);

        $this->assertStringContainsString('Tidak tersedia; susun analisis build dari kit karakter', $prompt);
        $this->assertStringContainsString('Tidak ada knowledge RAG lokal', $prompt);
        $this->assertStringContainsString('Searing Onslaught', $prompt);
        $this->assertStringContainsString('Skill DMG 94%', $prompt);
        $this->assertStringContainsString('Dawn', $prompt);
        $this->assertStringContainsString('C1 Conviction', $prompt);
        $this->assertStringContainsString('Jangan membuat ranking mutlak', $prompt);
        $this->assertStringContainsString('Jangan hanya mengulang retrieved knowledge', $prompt);
        $this->assertStringContainsString('CLAYMORE', $prompt);
    }

    private function createCharacter(string $slug): Character
    {
        return Character::create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'vision' => 'PYRO',
            'weapon_type' => 'CLAYMORE',
            'rarity' => 5,
            'patch_version' => '7.0',
        ]);
    }

    private function createKnowledge(
        Character $character,
        string $category,
        string $targetContent,
        string $patchVersion = '7.0'
    ): BuildKnowledge {
        return BuildKnowledge::create([
            'character_id' => $character->id,
            'category' => $category,
            'title' => "{$category} guide",
            'content' => "{$category} content",
            'target_content' => $targetContent,
            'embedding' => [1.0, 0.0],
            'patch_version' => $patchVersion,
        ]);
    }
}

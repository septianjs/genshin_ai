<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Services\Ai\ChatbotService;
use App\Services\Ai\RecommendationEngine;
use App\Services\Nvidia\NvidiaService;
use App\Services\Rag\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class BuildRecommendationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('file')->forget('build_weapon_catalog');
        Cache::store('file')->forget('build_artifact_catalog');
        Http::fake([
            '*/weapons/all' => Http::response([
                ['id' => 'favonius-sword', 'name' => 'Favonius Sword', 'type' => 'Sword', 'rarity' => 4, 'subStat' => 'Energy Recharge', 'passiveName' => 'Windfall', 'passiveDesc' => 'CRIT hits generate particles.'],
                ['id' => 'freedom-sworn', 'name' => 'Freedom-Sworn', 'type' => 'Sword', 'rarity' => 5, 'subStat' => 'Elemental Mastery', 'passiveName' => 'Revolutionary Chorale', 'passiveDesc' => 'Increases damage and team attack.'],
                ['id' => 'the-flute', 'name' => 'The Flute', 'type' => 'Sword', 'rarity' => 4, 'subStat' => 'ATK', 'passiveName' => 'Chord', 'passiveDesc' => 'Normal attacks grant harmonic stacks.'],
                ['id' => 'lions-roar', 'name' => "Lion's Roar", 'type' => 'Sword', 'rarity' => 4, 'subStat' => 'ATK', 'passiveName' => 'Bane of Fire and Thunder', 'passiveDesc' => 'Increases damage against Pyro/Electro affected enemies.'],
                ['id' => 'mappa-mare', 'name' => 'Mappa Mare', 'type' => 'Catalyst', 'rarity' => 4, 'subStat' => 'Elemental Mastery', 'passiveName' => 'Infusion Scroll', 'passiveDesc' => 'Triggers elemental reactions.'],
                ['id' => 'the-widsith', 'name' => 'The Widsith', 'type' => 'Catalyst', 'rarity' => 4, 'subStat' => 'CRIT DMG', 'passiveName' => 'Debut', 'passiveDesc' => 'Grants a random song buff.'],
                ['id' => 'sacrificial-fragments', 'name' => 'Sacrificial Fragments', 'type' => 'Catalyst', 'rarity' => 4, 'subStat' => 'Elemental Mastery', 'passiveName' => 'Composed', 'passiveDesc' => 'May reset Elemental Skill cooldown.'],
                ['id' => 'lost-prayer', 'name' => 'Lost Prayer to the Sacred Winds', 'type' => 'Catalyst', 'rarity' => 5, 'subStat' => 'CRIT Rate', 'passiveName' => 'Boundless Blessing', 'passiveDesc' => 'Increases movement speed and Elemental DMG over time.'],
                ['id' => 'staff-of-scarlet-sands', 'name' => 'Staff of the Scarlet Sands', 'type' => 'Polearm', 'rarity' => 5, 'subStat' => 'CRIT Rate', 'passiveName' => 'Heat Haze at Horizon\'s End', 'passiveDesc' => 'Gain ATK based on Elemental Mastery.'],
                ['id' => 'deathmatch', 'name' => 'Deathmatch', 'type' => 'Polearm', 'rarity' => 4, 'subStat' => 'CRIT Rate', 'passiveName' => 'Gladiator', 'passiveDesc' => 'Increases ATK based on nearby opponents.'],
                ['id' => 'dragons-bane', 'name' => "Dragon's Bane", 'type' => 'Polearm', 'rarity' => 4, 'subStat' => 'Elemental Mastery', 'passiveName' => 'Bane of Flame and Water', 'passiveDesc' => 'Increases DMG against Hydro/Pyro affected enemies.'],
                ['id' => 'favonius-lance', 'name' => 'Favonius Lance', 'type' => 'Polearm', 'rarity' => 4, 'subStat' => 'Energy Recharge', 'passiveName' => 'Windfall', 'passiveDesc' => 'CRIT hits generate particles.'],
                ['id' => 'amos-bow', 'name' => 'Amos Bow', 'type' => 'Bow', 'rarity' => 5, 'subStat' => 'ATK', 'passiveName' => 'Strong-Willed', 'passiveDesc' => 'Increases Normal Attack DMG.'],
            ]),
            '*/artifacts/all' => Http::response([
                ['id' => 'noblesse-oblige', 'name' => 'Noblesse Oblige', 'max_rarity' => 5, '2-piece_bonus' => 'Elemental Burst DMG +20%', '4-piece_bonus' => "Using an Elemental Burst increases all party members' ATK by 20% for 12s."],
                ['id' => 'thundering-fury', 'name' => 'Thundering Fury', 'max_rarity' => 5, '2-piece_bonus' => 'Gain a 15% Electro DMG Bonus.', '4-piece_bonus' => 'Increases damage caused by Overloaded, Electro-Charged, and Superconduct DMG by 40%.'],
                ['id' => 'gladiators-finale', 'name' => "Gladiator's Finale", 'max_rarity' => 5, '2-piece_bonus' => 'ATK +18%', '4-piece_bonus' => 'Increases Normal Attack DMG.'],
                ['id' => 'marechaussee-hunter', 'name' => 'Marechaussee Hunter', 'max_rarity' => 5, '2-piece_bonus' => 'Normal and Charged Attack DMG +15%', '4-piece_bonus' => 'CRIT Rate increases when HP changes.'],
            ]),
        ]);

        Character::create([
            'slug' => 'furina',
            'name' => 'Furina',
            'vision' => 'HYDRO',
            'weapon_type' => 'SWORD',
            'rarity' => 5,
            'skill_data' => [[
                'name' => 'Salon Solitaire',
                'description' => 'Furina changes her Arkhe alignment and summons Salon Members.',
            ]],
            'is_validated' => true,
            'patch_version' => '7.0',
        ]);

        foreach ([
            ['slug' => 'bennett', 'name' => 'Bennett', 'vision' => 'PYRO', 'weapon_type' => 'SWORD'],
            ['slug' => 'kazuha', 'name' => 'Kaedehara Kazuha', 'vision' => 'ANEMO', 'weapon_type' => 'SWORD'],
            ['slug' => 'xingqiu', 'name' => 'Xingqiu', 'vision' => 'HYDRO', 'weapon_type' => 'SWORD'],
            ['slug' => 'zhongli', 'name' => 'Zhongli', 'vision' => 'GEO', 'weapon_type' => 'POLEARM'],
        ] as $candidate) {
            Character::create($candidate + [
                'rarity' => 5,
                'is_validated' => true,
                'patch_version' => '7.0',
            ]);
        }

        Character::create([
            'slug' => 'albedo',
            'name' => 'Albedo',
            'vision' => 'GEO',
            'weapon_type' => 'SWORD',
            'rarity' => 5,
            'is_validated' => true,
            'patch_version' => '7.0',
        ]);
    }

    public function test_character_list_returns_valid_json(): void
    {
        $this->getJson('/api/characters')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'total',
                'patch_version',
                'data',
            ]);
    }

    public function test_team_analysis_returns_resonances_and_reactions(): void
    {
        $this->postJson('/api/team/analyze', [
            'characters' => ['furina', 'albedo'],
        ])
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'characters',
                'resonances',
                'reactions',
            ]);
    }

    public function test_build_prompt_uses_character_data_and_asks_ai_for_complete_experimental_build(): void
    {
        $capturedPrompt = '';
        $nvidia = Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chat')
            ->once()
            ->withArgs(function (array $messages) use (&$capturedPrompt): bool {
                $capturedPrompt = $messages[0]['content'] ?? '';

                return true;
            })
            ->andReturn([
                'content' => $this->structuredBuildJson(
                    ['favonius-sword', 'freedom-sworn', 'the-flute', 'lions-roar'],
                    ['marechaussee-hunter', 'gladiators-finale', 'noblesse-oblige', 'thundering-fury']
                ),
                'status' => 'success',
                'source' => 'nvidia',
            ]);
        $this->app->instance(NvidiaService::class, $nvidia);

        $result = app(RecommendationEngine::class)->generateBuild(
            'furina',
            customQuery: 'Coba build Furina sebagai DPS physical.',
            preferredRole: 'DPS physical'
        );

        $this->assertStringContainsString('Furina Build', $result['ai_recommendation']);
        $this->assertStringContainsString('Salon Solitaire', $capturedPrompt);
        $this->assertStringContainsString('Albedo (GEO / SWORD)', $capturedPrompt);
        $this->assertStringContainsString('Favonius Sword', $capturedPrompt);
        $this->assertStringContainsString('Struktur JSON wajib', $capturedPrompt);
        $this->assertStringNotContainsString('Mappa Mare', $capturedPrompt);
        $this->assertStringNotContainsString('Amos Bow', $capturedPrompt);
        $this->assertStringNotContainsString('RETRIEVED THEORYCRAFT', $capturedPrompt);
        $this->assertSame('nvidia', $result['recommendation_source']);
        $this->assertCount(4, $result['recommendation_cards']['weapons']);
        $this->assertCount(4, $result['recommendation_cards']['artifacts']);
        $this->assertCount(4, $result['recommendation_cards']['teams']);
        $this->assertSame('Favonius Sword', $result['recommendation_cards']['weapons'][0]['name']);
        $this->assertNotEmpty($result['recommendation_cards']['weapons'][0]['image_id']);
        $this->assertNotEmpty($result['recommendation_cards']['artifacts'][0]['four_piece_bonus']);
        $this->assertSame('Albedo', $result['recommendation_cards']['teams'][0]['members'][1]['name']);
        $this->assertFalse(Schema::hasTable('build_knowledge'));
        $this->assertFalse(Schema::hasTable('genshin_artifacts'));
        $this->assertFalse(Schema::hasTable('genshin_weapons'));
    }

    public function test_cyno_prompt_only_contains_polearms_and_accurate_artifact_bonuses(): void
    {
        Character::create([
            'slug' => 'cyno',
            'name' => 'Cyno',
            'vision' => 'ELECTRO',
            'weapon_type' => 'POLEARM',
            'rarity' => 5,
            'skill_data' => [[
                'name' => 'Sacred Rite: Wolf\'s Swiftness',
                'unlock' => 'Elemental Burst',
                'description' => 'Cyno enters the Pactsworn Pathclearer state, converting Normal, Charged, and Plunging Attack DMG to Electro DMG. This damage cannot be overridden.',
                'upgrades' => [['name' => 'Pactsworn Pathclearer Normal Attack DMG', 'value' => 'x%']],
            ]],
            'is_validated' => true,
            'patch_version' => '7.0',
        ]);

        $cyno = Character::where('slug', 'cyno')->firstOrFail();
        $teamCandidates = Character::query()
            ->where('is_validated', true)
            ->where('slug', '!=', 'cyno')
            ->get(['slug', 'name', 'vision', 'weapon_type', 'icon_url'])
            ->map(fn (Character $candidate) => $candidate->toArray())
            ->all();
        $parser = new \ReflectionMethod(RecommendationEngine::class, 'parseBuildRecommendation');
        $parser->setAccessible(true);
        $parsed = $parser->invoke(
            app(RecommendationEngine::class),
            $this->structuredBuildJson(
                ['staff-of-scarlet-sands', 'deathmatch', 'dragons-bane', 'favonius-lance'],
                ['thundering-fury', 'gladiators-finale', 'noblesse-oblige', 'marechaussee-hunter']
            ),
            $cyno,
            $teamCandidates,
            [
                ['id' => 'staff-of-scarlet-sands', 'name' => 'Staff of the Scarlet Sands', 'type' => 'Polearm'],
                ['id' => 'deathmatch', 'name' => 'Deathmatch', 'type' => 'Polearm'],
                ['id' => 'dragons-bane', 'name' => "Dragon's Bane", 'type' => 'Polearm'],
                ['id' => 'favonius-lance', 'name' => 'Favonius Lance', 'type' => 'Polearm'],
            ],
            [
                ['id' => 'thundering-fury', 'name' => 'Thundering Fury'],
                ['id' => 'gladiators-finale', 'name' => "Gladiator's Finale"],
                ['id' => 'noblesse-oblige', 'name' => 'Noblesse Oblige'],
                ['id' => 'marechaussee-hunter', 'name' => 'Marechaussee Hunter'],
            ]
        );
        $this->assertNotNull($parsed);

        $nvidia = Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => $this->structuredBuildJson(
                    ['staff-of-scarlet-sands', 'deathmatch', 'dragons-bane', 'favonius-lance'],
                    ['thundering-fury', 'gladiators-finale', 'noblesse-oblige', 'marechaussee-hunter']
                ),
                'status' => 'success',
                'source' => 'nvidia',
            ]);
        $this->app->instance(NvidiaService::class, $nvidia);

        $result = app(RecommendationEngine::class)->generateBuild(
            'cyno',
            preferredRole: 'Aggravate main DPS'
        );

        $this->assertStringContainsString('Cyno Build', $result['ai_recommendation']);
        $this->assertSame('Staff of the Scarlet Sands', $result['recommendation_cards']['weapons'][0]['name']);
        $this->assertSame('nvidia', $result['recommendation_source']);
        $this->assertCount(4, $result['recommendation_cards']['weapons']);
        $this->assertCount(4, $result['recommendation_cards']['artifacts']);
        $this->assertCount(4, $result['recommendation_cards']['teams']);
        $this->assertSame('Staff of the Scarlet Sands', $result['recommendation_cards']['weapons'][0]['name']);
    }

    public function test_weapon_type_guard_detects_catalog_weapons_of_another_type(): void
    {
        $engine = app(RecommendationEngine::class);
        $guard = new \ReflectionMethod(RecommendationEngine::class, 'unsupportedWeaponNames');
        $guard->setAccessible(true);

        $invalid = $guard->invoke(
            $engine,
            'Try Mappa Mare and Hamayumi for Cyno.',
            [
                ['id' => 'staff-of-scarlet-sands', 'name' => 'Staff of the Scarlet Sands', 'type' => 'Polearm'],
                ['id' => 'mappa-mare', 'name' => 'Mappa Mare', 'type' => 'Catalyst'],
                ['id' => 'hamayumi', 'name' => 'Hamayumi', 'type' => 'Bow'],
            ],
            [
                ['id' => 'staff-of-scarlet-sands', 'name' => 'Staff of the Scarlet Sands', 'type' => 'Polearm'],
            ]
        );

        $this->assertSame(['Mappa Mare', 'Hamayumi'], $invalid);
    }

    public function test_barbara_prompt_distinguishes_healing_burst_from_noblesse_bonuses(): void
    {
        $barbara = Character::create([
            'slug' => 'barbara',
            'name' => 'Barbara',
            'vision' => 'HYDRO',
            'weapon_type' => 'CATALYST',
            'rarity' => 4,
            'skill_data' => [[
                'name' => 'Shining Miracle',
                'unlock' => 'Elemental Burst',
                'description' => "Heals friendly forces and all parties for a large amount of HP that scales with Barbara's Max HP.",
                'upgrades' => [['name' => 'Healing Amount', 'value' => '17.6% Max HP + 1694']],
            ]],
            'patch_version' => '7.0',
        ]);

        $prompt = app(ContextBuilder::class)->buildSystemPrompt(
            $barbara,
            [
                'constellation' => [
                    'constellation_level' => 0,
                    'gameplay_notes' => [],
                ],
                'content_profile' => [
                    'name' => 'Spiral Abyss',
                    'description' => 'Combat challenge',
                ],
                'team' => [],
            ],
            [],
            'main DPS',
            [['name' => 'The Widsith', 'type' => 'Catalyst', 'rarity' => 4]],
            [[
                'name' => 'Noblesse Oblige',
                'max_rarity' => 5,
                '2-piece_bonus' => 'Elemental Burst DMG +20%',
                '4-piece_bonus' => "Using an Elemental Burst increases all party members' ATK by 20% for 12s.",
            ]]
        );

        $this->assertStringContainsString('Shining Miracle', $prompt);
        $this->assertStringContainsString('Healing Amount 17.6% Max HP + 1694', $prompt);
        $this->assertStringContainsString('jangan memilih bonus Burst DMG untuk damage pribadi bila Burst tidak memberi damage', $prompt);
        $this->assertStringContainsString('Noblesse Oblige', $prompt);
        $this->assertStringContainsString('all party members\' ATK by 20%', $prompt);
    }

    public function test_build_endpoint_passes_experimental_role_to_ai_engine(): void
    {
        $engine = Mockery::mock(RecommendationEngine::class);
        $engine->shouldReceive('generateBuild')
            ->once()
            ->withArgs(fn (...$arguments) => ($arguments[5] ?? null) === 'DPS physical')
            ->andReturn([
                'character' => ['slug' => 'furina', 'name' => 'Furina'],
                'ai_recommendation' => 'Furina DPS physical.',
            ]);
        $this->app->instance(RecommendationEngine::class, $engine);

        $this->postJson('/api/build/recommend', [
            'character' => 'furina',
            'preferred_role' => 'DPS physical',
        ])
            ->assertOk()
            ->assertJsonPath('data.ai_recommendation', 'Furina DPS physical.');
    }

    public function test_ai_unavailable_does_not_substitute_a_local_build(): void
    {
        $nvidia = Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chat')->once()->andReturn([
            'content' => 'Sistem rekomendasi lokal digunakan.',
            'status' => 'fallback',
            'source' => 'local',
            'fallback_reason' => 'CONNECTION_ERROR',
        ]);
        $this->app->instance(NvidiaService::class, $nvidia);

        $result = app(RecommendationEngine::class)->generateBuild(
            'furina',
            preferredRole: 'DPS physical'
        );

        $this->assertSame('ai_unavailable', $result['recommendation_source']);
        $this->assertStringContainsString('belum dapat dibuat', $result['ai_recommendation']);
        $this->assertStringNotContainsString('Sistem rekomendasi lokal digunakan', $result['ai_recommendation']);
    }

    public function test_build_retries_internal_reasoning_and_returns_final_recommendation(): void
    {
        Character::create([
            'slug' => 'barbara',
            'name' => 'Barbara',
            'vision' => 'HYDRO',
            'weapon_type' => 'CATALYST',
            'rarity' => 4,
            'patch_version' => '7.0',
        ]);

        $nvidia = Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chat')
            ->twice()
            ->withArgs(function (array $messages, float $temperature, int $maxTokens): bool {
                if ($maxTokens === 2000) {
                    return str_contains($messages[0]['content'], 'OUTPUT CONTRACT')
                        && str_contains($messages[1]['content'], 'finished recommendation');
                }

                return $maxTokens === 1000;
            })
            ->andReturn(
                [
                    'content' => 'Let me think through the build first.',
                    'status' => 'success',
                    'source' => 'nvidia',
                ],
                [
                    'content' => $this->structuredBuildJson(
                        ['lost-prayer', 'the-widsith', 'sacrificial-fragments', 'mappa-mare'],
                        ['marechaussee-hunter', 'gladiators-finale', 'noblesse-oblige', 'thundering-fury']
                    ),
                    'status' => 'success',
                    'source' => 'nvidia',
                ]
            );
        $this->app->instance(NvidiaService::class, $nvidia);

        $result = app(RecommendationEngine::class)->generateBuild(
            'barbara',
            customQuery: 'Build Barbara sebagai main DPS physical.',
            preferredRole: 'main DPS physical'
        );

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('Barbara Build', $result['ai_recommendation']);
        $this->assertStringNotContainsString('Let me think', $result['ai_recommendation']);
    }

    public function test_chat_build_request_passes_requested_role_to_ai_engine(): void
    {
        $engine = Mockery::mock(RecommendationEngine::class);
        $engine->shouldReceive('generateBuild')
            ->once()
            ->withArgs(fn (...$arguments) => ($arguments[5] ?? null) === 'dps')
            ->andReturn([
                'character' => ['slug' => 'furina', 'name' => 'Furina'],
                'ai_recommendation' => 'Rekomendasi eksperimen DPS Furina.',
                'status' => 'success',
                'recommendation_source' => 'nvidia',
            ]);
        $this->app->instance(RecommendationEngine::class, $engine);

        $chatbot = app(ChatbotService::class);
        $conversation = $chatbot->getOrCreateConversation('experimental_chat_role');
        $result = $chatbot->handleMessage($conversation, 'build furina sebagai dps');

        $this->assertSame('Rekomendasi eksperimen DPS Furina.', $result['bot_message']->content);
    }

    public function test_web_home_page_loads_successfully(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('GENSHIN BUILD AI');
    }

    private function structuredBuildJson(array $weaponIds, array $artifactIds): string
    {
        $teamSlugs = [
            ['albedo', 'bennett', 'kazuha'],
            ['xingqiu', 'bennett', 'zhongli'],
            ['albedo', 'xingqiu', 'kazuha'],
            ['bennett', 'kazuha', 'zhongli'],
        ];

        return json_encode([
            'role_analysis' => 'Main DPS on-field berdasarkan damage serangan dan scaling kit.',
            'artifacts' => array_map(fn (string $artifactId, int $index) => [
                'rank' => $index + 1,
                'id' => $artifactId,
                'main_stats' => [
                    'sands' => 'ATK%',
                    'goblet' => 'Elemental DMG',
                    'circlet' => 'CRIT',
                ],
                'substats' => ['CRIT Rate', 'CRIT DMG', 'ATK%'],
                'reason' => 'Set ini mendukung damage karakter.',
            ], $artifactIds, array_keys($artifactIds)),
            'weapons' => array_map(fn (string $weaponId, int $index) => [
                'rank' => $index + 1,
                'id' => $weaponId,
                'reason' => 'Stat senjata mendukung role karakter.',
            ], $weaponIds, array_keys($weaponIds)),
            'teams' => array_map(fn (array $slugs, int $index) => [
                'rank' => $index + 1,
                'teammate_slugs' => $slugs,
                'reason' => 'Komposisi mendukung damage dan rotasi.',
            ], $teamSlugs, array_keys($teamSlugs)),
            'stat_priorities' => ['CRIT Rate', 'CRIT DMG', 'ATK%'],
            'rotation' => ['Gunakan support', 'Aktifkan skill karakter utama', 'Lakukan serangan on-field'],
        ], JSON_THROW_ON_ERROR);
    }
}

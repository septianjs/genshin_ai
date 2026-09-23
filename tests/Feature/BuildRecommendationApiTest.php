<?php

namespace Tests\Feature;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildRecommendationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Character::create([
            'slug' => 'furina',
            'name' => 'Furina',
            'vision' => 'HYDRO',
            'weapon_type' => 'SWORD',
            'rarity' => 5,
            'is_validated' => true,
            'patch_version' => '7.0',
        ]);

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

    public function test_api_characters_list_returns_valid_json(): void
    {
        $response = $this->getJson('/api/characters');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'total',
                'patch_version',
                'data',
            ]);
    }

    public function test_api_team_analyze_returns_resonances_and_reactions(): void
    {
        $response = $this->postJson('/api/team/analyze', [
            'characters' => ['furina', 'albedo'],
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'characters',
                'resonances',
                'reactions',
            ]);
    }

    public function test_api_build_recommend_generates_recommendation(): void
    {
        $response = $this->postJson('/api/build/recommend', [
            'character' => 'furina',
            'constellation' => 0,
            'team' => ['albedo'],
            'content_mode' => 'abyss',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'character',
                    'mechanics',
                    'ai_recommendation',
                    'model',
                ]
            ]);
    }

    public function test_api_chat_send_stores_and_replies(): void
    {
        $response = $this->postJson('/api/chat/send', [
            'session_token' => 'test_session_123',
            'message' => 'Rekomendasi artefak untuk furina',
            'character' => 'furina',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'conversation_id',
                'message' => [
                    'id',
                    'role',
                    'content',
                ]
            ]);
    }

    public function test_web_home_page_loads_successfully(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('GENSHIN BUILD AI');
    }
}

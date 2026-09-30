<?php

namespace Tests\Feature;

use App\Models\BuildKnowledge;
use App\Models\Character;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\ChatbotService;
use App\Services\Ai\RecommendationEngine;
use App\Services\Nvidia\NvidiaService;
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
                ],
            ]);
    }

    public function test_api_build_recommend_can_skip_local_knowledge_when_requested(): void
    {
        $this->createDilucLocalKnowledge();

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('getEmbeddingModel')->once()->andReturn('test-embedding-model');
        $nvidia->shouldReceive('generateEmbedding')->once()->andReturn(array_fill(0, 384, 0.1));
        $nvidia->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => 'Layanan AI sedang tidak tersedia.',
                'status' => 'fallback',
                'source' => 'local',
                'fallback_reason' => 'CONNECTION_ERROR',
            ]);

        $this->app->instance(NvidiaService::class, $nvidia);

        $response = $this->postJson('/api/build/recommend', [
            'character' => 'diluc',
            'content_mode' => 'abyss',
            'use_local_knowledge' => false,
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('Layanan AI saat ini tidak tersedia', $response->json('data.ai_recommendation'));
        $this->assertSame('ai_unavailable', $response->json('data.recommendation_source'));
        $this->assertStringNotContainsString('# Build Diluc', $response->json('data.ai_recommendation'));
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
                ],
            ]);
    }

    public function test_build_recommendation_uses_local_knowledge_when_nvidia_is_unavailable(): void
    {
        $this->createDilucLocalKnowledge();

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('getEmbeddingModel')->once()->andReturn('test-embedding-model');
        $nvidia->shouldReceive('generateEmbedding')->once()->andReturn(array_fill(0, 384, 0.1));
        $nvidia->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => 'Sistem rekomendasi lokal digunakan.',
                'status' => 'fallback',
                'source' => 'local',
                'fallback_reason' => 'CONNECTION_ERROR',
            ]);

        $this->app->instance(NvidiaService::class, $nvidia);
        $result = app(RecommendationEngine::class)->generateBuild('diluc');

        $this->assertSame('fallback', $result['status']);
        $this->assertSame('local_knowledge', $result['recommendation_source']);
        $this->assertStringContainsString('# Build Diluc', $result['ai_recommendation']);
        $this->assertStringContainsString('Crimson Witch of Flames', $result['ai_recommendation']);
    }

    public function test_local_build_guide_is_available_for_arlecchino(): void
    {
        $this->createArlecchinoLocalKnowledge();

        $recommendation = app(RecommendationEngine::class)
            ->buildLocalRecommendation('arlecchino');

        $this->assertNotNull($recommendation);
        $this->assertStringContainsString('# Build Arlecchino', $recommendation);
        $this->assertStringContainsString('Fragment of Harmonic Whimsy', $recommendation);
        $this->assertStringContainsString('Vaporize', $recommendation);
        $this->assertStringContainsString('Normal Attack', $recommendation);
    }

    public function test_build_fallback_does_not_claim_to_recommend_without_local_guide(): void
    {
        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => 'Sistem rekomendasi lokal digunakan.',
                'status' => 'fallback',
                'source' => 'local',
                'fallback_reason' => 'CONNECTION_ERROR',
            ]);

        $this->app->instance(NvidiaService::class, $nvidia);
        $result = app(RecommendationEngine::class)->generateBuild('furina');

        $this->assertStringContainsString(
            'Koneksi AI tidak tersedia dan panduan lokal untuk Furina belum tersedia.',
            $result['ai_recommendation']
        );
        $this->assertStringNotContainsString('Rekomendasi tetap dibuat', $result['ai_recommendation']);
    }

    public function test_chat_uses_local_build_knowledge_when_nvidia_is_unavailable(): void
    {
        $this->createDilucLocalKnowledge();

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => 'Sistem rekomendasi lokal digunakan.',
                'status' => 'fallback',
                'source' => 'local',
                'fallback_reason' => 'CONNECTION_ERROR',
            ]);

        $this->app->instance(NvidiaService::class, $nvidia);
        $chatbot = app(ChatbotService::class);
        $conversation = $chatbot->getOrCreateConversation('local_build_non_stream', 'diluc');

        $result = $chatbot->handleMessage($conversation, 'build terbaik untuk diluc');

        $this->assertSame('local_knowledge', $result['bot_message']->meta_payload['recommendation_source']);
        $this->assertStringContainsString('# Build Diluc', $result['bot_message']->content);
        $this->assertStringContainsString('Crimson Witch of Flames', $result['bot_message']->content);
    }

    public function test_chat_greeting_does_not_return_active_characters_local_build_on_fallback(): void
    {
        $recommendationEngine = \Mockery::mock(RecommendationEngine::class);
        $recommendationEngine->shouldNotReceive('generateBuild');
        $recommendationEngine->shouldNotReceive('buildLocalRecommendation');
        $this->app->instance(RecommendationEngine::class, $recommendationEngine);

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldNotReceive('chat');
        $this->app->instance(NvidiaService::class, $nvidia);

        $chatbot = app(ChatbotService::class);
        $conversation = $chatbot->getOrCreateConversation('greeting_fallback', 'raiden');
        $result = $chatbot->handleMessage($conversation, 'hai');

        $this->assertStringStartsWith('Hai! 👋', $result['bot_message']->content);
        $this->assertSame('GREETING', $result['bot_message']->meta_payload['intent']);
        $this->assertNotSame('local_knowledge', $result['bot_message']->meta_payload['recommendation_source']);
    }

    public function test_chat_stream_http_endpoint_returns_greeting_without_calling_ai_or_build_pipeline(): void
    {
        $recommendationEngine = \Mockery::mock(RecommendationEngine::class);
        $recommendationEngine->shouldNotReceive('generateBuild');
        $recommendationEngine->shouldNotReceive('buildLocalRecommendation');
        $this->app->instance(RecommendationEngine::class, $recommendationEngine);

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldNotReceive('chat');
        $nvidia->shouldNotReceive('chatStream');
        $this->app->instance(NvidiaService::class, $nvidia);

        $response = $this->post('/api/chat/stream', [
            'session_token' => 'greeting_http_flow',
            'message' => 'hai',
            'character' => 'raiden',
        ], ['Accept' => 'text/event-stream']);

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('type":"chunk"', $content);
        $this->assertStringContainsString('Hai! 👋 Ada yang bisa saya bantu?', $content);
        $this->assertStringContainsString('[DONE]', $content);
    }

    public function test_chat_stream_http_endpoint_keeps_build_requests_inside_chatbot_flow(): void
    {
        Character::create([
            'slug' => 'barbara',
            'name' => 'Barbara',
            'vision' => 'HYDRO',
            'weapon_type' => 'CATALYST',
            'rarity' => 4,
            'patch_version' => '7.0',
        ]);

        $recommendationEngine = \Mockery::mock(RecommendationEngine::class);
        $recommendationEngine->shouldNotReceive('generateBuild');
        $this->app->instance(RecommendationEngine::class, $recommendationEngine);

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chatStream')
            ->once()
            ->andReturn([
                'content' => '# Build Barbara DPS',
                'status' => 'success',
                'source' => 'nvidia',
                'model' => 'test-model',
                'tokens_used' => 15,
            ]);
        $this->app->instance(NvidiaService::class, $nvidia);

        $response = $this->post('/api/chat/stream', [
            'session_token' => 'barbara_build_http_flow',
            'message' => 'hai, saya ingin build Barbara DPS',
            'character' => 'furina',
        ], ['Accept' => 'text/event-stream']);

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('# Build Barbara DPS', $content);
        $this->assertStringNotContainsString('type":"build_result"', $content);
        $this->assertStringNotContainsString('"slug":"barbara"', $content);
        $this->assertStringContainsString('[DONE]', $content);
    }

    public function test_chat_send_http_endpoint_keeps_build_requests_inside_chatbot_flow(): void
    {
        Character::create([
            'slug' => 'barbara',
            'name' => 'Barbara',
            'vision' => 'HYDRO',
            'weapon_type' => 'CATALYST',
            'rarity' => 4,
            'patch_version' => '7.0',
        ]);

        $recommendationEngine = \Mockery::mock(RecommendationEngine::class);
        $recommendationEngine->shouldNotReceive('generateBuild');
        $this->app->instance(RecommendationEngine::class, $recommendationEngine);

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => 'Jawaban chatbot untuk build Barbara tanpa memicu Generate Build.',
                'status' => 'success',
                'source' => 'nvidia',
                'model' => 'test-model',
                'tokens_used' => 15,
            ]);
        $this->app->instance(NvidiaService::class, $nvidia);

        $response = $this->postJson('/api/chat/send', [
            'session_token' => 'barbara_build_json_flow',
            'message' => 'build karakter Barbara DPS',
            'character' => 'furina',
        ]);

        $response->assertOk()
            ->assertJsonPath('message.content', 'Jawaban chatbot untuk build Barbara tanpa memicu Generate Build.')
            ->assertJsonPath('message.meta_payload.intent', 'BUILD_RECOMMENDATION')
            ->assertJsonPath('build_data', null);
    }

    public function test_build_follow_up_with_another_character_reuses_build_intent_in_chatbot_flow(): void
    {
        Character::create([
            'slug' => 'barbara',
            'name' => 'Barbara',
            'vision' => 'HYDRO',
            'weapon_type' => 'CATALYST',
            'rarity' => 4,
            'patch_version' => '7.0',
        ]);

        $conversation = Conversation::create([
            'session_token' => 'followup_barbara',
            'character_slug' => 'diluc',
            'target_content' => 'abyss',
            'patch_version' => '7.0',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '# Build Diluc',
        ]);

        $recommendationEngine = \Mockery::mock(RecommendationEngine::class);
        $recommendationEngine->shouldNotReceive('generateBuild');
        $this->app->instance(RecommendationEngine::class, $recommendationEngine);

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => 'Chatbot follow-up untuk Barbara tanpa memicu Generate Build.',
                'status' => 'success',
                'source' => 'nvidia',
                'model' => 'test-model',
                'tokens_used' => 10,
            ]);
        $this->app->instance(NvidiaService::class, $nvidia);

        $response = $this->postJson('/api/chat/send', [
            'session_token' => 'followup_barbara',
            'message' => 'kalau barbara',
            'character' => 'diluc',
        ]);

        $response->assertOk()
            ->assertJsonPath('message.content', 'Chatbot follow-up untuk Barbara tanpa memicu Generate Build.')
            ->assertJsonPath('message.meta_payload.intent', 'BUILD_RECOMMENDATION')
            ->assertJsonPath('build_data', null);
    }

    public function test_chat_follow_up_clears_stale_active_team_before_new_character_context(): void
    {
        Character::create([
            'slug' => 'barbara',
            'name' => 'Barbara',
            'vision' => 'HYDRO',
            'weapon_type' => 'CATALYST',
            'rarity' => 4,
            'patch_version' => '7.0',
        ]);

        $conversation = Conversation::create([
            'session_token' => 'stale_team_context',
            'character_slug' => 'diluc',
            'active_team' => ['albedo'],
            'target_content' => 'abyss',
            'patch_version' => '7.0',
        ]);

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => 'Tim lama tidak ikut dipakai karena konteks baru.',
                'status' => 'success',
                'source' => 'nvidia',
                'model' => 'test-model',
                'tokens_used' => 8,
            ]);
        $this->app->instance(NvidiaService::class, $nvidia);

        $result = app(ChatbotService::class)->handleMessage($conversation, 'mekanik barbara');

        $this->assertStringContainsString('Tim lama tidak ikut dipakai', $result['bot_message']->content);
        $this->assertSame('barbara', $conversation->fresh()->character_slug);
        $this->assertSame([], $conversation->fresh()->active_team);
    }

    public function test_mechanics_question_uses_matching_local_knowledge_when_ai_is_unavailable(): void
    {
        $this->createDilucLocalKnowledge();
        $diluc = Character::where('slug', 'diluc')->firstOrFail();
        BuildKnowledge::create([
            'character_id' => $diluc->id,
            'category' => 'weapons_ranking',
            'title' => 'Pilihan Senjata Diluc',
            'content' => 'Beacon of the Reed Sea dan Wolf\'s Gravestone adalah opsi kuat.',
            'target_content' => 'universal',
            'patch_version' => '7.0',
        ]);
        BuildKnowledge::create([
            'character_id' => $diluc->id,
            'category' => 'role_and_reactions',
            'title' => 'Mekanik dan reaksi Diluc',
            'content' => 'Diluc adalah DPS on-field Pyro yang dapat memanfaatkan Vaporize.',
            'target_content' => 'universal',
            'patch_version' => '7.0',
        ]);

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => '',
                'status' => 'fallback',
                'source' => 'local',
                'fallback_reason' => 'CONNECTION_ERROR',
            ]);
        $this->app->instance(NvidiaService::class, $nvidia);

        $conversation = app(ChatbotService::class)
            ->getOrCreateConversation('diluc_mechanics_fallback', 'diluc');
        $result = app(ChatbotService::class)->handleMessage($conversation, 'mekanik diluc');

        $this->assertStringContainsString('Mekanik dan reaksi Diluc', $result['bot_message']->content);
        $this->assertStringContainsString('Vaporize', $result['bot_message']->content);
        $this->assertSame('MECHANICS_QUESTION', $result['bot_message']->meta_payload['intent']);
        $this->assertSame('local_knowledge', $result['bot_message']->meta_payload['recommendation_source']);
    }

    public function test_artifact_stats_follow_up_uses_diluc_context_not_frontend_default_character(): void
    {
        $this->createDilucLocalKnowledge();
        $diluc = Character::where('slug', 'diluc')->firstOrFail();
        BuildKnowledge::create([
            'character_id' => $diluc->id,
            'category' => 'weapons_ranking',
            'title' => 'Pilihan Senjata Diluc',
            'content' => 'Beacon of the Reed Sea dan Wolf\'s Gravestone adalah opsi kuat.',
            'target_content' => 'universal',
            'patch_version' => '7.0',
        ]);
        BuildKnowledge::create([
            'character_id' => $diluc->id,
            'category' => 'artifact_priorities',
            'title' => 'Stat artefak Diluc',
            'content' => 'Prioritaskan CRIT Rate, CRIT DMG, ATK%, dan Elemental Mastery untuk tim Vaporize.',
            'target_content' => 'universal',
            'patch_version' => '7.0',
        ]);

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chatStream')
            ->twice()
            ->andReturn([
                'content' => '',
                'status' => 'fallback',
                'source' => 'local',
                'fallback_reason' => 'CONNECTION_ERROR',
            ]);
        $this->app->instance(NvidiaService::class, $nvidia);

        $firstResponse = $this->post('/api/chat/stream', [
            'session_token' => 'diluc_artifact_followup',
            'message' => 'senjata terbaik diluc',
            'character' => 'furina',
        ], ['Accept' => 'text/event-stream']);
        $firstResponse->assertOk();
        $this->assertStringContainsString(
            'Pilihan Senjata Diluc',
            $firstResponse->streamedContent()
        );

        $secondResponse = $this->post('/api/chat/stream', [
            'session_token' => 'diluc_artifact_followup',
            'message' => 'kalau stats artefak nya, nyari apa',
            'character' => 'furina',
        ], ['Accept' => 'text/event-stream']);
        $secondResponse->assertOk();
        $secondContent = $secondResponse->streamedContent();

        $this->assertStringContainsString('Stat artefak Diluc', $secondContent);
        $this->assertStringContainsString('Elemental Mastery', $secondContent);
        $this->assertStringNotContainsString('Build Furina', $secondContent);
        $assistantMessage = Message::query()
            ->where('conversation_id', Conversation::where('session_token', 'diluc_artifact_followup')->value('id'))
            ->where('role', 'assistant')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('diluc', $assistantMessage->meta_payload['character']);
        $this->assertSame('ARTIFACT_QUESTION', $assistantMessage->meta_payload['intent']);
    }

    public function test_chat_lists_local_build_guides_without_calling_nvidia(): void
    {
        $this->createDilucLocalKnowledge();
        $recommendationEngine = \Mockery::mock(RecommendationEngine::class);
        $recommendationEngine->shouldNotReceive('generateBuild');
        $this->app->instance(RecommendationEngine::class, $recommendationEngine);

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldNotReceive('chat');
        $this->app->instance(NvidiaService::class, $nvidia);

        $conversation = app(ChatbotService::class)->getOrCreateConversation(
            'local_knowledge_inventory',
            'furina'
        );
        $result = app(ChatbotService::class)->handleMessage(
            $conversation,
            'apa aja panduan lokal yang ada'
        );

        $this->assertStringContainsString('Panduan build lokal tersedia', $result['bot_message']->content);
        $this->assertStringContainsString('- Diluc', $result['bot_message']->content);
        $this->assertStringNotContainsString('- Furina', $result['bot_message']->content);
        $this->assertSame('KNOWLEDGE_STATUS', $result['bot_message']->meta_payload['intent']);
    }

    public function test_chat_stream_uses_local_build_knowledge_when_nvidia_is_unavailable(): void
    {
        $this->createDilucLocalKnowledge();

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chatStream')
            ->once()
            ->andReturn([
                'content' => 'Sistem rekomendasi lokal digunakan.',
                'status' => 'fallback',
                'source' => 'local',
                'fallback_reason' => 'CONNECTION_ERROR',
            ]);

        $this->app->instance(NvidiaService::class, $nvidia);
        $chatbot = app(ChatbotService::class);
        $conversation = $chatbot->getOrCreateConversation('local_build_stream', 'diluc');
        $deliveredContent = '';

        $result = $chatbot->streamMessage(
            $conversation,
            'build terbaik untuk diluc',
            static function (string $content) use (&$deliveredContent): void {
                $deliveredContent .= $content;
            }
        );

        $this->assertSame($result['bot_message']->content, $deliveredContent);
        $this->assertStringContainsString('# Build Diluc', $deliveredContent);
        $this->assertStringContainsString('Crimson Witch of Flames', $deliveredContent);
    }

    public function test_chat_stream_greeting_does_not_return_active_characters_local_build_on_fallback(): void
    {
        $recommendationEngine = \Mockery::mock(RecommendationEngine::class);
        $recommendationEngine->shouldNotReceive('generateBuild');
        $recommendationEngine->shouldNotReceive('buildLocalRecommendation');
        $this->app->instance(RecommendationEngine::class, $recommendationEngine);

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldNotReceive('chatStream');
        $this->app->instance(NvidiaService::class, $nvidia);

        $chatbot = app(ChatbotService::class);
        $conversation = $chatbot->getOrCreateConversation('greeting_stream_fallback', 'raiden');
        $deliveredContent = '';

        $result = $chatbot->streamMessage(
            $conversation,
            'hai',
            static function (string $content) use (&$deliveredContent): void {
                $deliveredContent .= $content;
            }
        );

        $this->assertStringStartsWith('Hai! 👋', $deliveredContent);
        $this->assertSame('GREETING', $result['bot_message']->meta_payload['intent']);
        $this->assertSame($deliveredContent, $result['bot_message']->content);
        $this->assertNotSame('local_knowledge', $result['bot_message']->meta_payload['recommendation_source']);
    }

    public function test_chat_stream_uses_local_guide_instead_of_internal_reasoning(): void
    {
        $this->createDilucLocalKnowledge();

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chatStream')
            ->once()
            ->andReturn([
                'content' => "Here's a thinking process:\n\n1. **Analyze User Input:** build diluc",
                'status' => 'success',
            ]);

        $this->app->instance(NvidiaService::class, $nvidia);
        $chatbot = app(ChatbotService::class);
        $conversation = $chatbot->getOrCreateConversation('reasoning_stream_test', 'diluc');
        $deliveredContent = '';

        $result = $chatbot->streamMessage(
            $conversation,
            'build diluc',
            static function (string $content) use (&$deliveredContent): void {
                $deliveredContent .= $content;
            }
        );

        $this->assertStringContainsString('# Build Diluc', $deliveredContent);
        $this->assertSame($deliveredContent, $result['bot_message']->content);
        $this->assertSame('local_knowledge', $result['bot_message']->meta_payload['recommendation_source']);
        $this->assertStringNotContainsString('thinking process', $result['bot_message']->content);
    }

    public function test_non_stream_chat_uses_local_guide_instead_of_internal_reasoning(): void
    {
        $this->createDilucLocalKnowledge();

        $nvidia = \Mockery::mock(NvidiaService::class);
        $nvidia->shouldReceive('chat')
            ->once()
            ->andReturn([
                'content' => "Here's a thinking process:\n\n1. **Analyze User Input:** build diluc",
                'status' => 'success',
            ]);

        $this->app->instance(NvidiaService::class, $nvidia);
        $chatbot = app(ChatbotService::class);
        $conversation = $chatbot->getOrCreateConversation('reasoning_non_stream_test', 'diluc');

        $result = $chatbot->handleMessage($conversation, 'build diluc');

        $this->assertStringContainsString('# Build Diluc', $result['bot_message']->content);
        $this->assertSame('local_knowledge', $result['bot_message']->meta_payload['recommendation_source']);
        $this->assertStringNotContainsString(
            'thinking process',
            $result['bot_message']->content
        );
    }

    public function test_web_home_page_loads_successfully(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('GENSHIN BUILD AI');
    }

    private function createDilucLocalKnowledge(): void
    {
        $diluc = Character::create([
            'slug' => 'diluc',
            'name' => 'Diluc',
            'vision' => 'PYRO',
            'weapon_type' => 'CLAYMORE',
            'rarity' => 5,
            'is_validated' => true,
            'patch_version' => '7.0',
        ]);

        BuildKnowledge::create([
            'character_id' => $diluc->id,
            'category' => 'artifact_priorities',
            'title' => 'Artefak Diluc',
            'content' => 'Gunakan 4-piece Crimson Witch of Flames.',
            'target_content' => 'universal',
            'patch_version' => '7.0',
        ]);
    }

    private function buildResponseFixture(string $name): array
    {
        return [
            'character' => [
                'slug' => strtolower($name),
                'name' => $name,
                'vision' => 'HYDRO',
                'weapon_type' => 'CATALYST',
                'rarity' => 4,
                'constellation' => 0,
            ],
            'teammates' => [],
            'mechanics' => [],
            'ai_recommendation' => "{$name} DPS recommendation",
            'model' => 'test-model',
            'status' => 'success',
            'source' => 'nvidia',
            'recommendation_source' => 'nvidia',
            'tokens_used' => 15,
        ];
    }

    private function createArlecchinoLocalKnowledge(): void
    {
        $arlecchino = Character::create([
            'slug' => 'arlecchino',
            'name' => 'Arlecchino',
            'vision' => 'PYRO',
            'weapon_type' => 'POLEARM',
            'rarity' => 5,
            'is_validated' => true,
            'patch_version' => '7.0',
        ]);

        BuildKnowledge::create([
            'character_id' => $arlecchino->id,
            'category' => 'artifact_priorities',
            'title' => 'Artefak Arlecchino',
            'content' => 'Gunakan 4-piece Fragment of Harmonic Whimsy.',
            'target_content' => 'universal',
            'patch_version' => '7.0',
        ]);

        BuildKnowledge::create([
            'character_id' => $arlecchino->id,
            'category' => 'team_synergies',
            'title' => 'Tim Arlecchino',
            'content' => 'Tim Vaporize menggunakan Xingqiu.',
            'target_content' => 'abyss',
            'patch_version' => '7.0',
        ]);

        BuildKnowledge::create([
            'character_id' => $arlecchino->id,
            'category' => 'rotation',
            'title' => 'Rotasi Arlecchino',
            'content' => 'Lanjutkan dengan Normal Attack.',
            'target_content' => 'abyss',
            'patch_version' => '7.0',
        ]);

        BuildKnowledge::create([
            'character_id' => $arlecchino->id,
            'category' => 'role_and_reactions',
            'title' => 'Peran Arlecchino',
            'content' => 'Arlecchino memicu Vaporize.',
            'target_content' => 'universal',
            'patch_version' => '7.0',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Services\QueryUnderstanding\AliasNormalizer;
use App\Services\QueryUnderstanding\EntityExtractor;
use App\Services\QueryUnderstanding\IntentClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueryUnderstandingTest extends TestCase
{
    use RefreshDatabase;
    public function test_alias_normalizer_resolves_community_slang(): void
    {
        $normalizer = app(AliasNormalizer::class);

        $this->assertEquals('raiden', $normalizer->normalizeCharacter('ei'));
        $this->assertEquals('raiden', $normalizer->normalizeCharacter('shogun'));
        $this->assertEquals('tartaglia', $normalizer->normalizeCharacter('childe'));
        $this->assertEquals('hu-tao', $normalizer->normalizeCharacter('tao'));
        $this->assertEquals('viridescent-venerer', $normalizer->normalizeArtifact('vv'));
        $this->assertEquals('abyss', $normalizer->normalizeContentMode('lantai 12'));
    }

    public function test_entity_extractor_parses_constellation_and_content_mode(): void
    {
        $extractor = app(EntityExtractor::class);

        $query = 'tolong build ei c2 buat abyss lantai 12 tim sara bennett kazuha';
        $extracted = $extractor->extract($query);

        $this->assertEquals('raiden', $extracted['target_character']);
        $this->assertEquals(2, $extracted['constellation']);
        $this->assertEquals('abyss', $extracted['content_mode']);
        $this->assertContains('sara', $extracted['all_detected_characters']);
    }

    public function test_intent_classifier_detects_intents(): void
    {
        $classifier = app(IntentClassifier::class);

        $this->assertEquals(IntentClassifier::INTENT_ROTATION, $classifier->classify('bagaimana urutan rotasi skill tim ini?'));
        $this->assertEquals(IntentClassifier::INTENT_WEAPON_COMPARE, $classifier->classify('bagusan the catch atau favonius buat raiden?'));
        $this->assertEquals(IntentClassifier::INTENT_BUILD, $classifier->classify('rekomendasi build furina full set'));
    }
}

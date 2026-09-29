<?php

namespace Tests\Feature;

use App\Models\Character;
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
        $this->assertEquals(IntentClassifier::INTENT_GREETING, $classifier->classify('hai'));
        $this->assertEquals(IntentClassifier::INTENT_GREETING, $classifier->classify('selamat malam'));
        $this->assertEquals(IntentClassifier::INTENT_BUILD, $classifier->classify('hai, saya ingin build Barbara DPS'));
        $this->assertEquals(IntentClassifier::INTENT_BUILD, $classifier->classify('saya ingin membuild karakter diluc'));
        $this->assertEquals(IntentClassifier::INTENT_ARTIFACT_QUESTION, $classifier->classify('artefak Diluc'));
        $this->assertEquals(IntentClassifier::INTENT_ARTIFACT_QUESTION, $classifier->classify('artifact Diluc'));
        $this->assertEquals(IntentClassifier::INTENT_ARTIFACT_QUESTION, $classifier->classify('stats artefak yang bagus apa'));
        $this->assertEquals(IntentClassifier::INTENT_ARTIFACT_QUESTION, $classifier->classify('main stat artefak Diluc'));
        $this->assertEquals(IntentClassifier::INTENT_ARTIFACT_QUESTION, $classifier->classify('substat artefak Diluc'));
        $this->assertEquals(IntentClassifier::INTENT_ARTIFACT_QUESTION, $classifier->classify('artefak terbaik Diluc'));
        $this->assertEquals(IntentClassifier::INTENT_WEAPON_QUESTION, $classifier->classify('senjata Diluc'));
        $this->assertEquals(IntentClassifier::INTENT_WEAPON_QUESTION, $classifier->classify('stats senjata Diluc'));
        $this->assertEquals(IntentClassifier::INTENT_REACTION, $classifier->classify('apa itu Overload?'));
        $this->assertEquals(IntentClassifier::INTENT_KNOWLEDGE_STATUS, $classifier->classify('apa aja panduan lokal yang ada'));
        $this->assertEquals(IntentClassifier::INTENT_WEAPON_QUESTION, $classifier->classify('hai, senjata Barbara apa?'));
        $this->assertEquals(IntentClassifier::INTENT_ARTIFACT_QUESTION, $classifier->classify('kalau stats artefak nya, nyari apa'));
        $this->assertEquals(IntentClassifier::INTENT_TEAM_SYNERGY, $classifier->classify('hai, Barbara cocok dengan siapa?'));
        $this->assertEquals(IntentClassifier::INTENT_REACTION, $classifier->classify('hai, apa itu Vaporize?'));
        $this->assertEquals(IntentClassifier::INTENT_MECHANICS, $classifier->classify('mekanik diluc'));
    }

    public function test_entity_extractor_keeps_character_id_and_requested_role(): void
    {
        Character::create([
            'slug' => 'barbara',
            'name' => 'Barbara',
            'vision' => 'HYDRO',
            'weapon_type' => 'CATALYST',
            'rarity' => 4,
            'patch_version' => '7.0',
        ]);

        $extracted = app(EntityExtractor::class)->extract('build karakter Barbara DPS');

        $this->assertSame('barbara', $extracted['target_character']);
        $this->assertSame('dps', $extracted['role']);
    }

    public function test_entity_extractor_differentiates_artifact_and_weapon_topics(): void
    {
        $extractor = app(EntityExtractor::class);

        $this->assertSame('artifact_stats', $extractor->extract('stats artefak Diluc')['topic']);
        $this->assertSame('artifact_stats', $extractor->extract('main stat artefak Diluc')['topic']);
        $this->assertSame('artifact_stats', $extractor->extract('substat artefak Diluc')['topic']);
        $this->assertSame('artifact_set', $extractor->extract('artefak terbaik Diluc')['topic']);
        $this->assertSame('weapon_recommendation', $extractor->extract('senjata Diluc')['topic']);
        $this->assertSame('weapon_stats', $extractor->extract('stats senjata Diluc')['topic']);
    }
}

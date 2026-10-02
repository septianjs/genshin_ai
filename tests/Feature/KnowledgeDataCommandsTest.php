<?php

namespace Tests\Feature;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class KnowledgeDataCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_character_dataset_import_supports_dry_run_and_skips_existing_ids(): void
    {
        Character::create([
            'slug' => 'diluc',
            'name' => 'Diluc',
            'vision' => 'PYRO',
            'weapon_type' => 'CLAYMORE',
            'rarity' => 5,
            'patch_version' => '7.0',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'genshin-characters-');
        $this->assertNotFalse($path);
        file_put_contents($path, json_encode([
            [
                'id' => 'diluc',
                'name' => 'Updated Diluc',
                'vision' => 'Pyro',
                'weapon' => 'Claymore',
                'rarity' => 5,
            ],
            [
                'id' => 'furina',
                'name' => 'Furina',
                'vision' => 'Hydro',
                'weapon' => 'Sword',
                'rarity' => 5,
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->assertSame(0, Artisan::call('genshin:import-characters', [
                'path' => $path,
                '--dry-run' => true,
            ]));
            $this->assertDatabaseCount('characters', 1);

            $this->assertSame(0, Artisan::call('genshin:import-characters', [
                'path' => $path,
            ]));
            $this->assertDatabaseCount('characters', 2);
            $this->assertDatabaseHas('characters', [
                'slug' => 'diluc',
                'name' => 'Diluc',
            ]);
            $this->assertDatabaseHas('characters', [
                'slug' => 'furina',
                'icon_url' => 'https://genshin.jmp.blue/characters/furina/icon-big',
            ]);
        } finally {
            unlink($path);
        }
    }

}

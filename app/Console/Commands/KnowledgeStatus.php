<?php

namespace App\Console\Commands;

use App\Models\BuildKnowledge;
use App\Models\Character;
use Illuminate\Console\Command;

class KnowledgeStatus extends Command
{
    protected $signature = 'genshin:knowledge-status {--patch= : Patch version to report}';

    protected $description = 'Report character and theorycraft knowledge coverage';

    public function handle(): int
    {
        $patch = (string) ($this->option('patch') ?: config('services.genshin.target_patch', '7.0'));
        $characters = Character::query()->where('patch_version', $patch)->orderBy('name')->get();
        $knowledgeCharacterIds = BuildKnowledge::query()
            ->where('patch_version', $patch)
            ->where('category', '!=', 'character_overview')
            ->distinct()
            ->pluck('character_id');
        $knowledgeCharacterIds = $knowledgeCharacterIds->all();
        $coveredCharacters = $characters->whereIn('id', $knowledgeCharacterIds);
        $missingCharacters = $characters->whereNotIn('id', $knowledgeCharacterIds);
        $coverage = $characters->isEmpty()
            ? 0
            : round(($coveredCharacters->count() / $characters->count()) * 100, 1);

        $this->info("Theorycraft coverage for patch {$patch}");
        $this->table(
            ['Characters in patch', 'With build knowledge', 'Without build knowledge', 'Coverage'],
            [[$characters->count(), $coveredCharacters->count(), $missingCharacters->count(), "{$coverage}%"]]
        );

        $this->line('Knowledge chunks by category:');
        $categoryCounts = BuildKnowledge::query()
            ->selectRaw('category, COUNT(*) as total')
            ->where('patch_version', $patch)
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(fn (BuildKnowledge $knowledge) => [$knowledge->category, $knowledge->total])
            ->all();
        $this->table(['Category', 'Chunks'], $categoryCounts ?: [['None', 0]]);

        $this->line('Characters without build knowledge:');
        if ($missingCharacters->isEmpty()) {
            $this->info('None');
        } else {
            foreach ($missingCharacters as $character) {
                $this->line("- {$character->name} ({$character->slug})");
            }
        }

        $this->comment('Weapon and artifact catalogs are not stored in the current database schema.');

        return Command::SUCCESS;
    }
}

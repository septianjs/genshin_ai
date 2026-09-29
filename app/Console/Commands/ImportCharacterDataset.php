<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Services\Ingestion\GameDataValidator;
use Illuminate\Console\Command;
use JsonException;

class ImportCharacterDataset extends Command
{
    protected $signature = 'genshin:import-characters
                            {path : Path to a JSON array of raw character records}
                            {--patch= : Patch version for imported records}
                            {--update-existing : Update characters whose IDs already exist}
                            {--dry-run : Validate and report without writing to the database}';

    protected $description = 'Import character entity data from a local JSON dataset';

    public function handle(GameDataValidator $validator): int
    {
        $path = $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Dataset file does not exist or cannot be read: {$path}");

            return Command::FAILURE;
        }

        try {
            $contents = file_get_contents($path);
            if ($contents === false) {
                $this->error("Unable to read dataset file: {$path}");

                return Command::FAILURE;
            }

            $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("Dataset contains invalid JSON: {$exception->getMessage()}");

            return Command::FAILURE;
        }

        if (! is_array($records) || ! array_is_list($records)) {
            $this->error('Dataset must contain a JSON array of character records.');

            return Command::FAILURE;
        }

        $patch = (string) ($this->option('patch') ?: config('services.genshin.target_patch', '7.0'));
        $updateExisting = (bool) $this->option('update-existing');
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['ready' => 0, 'skipped' => 0, 'invalid' => 0];
        $thirdPartyUrl = rtrim(config('services.genshin.third_party_url', 'https://genshin.jmp.blue'), '/');

        foreach ($records as $record) {
            if (! is_array($record)) {
                $counts['invalid']++;

                continue;
            }

            $character = $validator->validateAndSanitize($record, $patch);
            if ($character === null) {
                $counts['invalid']++;

                continue;
            }

            $character['icon_url'] ??= "{$thirdPartyUrl}/characters/{$character['slug']}/icon-big";
            $existing = Character::query()->where('slug', $character['slug'])->exists();
            if ($existing && ! $updateExisting) {
                $counts['skipped']++;

                continue;
            }

            $counts['ready']++;
            if (! $dryRun) {
                Character::updateOrCreate(['slug' => $character['slug']], $character);
            }
        }

        $this->table(
            ['Valid for import', 'Skipped existing', 'Invalid records', 'Mode'],
            [[$counts['ready'], $counts['skipped'], $counts['invalid'], $dryRun ? 'dry-run' : 'import']]
        );

        if ($counts['invalid'] > 0) {
            $this->warn('Some records were skipped because required character data was missing or invalid.');
        }

        return $counts['invalid'] === count($records) && count($records) > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Ingestion\RemoteGenshinDatasetService;
use Illuminate\Console\Command;

class SyncRemoteGenshinDataset extends Command
{
    protected $signature = 'genshin:sync-remote
        {--patch=7.0 : Patch version dataset}
        {--only=all : all, characters, artifacts, atau weapons}
        {--dry-run : Download dan validasi tanpa menyimpan database}';

    protected $description =
        'Sync characters, artifacts, dan weapons dari repository genshin_build';

    public function handle(
        RemoteGenshinDatasetService $service
    ): int {
        $patch =
            (string) $this->option('patch');

        $only =
            strtolower(
                trim(
                    (string) $this->option('only')
                )
            );

        $dryRun =
            (bool) $this->option('dry-run');

        $validOnly = [
            'all',
            'characters',
            'artifacts',
            'weapons',
        ];

        if (! in_array($only, $validOnly, true)) {
            $this->error(
                "Nilai --only tidak valid: {$only}"
            );

            $this->line(
                'Gunakan: all, characters, artifacts, weapons'
            );

            return self::FAILURE;
        }

        $this->info(
            '============================================'
        );

        $this->info(
            ' GENSHIN REMOTE DATASET SYNC'
        );

        $this->info(
            '============================================'
        );

        $this->line(
            "Patch : {$patch}"
        );

        $this->line(
            "Only  : {$only}"
        );

        $this->line(
            'Mode  : '
            .($dryRun
                ? 'DRY RUN'
                : 'IMPORT DATABASE')
        );

        $this->newLine();

        try {
            $result =
                $service->sync(
                    patchVersion: $patch,
                    dryRun: $dryRun,
                    onlyCharacters:
                        $only === 'characters',
                    onlyArtifacts:
                        $only === 'artifacts',
                    onlyWeapons:
                        $only === 'weapons',
                );
        } catch (\Throwable $e) {
            $this->error(
                'SYNC GAGAL'
            );

            $this->error(
                $e->getMessage()
            );

            return self::FAILURE;
        }

        $this->newLine();

        $this->info(
            'HASIL SYNC'
        );

        $this->table(
            [
                'Dataset',
                'Downloaded',
                'Imported',
                'Failed',
            ],
            [
                [
                    'Characters',
                    $result['characters']['downloaded'],
                    $result['characters']['imported'],
                    $result['characters']['failed'],
                ],

                [
                    'Artifacts',
                    $result['artifacts']['downloaded'],
                    $result['artifacts']['imported'],
                    $result['artifacts']['failed'],
                ],

                [
                    'Weapons',
                    $result['weapons']['downloaded'],
                    $result['weapons']['imported'],
                    $result['weapons']['failed'],
                ],
            ]
        );

        $failed =
            $result['characters']['failed']
            + $result['artifacts']['failed']
            + $result['weapons']['failed'];

        $this->newLine();

        if ($failed > 0) {
            $this->warn(
                "Sync selesai dengan {$failed} data gagal."
            );

            return self::FAILURE;
        }

        $this->info(
            $dryRun
                ? 'DRY RUN selesai. Tidak ada data yang disimpan.'
                : 'Dataset berhasil disinkronkan ke database.'
        );

        return self::SUCCESS;
    }
}
<?php

namespace App\Console\Commands;

use App\Services\Genshin\GenshinApiService;
use Illuminate\Console\Command;

class SyncGenshinData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'genshin:sync 
                            {--limit= : Batasi jumlah karakter yang disinkronkan}
                            {--patch=7.0 : Target patch version}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sinkronisasi data karakter dari API pihak ketiga ke database lokal dengan validasi skema';

    /**
     * Execute the console command.
     */
    public function handle(GenshinApiService $apiService): int
    {
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $patch = (string) ($this->option('patch') ?? '7.0');

        $this->info("Memulai sinkronisasi dataset karakter Genshin (Patch {$patch})...");

        if ($limit) {
            $this->comment("Dibatasi maksimal {$limit} karakter.");
        }

        $result = $apiService->syncAllCharacters($limit, $patch);

        $this->info("Sinkronisasi selesai!");
        $this->table(
            ['Total Diunduh', 'Berhasil Tervalidasi', 'Gagal'],
            [[$result['total'], $result['success'], $result['failed']]]
        );

        return $result['success'] > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}

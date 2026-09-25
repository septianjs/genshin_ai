<?php

namespace App\Console\Commands;

use App\Services\Nvidia\NvidiaService;
use Illuminate\Console\Command;

class TestNvidiaChat extends Command
{
    /**
     * Nama command.
     */
    protected $signature = 'app:test-nvidia-chat';

    /**
     * Deskripsi command.
     */
    protected $description = 'Menguji koneksi Chat NVIDIA dengan prompt sederhana';

    /**
     * Execute the console command.
     */
    public function handle(NvidiaService $nvidiaService): int
    {
        $this->info('========================================');
        $this->info(' TEST NVIDIA CHAT');
        $this->info('========================================');
        $this->newLine();

        $this->info('Mengirim request ke NVIDIA...');
        $this->info('Model: ' . $nvidiaService->getStatus()['model']);
        $this->newLine();

        $start = microtime(true);

        $result = $nvidiaService->chat(
            [
                [
                    'role' => 'user',
                    'content' => 'Jawab singkat: Apakah Furina adalah karakter Hydro di Genshin Impact?'
                ],
            ],
            0.2,
            50
        );

        $duration = microtime(true) - $start;

        $this->newLine();
        $this->info('========================================');
        $this->info(' HASIL');
        $this->info('========================================');

        $this->line('Status   : ' . ($result['status'] ?? 'unknown'));
        $this->line('Source   : ' . ($result['source'] ?? 'unknown'));
        $this->line('Model    : ' . ($result['model'] ?? 'unknown'));
        $this->line('Durasi   : ' . number_format($duration, 2) . ' detik');

        if (!empty($result['fallback_reason'])) {
            $this->line(
                'Fallback : ' . $result['fallback_reason']
            );
        }

        $this->newLine();

        $this->info('Response:');
        $this->line($result['content'] ?? '(tidak ada response)');

        $this->newLine();
        $this->info('========================================');

        if (($result['status'] ?? null) === 'success') {
            $this->info('TEST BERHASIL');

            return self::SUCCESS;
        }

        $this->error('TEST GAGAL / FALLBACK');

        return self::FAILURE;
    }
}

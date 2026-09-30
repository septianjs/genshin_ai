<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Services\Ingestion\KnowledgeDatasetParser;
use App\Services\QueryUnderstanding\AliasNormalizer;
use App\Services\Rag\VectorStoreService;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Throwable;

class ImportBuildKnowledge extends Command
{
    protected $signature = 'genshin:import-knowledge
                            {path : File (.json/.md) atau folder berisi panduan build}
                            {--patch= : Versi patch untuk chunk yang diimpor}
                            {--source=imported-local-dataset : Nilai kolom source}
                            {--character= : Hanya impor karakter ini (slug atau nama)}
                            {--dry-run : Validasi dan laporkan tanpa menulis ke database}';

    protected $description = 'Import panduan build (artefak, senjata, tim, ER, rotasi) dari file lokal ke build_knowledge';

    /** @var array<string, Character> */
    private array $bySlug = [];

    /** @var array<string, Character> */
    private array $byName = [];

    public function handle(
        KnowledgeDatasetParser $parser,
        VectorStoreService $vectorStore,
        AliasNormalizer $aliases
    ): int {
        $path = (string) $this->argument('path');
        $files = $this->collectFiles($path);

        if ($files === []) {
            $this->error("Tidak ada file .json/.md yang bisa dibaca di: {$path}");

            return Command::FAILURE;
        }

        foreach (Character::query()->get() as $character) {
            $this->bySlug[strtolower($character->slug)] = $character;
            $this->byName[$this->key($character->name)] = $character;
        }

        $only = null;
        if ($this->option('character')) {
            $only = $this->resolveCharacter((string) $this->option('character'), $aliases);
            if ($only === null) {
                $this->error('Karakter untuk --character tidak ditemukan di database.');

                return Command::FAILURE;
            }
        }

        $patch = (string) ($this->option('patch') ?: config('services.genshin.target_patch', '7.0'));
        $source = (string) $this->option('source');
        $dryRun = (bool) $this->option('dry-run');

        $groups = [];
        $unmatched = [];
        $unknownCategories = [];
        $malformed = 0;

        foreach ($files as $file) {
            $parsed = $parser->parseFile($file);

            foreach ($parsed['issues']['errors'] as $error) {
                $this->warn($error);
            }

            $malformed += $parsed['issues']['malformed'];
            foreach ($parsed['issues']['unknown_categories'] as $label => $count) {
                $unknownCategories[$label] = ($unknownCategories[$label] ?? 0) + $count;
            }

            foreach ($parsed['chunks'] as $chunk) {
                $character = null;
                foreach ((array) $chunk['character'] as $candidate) {
                    $character = $this->resolveCharacter((string) $candidate, $aliases);
                    if ($character !== null) {
                        break;
                    }
                }

                if ($character === null) {
                    $label = (string) (((array) $chunk['character'])[0] ?? '?');
                    $unmatched[$label] = ($unmatched[$label] ?? 0) + 1;

                    continue;
                }

                if ($only !== null && $character->id !== $only->id) {
                    continue;
                }

                $groupKey = "{$character->id}|{$chunk['category']}|{$chunk['target']}";
                $groups[$groupKey] ??= [
                    'character' => $character,
                    'category' => $chunk['category'],
                    'target' => $chunk['target'],
                    'title' => $chunk['title'] ?: $this->defaultTitle($chunk['category'], $character),
                    'contents' => [],
                ];
                $groups[$groupKey]['contents'][] = $chunk['content'];
            }
        }

        $perCharacter = [];
        $failed = 0;

        foreach ($groups as $group) {
            /** @var Character $character */
            $character = $group['character'];
            $content = implode("\n\n", array_values(array_unique($group['contents'])));

            if (! $dryRun) {
                try {
                    $vectorStore->storeKnowledge(
                        $character,
                        $group['category'],
                        $group['title'],
                        $content,
                        $group['target'],
                        $patch,
                        $source
                    );
                } catch (Throwable $exception) {
                    $failed++;
                    $this->warn("Gagal menyimpan {$character->name} / {$group['category']}: {$exception->getMessage()}");

                    continue;
                }
            }

            $perCharacter[$character->name][] = $group['category'];
        }

        ksort($perCharacter);
        $rows = [];
        foreach ($perCharacter as $name => $categories) {
            $rows[] = [$name, count($categories), implode(', ', $categories)];
        }

        $this->table(
            ['Karakter', 'Chunk', 'Kategori'],
            $rows ?: [['(tidak ada)', 0, '-']]
        );

        $total = array_sum(array_map('count', $perCharacter));
        $this->info(($dryRun ? '[dry-run] Siap diimpor: ' : 'Tersimpan: ')."{$total} chunk untuk ".count($perCharacter).' karakter (patch '.$patch.').');

        if ($unmatched !== []) {
            $this->warn('Nama karakter tidak cocok dengan database (dilewati):');
            foreach ($unmatched as $label => $count) {
                $this->line("  - {$label} ({$count} chunk)");
            }
            $this->line('  Tambahkan alias di config/genshin_aliases.php bila itu karakter yang sama.');
        }

        if ($unknownCategories !== []) {
            $this->warn('Bagian dengan kategori tidak dikenal (dilewati):');
            foreach ($unknownCategories as $label => $count) {
                $this->line("  - {$label} ({$count}x)");
            }
        }

        if ($malformed > 0) {
            $this->warn("{$malformed} entri dilewati karena isi atau kategori kosong.");
        }

        if ($failed > 0) {
            $this->warn("{$failed} chunk gagal disimpan.");
        }

        $this->comment('Katalog set artefak/senjata global belum punya tabel; panduan artefak per karakter disimpan sebagai kategori artifact_priorities.');

        return $total === 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function collectFiles(string $path): array
    {
        if (is_file($path) && is_readable($path)) {
            return [$path];
        }

        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        $finder = (new Finder)->files()->in($path)->name(['*.json', '*.md', '*.markdown'])->sortByName();
        foreach ($finder as $file) {
            $files[] = $file->getRealPath();
        }

        return $files;
    }

    private function resolveCharacter(string $raw, AliasNormalizer $aliases): ?Character
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $lower = strtolower($raw);
        $candidates = [
            $lower,
            str_replace([' ', '_'], '-', $lower),
            $aliases->normalizeCharacter($lower),
            $aliases->normalizeCharacter(str_replace(['-', '_'], ' ', $lower)),
        ];

        foreach ($candidates as $candidate) {
            if (isset($this->bySlug[$candidate])) {
                return $this->bySlug[$candidate];
            }
        }

        return $this->byName[$this->key($raw)] ?? null;
    }

    private function key(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }

    private function defaultTitle(string $category, Character $character): string
    {
        $label = match ($category) {
            'role_and_reactions' => 'Peran dan Reaksi',
            'weapons_ranking' => 'Senjata',
            'artifact_priorities' => 'Artefak dan Stat',
            'er_breakpoints' => 'Energy Recharge',
            'team_synergies' => 'Rekomendasi Tim',
            'rotation' => 'Rotasi',
            default => ucfirst(str_replace('_', ' ', $category)),
        };

        return "{$label} {$character->name}";
    }
}
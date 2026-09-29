<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$apiKey = trim((string) config('services.nvidia.api_key'));
$baseUrl = 'https://integrate.api.nvidia.com/v1';
$model = 'nvidia/nemotron-3.5-lightning-30b-a3b';
$timeoutSeconds = 60;

if ($apiKey === '') {
    echo "NVIDIA API key is not configured.\n";
    exit(1);
}

function benchmarkRequest(
    string $baseUrl,
    string $apiKey,
    string $model,
    array $messages,
    int $maxTokens = 25,
    float $temperature = 0.2,
    float $topP = 0.95,
    bool $stream = false,
    int $timeoutSeconds = 60
): array {
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'top_p' => $topP,
        'max_tokens' => $maxTokens,
        'stream' => $stream,
        'reasoning_budget' => 0,
    ];

    $handle = curl_init($baseUrl . '/chat/completions');
    $body = '';
    $sseBuffer = '';
    $firstChunkAt = null;
    $firstContentAt = null;
    $outputContent = '';
    $usage = [];
    $finishReason = null;
    $requestStart = microtime(true);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
            'Content-Type: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (
            &$body,
            &$sseBuffer,
            &$firstChunkAt,
            &$firstContentAt,
            &$outputContent,
            &$usage,
            &$finishReason,
            $stream
        ): int {
            if ($firstChunkAt === null) {
                $firstChunkAt = microtime(true);
            }

            $body .= $chunk;
            if (!$stream) {
                return strlen($chunk);
            }

            $sseBuffer .= $chunk;
            $lines = explode("\n", $sseBuffer);
            $sseBuffer = (string) array_pop($lines);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }

                $event = json_decode($data, true);
                if (!is_array($event)) {
                    continue;
                }

                if (isset($event['usage']) && is_array($event['usage'])) {
                    $usage = $event['usage'];
                }

                $choice = $event['choices'][0] ?? [];
                if (isset($choice['finish_reason'])) {
                    $finishReason = $choice['finish_reason'];
                }

                $content = $choice['delta']['content'] ?? '';
                if (is_string($content) && $content !== '') {
                    $firstContentAt ??= microtime(true);
                    $outputContent .= $content;
                }
            }

            return strlen($chunk);
        },
    ]);

    $curlResult = curl_exec($handle);
    $requestEnd = microtime(true);
    $info = curl_getinfo($handle);
    $curlError = curl_error($handle);
    curl_close($handle);

    $decoded = $stream ? null : json_decode($body, true);
    if (is_array($decoded)) {
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $choice = $decoded['choices'][0] ?? [];
        $finishReason = $choice['finish_reason'] ?? null;
        $outputContent = (string) ($choice['message']['content'] ?? '');
    }

    return [
        'http' => (int) ($info['http_code'] ?? 0),
        'body_bytes' => strlen($body),
        'curl_error' => $curlError,
        'request_start' => $requestStart,
        'request_end' => $requestEnd,
        'duration' => $requestEnd - $requestStart,
        'first_chunk' => $firstChunkAt === null ? null : $firstChunkAt - $requestStart,
        'first_content' => $firstContentAt === null ? null : $firstContentAt - $requestStart,
        'dns' => (float) ($info['namelookup_time'] ?? 0),
        'connect' => (float) ($info['connect_time'] ?? 0),
        'tls' => (float) ($info['appconnect_time'] ?? 0),
        'ttfb' => (float) ($info['starttransfer_time'] ?? 0),
        'curl_total' => (float) ($info['total_time'] ?? ($requestEnd - $requestStart)),
        'output_tokens' => $usage['completion_tokens'] ?? null,
        'finish_reason' => $finishReason,
        'output_chars' => strlen($outputContent),
        'valid_output' => (int) ($info['http_code'] ?? 0) === 200
            && trim($outputContent) !== ''
            && $finishReason !== 'length',
        'curl_ok' => $curlResult !== false,
    ];
}

function showResult(string $label, array $result, int $inputChars): void
{
    echo "\n{$label}\n";
    echo 'HTTP: ' . ($result['http'] ?: 'n/a') . "\n";
    echo 'Input: ' . $inputChars . ' chars (~' . (int) ceil($inputChars / 4) . " tokens)\n";
    echo 'Request start: ' . date('c', (int) $result['request_start']) . "\n";
    echo 'TTFB / first response data: ' . number_format($result['ttfb'], 3) . " s\n";
        echo 'TTFB / first response data: ' . ($result['http'] > 0 ? number_format($result['ttfb'], 3) . ' s' : 'no response') . "\n";
    echo 'First chunk: ' . ($result['first_chunk'] === null ? 'n/a' : number_format($result['first_chunk'], 3) . ' s') . "\n";
    echo 'First content: ' . ($result['first_content'] === null ? 'n/a' : number_format($result['first_content'], 3) . ' s') . "\n";
    echo 'Total: ' . number_format($result['duration'], 3) . " s\n";
    echo 'Output tokens: ' . ($result['output_tokens'] ?? 'not reported') . "\n";
    echo 'Finish reason: ' . ($result['finish_reason'] ?? 'not reported') . "\n";
    echo 'Output valid/non-empty: ' . ($result['valid_output'] ? 'yes' : 'no') . "\n";
    echo 'Response bytes: ' . $result['body_bytes'] . "\n";
    echo 'DNS/connect/TLS: ' . number_format($result['dns'], 3) . ' / '
        . number_format($result['connect'], 3) . ' / '
        . number_format($result['tls'], 3) . " s\n";
    if ($result['curl_error'] !== '') {
        echo 'cURL error: ' . $result['curl_error'] . "\n";
    }
}

function promptChars(array $messages): int
{
    return array_sum(array_map(
        static fn (array $message): int => strlen((string) ($message['content'] ?? '')),
        $messages
    ));
}

function averageMetric(array $results, string $metric): ?float
{
    $values = array_values(array_filter(array_map(
        static fn (array $result): mixed => $metric === 'duration' || ($result['http'] ?? 0) > 0
            ? ($result[$metric] ?? null)
            : null,
        $results
    ), static fn (mixed $value): bool => is_numeric($value)));

    return $values === [] ? null : array_sum($values) / count($values);
}

$minimalMessages = [
    ['role' => 'user', 'content' => 'Say hello in one short sentence.'],
];

$mediumPrompt = <<<'PROMPT'
Read this short guide and respond to the request at the end. In a role-playing game, a character's equipment can improve several attributes at once. A useful selection process starts by identifying the character's job, then checking which attributes directly improve that job. A damage dealer may value critical chance, critical damage, and an appropriate amount of energy recharge. A support may instead prioritize reliable skill uptime, team buffs, or healing. Set bonuses should be compared with individual pieces rather than selected automatically. Consider how often the character uses their burst, whether teammates provide energy, and whether the encounter has a single target or many targets. Recommendations should explain tradeoffs, distinguish an ideal option from an accessible alternative, and avoid claiming exact performance without a controlled calculation. Keep the final answer concise and grounded only in the information provided. Request: summarize the most important equipment-selection principle in one sentence.
PROMPT;
$mediumMessages = [['role' => 'user', 'content' => $mediumPrompt]];

$productionLikeMessages = [
    [
        'role' => 'system',
        'content' => <<<'PROMPT'
Anda adalah Genshin Build AI, asisten theorycrafting untuk Genshin Impact (Target Patch: v7.0).
Berikan rekomendasi berdasarkan data karakter dan mekanik tim berikut. Pertimbangkan konstelasi C0, target konten Spiral Abyss, dan sinergi reaksi. Sajikan analisis peran, senjata terbaik beserta alternatif F2P, set artefak, stat utama, prioritas substat, target ER, dan rotasi.

[FAKTA GAME DATA RESMI: Raiden Shogun]
- Elemen / Vision: Electro
- Tipe Senjata: Polearm
- Konstelasi Aktif: C0
- Pergeseran peran: on-field damage dealer dan battery tim.

[MEKANIK TIM AKTIF]
Resonansi Elemen: Electro Resonance menghasilkan partikel tambahan saat reaksi Electro.
Reaksi Elemen: Electro-Charged; prioritaskan konsistensi aplikasi elemen dan uptime burst.

[TARGET KONTEN]
Mode: Spiral Abyss; fokus pada rotasi stabil, damage konsisten, dan survival tim.

[PANDUAN THEORYCRAFT TAMBAHAN]
Senjata dan artefak perlu dibandingkan dengan kebutuhan energi serta statistik aktual. Hindari angka damage pasti tanpa kalkulasi. Jelaskan bahwa rekomendasi bergantung pada substat dan komposisi tim.
PROMPT,
    ],
    [
        'role' => 'user',
        'content' => 'Tolong berikan rekomendasi build lengkap untuk Raiden Shogun C0 dalam tim dengan mode Spiral Abyss.',
    ],
];

$baselineResults = [];
echo "========================================\nNEMOTRON BENCHMARK\n========================================\n";
echo "MODEL: {$model}\nTimeout probe: {$timeoutSeconds}s\nReasoning budget: 0\n";
echo "Reasoning benchmark skipped because supported nonzero values could not be verified.\n";

for ($run = 1; $run <= 2; $run++) {
    $result = benchmarkRequest($baseUrl, $apiKey, $model, $minimalMessages, timeoutSeconds: $timeoutSeconds);
    $baselineResults[] = $result;
    showResult("BASELINE RUN {$run}", $result, promptChars($minimalMessages));
}

$maxTokenResults = [25 => $baselineResults[0]];
foreach ([10, 50] as $maxTokens) {
    $result = benchmarkRequest(
        $baseUrl,
        $apiKey,
        $model,
        $minimalMessages,
        maxTokens: $maxTokens,
        timeoutSeconds: $timeoutSeconds
    );
    $maxTokenResults[$maxTokens] = $result;
    showResult("MAX TOKENS {$maxTokens}", $result, promptChars($minimalMessages));
}

$streamResult = benchmarkRequest(
    $baseUrl,
    $apiKey,
    $model,
    $minimalMessages,
    stream: true,
    timeoutSeconds: $timeoutSeconds
);
showResult('STREAM TRUE', $streamResult, promptChars($minimalMessages));

$samplingResult = benchmarkRequest(
    $baseUrl,
    $apiKey,
    $model,
    $minimalMessages,
    temperature: 0.7,
    timeoutSeconds: $timeoutSeconds
);
showResult('SAMPLING VARIATION temperature=0.7, top_p=0.95', $samplingResult, promptChars($minimalMessages));

$mediumResult = benchmarkRequest($baseUrl, $apiKey, $model, $mediumMessages, timeoutSeconds: $timeoutSeconds);
showResult('PROMPT MEDIUM', $mediumResult, promptChars($mediumMessages));

$productionLikeResult = benchmarkRequest(
    $baseUrl,
    $apiKey,
    $model,
    $productionLikeMessages,
    timeoutSeconds: $timeoutSeconds
);
showResult('PROMPT PRODUCTION-LIKE (static example; no RAG/database)', $productionLikeResult, promptChars($productionLikeMessages));

$baselineAverageTtfb = averageMetric($baselineResults, 'ttfb');
$baselineAverageTotal = averageMetric($baselineResults, 'duration');
$baselineAverageTokens = averageMetric($baselineResults, 'output_tokens');
$baselineHttp = $baselineResults[0]['http'] > 0 ? $baselineResults[0]['http'] : 'n/a';

echo "\n========================================\nSUMMARY TABLE\n========================================\n";
echo "Test | Configuration | HTTP | TTFB | Total | Output tokens | Valid output\n";
echo 'Baseline | 2 runs average | ' . $baselineHttp . ' | '
    . ($baselineAverageTtfb === null ? 'no response' : number_format($baselineAverageTtfb, 3) . 's') . ' | '
    . number_format((float) $baselineAverageTotal, 3) . 's | '
    . ($baselineAverageTokens === null ? 'n/a' : number_format($baselineAverageTokens, 1)) . ' | '
    . (($baselineResults[0]['valid_output'] && $baselineResults[1]['valid_output']) ? 'yes' : 'no') . "\n";
foreach ([10, 25, 50] as $maxTokens) {
    $result = $maxTokenResults[$maxTokens];
    $time = $maxTokens === 25 ? (float) $baselineAverageTotal : $result['duration'];
    $ttfb = $maxTokens === 25 ? (float) $baselineAverageTtfb : $result['ttfb'];
    echo "Max tokens | {$maxTokens} | " . ($result['http'] ?: 'n/a') . ' | '
        . ($result['http'] > 0 ? number_format($ttfb, 3) . 's' : 'no response') . ' | ' . number_format($time, 3) . 's | '
        . ($result['output_tokens'] ?? 'n/a') . ' | ' . ($result['valid_output'] ? 'yes' : 'no') . "\n";
}
echo 'Streaming | true | ' . ($streamResult['http'] ?: 'n/a') . ' | '
    . ($streamResult['http'] > 0 ? number_format($streamResult['ttfb'], 3) . 's' : 'no response') . ' | '
    . number_format($streamResult['duration'], 3) . 's | '
    . ($streamResult['output_tokens'] ?? 'n/a') . ' | ' . ($streamResult['valid_output'] ? 'yes' : 'no') . "\n";
echo 'Sampling | temperature 0.7 | ' . ($samplingResult['http'] ?: 'n/a') . ' | '
    . ($samplingResult['http'] > 0 ? number_format($samplingResult['ttfb'], 3) . 's' : 'no response') . ' | '
    . number_format($samplingResult['duration'], 3) . 's | '
    . ($samplingResult['output_tokens'] ?? 'n/a') . ' | ' . ($samplingResult['valid_output'] ? 'yes' : 'no') . "\n";
echo 'Prompt medium | ' . promptChars($mediumMessages) . ' chars | ' . ($mediumResult['http'] ?: 'n/a') . ' | '
    . ($mediumResult['http'] > 0 ? number_format($mediumResult['ttfb'], 3) . 's' : 'no response') . ' | '
    . number_format($mediumResult['duration'], 3) . 's | '
    . ($mediumResult['output_tokens'] ?? 'n/a') . ' | ' . ($mediumResult['valid_output'] ? 'yes' : 'no') . "\n";
echo 'Prompt production-like | ' . promptChars($productionLikeMessages) . ' chars | '
    . ($productionLikeResult['http'] ?: 'n/a') . ' | '
    . ($productionLikeResult['http'] > 0 ? number_format($productionLikeResult['ttfb'], 3) . 's' : 'no response') . ' | '
    . number_format($productionLikeResult['duration'], 3) . 's | '
    . ($productionLikeResult['output_tokens'] ?? 'n/a') . ' | '
    . ($productionLikeResult['valid_output'] ? 'yes' : 'no') . "\n";
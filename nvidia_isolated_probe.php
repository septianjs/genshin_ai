<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$apiKey = trim((string) config('services.nvidia.api_key'));
$nemotron = (string) config('services.nvidia.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
$baseUrl = rtrim((string) config('services.nvidia.base_url', 'https://integrate.api.nvidia.com/v1'), '/');

if ($apiKey === '') {
    echo "NO_API_KEY\n";
    exit(1);
}

function nvidiaProbe(string $url, string $apiKey, int $timeout, ?array $payload = null): array
{
    $handle = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => false,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }

    curl_setopt_array($handle, $options);
    $requestStart = microtime(true);
    $body = curl_exec($handle);
    $requestEnd = microtime(true);
    $info = curl_getinfo($handle);
    $error = curl_error($handle);
    curl_close($handle);

    return [
        'http' => (int) ($info['http_code'] ?? 0),
        'body' => is_string($body) ? $body : '',
        'bytes' => is_string($body) ? strlen($body) : 0,
        'error' => $error,
        'start' => $requestStart,
        'end' => $requestEnd,
        'duration' => $requestEnd - $requestStart,
        'dns' => (float) ($info['namelookup_time'] ?? 0),
        'connect' => (float) ($info['connect_time'] ?? 0),
        'tls' => (float) ($info['appconnect_time'] ?? 0),
        'ttfb' => (float) ($info['starttransfer_time'] ?? 0),
        'total' => (float) ($info['total_time'] ?? ($requestEnd - $requestStart)),
    ];
}

function printProbe(string $label, array $result, string $apiKey): void
{
    $safeBody = str_replace($apiKey, '[REDACTED]', $result['body']);
    echo "\n--- {$label} ---\n";
    echo 'HTTP: ' . ($result['http'] ?: 'n/a') . "\n";
    echo 'Request start: ' . date('c', (int) $result['start']) . "\n";
    echo 'Response received: ' . date('c', (int) $result['end']) . "\n";
    echo 'Total: ' . number_format($result['duration'], 3) . " s\n";
    echo 'Response bytes: ' . $result['bytes'] . "\n";
    echo 'DNS: ' . number_format($result['dns'], 3) . " s\n";
    echo 'TCP connect: ' . number_format($result['connect'], 3) . " s\n";
    echo 'TLS appconnect: ' . number_format($result['tls'], 3) . " s\n";
    echo 'TTFB: ' . number_format($result['ttfb'], 3) . " s\n";
    echo 'curl total_time: ' . number_format($result['total'], 3) . " s\n";
    echo 'Error: ' . ($result['error'] !== '' ? str_replace($apiKey, '[REDACTED]', $result['error']) : 'none') . "\n";

    if ($result['http'] >= 400 && $safeBody !== '') {
        $errorJson = json_decode($safeBody, true);
        if (is_array($errorJson)) {
            echo 'Error title: ' . ($errorJson['title'] ?? 'HTTP error') . "\n";
            echo 'Error status: ' . ($errorJson['status'] ?? $result['http']) . "\n";
        }
    } elseif ($result['http'] >= 200 && $result['http'] < 300 && $safeBody !== '') {
        $decoded = json_decode($safeBody, true);
        echo 'Result: ' . (isset($decoded['choices'][0]['message']['content']) ? 'response content received (omitted)' : 'HTTP success') . "\n";
    }
}

echo "=== NVIDIA DIAGNOSTIC PROBE ===\n";
echo "Endpoint: {$baseUrl}\n";
echo "Configured model: {$nemotron}\n";
echo "API key: configured (value hidden)\n";

$metadata = nvidiaProbe($baseUrl . '/models', $apiKey, 25);
printProbe('Metadata GET /models (25s)', $metadata, $apiKey);

$otherModel = null;
if ($metadata['http'] >= 200 && $metadata['http'] < 300) {
    $metadataJson = json_decode($metadata['body'], true);
    $models = $metadataJson['data'] ?? [];
    $excludedKinds = ['embed', 'rerank', 'guard', 'clip', 'whisper', 'parakeet', 'tts', 'asr'];

    foreach ($models as $modelInfo) {
        $candidate = $modelInfo['id'] ?? null;
        if (!is_string($candidate) || $candidate === '' || $candidate === $nemotron) {
            continue;
        }

        $lowerCandidate = strtolower($candidate);
        $looksNonChat = false;
        foreach ($excludedKinds as $kind) {
            if (str_contains($lowerCandidate, $kind)) {
                $looksNonChat = true;
                break;
            }
        }

        if (!$looksNonChat) {
            $otherModel = $candidate;
            break;
        }
    }

    echo "\nModels listed by API: " . count($models) . "\n";
    if ($otherModel !== null) {
        echo "Other model selected from API response: {$otherModel}\n";
    } else {
        echo "Other chat model: none identified in API response\n";
    }
} else {
    echo "\nOther model test skipped: metadata endpoint did not return a usable model list.\n";
}

$prompt = 'Say hello in one short sentence.';
$chatOptions = [
    'messages' => [['role' => 'user', 'content' => $prompt]],
    'temperature' => 0.2,
    'max_tokens' => 25,
    'stream' => false,
    'reasoning_budget' => 0,
];

$nemotron25 = nvidiaProbe($baseUrl . '/chat/completions', $apiKey, 25, ['model' => $nemotron] + $chatOptions);
printProbe("Nemotron {$nemotron} (25s)", $nemotron25, $apiKey);

$nemotron60 = nvidiaProbe($baseUrl . '/chat/completions', $apiKey, 60, ['model' => $nemotron] + $chatOptions);
printProbe("Nemotron {$nemotron} (60s)", $nemotron60, $apiKey);

if ($otherModel !== null) {
    $otherResult = nvidiaProbe($baseUrl . '/chat/completions', $apiKey, 60, ['model' => $otherModel] + $chatOptions);
    printProbe("Other model {$otherModel} (60s)", $otherResult, $apiKey);
} else {
    echo "\n--- Other NVIDIA model (60s) ---\nSkipped: no alternate chat model was identified from API metadata.\n";
}

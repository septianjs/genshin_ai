<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

echo "=== TEST NVIDIA MELALUI LARAVEL ===" . PHP_EOL;
echo PHP_EOL;

$apiKey = config('services.nvidia.api_key');
$model = config(
    'services.nvidia.model',
    'nvidia/nemotron-3.5-lightning-30b-a3b'
);
$baseUrl = rtrim(
    config(
        'services.nvidia.base_url',
        'https://integrate.api.nvidia.com/v1'
    ),
    '/'
);

echo "API KEY : ";

if (!empty($apiKey)) {
    echo "TERBACA" . PHP_EOL;
    echo "Prefix  : " . substr($apiKey, 0, 7) . "********" . PHP_EOL;
} else {
    echo "KOSONG" . PHP_EOL;
    exit;
}

echo "Base URL: {$baseUrl}" . PHP_EOL;
echo "Model   : {$model}" . PHP_EOL;
echo PHP_EOL;

echo "Mengirim request ke NVIDIA..." . PHP_EOL;
echo "Timeout : 90 detik" . PHP_EOL;
echo PHP_EOL;

$start = microtime(true);

try {
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . trim($apiKey),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ])
        ->connectTimeout(10)
        ->timeout(90)
        ->post($baseUrl . '/chat/completions', [
            'model' => $model,

            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Say hello in one short sentence.'
                ]
            ],

            'temperature' => 0.2,

            'top_p' => 0.95,

            'max_tokens' => 100,

            'stream' => false,

            'reasoning_budget' => 0,
        ]);

    $duration = round(
        microtime(true) - $start,
        2
    );

    echo "Waktu       : {$duration} detik" . PHP_EOL;
    echo "HTTP Status : {$response->status()}" . PHP_EOL;
    echo "Successful  : "
        . ($response->successful() ? 'YA' : 'TIDAK')
        . PHP_EOL;

    echo PHP_EOL;
    echo "Response:" . PHP_EOL;

    $json = $response->json();

    if (is_array($json)) {
        echo json_encode(
            $json,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;
    } else {
        echo $response->body() . PHP_EOL;
    }

} catch (\Throwable $e) {

    $duration = round(
        microtime(true) - $start,
        2
    );

    echo "RESULT      : GAGAL" . PHP_EOL;
    echo "Waktu       : {$duration} detik" . PHP_EOL;
    echo "Exception   : " . get_class($e) . PHP_EOL;
    echo "Pesan       : " . $e->getMessage() . PHP_EOL;
}
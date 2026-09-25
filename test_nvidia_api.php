<?php

echo "=== TEST NVIDIA API CHAT COMPLETIONS ===" . PHP_EOL;
echo PHP_EOL;

$apiKey = getenv('NVIDIA_API_KEY');

if (!$apiKey) {
    echo "API KEY : TIDAK TERBACA dari getenv()" . PHP_EOL;
    echo PHP_EOL;
    echo "Catatan: test ini tidak membaca .env Laravel secara langsung." . PHP_EOL;
    exit;
}

echo "API KEY : TERBACA" . PHP_EOL;
echo "API KEY : " . substr($apiKey, 0, 7) . "********" . PHP_EOL;
echo PHP_EOL;

$url = 'https://integrate.api.nvidia.com/v1/chat/completions';

$data = [
    'model' => 'nvidia/nemotron-3.5-lightning-30b-a3b',
    'messages' => [
        [
            'role' => 'user',
            'content' => 'Say hello in one short sentence.'
        ]
    ],
    'temperature' => 0.2,
    'max_tokens' => 50,
];

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_POST => true,

    CURLOPT_POSTFIELDS => json_encode($data),

    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ],

    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,

    CURLOPT_FOLLOWLOCATION => true,

    CURLOPT_USERAGENT => 'Genshin-Build-AI-Test',
]);

$start = microtime(true);

$result = curl_exec($ch);

$duration = round(microtime(true) - $start, 2);

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

$error = curl_error($ch);

$errorNo = curl_errno($ch);

curl_close($ch);

echo "Waktu       : {$duration} detik" . PHP_EOL;
echo "HTTP Status : {$httpCode}" . PHP_EOL;
echo "cURL Error  : {$errorNo}" . PHP_EOL;
echo PHP_EOL;

if ($result === false) {
    echo "RESULT      : GAGAL" . PHP_EOL;
    echo "Error       : {$error}" . PHP_EOL;
} else {
    echo "RESULT      : BERHASIL" . PHP_EOL;
    echo "Response:" . PHP_EOL;

    $json = json_decode($result, true);

    if (is_array($json)) {
        echo json_encode(
            $json,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;
    } else {
        echo trim($result) . PHP_EOL;
    }
}
<?php

$apiKey = 'GANTI_DENGAN_API_KEY_BARU_KAMU';

$url = 'https://integrate.api.nvidia.com/v1/chat/completions';

$data = [
    'model' => 'nvidia/nemotron-3.5-lightning-30b-a3b',
    'messages' => [
        [
            'role' => 'user',
            'content' => 'Hello!',
        ],
    ],
    'max_tokens' => 10,
    'stream' => false,
    'reasoning_budget' => 0,
];

$jsonBody = json_encode($data);

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
        'Content-Length: ' . strlen($jsonBody),
    ],

    CURLOPT_POSTFIELDS => $jsonBody,

    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,

    // Paksa IPv4.
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,

    // Paksa HTTP/1.1.
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
]);

echo "========================================\n";
echo " TEST NVIDIA PHP CURL\n";
echo " POST + IPv4 + HTTP/1.1\n";
echo "========================================\n\n";

echo "Model: " . $data['model'] . "\n";
echo "Protocol: HTTP/1.1\n";
echo "IP: IPv4\n\n";

$start = microtime(true);

$response = curl_exec($ch);

$duration = microtime(true) - $start;

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

$httpVersion = curl_getinfo($ch, CURLINFO_HTTP_VERSION);
$totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

curl_close($ch);

echo "========================================\n";
echo " HASIL\n";
echo "========================================\n";

echo "HTTP Status : " . $httpCode . "\n";
echo "Durasi      : " . number_format($duration, 2) . " detik\n";
echo "cURL Error  : " . ($error ?: '(tidak ada)') . "\n";
echo "HTTP Version: " . $httpVersion . "\n";
echo "cURL Time   : " . number_format($totalTime, 2) . " detik\n\n";

echo "Response:\n";

if ($response === false) {
    echo "(Tidak ada response)\n";
} else {
    echo $response . "\n";
}

echo "\n========================================\n";


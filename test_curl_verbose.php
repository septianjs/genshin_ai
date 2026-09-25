<?php

$url = 'https://integrate.api.nvidia.com/v1/chat/completions';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_VERBOSE => true,

    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 15,

    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
]);

echo "========================================\n";
echo " TEST PHP CURL VERBOSE\n";
echo "========================================\n\n";

echo "URL: " . $url . "\n";
echo "IPv4: dipaksa\n";
echo "Timeout: 15 detik\n\n";

$start = microtime(true);

$response = curl_exec($ch);

$duration = microtime(true) - $start;

echo "\n========================================\n";
echo " HASIL\n";
echo "========================================\n";

echo "HTTP Status : " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
echo "Durasi      : " . number_format($duration, 2) . " detik\n";
echo "cURL Error  : " . (curl_error($ch) ?: '(tidak ada)') . "\n";

echo "\n========================================\n";
echo " INFO CURL\n";
echo "========================================\n";

print_r(curl_getinfo($ch));

curl_close($ch);

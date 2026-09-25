<?php

echo "=== TEST PHP cURL NVIDIA ===" . PHP_EOL;

echo "PHP Version : " . PHP_VERSION . PHP_EOL;

if (!function_exists('curl_init')) {
    echo "cURL       : TIDAK TERSEDIA" . PHP_EOL;
    exit;
}

$curlInfo = curl_version();

echo "cURL Version: " . $curlInfo['version'] . PHP_EOL;
echo PHP_EOL;

$url = 'https://integrate.api.nvidia.com';

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
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

if ($result === false) {
    echo "RESULT      : GAGAL" . PHP_EOL;
    echo "Pesan       : {$error}" . PHP_EOL;
} else {
    echo "RESULT      : BERHASIL" . PHP_EOL;
    echo "Response    : " . trim($result) . PHP_EOL;
}
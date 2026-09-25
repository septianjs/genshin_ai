<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});
Route::get('/debug/php-timeout', function () {
    return response()->json([
        'php_version' => PHP_VERSION,
        'ini_file' => php_ini_loaded_file(),
        'max_execution_time' => ini_get('max_execution_time'),
        'max_input_time' => ini_get('max_input_time'),
        'sapi' => PHP_SAPI,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
    ]);
});
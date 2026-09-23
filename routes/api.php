<?php

use App\Http\Controllers\BuildController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\ChatbotController;
use App\Services\QueryUnderstanding\EntityExtractor;
use App\Services\QueryUnderstanding\IntentClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes for Genshin Build AI
|--------------------------------------------------------------------------
*/

// Karakter
Route::get('/characters', [CharacterController::class, 'index']);
Route::get('/characters/{slug}', [CharacterController::class, 'show']);

// Analisis Tim & Rekomendasi Build
Route::post('/team/analyze', [BuildController::class, 'analyzeTeam']);
Route::post('/build/recommend', [BuildController::class, 'recommend']);

// Chatbot Interaktif
Route::post('/chat/send', [ChatbotController::class, 'sendMessage']);
Route::get('/chat/history/{sessionToken}', [ChatbotController::class, 'getHistory']);

// Query Understanding Tester
Route::post('/query/understand', function (Request $request, EntityExtractor $extractor, IntentClassifier $classifier) {
    $query = $request->input('query', '');
    return response()->json([
        'success' => true,
        'query' => $query,
        'intent' => $classifier->classify($query),
        'entities' => $extractor->extract($query),
    ]);
});

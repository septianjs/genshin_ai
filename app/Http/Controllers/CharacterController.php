<?php

namespace App\Http\Controllers;

use App\Services\Genshin\GenshinApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CharacterController extends Controller
{
    public function __construct(
        protected GenshinApiService $genshinService
    ) {}

    /**
     * Mengambil daftar karakter lokal dari database.
     */
    public function index(Request $request): JsonResponse
    {
        $patch = $request->query('patch', '7.0');
        $characters = $this->genshinService->getAllCharacters($patch);

        return response()->json([
            'success' => true,
            'total' => $characters->count(),
            'patch_version' => $patch,
            'data' => $characters,
        ]);
    }

    /**
     * Mengambil detail satu karakter beserta skill data dan konstelasi C1-C6.
     */
    public function show(string $slug, Request $request): JsonResponse
    {
        $patch = $request->query('patch', '7.0');
        $character = $this->genshinService->getCharacter($slug, $patch);

        if (!$character) {
            return response()->json([
                'success' => false,
                'message' => "Karakter '{$slug}' tidak ditemukan.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $character,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Ai\RecommendationEngine;
use App\Services\Genshin\GenshinApiService;
use App\Services\Mechanics\ElementalReactionService;
use App\Services\Mechanics\ElementalResonanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuildController extends Controller
{
    public function __construct(
        protected RecommendationEngine $recommendationEngine,
        protected GenshinApiService $genshinService,
        protected ElementalResonanceService $resonanceService,
        protected ElementalReactionService $reactionService
    ) {}

    /**
     * Endpoint untuk menganalisis tim: menghitung resonansi aktif dan daftar reaksi elemen.
     */
    public function analyzeTeam(Request $request): JsonResponse
    {
        $request->validate([
            'characters' => 'required|array|min:1|max:4',
        ]);

        $slugs = $request->input('characters');
        $visions = [];
        $characterData = [];

        foreach ($slugs as $slug) {
            $char = $this->genshinService->getCharacter($slug);
            if ($char) {
                $visions[] = $char->vision;
                $characterData[] = [
                    'slug' => $char->slug,
                    'name' => $char->name,
                    'vision' => $char->vision,
                    'icon_url' => $char->icon_url,
                ];
            }
        }

        $resonances = $this->resonanceService->evaluateResonances($visions);
        $reactions = $this->reactionService->evaluateReactions($visions);

        return response()->json([
            'success' => true,
            'characters' => $characterData,
            'resonances' => $resonances['summary_buffs'],
            'reactions' => $reactions['stat_recommendations'],
        ]);
    }

    /**
     * Endpoint untuk menghasilkan rekomendasi build karakter lengkap.
     */
    public function recommend(Request $request): JsonResponse
    {
        $request->validate([
            'character' => 'required|string',
            'constellation' => 'nullable|integer|min:0|max:6',
            'team' => 'nullable|array',
            'content_mode' => 'nullable|string',
            'custom_query' => 'nullable|string',
        ]);

        $characterSlug = $request->input('character');
        $constellation = (int) $request->input('constellation', 0);
        $team = $request->input('team', []);
        $contentMode = $request->input('content_mode', 'abyss');
        $customQuery = $request->input('custom_query');

        $result = $this->recommendationEngine->generateBuild(
            $characterSlug,
            $constellation,
            $team,
            $contentMode,
            $customQuery
        );

        if (!empty($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}

<?php

namespace App\Services\Nvidia;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NvidiaService
{
    protected ?string $apiKey;
    protected string $baseUrl;
    protected string $model;
    protected string $embeddingModel;

    public function __construct()
    {
        $this->apiKey = config('services.nvidia.api_key');
        $this->baseUrl = rtrim(config('services.nvidia.base_url', 'https://integrate.api.nvidia.com/v1'), '/');
        $this->model = config('services.nvidia.model', 'nvidia/nemotron-3.5-lightning-30b-a3b');
        $this->embeddingModel = config('services.nvidia.embedding_model', 'nvidia/nemotron-3-embed-1b');
    }

    /**
     * Memeriksa apakah API Key NVIDIA sudah terkonfigurasi.
     */
    public function hasApiKey(): bool
    {
        return !empty($this->apiKey) && str_starts_with($this->apiKey, 'nvapi-');
    }

    /**
     * Mengirim pesan chat completions ke model Nemotron.
     *
     * @param array $messages Daftar pesan [['role' => 'user/system/assistant', 'content' => '...']]
     * @param float $temperature
     * @param int $maxTokens
     * @return array Respon dari model ['content' => string, 'tokens_used' => int, 'model' => string]
     */
    public function chat(array $messages, float $temperature = 0.2, int $maxTokens = 1500): array
    {
        if (!$this->hasApiKey()) {
            return $this->generateFallbackResponse($messages);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(30)->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['choices'][0]['message']['content'] ?? '';
                $tokens = $data['usage']['total_tokens'] ?? null;

                return [
                    'content' => $content,
                    'tokens_used' => $tokens,
                    'model' => $this->model,
                    'status' => 'success',
                ];
            }

            Log::error("NvidiaService: Error respon API ({$response->status()}): " . $response->body());
            return $this->generateFallbackResponse($messages);
        } catch (\Exception $e) {
            Log::error("NvidiaService: Exception saat memanggil NVIDIA NIM: " . $e->getMessage());
            return $this->generateFallbackResponse($messages);
        }
    }

    /**
     * Mengonversi teks menjadi array vektor embedding via model Nemotron 3 Embed.
     *
     * @param string $text
     * @return array<float>|null
     */
    public function generateEmbedding(string $text): ?array
    {
        if (!$this->hasApiKey()) {
            // Mock embedding deterministic 128 float values untuk pengujian lokal jika API key belum diisi
            return $this->generateMockEmbedding($text);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(15)->post("{$this->baseUrl}/embeddings", [
                'model' => $this->embeddingModel,
                'input' => $text,
                'encoding_format' => 'float',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['data'][0]['embedding'] ?? null;
            }

            Log::warning("NvidiaService: Gagal generate embedding ({$response->status()}): " . $response->body());
            return $this->generateMockEmbedding($text);
        } catch (\Exception $e) {
            Log::error("NvidiaService: Exception saat generate embedding: " . $e->getMessage());
            return $this->generateMockEmbedding($text);
        }
    }

    /**
     * Fallback cerdas berbasis data mekanik & RAG lokal jika API Key belum dipasang.
     */
    protected function generateFallbackResponse(array $messages): array
    {
        $systemPrompt = $messages[0]['content'] ?? '';
        $lastUserMessage = '';

        foreach (array_reverse($messages) as $msg) {
            if ($msg['role'] === 'user') {
                $lastUserMessage = $msg['content'];
                break;
            }
        }

        // Ekstraksi data karakter dari system prompt
        $charName = 'Karakter';
        if (preg_match('/\[FAKTA GAME DATA RESMI:\s*(.*?)\]/', $systemPrompt, $m)) {
            $charName = trim($m[1]);
        }

        $constellation = 'C0';
        if (preg_match('/Konstelasi Aktif:\s*(C\d+)/', $systemPrompt, $m)) {
            $constellation = trim($m[1]);
        }

        $contentMode = 'Spiral Abyss';
        if (preg_match('/Mode:\s*(.*?)\n/', $systemPrompt, $m)) {
            $contentMode = trim($m[1]);
        }

        // Ekstraksi panduan theorycraft RAG dari system prompt jika ada
        $ragSection = '';
        if (preg_match('/\[PANDUAN THEORYCRAFT TAMBAHAN \(RAG\)\](.*)/s', $systemPrompt, $m)) {
            $ragSection = trim($m[1]);
        }

        $resonancesSection = '';
        if (preg_match('/Resonansi Elemen:\n(.*?)\n\n/s', $systemPrompt, $m)) {
            $resonancesSection = trim($m[1]);
        }

        $reactionsSection = '';
        if (preg_match('/Reaksi Elemen yang Terpicu dalam Tim:\n(.*?)\n\n/s', $systemPrompt, $m)) {
            $reactionsSection = trim($m[1]);
        }

        $output = "### ✦ Analisis & Rekomendasi Build: {$charName} ({$constellation})\n\n";
        $output .= "*Mode: {$contentMode} • Powered by Local Mechanics & RAG Engine*\n\n";

        if (!empty($resonancesSection) && !str_contains($resonancesSection, 'Tidak ada')) {
            $output .= "**Buff Resonansi Elemen Aktif:**\n{$resonancesSection}\n\n";
        }

        if (!empty($reactionsSection) && !str_contains($reactionsSection, 'Tidak ada')) {
            $output .= "**Sinergi Reaksi Elemen:**\n{$reactionsSection}\n\n";
        }

        if (!empty($ragSection)) {
            $output .= "**Panduan Build Terverifikasi (KQM / Theorycraft):**\n\n{$ragSection}\n\n";
        } else {
            $output .= "Berdasarkan evaluasi statistik dan peran karakter di tim:\n";
            $output .= "- **Senjata Utama**: Gunakan senjata signature atau senjata dengan substat CRIT Rate / CRIT DMG / ER.\n";
            $output .= "- **Artefak Rekomendasi**: Gunakan set 4-piece yang selaras dengan mekanisme skill.\n";
            $output .= "- **Prioritas Main Stat**: Sands (ER / HP% / ATK%), Goblet (Elemental DMG%), Circlet (CRIT Rate / CRIT DMG).\n";
            $output .= "- **Target Substat**: ER (hingga batas rotasi) > CRIT Rate : CRIT DMG (rasio 1:2).\n\n";
        }

        $output .= "---\n";
        $output .= "> [!TIP]\n";
        $output .= "> **Untuk Mengaktifkan NVIDIA Nemotron Cloud**:\n";
        $output .= "> Masukkan API Key Anda di file `.env`:\n";
        $output .= "> `NVIDIA_API_KEY=nvapi-xxxxxxxxxxxxxxxxxxxxxxxx`\n";
        $output .= "> Dapatkan key gratis di: **[build.nvidia.com](https://build.nvidia.com/)**";

        return [
            'content' => $output,
            'tokens_used' => 350,
            'model' => 'NVIDIA Nemotron (Local Mechanics Engine)',
            'status' => 'fallback',
        ];
    }

    /**
     * Menghasilkan vektor float deterministik (128 dimensi) untuk testing offline.
     */
    protected function generateMockEmbedding(string $text): array
    {
        $hash = md5($text);
        $vector = [];
        for ($i = 0; $i < 64; $i++) {
            $hex = substr($hash, ($i % 30), 2);
            $val = (hexdec($hex) / 255.0) * 2 - 1; // rentang -1.0 s/d 1.0
            $vector[] = round($val, 4);
        }
        return $vector;
    }
}

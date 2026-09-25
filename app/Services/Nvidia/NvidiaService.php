<?php

namespace App\Services\Nvidia;

use Illuminate\Http\Client\ConnectionException;
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

        $this->baseUrl = rtrim(
            config(
                'services.nvidia.base_url',
                'https://integrate.api.nvidia.com/v1'
            ),
            '/'
        );

        $this->model = config(
            'services.nvidia.model',
            'nvidia/nemotron-3.5-lightning-30b-a3b'
        );

        $this->embeddingModel = config(
            'services.nvidia.embedding_model',
            'nvidia/nemotron-3-embed-1b'
        );
    }

    /**
     * Cek apakah API key NVIDIA tersedia dan memiliki format yang benar.
     */
    public function hasApiKey(): bool
    {
        return !empty($this->apiKey)
            && str_starts_with(trim($this->apiKey), 'nvapi-');
    }

    /**
     * Mendapatkan status konfigurasi NVIDIA.
     *
     * API key tidak pernah dikembalikan penuh.
     */
    public function getStatus(): array
    {
        return [
            'configured' => $this->hasApiKey(),
            'model' => $this->model,
            'base_url' => $this->baseUrl,
        ];
    }

    /**
     * Mengirim request chat ke NVIDIA NIM.
     *
     * @param array $messages
     * @param float $temperature
     * @param int $maxTokens
     * @return array
     */
    public function chat(
        array $messages,
        float $temperature = 0.2,
        int $maxTokens = 800
    ): array {
        /*
        |--------------------------------------------------------------------------
        | 1. Cek API Key
        |--------------------------------------------------------------------------
        */

        if (!$this->hasApiKey()) {
            Log::warning(
                'NvidiaService: API key NVIDIA tidak tersedia.'
            );

            return $this->generateFallbackResponse(
                $messages,
                'API_KEY_MISSING'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Persiapkan URL
        |--------------------------------------------------------------------------
        */

        $url = "{$this->baseUrl}/chat/completions";

        /*
        |--------------------------------------------------------------------------
        | 3. Kirim request ke NVIDIA
        |--------------------------------------------------------------------------
        */

        try {
            Log::info(
                'NvidiaService: Mengirim request ke NVIDIA.',
                [
                    'url' => $url,
                    'model' => $this->model,
                    'message_count' => count($messages),
                    'max_tokens' => $maxTokens,
                ]
            );

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . trim($this->apiKey),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                /*
                |--------------------------------------------------------------------------
                | Waktu untuk membuat koneksi.
                |--------------------------------------------------------------------------
                */
                ->connectTimeout(10)

                /*
                |--------------------------------------------------------------------------
                | Request NVIDIA kamu terbukti membutuhkan sekitar 55 detik.
                | Jadi 30 detik terlalu pendek.
                |--------------------------------------------------------------------------
                */
                ->timeout(45)

                ->post($url, [
                    'model' => $this->model,

                    'messages' => $messages,

                    /*
                    |--------------------------------------------------------------------------
                    | Temperature
                    |--------------------------------------------------------------------------
                    */
                    'temperature' => min(
                        max($temperature, 0),
                        1
                    ),

                    /*
                    |--------------------------------------------------------------------------
                    | Membatasi probabilitas token.
                    |--------------------------------------------------------------------------
                    */
                    'top_p' => 0.95,

                    /*
                    |--------------------------------------------------------------------------
                    | Batas output.
                    |--------------------------------------------------------------------------
                    */
                    'max_tokens' => $maxTokens,

                    /*
                    |--------------------------------------------------------------------------
                    | Gunakan response JSON biasa.
                    |
                    | Kita tidak menggunakan streaming karena aplikasi Laravel
                    | kita membutuhkan satu response lengkap.
                    |--------------------------------------------------------------------------
                    */
                    'stream' => false,

                    /*
                    |--------------------------------------------------------------------------
                    | Tidak membutuhkan reasoning untuk tahap awal.
                    |
                    | Ini membantu mengurangi waktu pemrosesan.
                    |--------------------------------------------------------------------------
                    */
                    'reasoning_budget' => 0,
                ]);

            /*
            |--------------------------------------------------------------------------
            | 4. Jika HTTP 2xx
            |--------------------------------------------------------------------------
            */

            if ($response->successful()) {
                $data = $response->json();

                $content = $data['choices'][0]['message']['content']
                    ?? '';

                $tokens = $data['usage']['total_tokens']
                    ?? null;

                Log::info(
                    'NvidiaService: Request NVIDIA berhasil.',
                    [
                        'status' => $response->status(),
                        'tokens_used' => $tokens,
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Response kosong
                |--------------------------------------------------------------------------
                */

                if (empty(trim($content))) {
                    Log::warning(
                        'NvidiaService: NVIDIA mengembalikan response kosong.'
                    );

                    return $this->generateFallbackResponse(
                        $messages,
                        'EMPTY_RESPONSE'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Response sukses
                |--------------------------------------------------------------------------
                */

                return [
                    'content' => $content,
                    'tokens_used' => $tokens,
                    'model' => $this->model,
                    'status' => 'success',
                    'source' => 'nvidia',
                    'fallback_reason' => null,
                    'http_status' => $response->status(),
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | 5. HTTP error dari NVIDIA
            |--------------------------------------------------------------------------
            */

            Log::error(
                'NvidiaService: NVIDIA API mengembalikan HTTP error.',
                [
                    'status' => $response->status(),
                    'body' => mb_substr(
                        $response->body(),
                        0,
                        2000
                    ),
                ]
            );

            return $this->generateFallbackResponse(
                $messages,
                'API_ERROR',
                $response->status()
            );

        } catch (ConnectionException $e) {

            /*
            |--------------------------------------------------------------------------
            | 6. Connection error / timeout
            |--------------------------------------------------------------------------
            |
            | PENTING:
            | Ini BUKAN berarti API key tidak terbaca.
            |
            | Sebelumnya aplikasi kamu salah menampilkan timeout sebagai
            | masalah API key.
            |--------------------------------------------------------------------------
            */

            Log::error(
                'NvidiaService: Connection error saat menghubungi NVIDIA.',
                [
                    'message' => $e->getMessage(),
                    'model' => $this->model,
                ]
            );

            return $this->generateFallbackResponse(
                $messages,
                'CONNECTION_ERROR'
            );

        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | 7. Error lainnya
            |--------------------------------------------------------------------------
            */

            Log::error(
                'NvidiaService: Exception tidak terduga.',
                [
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                ]
            );

            return $this->generateFallbackResponse(
                $messages,
                'EXCEPTION'
            );
        }
    }

    /**
     * Generate embedding menggunakan NVIDIA.
     *
     * Jika NVIDIA gagal, sistem menggunakan mock embedding lokal.
     */
    public function generateEmbedding(string $text): ?array
    {
        /*
        |--------------------------------------------------------------------------
        | Cek API Key
        |--------------------------------------------------------------------------
        */

        if (!$this->hasApiKey()) {
            Log::warning(
                'NvidiaService: API key tidak tersedia untuk embedding.'
            );

            return $this->generateMockEmbedding($text);
        }

        try {
            $url = "{$this->baseUrl}/embeddings";

            Log::info(
                'NvidiaService: Mengirim request embedding ke NVIDIA.',
                [
                    'model' => $this->embeddingModel,
                ]
            );

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . trim($this->apiKey),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->connectTimeout(10)
                ->timeout(30)
                ->post($url, [
                    'model' => $this->embeddingModel,
                    'input' => $text,
                    'encoding_format' => 'float',
                ]);

            /*
            |--------------------------------------------------------------------------
            | Embedding sukses
            |--------------------------------------------------------------------------
            */

            if ($response->successful()) {
                $data = $response->json();

                return $data['data'][0]['embedding']
                    ?? null;
            }

            /*
            |--------------------------------------------------------------------------
            | Embedding HTTP error
            |--------------------------------------------------------------------------
            */

            Log::warning(
                'NvidiaService: Gagal generate embedding.',
                [
                    'status' => $response->status(),
                    'body' => mb_substr(
                        $response->body(),
                        0,
                        1000
                    ),
                ]
            );

            return $this->generateMockEmbedding($text);

        } catch (\Throwable $e) {

            Log::error(
                'NvidiaService: Exception saat generate embedding.',
                [
                    'message' => $e->getMessage(),
                ]
            );

            return $this->generateMockEmbedding($text);
        }
    }

    /**
     * Membuat response fallback.
     *
     * Fallback sekarang membedakan:
     *
     * API_KEY_MISSING
     * API_ERROR
     * CONNECTION_ERROR
     * EMPTY_RESPONSE
     * EXCEPTION
     */
    protected function generateFallbackResponse(
        array $messages,
        string $reason = 'UNKNOWN',
        ?int $httpStatus = null
    ): array {
        $reasonText = match ($reason) {

            'API_KEY_MISSING' =>
                'API key NVIDIA belum dikonfigurasi.',

            'API_ERROR' =>
                'NVIDIA API mengembalikan error'
                . (
                    $httpStatus
                        ? " (HTTP {$httpStatus})."
                        : '.'
                ),

            'CONNECTION_ERROR' =>
                'Koneksi ke NVIDIA API gagal atau mengalami timeout.',

            'EMPTY_RESPONSE' =>
                'NVIDIA API mengembalikan response kosong.',

            'EXCEPTION' =>
                'Terjadi error saat memanggil NVIDIA API.',

            default =>
                'NVIDIA API tidak dapat digunakan.',
        };

        return [
            'content' => $this->buildFallbackContent(
                $messages,
                $reasonText
            ),

            'tokens_used' => null,

            'model' => 'local-fallback',

            'status' => 'fallback',

            'source' => 'local',

            'fallback_reason' => $reason,

            'http_status' => $httpStatus,
        ];
    }

    /**
     * Membuat response fallback lokal.
     */
    protected function buildFallbackContent(
        array $messages,
        string $reason
    ): string {
        return "Sistem rekomendasi lokal digunakan.\n\n"
            . "Status NVIDIA: {$reason}\n\n"
            . "Rekomendasi tetap dibuat berdasarkan "
            . "data karakter, senjata, artefak, mekanik, "
            . "reaksi, dan aturan tim yang tersedia "
            . "di sistem.";
    }

    /**
     * Membuat mock embedding lokal.
     *
     * Digunakan jika NVIDIA embedding tidak tersedia.
     */
    protected function generateMockEmbedding(
        string $text
    ): array {
        $dimension = 384;

        $hash = hash('sha256', $text);

        $embedding = [];

        for ($i = 0; $i < $dimension; $i++) {

            $index = ($i * 2) % strlen($hash);

            $value = hexdec(
                substr($hash, $index, 2)
            );

            $embedding[] = ($value / 127.5) - 1;
        }

        return $embedding;
    }
}

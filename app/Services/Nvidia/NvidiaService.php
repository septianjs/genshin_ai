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
     * Mengecek apakah API key NVIDIA tersedia
     * dan memiliki format nvapi-.
     */
    public function hasApiKey(): bool
    {
        return !empty($this->apiKey)
            && str_starts_with(trim($this->apiKey), 'nvapi-');
    }

    /**
     * Status konfigurasi NVIDIA.
     */
    public function getStatus(): array
    {
        return [
            'configured' => $this->hasApiKey(),
            'model' => $this->model,
            'embedding_model' => $this->embeddingModel,
            'base_url' => $this->baseUrl,
        ];
    }

    /**
     * Mengirim request chat ke NVIDIA NIM.
     */
    public function chat(
        array $messages,
        float $temperature = 0.2,
        int $maxTokens = 300
    ): array {
        $totalStart = microtime(true);

        if (!$this->hasApiKey()) {
            Log::warning(
                'NvidiaService: API key NVIDIA tidak tersedia.'
            );

            return $this->generateFallbackResponse(
                $messages,
                'API_KEY_MISSING'
            );
        }

        $url = "{$this->baseUrl}/chat/completions";

        /*
        |--------------------------------------------------------------------------
        | Hitung ukuran prompt untuk profiling
        |--------------------------------------------------------------------------
        */
        $systemChars = 0;
        $userChars = 0;
        $totalChars = 0;

        foreach ($messages as $message) {
            $content = (string) ($message['content'] ?? '');
            $length = strlen($content);

            $totalChars += $length;

            if (($message['role'] ?? '') === 'system') {
                $systemChars += $length;
            }

            if (($message['role'] ?? '') === 'user') {
                $userChars += $length;
            }
        }

        try {
            Log::info(
                '[NVIDIA] ===== START CHAT =====',
                [
                    'url' => $url,
                    'model' => $this->model,
                    'message_count' => count($messages),
                    'system_chars' => $systemChars,
                    'user_chars' => $userChars,
                    'total_chars' => $totalChars,
                    'estimated_prompt_tokens' => (int) ceil($totalChars / 4),
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                    'timeout' => 120,
                    'connect_timeout' => 15,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Request ke NVIDIA
            |--------------------------------------------------------------------------
            */
            $requestStart = microtime(true);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . trim($this->apiKey),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                /*
                 * Paksa HTTP/1.1.
                 *
                 * Ini membantu menghindari masalah tertentu
                 * pada koneksi HTTP/2/cURL.
                 */
                ->withOptions([
                    'version' => CURL_HTTP_VERSION_1_1,
                ])
                ->connectTimeout(15)
                ->timeout(120)
                ->post($url, [
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => min(
                        max($temperature, 0),
                        1
                    ),
                    'top_p' => 0.95,
                    'max_tokens' => $maxTokens,
                    'stream' => false,
                    'reasoning_budget' => 0,
                ]);

            $requestDuration = microtime(true) - $requestStart;

            /*
            |--------------------------------------------------------------------------
            | Catat hasil HTTP
            |--------------------------------------------------------------------------
            */
            Log::info(
                '[NVIDIA] HTTP request selesai',
                [
                    'duration_seconds' => round($requestDuration, 4),
                    'http_status' => $response->status(),
                    'successful' => $response->successful(),
                    'response_bytes' => strlen($response->body()),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Request berhasil
            |--------------------------------------------------------------------------
            */
            if ($response->successful()) {
                $parseStart = microtime(true);

                $data = $response->json();

                $content = $data['choices'][0]['message']['content']
                    ?? '';

                $tokens = $data['usage']['total_tokens']
                    ?? null;

                $promptTokens = $data['usage']['prompt_tokens']
                    ?? null;

                $completionTokens = $data['usage']['completion_tokens']
                    ?? null;

                $finishReason = $data['choices'][0]['finish_reason']
                    ?? null;

                $parseDuration = microtime(true) - $parseStart;

                $totalDuration = microtime(true) - $totalStart;

                Log::info(
                    '[NVIDIA] Request NVIDIA berhasil',
                    [
                        'http_status' => $response->status(),
                        'request_seconds' => round($requestDuration, 4),
                        'parse_seconds' => round($parseDuration, 4),
                        'total_seconds' => round($totalDuration, 4),

                        'tokens_used' => $tokens,
                        'prompt_tokens' => $promptTokens,
                        'completion_tokens' => $completionTokens,

                        'finish_reason' => $finishReason,

                        'content_chars' => strlen($content),
                        'has_content' => !empty(trim($content)),
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Jika API berhasil tetapi content kosong
                |--------------------------------------------------------------------------
                */
                if (empty(trim($content))) {
                    Log::warning(
                        '[NVIDIA] API berhasil tetapi content kosong',
                        [
                            'finish_reason' => $finishReason,
                            'tokens_used' => $tokens,
                            'request_seconds' => round(
                                $requestDuration,
                                4
                            ),
                        ]
                    );

                    return $this->generateFallbackResponse(
                        $messages,
                        'EMPTY_RESPONSE'
                    );
                }

                Log::info(
                    '[NVIDIA] ===== END CHAT SUCCESS =====',
                    [
                        'total_seconds' => round(
                            $totalDuration,
                            4
                        ),
                    ]
                );

                return [
                    'content' => $content,
                    'tokens_used' => $tokens,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'finish_reason' => $finishReason,
                    'model' => $this->model,
                    'status' => 'success',
                    'source' => 'nvidia',
                    'fallback_reason' => null,
                    'http_status' => $response->status(),
                    'request_seconds' => round($requestDuration, 4),
                    'total_seconds' => round($totalDuration, 4),
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | NVIDIA mengembalikan HTTP error
            |--------------------------------------------------------------------------
            */
            $totalDuration = microtime(true) - $totalStart;

            Log::error(
                '[NVIDIA] NVIDIA API mengembalikan HTTP error',
                [
                    'status' => $response->status(),
                    'request_seconds' => round(
                        $requestDuration,
                        4
                    ),
                    'total_seconds' => round(
                        $totalDuration,
                        4
                    ),
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

            $totalDuration = microtime(true) - $totalStart;

            /*
            |--------------------------------------------------------------------------
            | Error koneksi / timeout
            |--------------------------------------------------------------------------
            */
            Log::error(
                '[NVIDIA] Connection error / timeout',
                [
                    'message' => $e->getMessage(),
                    'model' => $this->model,
                    'total_seconds' => round(
                        $totalDuration,
                        4
                    ),
                ]
            );

            return $this->generateFallbackResponse(
                $messages,
                'CONNECTION_ERROR'
            );

        } catch (\Throwable $e) {

            $totalDuration = microtime(true) - $totalStart;

            /*
            |--------------------------------------------------------------------------
            | Error umum lainnya
            |--------------------------------------------------------------------------
            */
            Log::error(
                '[NVIDIA] Exception tidak terduga',
                [
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                    'total_seconds' => round(
                        $totalDuration,
                        4
                    ),
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
     */
    public function generateEmbedding(string $text): ?array
    {
        $totalStart = microtime(true);

        if (!$this->hasApiKey()) {
            Log::warning(
                '[NVIDIA] API key tidak tersedia untuk embedding.'
            );

            return $this->generateMockEmbedding($text);
        }

        try {
            $url = "{$this->baseUrl}/embeddings";

            $textLength = strlen($text);

            Log::info(
                '[NVIDIA] ===== START EMBEDDING =====',
                [
                    'model' => $this->embeddingModel,
                    'text_chars' => $textLength,
                    'estimated_tokens' => (int) ceil(
                        $textLength / 4
                    ),
                    'timeout' => 30,
                    'connect_timeout' => 10,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Request embedding
            |--------------------------------------------------------------------------
            */
            $requestStart = microtime(true);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . trim($this->apiKey),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->withOptions([
                    'version' => CURL_HTTP_VERSION_1_1,
                ])
                ->connectTimeout(10)
                ->timeout(30)
                ->post($url, [
                    'model' => $this->embeddingModel,
                    'input' => $text,
                    'encoding_format' => 'float',
                ]);

            $requestDuration = microtime(true) - $requestStart;

            Log::info(
                '[NVIDIA] Embedding HTTP selesai',
                [
                    'duration_seconds' => round(
                        $requestDuration,
                        4
                    ),
                    'http_status' => $response->status(),
                    'successful' => $response->successful(),
                    'response_bytes' => strlen($response->body()),
                ]
            );

            if ($response->successful()) {
                $parseStart = microtime(true);

                $data = $response->json();

                $embedding = $data['data'][0]['embedding']
                    ?? null;

                $parseDuration = microtime(true) - $parseStart;

                $totalDuration = microtime(true) - $totalStart;

                if ($embedding !== null) {
                    Log::info(
                        '[NVIDIA] Embedding berhasil',
                        [
                            'dimension' => count($embedding),
                            'request_seconds' => round(
                                $requestDuration,
                                4
                            ),
                            'parse_seconds' => round(
                                $parseDuration,
                                4
                            ),
                            'total_seconds' => round(
                                $totalDuration,
                                4
                            ),
                        ]
                    );
                } else {
                    Log::warning(
                        '[NVIDIA] Response embedding tidak memiliki vector',
                        [
                            'total_seconds' => round(
                                $totalDuration,
                                4
                            ),
                        ]
                    );
                }

                return $embedding;
            }

            $totalDuration = microtime(true) - $totalStart;

            Log::warning(
                '[NVIDIA] Gagal generate embedding',
                [
                    'status' => $response->status(),
                    'request_seconds' => round(
                        $requestDuration,
                        4
                    ),
                    'total_seconds' => round(
                        $totalDuration,
                        4
                    ),
                    'body' => mb_substr(
                        $response->body(),
                        0,
                        1000
                    ),
                ]
            );

            return $this->generateMockEmbedding($text);

        } catch (\Throwable $e) {

            $totalDuration = microtime(true) - $totalStart;

            Log::error(
                '[NVIDIA] Exception saat generate embedding',
                [
                    'message' => $e->getMessage(),
                    'total_seconds' => round(
                        $totalDuration,
                        4
                    ),
                ]
            );

            return $this->generateMockEmbedding($text);
        }
    }

    /**
     * Membuat response fallback ketika NVIDIA tidak tersedia.
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
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'finish_reason' => null,
            'model' => 'local-fallback',
            'status' => 'fallback',
            'source' => 'local',
            'fallback_reason' => $reason,
            'http_status' => $httpStatus,
            'request_seconds' => null,
            'total_seconds' => null,
        ];
    }

    /**
     * Isi response fallback.
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
     * Mock embedding ketika NVIDIA tidak tersedia.
     *
     * Digunakan agar sistem RAG tetap dapat berjalan
     * saat API key atau koneksi NVIDIA bermasalah.
     */
    protected function generateMockEmbedding(
        string $text
    ): array {
        $dimension = 384;

        $hash = hash(
            'sha256',
            $text
        );

        $embedding = [];

        for ($i = 0; $i < $dimension; $i++) {

            $index = ($i * 2) % strlen($hash);

            $value = hexdec(
                substr(
                    $hash,
                    $index,
                    2
                )
            );

            $embedding[] = ($value / 127.5) - 1;
        }

        return $embedding;
    }
}

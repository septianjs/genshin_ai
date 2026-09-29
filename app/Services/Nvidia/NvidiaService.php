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

    protected int $timeout;

    protected int $connectTimeout;

    protected int $streamTimeout;

    protected int $embeddingTimeout;

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

        // Timeout konfigurasi dipisahkan secara terukur agar gagal terkontrol sebelum PHP timeout (60s)
        $this->timeout = (int) config('services.nvidia.timeout', 30);
        $this->connectTimeout = (int) config('services.nvidia.connect_timeout', 10);
        $this->streamTimeout = (int) config('services.nvidia.stream_timeout', 45);
        $this->embeddingTimeout = (int) config('services.nvidia.embedding_timeout', 5);
    }

    /**
     * Mengecek apakah API key NVIDIA tersedia dan memiliki format nvapi-.
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
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'stream_timeout' => $this->streamTimeout,
            'embedding_timeout' => $this->embeddingTimeout,
        ];
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getEmbeddingModel(): string
    {
        return $this->embeddingModel;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getConnectTimeout(): int
    {
        return $this->connectTimeout;
    }

    public function getStreamTimeout(): int
    {
        return $this->streamTimeout;
    }

    public function getEmbeddingTimeout(): int
    {
        return $this->embeddingTimeout;
    }

    /**
     * Helper untuk menyamarkan token sensitif dari string/log.
     */
    protected function sanitizeLogString(string $input): string
    {
        return preg_replace('/nvapi-[a-zA-Z0-9_\-]+/', 'nvapi-[REDACTED]', $input);
    }

    /**
     * ============================================================
     * NON-STREAMING CHAT
     * ============================================================
     *
     * Digunakan oleh Build / RecommendationEngine.
     */
    public function chat(
        array $messages,
        float $temperature = 0.2,
        int $maxTokens = 800
    ): array {
        $totalStart = microtime(true);

        if (!$this->hasApiKey()) {
            Log::warning('[NVIDIA] API key NVIDIA tidak tersedia.');

            return $this->generateFallbackResponse(
                $messages,
                'API_KEY_MISSING'
            );
        }

        $url = "{$this->baseUrl}/chat/completions";

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
                    'timeout' => $this->timeout,
                    'connect_timeout' => $this->connectTimeout,
                ]
            );

            $requestStart = microtime(true);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . trim($this->apiKey),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->withOptions([
                    'version' => '1.1',
                ])
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post($url, [
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => min(max($temperature, 0), 1),
                    'top_p' => 0.95,
                    'max_tokens' => $maxTokens,
                    'stream' => false,
                ]);

            $requestDuration = microtime(true) - $requestStart;

            Log::info(
                '[NVIDIA] HTTP request selesai',
                [
                    'duration_seconds' => round($requestDuration, 4),
                    'http_status' => $response->status(),
                    'successful' => $response->successful(),
                    'response_bytes' => strlen($response->body()),
                ]
            );

            if ($response->successful()) {
                $parseStart = microtime(true);
                $data = $response->json();

                $choice = $data['choices'][0] ?? [];
                $messageData = $choice['message'] ?? [];
                $content = $messageData['content'] ?? '';
                $reasoningContent = $messageData['reasoning_content'] ?? '';

                $tokens = $data['usage']['total_tokens'] ?? null;
                $promptTokens = $data['usage']['prompt_tokens'] ?? null;
                $completionTokens = $data['usage']['completion_tokens'] ?? null;
                $finishReason = $choice['finish_reason'] ?? null;

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
                        'finish_reason' => $finishReason,
                        'content_chars' => strlen($content),
                        'has_content' => !empty(trim($content)),
                    ]
                );

                // Jika content kosong (misal terpotong oleh token limit pada model reasoning), gunakan fallback
                if (empty(trim($content))) {
                    Log::warning(
                        '[NVIDIA] API berhasil tetapi content kosong',
                        [
                            'finish_reason' => $finishReason,
                            'tokens_used' => $tokens,
                            'has_reasoning' => !empty(trim($reasoningContent)),
                            'request_seconds' => round($requestDuration, 4),
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
                        'total_seconds' => round($totalDuration, 4),
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

            $totalDuration = microtime(true) - $totalStart;

            Log::error(
                '[NVIDIA] NVIDIA API mengembalikan HTTP error',
                [
                    'status' => $response->status(),
                    'request_seconds' => round($requestDuration, 4),
                    'total_seconds' => round($totalDuration, 4),
                    'body' => $this->sanitizeLogString(mb_substr($response->body(), 0, 1000)),
                ]
            );

            return $this->generateFallbackResponse(
                $messages,
                'API_ERROR',
                $response->status()
            );

        } catch (ConnectionException $e) {
            $totalDuration = microtime(true) - $totalStart;

            Log::error(
                '[NVIDIA] Connection error / timeout terkontrol',
                [
                    'message' => $this->sanitizeLogString($e->getMessage()),
                    'model' => $this->model,
                    'timeout_limit' => $this->timeout,
                    'total_seconds' => round($totalDuration, 4),
                ]
            );

            return $this->generateFallbackResponse(
                $messages,
                'CONNECTION_ERROR'
            );

        } catch (\Throwable $e) {
            $totalDuration = microtime(true) - $totalStart;

            Log::error(
                '[NVIDIA] Exception tidak terduga',
                [
                    'type' => get_class($e),
                    'message' => $this->sanitizeLogString($e->getMessage()),
                    'total_seconds' => round($totalDuration, 4),
                ]
            );

            return $this->generateFallbackResponse(
                $messages,
                'EXCEPTION'
            );
        }
    }

    /**
     * ============================================================
     * STREAMING CHAT
     * ============================================================
     *
     * Dipakai khusus chatbot Ava.
     * $onToken akan dipanggil setiap kali NVIDIA mengirim potongan content baru.
     */
    public function chatStream(
        array $messages,
        callable $onToken,
        float $temperature = 0.2,
        int $maxTokens = 800
    ): array {
        $totalStart = microtime(true);

        if (!$this->hasApiKey()) {
            Log::warning('[NVIDIA STREAM] API key NVIDIA tidak tersedia.');

            $fallback = $this->generateFallbackResponse(
                $messages,
                'API_KEY_MISSING'
            );

            if (!empty($fallback['content'])) {
                $onToken($fallback['content']);
            }

            return $fallback;
        }

        $url = "{$this->baseUrl}/chat/completions";

        $totalChars = 0;
        foreach ($messages as $message) {
            $totalChars += strlen((string) ($message['content'] ?? ''));
        }

        Log::info(
            '[NVIDIA] Chat stream started',
            [
                'url' => $url,
                'model' => $this->model,
                'message_count' => count($messages),
                'prompt_chars' => $totalChars,
                'estimated_prompt_tokens' => (int) ceil($totalChars / 4),
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
                'timeout' => $this->streamTimeout,
                'connect_timeout' => $this->connectTimeout,
            ]
        );

        $requestStart = microtime(true);
        $fullContent = '';
        $promptTokens = null;
        $completionTokens = null;
        $totalTokens = null;
        $finishReason = null;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . trim($this->apiKey),
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ])
                ->withOptions([
                    'version' => '1.1',
                    'stream' => true,
                ])
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->streamTimeout)
                ->post($url, [
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => min(max($temperature, 0), 1),
                    'top_p' => 0.95,
                    'max_tokens' => $maxTokens,
                    'stream' => true,
                ]);

            $requestDuration = microtime(true) - $requestStart;

            Log::info(
                '[NVIDIA STREAM] Connection response diterima',
                [
                    'http_status' => $response->status(),
                    'successful' => $response->successful(),
                    'connection_seconds' => round($requestDuration, 4),
                ]
            );

            if (!$response->successful()) {
                $body = $response->body();

                Log::error(
                    '[NVIDIA STREAM] HTTP error',
                    [
                        'status' => $response->status(),
                        'body' => $this->sanitizeLogString(mb_substr($body, 0, 1000)),
                    ]
                );

                $fallback = $this->generateFallbackResponse(
                    $messages,
                    'API_ERROR',
                    $response->status()
                );

                if (!empty($fallback['content'])) {
                    $onToken($fallback['content']);
                }

                return $fallback;
            }

            $psrResponse = $response->toPsrResponse();
            $body = $psrResponse->getBody();
            $buffer = '';
            $firstTokenTime = null;

            $processEvent = function (string $event) use (
                &$firstTokenTime,
                &$fullContent,
                &$finishReason,
                &$promptTokens,
                &$completionTokens,
                &$totalTokens,
                $requestStart,
                $onToken
            ): bool {
                $lines = preg_split("/\r\n|\n|\r/", $event) ?: [];
                $dataLines = [];

                foreach ($lines as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $dataLines[] = ltrim(substr($line, 5), " \t");
                    }
                }

                if ($dataLines === []) {
                    return false;
                }

                $dataString = implode("\n", $dataLines);

                if ($dataString === '[DONE]') {
                    return true;
                }

                if ($dataString === '') {
                    return false;
                }

                $data = json_decode($dataString, true);

                if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
                    return false;
                }

                $delta = $data['choices'][0]['delta'] ?? [];
                $deltaContent = $delta['content'] ?? '';

                if (is_string($deltaContent) && $deltaContent !== '') {
                    if ($firstTokenTime === null) {
                        $firstTokenTime = microtime(true);

                        Log::info('[NVIDIA] First content token received', [
                            'ttft_seconds' => round($firstTokenTime - $requestStart, 4),
                        ]);
                    }

                    $fullContent .= $deltaContent;
                    $onToken($deltaContent);
                }

                if (isset($data['choices'][0]['finish_reason'])) {
                    $finishReason = $data['choices'][0]['finish_reason'];
                }

                if (isset($data['usage'])) {
                    $promptTokens = $data['usage']['prompt_tokens'] ?? $promptTokens;
                    $completionTokens = $data['usage']['completion_tokens'] ?? $completionTokens;
                    $totalTokens = $data['usage']['total_tokens'] ?? $totalTokens;
                }

                return false;
            };

            $streamDone = false;
            $eventSeparatorPattern = "/\r\n\r\n|\n\n|\r\r/";

            while (!$body->eof() && !$streamDone) {
                $chunk = $body->read(8192);

                if ($chunk === '') {
                    usleep(10000);
                    continue;
                }

                $buffer .= $chunk;

                while (preg_match(
                    $eventSeparatorPattern,
                    $buffer,
                    $separatorMatch,
                    PREG_OFFSET_CAPTURE
                ) === 1) {
                    $separator = $separatorMatch[0][0];
                    $separatorPosition = $separatorMatch[0][1];
                    $event = substr($buffer, 0, $separatorPosition);
                    $buffer = substr($buffer, $separatorPosition + strlen($separator));

                    if ($processEvent($event)) {
                        $streamDone = true;
                        break;
                    }
                }
            }

            if (!$streamDone && trim($buffer) !== '') {
                $processEvent($buffer);
            }

            $totalDuration = microtime(true) - $totalStart;

            Log::info(
                '[NVIDIA] Chat stream completed',
                [
                    'total_seconds' => round($totalDuration, 4),
                    'request_seconds' => round($requestDuration, 4),
                    'ttft_seconds' => $firstTokenTime !== null
                        ? round($firstTokenTime - $requestStart, 4)
                        : null,
                    'content_chars' => strlen($fullContent),
                    'tokens_used' => $totalTokens,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'finish_reason' => $finishReason,
                ]
            );

            if (empty(trim($fullContent))) {
                Log::warning('[NVIDIA STREAM] Streaming selesai tetapi content kosong.');

                $fallback = $this->generateFallbackResponse(
                    $messages,
                    'EMPTY_RESPONSE'
                );

                if (!empty($fallback['content'])) {
                    $onToken($fallback['content']);
                }

                return $fallback;
            }

            return [
                'content' => $fullContent,
                'tokens_used' => $totalTokens,
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
                'ttft_seconds' => $firstTokenTime !== null
                    ? round($firstTokenTime - $requestStart, 4)
                    : null,
            ];

        } catch (ConnectionException $e) {
            $totalDuration = microtime(true) - $totalStart;

            Log::error(
                '[NVIDIA STREAM] Connection error / timeout terkontrol',
                [
                    'message' => $this->sanitizeLogString($e->getMessage()),
                    'timeout_limit' => $this->streamTimeout,
                    'total_seconds' => round($totalDuration, 4),
                ]
            );

            if (!empty(trim($fullContent))) {
                return [
                    'content' => $fullContent,
                    'tokens_used' => $totalTokens,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'finish_reason' => 'connection_error',
                    'model' => $this->model,
                    'status' => 'partial',
                    'source' => 'nvidia',
                    'fallback_reason' => 'CONNECTION_ERROR',
                    'http_status' => null,
                    'request_seconds' => null,
                    'total_seconds' => round($totalDuration, 4),
                ];
            }

            $fallback = $this->generateFallbackResponse(
                $messages,
                'CONNECTION_ERROR'
            );

            if (!empty($fallback['content'])) {
                $onToken($fallback['content']);
            }

            return $fallback;

        } catch (\Throwable $e) {
            $totalDuration = microtime(true) - $totalStart;

            Log::error(
                '[NVIDIA STREAM] Exception',
                [
                    'type' => get_class($e),
                    'message' => $this->sanitizeLogString($e->getMessage()),
                    'total_seconds' => round($totalDuration, 4),
                ]
            );

            if (!empty(trim($fullContent))) {
                return [
                    'content' => $fullContent,
                    'tokens_used' => $totalTokens,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'finish_reason' => 'exception',
                    'model' => $this->model,
                    'status' => 'partial',
                    'source' => 'nvidia',
                    'fallback_reason' => 'EXCEPTION',
                    'http_status' => null,
                    'request_seconds' => null,
                    'total_seconds' => round($totalDuration, 4),
                ];
            }

            $fallback = $this->generateFallbackResponse(
                $messages,
                'EXCEPTION'
            );

            if (!empty($fallback['content'])) {
                $onToken($fallback['content']);
            }

            return $fallback;
        }
    }

    /**
     * Generate embedding menggunakan NVIDIA NIM.
     * Menggunakan timeout singkat (default 5s) agar tidak menghambat pipeline.
     */
    public function generateEmbedding(string $text): ?array
    {
        $totalStart = microtime(true);

        if (!$this->hasApiKey()) {
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
                    'estimated_tokens' => (int) ceil($textLength / 4),
                    'timeout' => $this->embeddingTimeout,
                    'connect_timeout' => 5,
                ]
            );

            $requestStart = microtime(true);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . trim($this->apiKey),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->withOptions([
                    'version' => '1.1',
                ])
                ->connectTimeout(5)
                ->timeout($this->embeddingTimeout)
                ->post($url, [
                    'model' => $this->embeddingModel,
                    'input' => $text,
                    'encoding_format' => 'float',
                ]);

            $requestDuration = microtime(true) - $requestStart;

            if ($response->successful()) {
                $data = $response->json();
                $embedding = $data['data'][0]['embedding'] ?? null;

                if ($embedding !== null) {
                    Log::info(
                        '[NVIDIA] Embedding berhasil',
                        [
                            'dimension' => count($embedding),
                            'request_seconds' => round($requestDuration, 4),
                            'total_seconds' => round(microtime(true) - $totalStart, 4),
                        ]
                    );

                    return $embedding;
                }
            }

            Log::warning(
                '[NVIDIA] Gagal generate embedding, fallback ke mock',
                [
                    'status' => $response->status(),
                    'total_seconds' => round(microtime(true) - $totalStart, 4),
                ]
            );

            return $this->generateMockEmbedding($text);

        } catch (\Throwable $e) {
            $totalDuration = microtime(true) - $totalStart;

            Log::warning(
                '[NVIDIA] Exception saat generate embedding, fallback ke mock',
                [
                    'type' => get_class($e),
                    'message' => $this->sanitizeLogString($e->getMessage()),
                    'total_seconds' => round($totalDuration, 4),
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
            'API_KEY_MISSING' => 'API key NVIDIA belum dikonfigurasi.',
            'API_ERROR' => 'NVIDIA API mengembalikan error' . ($httpStatus ? " (HTTP {$httpStatus})." : '.'),
            'CONNECTION_ERROR' => 'Koneksi ke NVIDIA API gagal atau mengalami timeout.',
            'EMPTY_RESPONSE' => 'NVIDIA API mengembalikan response kosong.',
            'EXCEPTION' => 'Terjadi error saat memanggil NVIDIA API.',
            default => 'NVIDIA API tidak dapat digunakan.',
        };

        return [
            'content' => $this->buildFallbackContent($messages, $reasonText),
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
            . "Rekomendasi tetap dibuat berdasarkan data karakter, senjata, artefak, mekanik, reaksi, dan aturan tim yang tersedia di sistem.";
    }

    /**
     * Mock embedding ketika NVIDIA tidak tersedia.
     */
    protected function generateMockEmbedding(string $text): array
    {
        $dimension = 384;
        $hash = hash('sha256', $text);
        $embedding = [];

        for ($i = 0; $i < $dimension; $i++) {
            $index = ($i * 2) % strlen($hash);
            $value = hexdec(substr($hash, $index, 2));
            $embedding[] = ($value / 127.5) - 1;
        }

        return $embedding;
    }
}

<?php

namespace Tests\Feature;

use App\Services\Nvidia\NvidiaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NvidiaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nvidia.api_key' => 'nvapi-test-key',
            'services.nvidia.base_url' => 'https://nvidia.test/v1',
            'services.nvidia.model' => 'test-model',
            'services.nvidia.timeout' => 120,
            'services.nvidia.connect_timeout' => 15,
        ]);
    }

    public function test_chat_uses_nvidia_completion_response(): void
    {
        Http::fake([
            'https://nvidia.test/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Build response']],
                ],
                'usage' => ['total_tokens' => 12],
            ]),
        ]);

        $result = app(NvidiaService::class)->chat([
            ['role' => 'user', 'content' => 'Build Furina'],
        ]);

        $this->assertSame('success', $result['status']);
        $this->assertSame('Build response', $result['content']);
        $this->assertSame('nvidia', $result['source']);
        Http::assertSent(fn ($request) => $request['chat_template_kwargs']['enable_thinking'] === false);
    }

    public function test_chat_stream_parses_nvidia_sse_chunks(): void
    {
        Http::fake([
            'https://nvidia.test/v1/chat/completions' => Http::response(
                "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
                . "data: {\"choices\":[{\"finish_reason\":\"stop\",\"delta\":{}}]}\n\n"
                . "data: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            ),
        ]);

        $receivedTokens = '';
        $result = app(NvidiaService::class)->chatStream(
            [['role' => 'user', 'content' => 'Say hello']],
            static function (string $token) use (&$receivedTokens): void {
                $receivedTokens .= $token;
            }
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('Hello', $result['content']);
        $this->assertSame('Hello', $receivedTokens);
        $this->assertSame('nvidia', $result['source']);
        Http::assertSent(fn ($request) => $request['chat_template_kwargs']['enable_thinking'] === false);
    }
}
<?php

declare(strict_types=1);

namespace Tests\Unit\Advisor;

use App\Modules\Advisor\Exceptions\AiProviderException;
use App\Modules\Advisor\Providers\GeminiProvider;
use App\Modules\Advisor\Providers\OpenAiCompatibleProvider;
use App\Modules\Advisor\Providers\OpenAiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each adapter against its provider's real wire contract — request shape,
 * authentication style, response extraction, and every failure mapped to the
 * category the gateway and the admin test-connection surface act on. All
 * through Http::fake; no adapter test ever leaves the process.
 */
final class AiProviderAdaptersTest extends TestCase
{
    private function openAi(): OpenAiProvider
    {
        return new OpenAiProvider(
            apiKey: 'fake-test-key',
            model: 'test-model',
            timeout: 5,
            rates: ['prompt' => 1.0, 'completion' => 2.0],
        );
    }

    private function gemini(): GeminiProvider
    {
        return new GeminiProvider(
            apiKey: 'fake-test-key',
            model: 'test-gemini',
            timeout: 5,
        );
    }

    /** @return array<string, mixed> */
    private function chatBody(string $text = 'Hello there'): array
    {
        return [
            'model' => 'served-model',
            'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        ];
    }

    /* ------------------------------------------------------------ OpenAI */

    public function test_openai_sends_bearer_auth_and_max_completion_tokens(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->chatBody())]);

        $result = $this->openAi()->complete([
            'system' => 'Be brief.',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'max_tokens' => 64,
            'json' => true,
        ]);

        $this->assertSame('Hello there', $result['text']);
        $this->assertSame('served-model', $result['model']);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer fake-test-key')
                && str_ends_with($request->url(), '/chat/completions')
                && $body['model'] === 'test-model'
                && $body['max_completion_tokens'] === 64
                && $body['messages'][0]['role'] === 'system'
                && $body['response_format']['type'] === 'json_object';
        });
    }

    public function test_openai_prices_usage_from_the_configured_rates(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->chatBody())]);

        $result = $this->openAi()->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);

        // 100 prompt tokens at $1/M + 50 completion tokens at $2/M.
        $this->assertSame('0.000200', $result['cost_usd']);
    }

    public function test_openai_retries_a_400_once_with_minimal_parameters(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'temperature unsupported']], 400)
                ->push($this->chatBody('Second attempt')),
        ]);

        $result = $this->openAi()->complete([
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'temperature' => 0.3,
        ]);

        $this->assertSame('Second attempt', $result['text']);

        // The second request must have dropped the tuning parameters a
        // stricter model rejected.
        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ! array_key_exists('temperature', $body) || $body['temperature'] === 0.3;
        });
    }

    /** @return array<string, array{int, string}> */
    public static function openAiFailureStatuses(): array
    {
        return [
            '401 auth' => [401, AiProviderException::CATEGORY_AUTH_FAILED],
            '403 auth' => [403, AiProviderException::CATEGORY_AUTH_FAILED],
            '404 model' => [404, AiProviderException::CATEGORY_MODEL_UNAVAILABLE],
            '429 rate' => [429, AiProviderException::CATEGORY_RATE_LIMITED],
            '500 provider' => [500, AiProviderException::CATEGORY_PROVIDER_ERROR],
        ];
    }

    #[DataProvider('openAiFailureStatuses')]
    public function test_openai_maps_the_failure_statuses_to_categories(int $status, string $category): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'x'], $status)]);

        try {
            $this->openAi()->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);
            $this->fail("HTTP {$status} must throw");
        } catch (AiProviderException $e) {
            $this->assertSame($category, $e->category(), "status {$status}");
            $this->assertSame('openai', $e->provider());
        }
    }

    public function test_openai_maps_transport_failure_to_unreachable_without_leaking_the_url(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: could not resolve https://api.openai.com/v1?key=fake-test-key'));

        try {
            $this->openAi()->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);
            $this->fail('transport failure must throw');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_UNREACHABLE, $e->category());
            $this->assertStringNotContainsString('fake-test-key', $e->getMessage());
            $this->assertStringNotContainsString('cURL', $e->getMessage());
        }
    }

    public function test_openai_rejects_a_malformed_body_as_invalid_response(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('not json at all', 200, ['Content-Type' => 'text/plain'])]);

        try {
            $this->openAi()->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);
            $this->fail('malformed body must throw');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_INVALID_RESPONSE, $e->category());
        }
    }

    public function test_openai_unconfigured_refuses_before_any_request(): void
    {
        Http::fake();

        $provider = new OpenAiProvider(apiKey: null, model: 'test-model');

        $this->assertFalse($provider->isConfigured());

        try {
            $provider->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);
            $this->fail('unconfigured must throw');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_NOT_CONFIGURED, $e->category());
        }

        Http::assertNothingSent();
    }

    public function test_openai_without_a_model_is_not_configured(): void
    {
        $provider = new OpenAiProvider(apiKey: 'fake-test-key', model: '');

        $this->assertFalse($provider->isConfigured());
    }

    /* ------------------------------------------------------------ Gemini */

    public function test_gemini_sends_the_header_key_and_native_shape(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'سڵاو، '], ['text' => 'بەخێربێیت']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 3],
            ]),
        ]);

        $result = $this->gemini()->complete([
            'system' => 'Be brief.',
            'messages' => [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => 'previous'],
                ['role' => 'user', 'content' => 'again'],
            ],
            'json' => true,
        ]);

        // Split parts are joined; thinking tokens count as completion output.
        $this->assertSame('سڵاو، بەخێربێیت', $result['text']);
        $this->assertSame(8, $result['completion_tokens']);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->hasHeader('x-goog-api-key', 'fake-test-key')
                && str_contains($request->url(), '/models/test-gemini:generateContent')
                // The key must ride in the header, never the URL.
                && ! str_contains($request->url(), 'fake-test-key')
                && $body['system_instruction']['parts'][0]['text'] === 'Be brief.'
                && $body['contents'][1]['role'] === 'model'
                && $body['contents'][0]['parts'][0]['text'] === 'hi'
                && $body['generationConfig']['responseMimeType'] === 'application/json';
        });
    }

    /** @return array<string, array{int, string}> */
    public static function geminiFailureStatuses(): array
    {
        return [
            '403 auth' => [403, AiProviderException::CATEGORY_AUTH_FAILED],
            '404 model' => [404, AiProviderException::CATEGORY_MODEL_UNAVAILABLE],
            '429 rate' => [429, AiProviderException::CATEGORY_RATE_LIMITED],
            '503 provider' => [503, AiProviderException::CATEGORY_PROVIDER_ERROR],
        ];
    }

    #[DataProvider('geminiFailureStatuses')]
    public function test_gemini_maps_the_failure_statuses_to_categories(int $status, string $category): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'x'], $status)]);

        try {
            $this->gemini()->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);
            $this->fail("HTTP {$status} must throw");
        } catch (AiProviderException $e) {
            $this->assertSame($category, $e->category(), "status {$status}");
        }
    }

    public function test_gemini_rejects_an_empty_candidate_as_invalid_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => []]]]]),
        ]);

        try {
            $this->gemini()->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);
            $this->fail('empty candidate must throw');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_INVALID_RESPONSE, $e->category());
        }
    }

    /* ------------------------------------------------- OpenAI-compatible */

    public function test_compatible_posts_to_the_configured_base_url(): void
    {
        Http::fake(['self-hosted.example/*' => Http::response($this->chatBody())]);

        $provider = new OpenAiCompatibleProvider(
            baseUrl: 'https://self-hosted.example/v1',
            apiKey: 'fake-test-key',
            defaultModel: 'local-model',
        );

        $result = $provider->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);

        $this->assertSame('Hello there', $result['text']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://self-hosted.example/v1/chat/completions'
            && $request->data()['model'] === 'local-model');
    }

    /** @return array<string, array{int, string}> */
    public static function compatibleFailureStatuses(): array
    {
        return [
            '401 auth' => [401, AiProviderException::CATEGORY_AUTH_FAILED],
            '429 rate' => [429, AiProviderException::CATEGORY_RATE_LIMITED],
            '502 provider' => [502, AiProviderException::CATEGORY_PROVIDER_ERROR],
        ];
    }

    #[DataProvider('compatibleFailureStatuses')]
    public function test_compatible_maps_the_failure_statuses_to_categories(int $status, string $category): void
    {
        Http::fake(['self-hosted.example/*' => Http::response(['error' => 'x'], $status)]);

        $provider = new OpenAiCompatibleProvider(
            baseUrl: 'https://self-hosted.example/v1',
            apiKey: 'fake-test-key',
            defaultModel: 'local-model',
        );

        try {
            $provider->complete(['messages' => [['role' => 'user', 'content' => 'hi']]]);
            $this->fail("HTTP {$status} must throw");
        } catch (AiProviderException $e) {
            $this->assertSame($category, $e->category(), "status {$status}");
        }
    }
}

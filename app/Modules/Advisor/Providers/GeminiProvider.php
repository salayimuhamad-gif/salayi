<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Providers;

use App\Modules\Advisor\Contracts\AiProvider;
use App\Modules\Advisor\Exceptions\AiProviderException;
use App\Modules\Advisor\Support\AiCost;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Native Google Gemini adapter, against the generateContent endpoint.
 *
 * Current-contract notes (verified against the official API reference, not
 * copied from it):
 *
 *   - endpoint style: POST {base}/models/{model}:generateContent
 *   - authentication: the `x-goog-api-key` request header. Google also
 *     accepts `?key=` in the URL, which this adapter deliberately does NOT
 *     use — a key in the URL leaks into transfer exceptions and access logs.
 *   - request: `system_instruction` is its own top-level field (not a
 *     message role), history is `contents` with roles `user`/`model`, and
 *     text lives in `parts`.
 *   - tuning: `generationConfig` carries temperature and
 *     `maxOutputTokens`; `responseMimeType: application/json` is the JSON
 *     switch.
 *   - response: text at candidates[0].content.parts[].text (joined —
 *     Gemini may split one answer across parts), finishReason beside it,
 *     usage in `usageMetadata` as promptTokenCount / candidatesTokenCount
 *     (+ thoughtsTokenCount on thinking models, which bills as output).
 *   - a 404 means the model name does not exist for this API version; 429
 *     is the rate/quota signal.
 */
final class GeminiProvider implements AiProvider
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        private readonly int $timeout = 30,
        /** @var array{prompt: float, completion: float} USD per 1M tokens */
        private readonly array $rates = ['prompt' => 0.0, 'completion' => 0.0],
    ) {}

    public function key(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '' && $this->model !== '';
    }

    public function supportsFallback(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function complete(array $request): array
    {
        if (! $this->isConfigured()) {
            throw AiProviderException::notConfigured($this->key());
        }

        $messages = $request['messages'] ?? [];

        if (! is_array($messages) || $messages === []) {
            throw AiProviderException::invalidRequest($this->key(), 'no messages supplied');
        }

        $model = (string) ($request['model'] ?? $this->model);
        $timeout = max(3, (int) ($request['timeout'] ?? $this->timeout));

        $contents = [];

        foreach ($messages as $message) {
            $contents[] = [
                // Gemini's assistant role is `model`; anything else is `user`.
                'role' => ($message['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($message['content'] ?? '')]],
            ];
        }

        $generationConfig = [
            'temperature' => (float) ($request['temperature'] ?? 0.2),
            'maxOutputTokens' => (int) ($request['max_tokens'] ?? 1200),
        ];

        if (($request['json'] ?? false) === true) {
            $generationConfig['responseMimeType'] = 'application/json';
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => $generationConfig,
        ];

        // A top-level field, not a pseudo-message: merging the system prompt
        // into the first user turn is the same prompt-injection route the
        // OpenAI adapters avoid by prepending a real system role.
        if (isset($request['system']) && is_string($request['system']) && $request['system'] !== '') {
            $body['system_instruction'] = ['parts' => [['text' => $request['system']]]];
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders(['x-goog-api-key' => (string) $this->apiKey])
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                // One retry, on connection failure only — same reasoning as
                // the OpenAI adapters.
                ->retry(1, 200, throw: false)
                ->acceptJson()
                ->post(rtrim($this->baseUrl, '/').'/models/'.rawurlencode($model).':generateContent', $body);
        } catch (Throwable) {
            throw AiProviderException::unreachable($this->key());
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->status() === 401 || $response->status() === 403) {
            throw AiProviderException::unauthorized($this->key());
        }

        if ($response->status() === 404) {
            throw AiProviderException::modelUnavailable($this->key(), $model);
        }

        if ($response->status() === 429) {
            throw AiProviderException::rateLimited($this->key());
        }

        if (! $response->successful()) {
            throw AiProviderException::failed($this->key(), $response->status());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw AiProviderException::invalidResponse($this->key(), 'body was not JSON');
        }

        $parts = $payload['candidates'][0]['content']['parts'] ?? null;

        if (! is_array($parts)) {
            throw AiProviderException::invalidResponse($this->key(), 'no candidate content');
        }

        $text = implode('', array_map(
            static fn (array $part): string => (string) ($part['text'] ?? ''),
            array_values(array_filter($parts, 'is_array')),
        ));

        if ($text === '') {
            throw AiProviderException::invalidResponse($this->key(), 'empty candidate text');
        }

        $usage = (array) ($payload['usageMetadata'] ?? []);
        $promptTokens = (int) ($usage['promptTokenCount'] ?? 0);
        // Thinking tokens bill as output, so they belong in the completion
        // figure the budget is computed from.
        $completionTokens = (int) ($usage['candidatesTokenCount'] ?? 0)
            + (int) ($usage['thoughtsTokenCount'] ?? 0);

        return [
            'text' => $text,
            'model' => $model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_usd' => AiCost::of($promptTokens, $completionTokens, $this->rates),
            'latency_ms' => $latencyMs,
            'finish_reason' => (string) ($payload['candidates'][0]['finishReason'] ?? 'unknown'),
        ];
    }
}

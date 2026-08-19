<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Providers;

use App\Modules\Advisor\Contracts\AiProvider;
use App\Modules\Advisor\Exceptions\AiProviderException;
use App\Modules\Advisor\Support\AiCost;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Native OpenAI adapter, against the hosted chat-completions endpoint.
 *
 * Distinct from {@see OpenAiCompatibleProvider} even though the wire format is
 * related, for two reasons that matter operationally. The base URL is pinned
 * to api.openai.com rather than configurable, so a misconfigured compatible
 * endpoint can never masquerade as "OpenAI" on the diagnostics surface. And a
 * 404 here can only mean the MODEL does not exist — the path is known good —
 * so it maps to the model_unavailable category instead of a generic failure,
 * which is exactly the distinction a Super Admin needs when a test fails.
 *
 * Current-contract notes (verified against the official API reference, not
 * copied from it): `max_completion_tokens` has replaced `max_tokens` across
 * current chat models; some reasoning models reject a non-default
 * `temperature`. Rather than hard-coding a model-name list that will rot,
 * a 400 is retried ONCE with the tuning parameters stripped to the minimum
 * the contract guarantees — model, messages, token ceiling — so a stricter
 * model degrades to default sampling instead of failing outright.
 */
final class OpenAiProvider implements AiProvider
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly int $timeout = 30,
        /** @var array{prompt: float, completion: float} USD per 1M tokens */
        private readonly array $rates = ['prompt' => 0.0, 'completion' => 0.0],
    ) {}

    public function key(): string
    {
        return 'openai';
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

        // Prepended rather than merged into the first user turn: merging lets
        // a user message that begins with instructions read as though the
        // system wrote them, which is a prompt-injection route.
        if (isset($request['system']) && is_string($request['system']) && $request['system'] !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $request['system']]);
        }

        $model = (string) ($request['model'] ?? $this->model);
        $timeout = max(3, (int) ($request['timeout'] ?? $this->timeout));
        $maxTokens = (int) ($request['max_tokens'] ?? 1200);

        $body = [
            'model' => $model,
            'messages' => array_values($messages),
            'temperature' => (float) ($request['temperature'] ?? 0.2),
            'max_completion_tokens' => $maxTokens,
        ];

        if (($request['json'] ?? false) === true) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $startedAt = microtime(true);
        $response = $this->post($body, $timeout);

        // Stricter models 400 on tuning parameters they do not accept. One
        // minimal retry — model, messages, token ceiling only — keeps a
        // configured reasoning model usable at its default sampling.
        if ($response->status() === 400) {
            $response = $this->post([
                'model' => $model,
                'messages' => array_values($messages),
                'max_completion_tokens' => $maxTokens,
            ], $timeout);
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

        $text = $payload['choices'][0]['message']['content'] ?? null;

        if (! is_string($text)) {
            throw AiProviderException::invalidResponse($this->key(), 'no message content');
        }

        $promptTokens = (int) ($payload['usage']['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($payload['usage']['completion_tokens'] ?? 0);

        return [
            'text' => $text,
            'model' => (string) ($payload['model'] ?? $model),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_usd' => AiCost::of($promptTokens, $completionTokens, $this->rates),
            'latency_ms' => $latencyMs,
            'finish_reason' => (string) ($payload['choices'][0]['finish_reason'] ?? 'unknown'),
        ];
    }

    /** @param array<string, mixed> $body */
    private function post(array $body, int $timeout): Response
    {
        try {
            return Http::withToken((string) $this->apiKey)
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                // One retry, on connection failure only — retrying a 4xx
                // repeats a request already rejected on its merits, and
                // retrying a 5xx risks double-billing a completion that may
                // have succeeded server-side.
                ->retry(1, 200, throw: false)
                ->acceptJson()
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', $body);
        } catch (Throwable) {
            // The message is not propagated: a transfer exception carries the
            // full request URL and headers context.
            throw AiProviderException::unreachable($this->key());
        }
    }
}

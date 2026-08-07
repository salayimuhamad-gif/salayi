<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Providers;

use App\Modules\Advisor\Contracts\AiProvider;
use App\Modules\Advisor\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpenAI-compatible chat-completions adapter (File one §6.1).
 *
 * "OpenAI-compatible" rather than "OpenAI" on purpose: the same wire format is
 * served by OpenAI, Groq, Together, OpenRouter, vLLM and Ollama, so a single
 * adapter covers the hosted and self-hosted cases the spec asks for. The base
 * URL is configuration, not code.
 *
 * The key is read from config, which reads only from the environment. It is
 * never accepted as a parameter and never stored in the database — an
 * administrator chooses WHICH provider is active; the credential itself is
 * visible only to whoever has filesystem access.
 *
 * Cost is computed here rather than trusted from the response because most
 * compatible endpoints do not return one. Rates are configuration: a wrong
 * hard-coded rate silently corrupts the monthly budget that is supposed to stop
 * runaway spend.
 */
final class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $defaultModel,
        private readonly int $timeout = 30,
        /** USD per 1M tokens, [prompt, completion]. */
        /** @var array{prompt: float, completion: float} per-1k-token cost */
        private readonly array $rates = ['prompt' => 0.0, 'completion' => 0.0],
    ) {}

    public function key(): string
    {
        return 'openai_compatible';
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== null && $this->apiKey !== '';
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

        // A system prompt is prepended rather than merged into the first user
        // turn: merging lets a user message that begins with instructions read
        // as though the system wrote them, which is a prompt-injection route.
        if (isset($request['system']) && is_string($request['system']) && $request['system'] !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $request['system']]);
        }

        $model = (string) ($request['model'] ?? $this->defaultModel);
        $startedAt = microtime(true);

        try {
            $timeout = max(3, (int) ($request['timeout'] ?? $this->timeout));
            $response = Http::withToken((string) $this->apiKey)
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                // One retry, on connection failure only. Retrying a 4xx would
                // repeat a request the provider has already rejected on its
                // merits, and retrying a 5xx risks double-billing a completion
                // that may have succeeded server-side.
                ->retry(1, 200, throw: false)
                ->acceptJson()
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                    'model' => $model,
                    'messages' => array_values($messages),
                    'temperature' => (float) ($request['temperature'] ?? 0.2),
                    'max_tokens' => (int) ($request['max_tokens'] ?? 1200),
                ]);
        } catch (Throwable $e) {
            // The message is not propagated: a transfer exception carries the
            // full request URL, and for some gateways the key is in that path.
            throw AiProviderException::unreachable($this->key());
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->status() === 401 || $response->status() === 403) {
            throw AiProviderException::unauthorized($this->key());
        }

        if ($response->status() === 429) {
            throw AiProviderException::rateLimited($this->key());
        }

        if (! $response->successful()) {
            throw AiProviderException::failed($this->key(), $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw AiProviderException::invalidResponse($this->key(), 'body was not JSON');
        }

        $text = $body['choices'][0]['message']['content'] ?? null;

        if (! is_string($text)) {
            throw AiProviderException::invalidResponse($this->key(), 'no message content');
        }

        $promptTokens = (int) ($body['usage']['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($body['usage']['completion_tokens'] ?? 0);

        return [
            'text' => $text,
            'model' => (string) ($body['model'] ?? $model),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_usd' => $this->cost($promptTokens, $completionTokens),
            'latency_ms' => $latencyMs,
            'finish_reason' => (string) ($body['choices'][0]['finish_reason'] ?? 'unknown'),
        ];
    }

    /**
     * Cost in USD as a decimal string.
     *
     * A string, not a float: this figure accumulates into a monthly budget that
     * gates whether the feature runs at all, and float addition over thousands
     * of small values drifts. The rest of this codebase uses Decimal for money
     * for the same reason.
     */
    private function cost(int $promptTokens, int $completionTokens): string
    {
        // The constructor's declared shape always carries both rates.
        $promptRate = $this->rates['prompt'];
        $completionRate = $this->rates['completion'];

        $total = ($promptTokens * $promptRate + $completionTokens * $completionRate) / 1_000_000;

        return number_format($total, 6, '.', '');
    }
}

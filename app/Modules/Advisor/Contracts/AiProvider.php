<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Contracts;

use App\Modules\Advisor\Exceptions\AiProviderException;

/**
 * Provider adapter contract (spec 17.1).
 *
 * The abstraction exists so the product is not tied to one vendor's uptime,
 * pricing or content policy — spec 17.1 lists OpenAI-compatible,
 * Anthropic-compatible, Google-compatible and local endpoints, and the
 * application must keep working when one of them fails.
 *
 * Credentials are NEVER passed through this interface from the database. Spec
 * 17.1: "Secrets must never be visible to ordinary administrators." An adapter
 * reads its key from config/services.php, which reads only from the
 * environment. An administrator chooses WHICH provider is active; the key
 * itself is visible only to whoever has filesystem access.
 */
interface AiProvider
{
    public function key(): string;

    /** The model this adapter will use when the request names none. */
    public function model(): string;

    /**
     * `json: true` asks the provider for a JSON-object response where its API
     * supports declaring that (OpenAI response_format, Gemini responseMimeType).
     * Adapters for endpoints without a reliable JSON switch ignore the flag —
     * the caller must always validate the text as JSON itself, because even a
     * declared JSON mode does not make parsing optional.
     *
     * @param  array{
     *     system?: string, messages: list<array{role: string, content: string}>,
     *     model?: string, temperature?: float, max_tokens?: int, timeout?: int,
     *     json?: bool
     * }  $request
     * @return array{
     *     text: string, model: string, prompt_tokens: int, completion_tokens: int,
     *     cost_usd: string, latency_ms: int, finish_reason: string
     * }
     *
     * @throws AiProviderException
     */
    public function complete(array $request): array;

    public function isConfigured(): bool;

    /** Whether this adapter may serve as a fallback for another (spec 17.1). */
    public function supportsFallback(): bool;
}

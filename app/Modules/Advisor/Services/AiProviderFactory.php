<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Contracts\AiProvider;
use App\Modules\Advisor\Providers\GeminiProvider;
use App\Modules\Advisor\Providers\OpenAiCompatibleProvider;
use App\Modules\Advisor\Providers\OpenAiProvider;

/**
 * The one place a provider NAME becomes a provider ADAPTER.
 *
 * This class is the fix for the audit's finding G: `AI_PROVIDER` existed in
 * configuration, was validated by the admin panel and written by the
 * installer — and was read by nothing. Activation was an accident of which
 * legacy keys happened to be non-empty. Here the selection is real:
 * `AI_PROVIDER` names the primary, `AI_FALLBACK_PROVIDER` optionally names
 * one fallback, `null` (or empty) means OFF regardless of what credentials
 * are lying around, and an unknown name resolves to nothing rather than to
 * a guess.
 *
 * Adding a provider is one adapter class plus one arm in {@see make()} plus
 * one config block — no call-site changes, which is what keeps "Mini4", if
 * the product owner ever confirms what it means, a small change instead of
 * a rework.
 *
 * Legacy compatibility is handled in config/services.php, not here: the
 * openai_compatible block falls back to the historical AI_BASE_URL /
 * AI_API_KEY / AI_MODEL keys, so an existing production `.env` keeps
 * working the moment its (already validated) AI_PROVIDER value starts being
 * obeyed.
 */
final class AiProviderFactory
{
    /** Provider names an administrator may select. */
    public const PROVIDERS = ['openai', 'gemini', 'openai_compatible'];

    /**
     * The configured chain, primary first — for the gateway.
     *
     * Unconfigured entries are dropped (a name without a credential is a
     * statement of intent, not a provider), as is a fallback that repeats
     * the primary.
     *
     * @return list<AiProvider>
     */
    public function chain(): array
    {
        $primaryName = $this->normalize((string) config('services.ai.provider', 'null'));
        $fallbackName = $this->normalize((string) config('services.ai.fallback_provider', ''));

        $chain = [];

        $primary = $primaryName === null ? null : $this->make($primaryName);

        if ($primary !== null && $primary->isConfigured()) {
            $chain[] = $primary;
        }

        if ($fallbackName !== null && $fallbackName !== $primaryName) {
            $fallback = $this->make($fallbackName);

            if ($fallback !== null && $fallback->isConfigured()) {
                $chain[] = $fallback;
            }
        }

        return $chain;
    }

    /**
     * One adapter by name, configured or not — for diagnostics and the
     * connection test, which need to talk ABOUT an unconfigured provider.
     */
    public function make(string $name): ?AiProvider
    {
        $timeout = (int) config('services.ai.timeout', 30);
        /** @var array{prompt: float, completion: float} $rates */
        $rates = [
            'prompt' => (float) config('services.ai.rates.prompt', 0),
            'completion' => (float) config('services.ai.rates.completion', 0),
        ];

        return match ($this->normalize($name)) {
            'openai' => new OpenAiProvider(
                apiKey: $this->secret('services.ai.providers.openai.key'),
                model: (string) config('services.ai.providers.openai.model', ''),
                baseUrl: (string) config('services.ai.providers.openai.base_url', 'https://api.openai.com/v1'),
                timeout: $timeout,
                rates: $rates,
            ),
            'gemini' => new GeminiProvider(
                apiKey: $this->secret('services.ai.providers.gemini.key'),
                model: (string) config('services.ai.providers.gemini.model', ''),
                baseUrl: (string) config('services.ai.providers.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'),
                timeout: $timeout,
                rates: $rates,
            ),
            'openai_compatible' => new OpenAiCompatibleProvider(
                baseUrl: (string) config('services.ai.providers.openai_compatible.base_url', ''),
                apiKey: $this->secret('services.ai.providers.openai_compatible.key'),
                defaultModel: (string) config('services.ai.providers.openai_compatible.model', ''),
                timeout: $timeout,
                rates: $rates,
            ),
            default => null,
        };
    }

    /** The selected primary name, or null when AI is off. */
    public function primaryName(): ?string
    {
        return $this->normalize((string) config('services.ai.provider', 'null'));
    }

    /** The selected fallback name, or null when none is configured. */
    public function fallbackName(): ?string
    {
        $name = $this->normalize((string) config('services.ai.fallback_provider', ''));

        return $name === $this->primaryName() ? null : $name;
    }

    /** 'null', '', 'none' and unknown names all mean "no provider". */
    private function normalize(string $name): ?string
    {
        $name = strtolower(trim($name));

        return in_array($name, self::PROVIDERS, true) ? $name : null;
    }

    private function secret(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

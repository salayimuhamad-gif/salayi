<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Contracts\AiProvider;
use App\Modules\Advisor\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The only sanctioned way to call a language model (File one §6.11, §6.14).
 *
 * §6.11 lists timeout, retry, fallback, circuit-breaker, token and monthly cost
 * controls. Scattering those across call sites guarantees one of them is
 * forgotten in the call that matters, so every completion goes through here.
 *
 * The controls exist for three different failure modes, and conflating them
 * produces bad behaviour:
 *
 *   - A provider that is DOWN should be skipped quickly, not retried into a
 *     timeout on every request. That is the circuit breaker.
 *   - A provider that is RATE-LIMITED should hand off to the fallback
 *     immediately; waiting does not help and the second provider is idle.
 *   - A budget that is EXHAUSTED should stop everything, including fallbacks.
 *     A fallback provider costs money too, and "we ran out of budget so we
 *     spent more" is not a recovery strategy.
 *
 * The cost ceiling is checked BEFORE the call, not after. Checking afterwards
 * means the request that breaches the limit is the one you have already paid
 * for, and on a runaway loop that is the difference between a small overrun and
 * an invoice nobody authorised.
 */
final class AiGateway
{
    /** Consecutive failures before a provider is skipped. */
    private const FAILURE_THRESHOLD = 3;

    /** How long a tripped breaker stays open. */
    private const BREAKER_SECONDS = 300;

    /**
     * @param  list<AiProvider>  $providers  primary first, fallbacks after
     */
    public function __construct(
        private readonly array $providers,
        private readonly float $monthlyLimitUsd = 0.0,
    ) {}

    /** Whether any provider is configured at all (§6.14 graceful fallback). */
    public function isAvailable(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->isConfigured() && ! $this->isOpen($provider)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Complete a request, falling back through the configured providers.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     *
     * @throws AiProviderException when every provider fails or the budget is spent
     */
    public function complete(array $request): array
    {
        $this->assertWithinBudget();

        $attempted = [];
        $lastFailure = null;

        foreach ($this->providers as $index => $provider) {
            if (! $provider->isConfigured()) {
                continue;
            }

            // A fallback must declare itself usable as one. A provider tuned
            // for a specific model may be primary-only.
            if ($index > 0 && ! $provider->supportsFallback()) {
                continue;
            }

            if ($this->isOpen($provider)) {
                $attempted[] = $provider->key().':breaker_open';

                continue;
            }

            try {
                $result = $provider->complete($request);

                $this->recordSuccess($provider);
                // Every AiProvider result declares cost_usd.
                $this->recordSpend($result['cost_usd']);

                $result['provider'] = $provider->key();
                $result['fell_back'] = $index > 0;

                return $result;
            } catch (AiProviderException $e) {
                $lastFailure = $e;
                $attempted[] = $provider->key();
                $this->recordFailure($provider);

                // The message is safe — AiProviderException never carries a
                // response body — but the request is not, so it is never logged.
                Log::warning('ai.provider_failed', [
                    'provider' => $provider->key(),
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        throw $lastFailure ?? AiProviderException::notConfigured('none');
    }

    /**
     * Refuse before spending rather than after.
     *
     * @throws AiProviderException
     */
    private function assertWithinBudget(): void
    {
        if ($this->monthlyLimitUsd <= 0.0) {
            return;
        }

        $spent = (float) Cache::get($this->spendKey(), 0.0);

        if ($spent >= $this->monthlyLimitUsd) {
            throw AiProviderException::costLimitReached(number_format($this->monthlyLimitUsd, 2, '.', ''));
        }
    }

    private function recordSpend(string $costUsd): void
    {
        if ($this->monthlyLimitUsd <= 0.0) {
            return;
        }

        $key = $this->spendKey();
        $spent = (float) Cache::get($key, 0.0);

        // Kept for 40 days so the running total survives to the end of any
        // month and expires on its own without a scheduled cleanup.
        Cache::put($key, $spent + (float) $costUsd, now()->addDays(40));
    }

    private function spendKey(): string
    {
        return 'ai:spend:'.date('Y-m');
    }

    private function breakerKey(AiProvider $provider): string
    {
        return 'ai:breaker:'.$provider->key();
    }

    private function isOpen(AiProvider $provider): bool
    {
        return (int) Cache::get($this->breakerKey($provider), 0) >= self::FAILURE_THRESHOLD;
    }

    private function recordFailure(AiProvider $provider): void
    {
        $key = $this->breakerKey($provider);
        $failures = (int) Cache::get($key, 0) + 1;

        Cache::put($key, $failures, now()->addSeconds(self::BREAKER_SECONDS));
    }

    /**
     * One success closes the breaker completely.
     *
     * Decrementing instead would leave a recovered provider one failure away
     * from being skipped again, which on an intermittent network turns a brief
     * blip into a five-minute outage.
     */
    private function recordSuccess(AiProvider $provider): void
    {
        Cache::forget($this->breakerKey($provider));
    }
}

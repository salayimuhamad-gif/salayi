<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Exceptions\AiProviderException;

/**
 * The Super Admin "Test connection" action, server-side.
 *
 * A deliberately tiny completion — a handful of tokens — against ONE named
 * provider, reported back as a category token from this application's own
 * vocabulary. Nothing from the provider crosses to the browser except the
 * measured latency and the model name this application already had in its
 * configuration: no bodies, no headers, no credential, no stack trace.
 *
 * The call goes through a single-provider {@see AiGateway} on purpose:
 * the monthly ceiling applies to tests too (a test is still a paid call),
 * the spend is recorded like any other, an open breaker is reported as its
 * own status instead of being silently bypassed — and a SUCCESSFUL test
 * closes the breaker, which makes the button double as the recovery lever
 * after an outage.
 */
final class AiConnectionTester
{
    public const STATUS_CONNECTED = 'connected';

    public const STATUS_UNKNOWN_PROVIDER = 'unknown_provider';

    public function __construct(private readonly AiProviderFactory $factory) {}

    /**
     * @return array{status: string, provider: string, model: string|null, latency_ms: int|null}
     */
    public function test(string $providerName): array
    {
        $adapter = $this->factory->make($providerName);

        if ($adapter === null) {
            return [
                'status' => self::STATUS_UNKNOWN_PROVIDER,
                'provider' => $providerName,
                'model' => null,
                'latency_ms' => null,
            ];
        }

        if (! $adapter->isConfigured()) {
            return [
                'status' => AiProviderException::CATEGORY_NOT_CONFIGURED,
                'provider' => $adapter->key(),
                'model' => $adapter->model() === '' ? null : $adapter->model(),
                'latency_ms' => null,
            ];
        }

        $gateway = new AiGateway(
            providers: [$adapter],
            monthlyLimitUsd: (float) config('services.ai.monthly_cost_limit_usd', 0),
        );

        $gatewayStatus = $gateway->status();

        if ($gatewayStatus['budget_exhausted']) {
            return [
                'status' => AiProviderException::CATEGORY_BUDGET_EXHAUSTED,
                'provider' => $adapter->key(),
                'model' => $adapter->model(),
                'latency_ms' => null,
            ];
        }

        if ($gatewayStatus['providers'][0]['breaker_open'] ?? false) {
            return [
                'status' => AiProviderException::CATEGORY_BREAKER_OPEN,
                'provider' => $adapter->key(),
                'model' => $adapter->model(),
                'latency_ms' => null,
            ];
        }

        try {
            $result = $gateway->complete([
                'messages' => [['role' => 'user', 'content' => 'Reply with the single word OK.']],
                'temperature' => 0.0,
                'max_tokens' => 8,
                'timeout' => min(10, (int) config('services.ai.timeout', 30)),
            ]);
        } catch (AiProviderException $e) {
            return [
                'status' => $e->category(),
                'provider' => $adapter->key(),
                'model' => $adapter->model(),
                'latency_ms' => null,
            ];
        }

        return [
            'status' => self::STATUS_CONNECTED,
            'provider' => $adapter->key(),
            'model' => (string) ($result['model'] ?? $adapter->model()),
            'latency_ms' => (int) ($result['latency_ms'] ?? 0),
        ];
    }
}

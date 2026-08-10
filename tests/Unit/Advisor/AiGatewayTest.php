<?php

declare(strict_types=1);

namespace Tests\Unit\Advisor;

use App\Modules\Advisor\Contracts\AiProvider;
use App\Modules\Advisor\Exceptions\AiProviderException;
use App\Modules\Advisor\Services\AiGateway;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** A configurable in-memory provider — the tests' only "model". */
final class FakeAiProvider implements AiProvider
{
    public int $calls = 0;

    public function __construct(
        private readonly string $key,
        private ?AiProviderException $failure = null,
        private readonly string $costUsd = '0.010000',
        private readonly bool $configured = true,
        private readonly bool $fallback = true,
    ) {}

    public function failWith(?AiProviderException $failure): void
    {
        $this->failure = $failure;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function supportsFallback(): bool
    {
        return $this->fallback;
    }

    public function complete(array $request): array
    {
        $this->calls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return [
            'text' => 'ok from '.$this->key,
            'model' => 'fake-model',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'cost_usd' => $this->costUsd,
            'latency_ms' => 12,
            'finish_reason' => 'stop',
        ];
    }
}

/**
 * The gateway's three distinct failure disciplines, pinned: a DOWN provider
 * is skipped by the breaker, a RATE-LIMITED one hands off to the fallback
 * immediately, and an EXHAUSTED budget stops everything — including the
 * fallback, because "we ran out of budget so we spent more" is not recovery.
 */
final class AiGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return ['messages' => [['role' => 'user', 'content' => 'hi']]];
    }

    public function test_the_primary_answers_and_its_provider_key_is_stamped(): void
    {
        $gateway = new AiGateway([new FakeAiProvider('openai')]);

        $result = $gateway->complete($this->request());

        $this->assertSame('ok from openai', $result['text']);
        $this->assertSame('openai', $result['provider']);
        $this->assertFalse($result['fell_back']);
    }

    public function test_a_failing_primary_falls_back_and_the_result_says_so(): void
    {
        $primary = new FakeAiProvider('openai', AiProviderException::rateLimited('openai'));
        $fallback = new FakeAiProvider('gemini');

        $result = (new AiGateway([$primary, $fallback]))->complete($this->request());

        $this->assertSame('gemini', $result['provider']);
        $this->assertTrue($result['fell_back']);
        $this->assertSame(1, $primary->calls);
    }

    public function test_an_exhausted_budget_refuses_before_any_provider_is_called(): void
    {
        $primary = new FakeAiProvider('openai');
        $fallback = new FakeAiProvider('gemini');
        Cache::put('ai:spend:'.date('Y-m'), 5.0, now()->addDay());

        $gateway = new AiGateway([$primary, $fallback], monthlyLimitUsd: 5.0);

        try {
            $gateway->complete($this->request());
            $this->fail('budget exhaustion must throw');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_BUDGET_EXHAUSTED, $e->category());
        }

        // NEITHER provider ran: the ceiling gates the fallback too.
        $this->assertSame(0, $primary->calls);
        $this->assertSame(0, $fallback->calls);
    }

    public function test_spend_accumulates_toward_the_ceiling(): void
    {
        $gateway = new AiGateway([new FakeAiProvider('openai', costUsd: '2.000000')], monthlyLimitUsd: 5.0);

        $gateway->complete($this->request());
        $gateway->complete($this->request());
        $this->assertSame(4.0, $gateway->monthlySpent());

        $gateway->complete($this->request());

        // 6.00 spent of 5.00: the next call must be refused.
        try {
            $gateway->complete($this->request());
            $this->fail('over-budget call must be refused');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_BUDGET_EXHAUSTED, $e->category());
        }
    }

    public function test_three_failures_open_the_breaker_and_one_success_closes_it(): void
    {
        $provider = new FakeAiProvider('openai', AiProviderException::failed('openai', 500));
        $gateway = new AiGateway([$provider]);

        for ($i = 0; $i < 3; $i++) {
            try {
                $gateway->complete($this->request());
            } catch (AiProviderException) {
            }
        }

        $this->assertFalse($gateway->isAvailable());

        // While open, the provider is not even attempted.
        try {
            $gateway->complete($this->request());
        } catch (AiProviderException) {
        }
        $this->assertSame(3, $provider->calls);

        // The window passes, the provider recovers, one success resets fully.
        Cache::forget('ai:breaker:openai');
        $provider->failWith(null);
        $gateway->complete($this->request());

        $this->assertTrue($gateway->isAvailable());
        $this->assertSame(0, (int) Cache::get('ai:breaker:openai', 0));
    }

    public function test_an_unconfigured_provider_is_skipped_silently(): void
    {
        $unconfigured = new FakeAiProvider('openai', configured: false);
        $fallback = new FakeAiProvider('gemini');

        $result = (new AiGateway([$unconfigured, $fallback]))->complete($this->request());

        $this->assertSame('gemini', $result['provider']);
        $this->assertSame(0, $unconfigured->calls);
    }

    public function test_no_configured_provider_throws_not_configured(): void
    {
        try {
            (new AiGateway([]))->complete($this->request());
            $this->fail('an empty chain must throw');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::CATEGORY_NOT_CONFIGURED, $e->category());
        }
    }

    public function test_telemetry_records_the_last_failure_category_and_last_success(): void
    {
        $provider = new FakeAiProvider('openai', AiProviderException::unauthorized('openai'));
        $gateway = new AiGateway([$provider]);

        try {
            $gateway->complete($this->request());
        } catch (AiProviderException) {
        }

        $status = $gateway->status();
        $this->assertSame('openai', $status['last_failure']['provider']);
        $this->assertSame(AiProviderException::CATEGORY_AUTH_FAILED, $status['last_failure']['category']);

        $provider->failWith(null);
        $gateway->complete($this->request());

        $status = $gateway->status();
        $this->assertSame('openai', $status['last_success']['provider']);
        $this->assertSame('fake-model', $status['last_success']['model']);
        // Telemetry carries category tokens and identifiers only — never
        // request content, keys, or provider bodies.
        $this->assertArrayNotHasKey('request', (array) $status['last_failure']);
    }

    public function test_status_reports_the_chain_breaker_and_budget_state(): void
    {
        Cache::put('ai:spend:'.date('Y-m'), 9.5, now()->addDay());

        $gateway = new AiGateway(
            [new FakeAiProvider('openai'), new FakeAiProvider('gemini')],
            monthlyLimitUsd: 9.0,
        );

        $status = $gateway->status();

        $this->assertFalse($status['providers'][0]['is_fallback']);
        $this->assertTrue($status['providers'][1]['is_fallback']);
        $this->assertTrue($status['budget_exhausted']);
        $this->assertSame('9.00', $status['monthly_limit_usd']);
    }
}

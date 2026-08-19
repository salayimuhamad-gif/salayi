<?php

declare(strict_types=1);

namespace Tests\Unit\Advisor;

use App\Modules\Advisor\Providers\GeminiProvider;
use App\Modules\Advisor\Providers\OpenAiCompatibleProvider;
use App\Modules\Advisor\Providers\OpenAiProvider;
use App\Modules\Advisor\Services\AiProviderFactory;
use Tests\TestCase;

/**
 * Provider selection is CONFIGURATION, not accident.
 *
 * The audit's finding G: AI_PROVIDER was validated, stored, displayed — and
 * read by nothing; the adapter activated whenever legacy credentials were
 * present. These tests pin the repaired contract: the named primary is the
 * one that runs, 'null' means off even with credentials on disk, the
 * fallback is a second link only when it is a different, configured
 * provider, and the legacy env keys keep an existing production
 * openai_compatible configuration alive.
 */
final class AiProviderFactoryTest extends TestCase
{
    private function factory(): AiProviderFactory
    {
        return new AiProviderFactory;
    }

    public function test_null_provider_means_off_even_with_credentials_present(): void
    {
        config([
            'services.ai.provider' => 'null',
            // The exact legacy trap: credentials present, provider 'null'.
            // The old wiring switched the AI ON here; the repaired factory
            // must not.
            'services.ai.providers.openai_compatible.base_url' => 'https://ai.example',
            'services.ai.providers.openai_compatible.key' => 'fake-test-key',
            'services.ai.providers.openai_compatible.model' => 'test-model',
        ]);

        $this->assertSame([], $this->factory()->chain());
        $this->assertNull($this->factory()->primaryName());
    }

    public function test_openai_is_selected_by_name(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.providers.openai.key' => 'fake-test-key',
            'services.ai.providers.openai.model' => 'test-model',
        ]);

        $chain = $this->factory()->chain();

        $this->assertCount(1, $chain);
        $this->assertInstanceOf(OpenAiProvider::class, $chain[0]);
        $this->assertSame('test-model', $chain[0]->model());
    }

    public function test_gemini_is_selected_by_name(): void
    {
        config([
            'services.ai.provider' => 'gemini',
            'services.ai.providers.gemini.key' => 'fake-test-key',
            'services.ai.providers.gemini.model' => 'test-gemini-model',
        ]);

        $chain = $this->factory()->chain();

        $this->assertCount(1, $chain);
        $this->assertInstanceOf(GeminiProvider::class, $chain[0]);
    }

    public function test_openai_compatible_is_selected_by_name(): void
    {
        config([
            'services.ai.provider' => 'openai_compatible',
            'services.ai.providers.openai_compatible.base_url' => 'https://ai.example/v1',
            'services.ai.providers.openai_compatible.key' => 'fake-test-key',
            'services.ai.providers.openai_compatible.model' => 'test-model',
        ]);

        $chain = $this->factory()->chain();

        $this->assertCount(1, $chain);
        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $chain[0]);
    }

    public function test_a_named_but_unconfigured_primary_yields_no_chain(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.providers.openai.key' => null,
            'services.ai.providers.openai.model' => 'test-model',
        ]);

        $this->assertSame([], $this->factory()->chain());
    }

    public function test_the_fallback_provider_becomes_the_second_link(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.fallback_provider' => 'gemini',
            'services.ai.providers.openai.key' => 'fake-test-key',
            'services.ai.providers.openai.model' => 'primary-model',
            'services.ai.providers.gemini.key' => 'fake-test-key-2',
            'services.ai.providers.gemini.model' => 'fallback-model',
        ]);

        $chain = $this->factory()->chain();

        $this->assertCount(2, $chain);
        $this->assertInstanceOf(OpenAiProvider::class, $chain[0]);
        $this->assertInstanceOf(GeminiProvider::class, $chain[1]);
        $this->assertSame('gemini', $this->factory()->fallbackName());
    }

    public function test_a_fallback_equal_to_the_primary_is_dropped(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.fallback_provider' => 'openai',
            'services.ai.providers.openai.key' => 'fake-test-key',
            'services.ai.providers.openai.model' => 'test-model',
        ]);

        $this->assertCount(1, $this->factory()->chain());
        $this->assertNull($this->factory()->fallbackName());
    }

    public function test_an_unconfigured_fallback_is_dropped_from_the_chain(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.fallback_provider' => 'gemini',
            'services.ai.providers.openai.key' => 'fake-test-key',
            'services.ai.providers.openai.model' => 'test-model',
            'services.ai.providers.gemini.key' => null,
            'services.ai.providers.gemini.model' => '',
        ]);

        $this->assertCount(1, $this->factory()->chain());
    }

    public function test_an_unknown_provider_name_resolves_to_nothing_not_a_guess(): void
    {
        config(['services.ai.provider' => 'mini4']);

        $this->assertSame([], $this->factory()->chain());
        $this->assertNull($this->factory()->make('mini4'));
    }

    public function test_legacy_env_keys_keep_an_existing_compatible_configuration_alive(): void
    {
        /*
         * An existing production .env carries AI_PROVIDER=openai_compatible
         * with the historical AI_BASE_URL / AI_API_KEY / AI_MODEL keys. The
         * config layer maps those onto the openai_compatible block, so the
         * moment AI_PROVIDER starts being obeyed nothing breaks.
         */
        config([
            'services.ai.provider' => 'openai_compatible',
            'services.ai.providers.openai_compatible.base_url' => 'https://legacy.example/v1',
            'services.ai.providers.openai_compatible.key' => 'fake-legacy-key',
            'services.ai.providers.openai_compatible.model' => 'legacy-model',
        ]);

        $chain = $this->factory()->chain();

        $this->assertCount(1, $chain);
        $this->assertTrue($chain[0]->isConfigured());
        $this->assertSame('legacy-model', $chain[0]->model());
    }
}

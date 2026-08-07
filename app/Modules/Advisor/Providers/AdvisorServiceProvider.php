<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Providers;

use App\Modules\Advisor\Services\AdvisorAnswerComposer;
use App\Modules\Advisor\Services\AdvisorConversationFlow;
use App\Modules\Advisor\Services\AdvisorLanguage;
use App\Modules\Advisor\Services\AdvisorProjectMatcher;
use App\Modules\Advisor\Services\AdvisorTurnComposer;
use App\Modules\Advisor\Services\AiGateway;
use App\Modules\Advisor\Services\LifestyleCandidateBuilder;
use App\Modules\Advisor\Services\LifestyleMatcher;
use App\Modules\Advisor\Support\NumericGuard;
use App\Modules\Advisor\Support\RetrievalGuard;
use App\Modules\Core\Support\ModuleServiceProvider;

/**
 * Advisor domain — roadmap Step 4 (spec 16, 17).
 *
 * Implemented: the deterministic lifestyle matcher, the spec 17.3 retrieval
 * allowlist, numeric grounding for spec 17.5 "no invented prices", the provider
 * adapter contract, and the schema that makes an unvalidated AI answer
 * unrenderable.
 *
 * Phase 7 added: an OpenAI-compatible adapter, the resilient gateway that owns
 * timeout/fallback/circuit-breaker/cost limits, the deterministic question flow,
 * and the answer composer that lets the model explain a ranking it cannot move.
 *
 * The residential advisor now includes a live multilingual intake UI. Still
 * outside this module: database-backed prompt management, the five remaining
 * agents, and a formal Sorani evaluation corpus.
 */
final class AdvisorServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Advisor';
    }

    protected function roadmapStep(): int
    {
        return 4;
    }

    protected function registerModule(): void
    {
        // Production deployments use Composer's authoritative classmap. These
        // two patch-added services are loaded explicitly so the Hostinger patch
        // works without requiring Composer or rebuilding vendor/autoload.php.
        require_once dirname(__DIR__).'/Services/AdvisorLanguage.php';
        require_once dirname(__DIR__).'/Services/AdvisorTurnComposer.php';
        require_once dirname(__DIR__).'/Services/AdvisorProjectMatcher.php';
        $this->app->singleton(NumericGuard::class);
        $this->app->singleton(RetrievalGuard::class);
        $this->app->singleton(LifestyleMatcher::class);
        $this->app->singleton(AdvisorConversationFlow::class);
        $this->app->singleton(AdvisorLanguage::class);
        $this->app->singleton(LifestyleCandidateBuilder::class);
        $this->app->singleton(AdvisorProjectMatcher::class);

        /*
         * Providers are ordered primary-first. Today there is one adapter and
         * the fallback chain has a single link — which is still worth building
         * as a chain, because adding a second provider must not require
         * touching any call site.
         */
        $this->app->singleton(AiGateway::class, function (): AiGateway {
            $providers = [];

            $baseUrl = (string) config('services.ai.base_url', '');
            $key = config('services.ai.key');

            if ($baseUrl !== '' && is_string($key) && $key !== '') {
                $providers[] = new OpenAiCompatibleProvider(
                    baseUrl: $baseUrl,
                    apiKey: $key,
                    defaultModel: (string) config('services.ai.model', ''),
                    timeout: (int) config('services.ai.timeout', 30),
                    rates: (array) config('services.ai.rates', ['prompt' => 0.0, 'completion' => 0.0]),
                );
            }

            return new AiGateway(
                providers: $providers,
                monthlyLimitUsd: (float) config('services.ai.monthly_cost_limit_usd', 0),
            );
        });

        $this->app->singleton(AdvisorAnswerComposer::class);
        $this->app->singleton(AdvisorTurnComposer::class);
    }
}

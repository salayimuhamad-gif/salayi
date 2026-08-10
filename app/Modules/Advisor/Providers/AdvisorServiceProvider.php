<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Providers;

use App\Modules\Advisor\Services\AdvisorAnswerComposer;
use App\Modules\Advisor\Services\AdvisorConversationFlow;
use App\Modules\Advisor\Services\AdvisorLanguage;
use App\Modules\Advisor\Services\AdvisorProjectMatcher;
use App\Modules\Advisor\Services\AdvisorTurnComposer;
use App\Modules\Advisor\Services\AiGateway;
use App\Modules\Advisor\Services\AiProviderFactory;
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

        $this->app->singleton(AiProviderFactory::class);

        /*
         * Providers are ordered primary-first, and the ORDER IS CONFIGURATION:
         * AI_PROVIDER names the primary and AI_FALLBACK_PROVIDER the optional
         * second link. The factory owns that resolution — the audit's finding
         * G was precisely that this closure used to ignore AI_PROVIDER and
         * activate on credential presence alone.
         */
        $this->app->singleton(AiGateway::class, function (): AiGateway {
            return new AiGateway(
                providers: $this->app->make(AiProviderFactory::class)->chain(),
                monthlyLimitUsd: (float) config('services.ai.monthly_cost_limit_usd', 0),
            );
        });

        $this->app->singleton(AdvisorAnswerComposer::class);
        $this->app->singleton(AdvisorTurnComposer::class);
    }
}

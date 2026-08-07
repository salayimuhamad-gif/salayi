<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;

/**
 * Knowledge domain — roadmap Step 7 (spec 21, 24.5, 25, 32).
 *
 * See docs/ROADMAP_STATUS.md for implemented versus scaffolded.
 */
final class KnowledgeServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Knowledge';
    }

    protected function roadmapStep(): int
    {
        return 7;
    }
}

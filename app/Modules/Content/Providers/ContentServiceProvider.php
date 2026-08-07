<?php

declare(strict_types=1);

namespace App\Modules\Content\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;

/**
 * Content domain — roadmap Step 7 (spec 21, 24.5, 25, 32).
 *
 * See docs/ROADMAP_STATUS.md for implemented versus scaffolded.
 */
final class ContentServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Content';
    }

    protected function roadmapStep(): int
    {
        return 7;
    }
}

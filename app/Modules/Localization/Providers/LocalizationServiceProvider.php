<?php

declare(strict_types=1);

namespace App\Modules\Localization\Providers;

use App\Modules\Core\Support\ModuleServiceProvider;
use Illuminate\Support\Facades\Blade;

final class LocalizationServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Localization';
    }

    protected function bootModule(): void
    {
        // Directional helpers for any server-rendered markup (error pages,
        // e-mail templates, the installer — none of which run through Vue).
        Blade::directive('dir', static fn (): string => '<?php echo locale_direction(); ?>');
        Blade::directive('rtl', static fn (): string => '<?php if (is_rtl()): ?>');
        Blade::directive('endrtl', static fn (): string => '<?php endif; ?>');
    }
}

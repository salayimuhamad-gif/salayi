<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Sorani and Arabic strings must never be truncated mid-codepoint.
        // Str::limit is byte-safe by default in Laravel, but plugin code and
        // future modules often reach for substr(); registering the macro gives
        // them an obvious correct option.
        // Not static: `Str::macro()` binds the closure to the macroable class
        // so a macro can reach `$this`. A static closure cannot be bound, and
        // registering one is a fatal the moment anything tries.
        Str::macro('safeLimit', function (string $value, int $limit = 100, string $end = '…'): string {
            return mb_strwidth($value, 'UTF-8') <= $limit
                ? $value
                : rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')).$end;
        });
    }
}

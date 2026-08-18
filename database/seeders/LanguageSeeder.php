<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Localization\Models\Language;
use Illuminate\Database\Seeder;

final class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, array<string, mixed>> $supported */
        $supported = (array) config('localization.supported', []);
        $default = (string) config('localization.default', 'ckb');
        $enabled = enabled_locales();

        $order = 0;

        foreach ($supported as $code => $meta) {
            Language::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => (string) ($meta['english'] ?? $code),
                    'native_name' => (string) ($meta['native'] ?? $code),
                    'direction' => (string) ($meta['direction'] ?? 'ltr'),
                    // ckb is enabled unconditionally; it cannot be turned off.
                    'is_enabled' => $code === $default || in_array($code, $enabled, true),
                    'is_default' => $code === $default,
                    'sort_order' => $order++,
                ],
            );
        }
    }
}

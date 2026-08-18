<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Branding\Models\FeatureFlag;
use Illuminate\Database\Seeder;

/**
 * Materialises every known flag as a row so the admin screen can list them
 * (spec Appendix D).
 *
 * Every flag is seeded at its config default, which for Step 1 means every
 * optional module is OFF. `firstOrCreate` means a later deploy never re-enables
 * something an administrator deliberately turned off.
 */
final class FeatureFlagSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, bool> $defaults */
        $defaults = (array) config('features.defaults', []);

        foreach ($defaults as $flag => $enabled) {
            FeatureFlag::query()->firstOrCreate(
                ['flag' => $flag],
                ['enabled' => (bool) $enabled],
            );
        }
    }
}

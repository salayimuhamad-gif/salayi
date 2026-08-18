<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Baseline data required for the application to boot at all.
 *
 * Deliberately contains no sample content. Spec 26.1: "Data quality over
 * quantity" — a demo project seeded into a production database is
 * indistinguishable from real data three months later, and the market
 * intelligence surface would then be quoting a figure nobody entered.
 * Demonstration data belongs in a separate, explicitly-named seeder.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
            RoleSeeder::class,
            PlaceCategorySeeder::class,
            SiteSettingSeeder::class,
            FeatureFlagSeeder::class,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * The shape a migration file's anonymous class actually has.
 *
 * `Illuminate\Database\Migrations\Migration` declares NEITHER `up()` nor
 * `down()` — the migrator calls them dynamically — so a test that loads a
 * migration file and runs it is, as far as static analysis is concerned,
 * calling methods that do not exist. Intersecting the base class with this
 * interface describes the object honestly instead of silencing the warning.
 */
interface RunnableMigration
{
    public function up(): void;

    public function down(): void;
}

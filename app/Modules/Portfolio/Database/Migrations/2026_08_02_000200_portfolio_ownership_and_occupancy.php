<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Portfolio completion (spec §11): the two ownership facts the original
 * table did not model. Additive and engine-neutral — plain columns, no
 * dialect-specific SQL, safe on a populated MariaDB, MySQL 8 or SQLite.
 *
 * `ownership_percent` is a DECIMAL(5,2), so 100.00 fits and 33.33 survives
 * without float drift. `occupancy_status` is a short string vocabulary
 * validated at the controller (owner_occupied / rented / vacant) rather than
 * an enum column, matching how property_type and unit_type are already
 * stored on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_properties', function (Blueprint $table): void {
            $table->decimal('ownership_percent', 5, 2)->nullable()->after('purchase_date');
            $table->string('occupancy_status', 24)->nullable()->after('ownership_percent');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_properties', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's
            // own additions only; no pre-existing column or data is touched.
            $table->dropColumn(['ownership_percent', 'occupancy_status']);
        });
    }
};

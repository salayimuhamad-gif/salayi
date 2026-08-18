<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wizard completion: concurrency, pricing, provenance (spec 12.1, 15.2).
 *
 * THREE ADDITIONS, ALL ADDITIVE.
 *
 * 1. DRAFT CONCURRENCY. `submitted_at` and `version` make submission
 *    idempotent and saves optimistically locked. Without them a double-click
 *    creates two projects, and two tabs on the same draft silently overwrite
 *    each other with no conflict signal.
 *
 * 2. PROJECT PRICING. A dedicated table rather than `price_records`.
 *    `price_records` is market-observation schema: it requires scope_type,
 *    property_type, transaction_type, effective_date and period because every
 *    row feeds an index whose confidence depends on that provenance. A
 *    developer's advertised price range for their own project is not an
 *    observation of the market — writing it there would inject asking prices
 *    into indices as though they were sampled data, which is precisely the
 *    asking/verified contamination §15 exists to prevent. Kept separate, it
 *    can be shown on a project page and deliberately excluded from indices.
 *
 * 3. AREA PROVENANCE ON PROJECTS. The columns already exist from
 *    `add_area_assignment_provenance`; this adds the "unresolved" case, so a
 *    project whose coordinates fall in no published area is visibly awaiting
 *    review rather than silently area-less.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_drafts', function (Blueprint $table): void {
            // Every column guarded individually: a partially-applied run may
            // have added some and not others, and re-running must complete the
            // job rather than fail on the first one that already exists.
            if (! Schema::hasColumn('project_drafts', 'submitted_at')) {
                // Set once, atomically, inside the submission transaction.
                $table->timestamp('submitted_at')->nullable()->after('last_touched_at');
            }

            /*
             * Optimistic lock. Incremented on every successful save; a save
             * carrying a stale version is rejected rather than applied. Two
             * tabs on one draft is not exotic — it is what happens when
             * somebody opens the wizard on a phone and a laptop.
             */
            if (! Schema::hasColumn('project_drafts', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('submitted_at');
            }

            /*
             * The company the author is ACTING FOR, chosen explicitly.
             * `company_id` records the scope; this records the deliberate
             * choice behind it, which matters for a user who belongs to more
             * than one company.
             */
            if (! Schema::hasColumn('project_drafts', 'acting_company_id')) {
                $table->foreignId('acting_company_id')->nullable()->after('company_id')
                    ->constrained('companies')->nullOnDelete();
            }
        });

        Schema::table('projects', function (Blueprint $table): void {
            if (! Schema::hasColumn('projects', 'area_unresolved')) {
                $table->boolean('area_unresolved')->default(false)->after('area_match_type');
            }
        });

        if (Schema::hasTable('project_prices')) {
            return;
        }

        Schema::create('project_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->decimal('price_from', 18, 2)->nullable();
            $table->decimal('price_to', 18, 2)->nullable();
            $table->string('currency', 3);

            /*
             * §15.1. The asking/verified distinction is a column, not a note.
             * A developer's advertised range is an ASKING price; recording it
             * without saying so makes it indistinguishable from a transaction.
             */
            $table->string('price_type', 32)->index();

            $table->string('period', 16)->nullable();
            $table->date('effective_date')->nullable();

            // Where the figure came from and how much to trust it. A price
            // with no provenance is a number nobody can check.
            $table->string('source', 64)->nullable();
            $table->string('confidence', 16)->default('medium');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['project_id', 'price_type']);
        });

    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            // `created_by` is NOT dropped: it belongs to create_projects_tables
            // and predates this migration. Dropping it here would destroy
            // creator provenance for every project ever entered, including
            // those created before the wizard existed.
            if (Schema::hasColumn('projects', 'area_unresolved')) {
                // MIGRATION-GUARD: intentional-drop — reversing this migration's
                // own additive column.
                $table->dropColumn('area_unresolved');
            }
        });

        // MIGRATION-GUARD: intentional-drop — reversing this migration's own
        // table. Project pricing entered through the wizard is removed with it;
        // no market index reads this table, so no index is affected.
        Schema::dropIfExists('project_prices');

        // Guarded in both directions: a rollback interrupted halfway must be
        // repeatable too, and dropping a column that is already gone aborts
        // the whole reversal.
        Schema::table('project_drafts', function (Blueprint $table): void {
            if (Schema::hasColumn('project_drafts', 'acting_company_id')) {
                $table->dropConstrainedForeignId('acting_company_id');
            }

            foreach (['submitted_at', 'version'] as $column) {
                if (Schema::hasColumn('project_drafts', $column)) {
                    // MIGRATION-GUARD: intentional-drop — reversing this
                    // migration's own additive column.
                    $table->dropColumn($column);
                }
            }
        });
    }
};

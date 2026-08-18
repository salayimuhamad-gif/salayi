<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Market data schema (spec 14, 15).
 *
 * The central structural decision: `price_type` is part of every index and
 * every aggregate's identity, never merely an attribute. Spec 14.1 requires
 * that official snapshots and asking prices never mix, and the cheapest way to
 * guarantee that is to make a mixed aggregate impossible to represent — an
 * index row declares one price_type, so there is no row shape that could hold
 * a blended figure even if application code tried.
 *
 * All money is DECIMAL(18,4). Never DOUBLE. Spec 26.1.
 *
 * TABLE ORDER MATTERS. `price_records` carries a foreign key to
 * `market_import_batches`, so the batches table is created first. An earlier
 * draft had them the other way round, which would have failed at migrate time
 * with a foreign-key error — a class of bug that cannot be caught by linting,
 * so scripts/migration-guard.php now checks declaration order explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->string('kind', 32)->default('price');
            $table->string('template_version', 16)->nullable();
            $table->string('original_filename')->nullable();
            $table->string('checksum', 64)->nullable();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);

            // Spec 14.3 "support partial acceptance" and "support rollback
            // before publication". Rollback is only offered while the batch is
            // unpublished; once published, a correction is a new batch with an
            // audit trail rather than a silent erasure.
            $table->string('status', 24)->default('previewing')->index();
            $table->json('column_mapping')->nullable();
            $table->json('report')->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('price_records', function (Blueprint $table): void {
            $table->id();
            $table->string('record_id', 64)->nullable()->unique();

            // Polymorphic scope, resolved through the enforced morph map.
            $table->string('scope_type', 24)->index();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_external_id', 64)->nullable();

            $table->string('property_type', 32)->index();
            $table->string('unit_type', 64)->nullable();
            $table->string('transaction_type', 16)->index();

            // The separation axis (spec 14.1). Indexed with effective_date
            // because every query is "this type, this period".
            $table->string('price_type', 32)->index();

            $table->string('currency', 3);
            $table->decimal('price', 18, 4)->nullable();
            $table->decimal('price_per_sqm', 18, 4)->nullable();
            $table->decimal('minimum', 18, 4)->nullable();
            $table->decimal('maximum', 18, 4)->nullable();
            $table->decimal('median', 18, 4)->nullable();
            $table->unsignedInteger('sample_size')->nullable();
            $table->unsignedInteger('source_count')->nullable();

            $table->date('effective_date')->index();
            $table->date('published_date')->nullable();
            $table->string('period', 16)->index();

            $table->string('source', 191)->nullable();
            $table->string('source_url')->nullable();
            $table->string('confidence', 16)->default('medium');
            $table->text('notes')->nullable();

            // Outlier state is stored, not recomputed on read, so a published
            // figure never silently changes when the detector is retuned.
            $table->boolean('is_outlier')->default(false);
            $table->string('outlier_reason', 64)->nullable();

            $table->foreignId('import_batch_id')->nullable()->constrained('market_import_batches')->nullOnDelete();
            $table->string('publication_status', 24)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_type', 'scope_id', 'price_type', 'effective_date'], 'price_records_scope_type_date');
            $table->index(['price_type', 'period']);
            // Spec 14.3 "identify duplicate periods": one figure per scope,
            // property type, price type and period. A second row for the same
            // slot is a duplicate by definition, not a second opinion.
            $table->unique(
                ['scope_type', 'scope_id', 'property_type', 'unit_type', 'price_type', 'period'],
                'price_records_period_slot',
            );
        });

        Schema::create('market_indices', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name_ckb');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();

            $table->string('scope_type', 24);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('property_type', 32)->nullable();

            // An index declares ONE price type. This is what makes a blended
            // index unrepresentable rather than merely discouraged.
            $table->string('price_type', 32)->index();

            $table->string('basis', 24)->default('median');
            $table->string('currency', 3)->default('USD');
            $table->string('methodology_version', 16)->default('v1');
            $table->text('methodology_ckb')->nullable();
            $table->text('methodology_ar')->nullable();
            $table->text('methodology_en')->nullable();
            $table->json('data_source_rules')->nullable();
            $table->json('weights')->nullable();
            $table->unsignedInteger('minimum_sample')->default(5);
            $table->json('outlier_rules')->nullable();
            $table->json('confidence_rules')->nullable();
            $table->string('publication_status', 24)->default('draft')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('market_index_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_index_id')->constrained('market_indices')->cascadeOnDelete();
            $table->string('period', 16);
            $table->date('effective_date');

            $table->decimal('value', 18, 4)->nullable();
            $table->decimal('median', 18, 4)->nullable();
            $table->decimal('minimum', 18, 4)->nullable();
            $table->decimal('maximum', 18, 4)->nullable();
            $table->decimal('change_percent', 8, 4)->nullable();

            // Spec 15.3: every public index value carries all of these.
            // Columns rather than a JSON blob so a missing one is a schema
            // error rather than a quietly absent key on a public page.
            $table->unsignedInteger('sample_size')->default(0);
            $table->unsignedInteger('excluded_outliers')->default(0);
            $table->json('contributing_sources')->nullable();
            $table->string('confidence', 16)->default('low');
            $table->boolean('is_limited')->default(false);
            $table->string('warning', 64)->nullable();
            $table->string('methodology_version', 16);
            $table->string('revision_status', 24)->default('initial');
            $table->unsignedSmallInteger('revision_number')->default(0);

            $table->string('publication_status', 24)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['market_index_id', 'period', 'revision_number'], 'index_values_period_revision');
            $table->index(['market_index_id', 'effective_date']);
        });

        Schema::create('market_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_import_batch_id')->constrained('market_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->json('normalised')->nullable();
            // Spec 14.3 "show errors by row".
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('price_record_id')->nullable()->constrained('price_records')->nullOnDelete();
            $table->timestamps();

            $table->unique(['market_import_batch_id', 'row_number']);
        });
    }

    /**
     * Dropped CHILD-FIRST, because foreign keys are real on MySQL.
     *
     * `price_records` holds `price_records_import_batch_id_foreign` pointing at
     * `market_import_batches`, but the batches table was dropped first — so the
     * rollback died with
     *
     *   SQLSTATE[HY000] 3730 Cannot drop table 'market_import_batches'
     *   referenced by a foreign key constraint ... on table 'price_records'
     *
     * and every later migration in the batch went unreversed. SQLite does not
     * enforce this, which is why the order looked fine for as long as SQLite
     * was the only engine anybody ran a rollback on.
     *
     * The order below is strictly dependants before dependencies.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_import_rows');
        Schema::dropIfExists('price_records');
        Schema::dropIfExists('market_index_values');
        Schema::dropIfExists('market_import_batches');
        Schema::dropIfExists('market_indices');
    }
};

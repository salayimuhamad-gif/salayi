<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Geography schema (spec 10, 11).
 *
 * DUAL-ENGINE STRATEGY. The CI matrix runs MySQL 8 and SQLite, and SQLite has
 * no spatial types at all. Rather than skip the tests on SQLite — which would
 * mean the geometry code is only ever exercised on one engine — every table
 * stores geometry three ways:
 *
 *   latitude / longitude   DECIMAL(10,7). Present on BOTH engines. This is what
 *                          the bounding-box pre-filter queries, so the hot path
 *                          is a plain indexed range scan everywhere.
 *   boundary_wkt           TEXT. Present on both. The portable source of truth,
 *                          parsed by App\Modules\Geography\Support\Wkt.
 *   boundary               GEOMETRY. MySQL only, added conditionally below.
 *                          A derived index, never the source of truth.
 *
 * DECIMAL rather than DOUBLE for the coordinate columns: a coordinate is an
 * identifier as much as a measurement, and two rows describing the same point
 * must compare equal. DECIMAL(10,7) holds the full +/-180.0000000 range at the
 * 1.1 cm precision Coordinates enforces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table): void {
            $table->id();

            // Materialised path. Storing the ancestry inline means "every
            // project in this district, including its sectors and
            // neighbourhoods" is one LIKE 'path%' index scan rather than a
            // recursive CTE — which matters because MySQL 5.7-era shared
            // hosting cannot be assumed to support recursion well.
            $table->foreignId('parent_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->string('path', 255)->index();
            $table->unsignedTinyInteger('depth')->default(0);

            $table->string('type', 24)->index();
            $table->string('slug', 191)->unique();
            $table->string('external_id', 64)->nullable()->unique();

            // Trilingual, ckb authored first (spec 7.1).
            $table->string('name_ckb');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->json('aliases')->nullable();
            // Derived from name_ckb by SoraniText::searchKey on save.
            $table->string('search_key', 191)->nullable()->index();

            $table->text('description_ckb')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->longText('boundary_wkt')->nullable();
            // Cached from the boundary so map fitting needs no geometry parse.
            $table->decimal('bbox_min_lat', 10, 7)->nullable();
            $table->decimal('bbox_min_lng', 10, 7)->nullable();
            $table->decimal('bbox_max_lat', 10, 7)->nullable();
            $table->decimal('bbox_max_lng', 10, 7)->nullable();
            $table->unsignedBigInteger('area_sqm')->nullable();

            $table->string('lifestyle_classification', 48)->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->string('publication_status', 24)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('source', 191)->nullable();
            $table->string('confidence', 16)->default('medium');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'publication_status']);
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('place_categories', function (Blueprint $table): void {
            $table->id();
            // Spec 10.4: "Admin can add categories and icons without code",
            // so this table is authoritative and PlaceCategoryKey only
            // guarantees stable keys for the shipped set.
            $table->string('key', 64)->unique();
            $table->foreignId('parent_id')->nullable()->constrained('place_categories')->nullOnDelete();
            $table->string('group', 32)->index();
            $table->string('name_ckb');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('colour', 16)->nullable();
            $table->unsignedInteger('default_radius_m')->default(3000);
            $table->decimal('amenity_weight', 3, 2)->default(0.50);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('places', function (Blueprint $table): void {
            $table->id();
            $table->string('external_id', 64)->nullable()->unique();
            $table->foreignId('place_category_id')->constrained('place_categories')->restrictOnDelete();
            $table->string('subcategory', 64)->nullable();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();

            $table->string('name_ckb');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->json('aliases')->nullable();
            $table->string('search_key', 191)->nullable()->index();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->string('address_ckb')->nullable();
            $table->string('address_ar')->nullable();
            $table->string('address_en')->nullable();
            // Encrypted at rest: a place phone is often a personal mobile.
            $table->text('phone_encrypted')->nullable();
            $table->string('website')->nullable();
            $table->json('opening_hours')->nullable();
            $table->text('accessibility_notes')->nullable();

            $table->string('operational_status', 24)->default('operating')->index();
            $table->boolean('is_public')->default(true);
            $table->json('tags')->nullable();

            // Provenance. Spec 5: every public fact carries source and freshness.
            $table->string('source', 191)->nullable();
            $table->string('source_url')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('confidence', 16)->default('medium');

            // Spec 11.4 quality dimensions, each stored so the admin can see
            // WHICH dimension is weak rather than only a single number.
            $table->unsignedTinyInteger('completeness_score')->nullable();
            $table->unsignedTinyInteger('source_quality_score')->nullable();
            $table->unsignedTinyInteger('location_accuracy_score')->nullable();
            $table->unsignedTinyInteger('freshness_score')->nullable();
            $table->unsignedTinyInteger('translation_score')->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable()->index();
            $table->string('verification_status', 24)->default('unverified')->index();

            // Duplicate handling: spec 11.3 asks the import assistant to
            // "identify duplicates", and 11.2 has a duplicate_group field.
            // Grouping rather than deleting keeps both source rows auditable.
            $table->string('duplicate_group', 64)->nullable()->index();
            $table->boolean('is_duplicate_primary')->default(true);

            $table->string('publication_status', 24)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The bounding-box pre-filter index. Composite and in this order
            // because latitude is always the more selective of the two for a
            // city-scale dataset.
            $table->index(['latitude', 'longitude'], 'places_bbox_index');
            $table->index(['place_category_id', 'publication_status']);
        });

        $this->addSpatialColumns();
    }

    /**
     * MySQL-only spatial columns and indexes.
     *
     * Added separately and guarded, because SQLite has no GEOMETRY type and
     * would abort the whole migration. These are a query optimisation layered
     * over the portable WKT, never the source of truth — which is what lets
     * the same geometry code run identically on both engines.
     */
    private function addSpatialColumns(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        // SRID 4326 is WGS-84. Declaring it explicitly is required for
        // ST_Distance_Sphere to return metres rather than degrees.
        DB::statement('ALTER TABLE areas ADD COLUMN boundary GEOMETRY REF_SYSTEM_ID=4326 NULL');
        DB::statement('ALTER TABLE areas ADD COLUMN centre_point POINT REF_SYSTEM_ID=4326 NULL');
        DB::statement('ALTER TABLE places ADD COLUMN location POINT REF_SYSTEM_ID=4326 NULL');

        // A spatial index requires the column to be NOT NULL, so these are
        // created only once the columns are populated by a later backfill.
        // Documented here rather than silently omitted — see ROADMAP_STATUS.
    }

    private function isMySql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
        Schema::dropIfExists('place_categories');
        Schema::dropIfExists('areas');
    }
};

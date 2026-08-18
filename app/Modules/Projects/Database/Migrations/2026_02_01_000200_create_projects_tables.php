<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projects schema (spec 12, 13).
 *
 * Same dual-engine strategy as the geography tables: DECIMAL coordinates and
 * WKT text on both engines, a MySQL GEOMETRY column as a derived index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developers', function (Blueprint $table): void {
            $table->id();
            $table->string('external_id', 64)->nullable()->unique();
            $table->string('slug', 191)->unique();
            $table->string('name_ckb');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('search_key', 191)->nullable()->index();
            $table->text('description_ckb')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->unsignedSmallInteger('founded_year')->nullable();
            $table->string('country', 2)->nullable();
            // A developer's reputation rating lives in project_ratings, scoped
            // to the developer via the polymorphic subject, so the provenance
            // separation rules apply identically.
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('source', 191)->nullable();
            $table->string('publication_status', 24)->default('draft')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('external_id', 64)->nullable()->unique();
            $table->string('slug', 191)->unique();

            $table->foreignId('developer_id')->nullable()->constrained('developers')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();

            $table->string('name_ckb');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->json('aliases')->nullable();
            $table->string('search_key', 191)->nullable()->index();

            $table->text('description_ckb')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            $table->string('project_type', 32)->index();
            $table->string('construction_status', 32)->index();
            $table->string('delivery_status', 32)->index();
            $table->unsignedTinyInteger('completion_percent')->nullable();

            // Spec 37.2: "Polygon or coordinate is required." Both columns are
            // nullable at the schema level because a draft is saved
            // incrementally through a 14-step wizard; the constraint is
            // enforced at the publish transition, not at every autosave.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->longText('boundary_wkt')->nullable();
            $table->decimal('bbox_min_lat', 10, 7)->nullable();
            $table->decimal('bbox_min_lng', 10, 7)->nullable();
            $table->decimal('bbox_max_lat', 10, 7)->nullable();
            $table->decimal('bbox_max_lng', 10, 7)->nullable();

            $table->date('launch_date')->nullable();
            $table->date('expected_delivery')->nullable();
            $table->date('actual_delivery')->nullable();

            $table->unsignedBigInteger('land_area_sqm')->nullable();
            $table->unsignedInteger('building_count')->nullable();
            $table->unsignedInteger('unit_count')->nullable();

            $table->json('facilities')->nullable();
            $table->json('services')->nullable();
            $table->json('ownership_rules')->nullable();
            $table->json('payment_plans')->nullable();
            // Money as DECIMAL, never float (spec 26.1).
            $table->decimal('maintenance_fee_monthly', 18, 4)->nullable();
            $table->string('maintenance_fee_currency', 3)->nullable();

            $table->string('official_url')->nullable();
            $table->json('video_urls')->nullable();
            $table->json('virtual_tour_urls')->nullable();

            // Derived scores, recomputed rather than entered.
            $table->unsignedTinyInteger('quality_score')->nullable()->index();
            $table->unsignedTinyInteger('completeness_score')->nullable();
            $table->unsignedTinyInteger('investment_score')->nullable();
            $table->unsignedTinyInteger('family_suitability_score')->nullable();
            $table->unsignedTinyInteger('nearby_amenity_score')->nullable();
            $table->string('demand_level', 16)->nullable();

            // Provenance (spec 5, 37.2 "public profile displays evidence and dates").
            $table->string('source', 191)->nullable();
            $table->json('data_sources')->nullable();
            $table->string('confidence', 16)->default('medium');
            $table->timestamp('verified_at')->nullable();

            $table->string('publication_status', 24)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            // Resumable wizard state, mirroring the installer's approach.
            $table->string('wizard_step', 32)->nullable();
            $table->json('wizard_state')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['latitude', 'longitude'], 'projects_bbox_index');
            $table->index(['publication_status', 'project_type']);
            $table->index(['area_id', 'publication_status']);
        });

        Schema::create('project_phases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name_ckb');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('construction_status', 32);
            $table->string('delivery_status', 32);
            $table->unsignedTinyInteger('completion_percent')->nullable();
            $table->date('expected_delivery')->nullable();
            $table->date('actual_delivery')->nullable();
            $table->unsignedInteger('unit_count')->nullable();
            $table->longText('boundary_wkt')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'sequence']);
        });

        Schema::create('project_unit_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('project_phase_id')->nullable()->constrained('project_phases')->nullOnDelete();
            $table->string('name_ckb');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('category', 32);
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->decimal('area_sqm_min', 10, 2)->nullable();
            $table->decimal('area_sqm_max', 10, 2)->nullable();
            $table->unsignedInteger('unit_count')->nullable();
            $table->json('floor_plan_paths')->nullable();
            $table->timestamps();
        });

        Schema::create('project_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('kind', 24)->index();
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 96)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum', 64)->nullable();
            // Alt text per language: an image with no alt text is invisible to
            // a screen reader in all three languages (spec 31.3).
            $table->string('alt_ckb')->nullable();
            $table->string('alt_ar')->nullable();
            $table->string('alt_en')->nullable();
            $table->string('credit')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'kind', 'sort_order']);
        });

        Schema::create('project_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('title_ckb');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            // Private disk by default: a project document may contain a
            // developer's commercial terms (spec 26.1).
            $table->string('disk', 32)->default('private');
            $table->string('path');
            $table->string('mime_type', 96)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->boolean('is_public')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('project_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('category', 48)->index();
            // Provenance. Spec 13.2: "These types must remain separate."
            // Part of the unique key so an expert rating and a public rating of
            // the same category coexist rather than overwrite one another.
            $table->string('type', 32)->index();
            $table->decimal('value', 3, 2);
            $table->unsignedInteger('sample_size')->default(1);
            $table->string('confidence', 16)->default('medium');
            $table->text('reason')->nullable();
            $table->string('source', 191)->nullable();
            $table->date('effective_date')->nullable();
            $table->string('review_status', 24)->default('draft')->index();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'category', 'type']);
        });

        Schema::create('project_rating_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_rating_id')->constrained('project_ratings')->cascadeOnDelete();
            // Spec 13.3: every change stores previous, new, date, author,
            // source, reason, confidence and review status. Append-only.
            $table->decimal('previous_value', 3, 2)->nullable();
            $table->decimal('new_value', 3, 2);
            $table->date('effective_date');
            $table->string('author_label')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 191)->nullable();
            $table->text('reason')->nullable();
            $table->string('confidence', 16)->default('medium');
            $table->string('review_status', 24)->default('draft');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_rating_id', 'effective_date']);
        });

        Schema::create('project_nearby_places', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();

            // Spec 10.5 step 4: "Store relationship snapshot." The distance is
            // frozen at calculation time so a moved place does not silently
            // rewrite history; step 7 then flags the row stale.
            $table->unsignedInteger('straight_line_m');
            $table->unsignedInteger('travel_distance_m')->nullable();
            $table->unsignedInteger('travel_time_s')->nullable();
            $table->string('travel_provider', 32)->nullable();
            $table->string('compass_point', 2)->nullable();

            $table->unsignedSmallInteger('rank')->nullable();
            $table->decimal('relevance', 5, 4)->nullable();

            // Spec 10.5 step 6: "Allow manual override."
            $table->boolean('is_manual')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->text('override_reason')->nullable();

            // Spec 10.5 step 7: "Flag stale calculations."
            $table->boolean('is_stale')->default(false);
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'place_id']);
            $table->index(['project_id', 'rank']);
            $table->index(['project_id', 'is_stale']);
        });

        $this->addSpatialColumns();
    }

    private function addSpatialColumns(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE projects ADD COLUMN location POINT REF_SYSTEM_ID=4326 NULL');
        DB::statement('ALTER TABLE projects ADD COLUMN boundary GEOMETRY REF_SYSTEM_ID=4326 NULL');
        DB::statement('ALTER TABLE project_phases ADD COLUMN boundary GEOMETRY REF_SYSTEM_ID=4326 NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_nearby_places');
        Schema::dropIfExists('project_rating_history');
        Schema::dropIfExists('project_ratings');
        Schema::dropIfExists('project_documents');
        Schema::dropIfExists('project_media');
        Schema::dropIfExists('project_unit_types');
        Schema::dropIfExists('project_phases');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('developers');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portfolio and valuation (spec 20).
 *
 * "Ownership remains private by default" (spec 20.3) is the design constraint.
 * A portfolio row is the most sensitive record in the product: it says a named
 * person owns a specific property and what they paid. So the private label and
 * notes are encrypted at rest, coordinates are optional with a stated
 * precision, and there is no publication_status column at all — there is no
 * state in which a portfolio property becomes public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_properties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // The owner's private name for it ("the Ankawa flat"). Encrypted:
            // it frequently identifies the property to anyone who knows them.
            $table->text('label_encrypted');
            $table->text('notes_encrypted')->nullable();

            $table->string('property_type', 32);
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('project_phase_id')->nullable()->constrained('project_phases')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->string('unit_type', 64)->nullable();

            $table->decimal('size_sqm', 10, 2)->nullable();
            $table->unsignedTinyInteger('rooms')->nullable();
            $table->smallInteger('floor')->nullable();
            $table->string('orientation', 16)->nullable();

            $table->decimal('purchase_price', 18, 4)->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('currency', 3)->default('USD');

            // Optional GPS with a stated precision (spec 20.1). An owner who
            // does not want their exact home coordinates stored can still get
            // an area-level valuation.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_precision', 16)->default('area_only');

            $table->json('alert_preferences')->nullable();
            $table->boolean('consent_valuation')->default(false);
            $table->boolean('consent_contact')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'deleted_at']);
        });

        Schema::create('portfolio_valuations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_property_id')->constrained('portfolio_properties')->cascadeOnDelete();

            // Spec 20.2 result fields. Columns, not a JSON blob, because a
            // missing match level or excluded-asking note would otherwise be a
            // quietly absent key behind a figure an owner acts on.
            $table->decimal('midpoint', 18, 4)->nullable();
            $table->decimal('low', 18, 4)->nullable();
            $table->decimal('high', 18, 4)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('confidence', 16)->default('insufficient');
            $table->unsignedTinyInteger('match_level')->nullable();
            $table->string('match_label', 48)->default('none');
            $table->string('methodology', 32);
            $table->unsignedInteger('comparison_count')->default(0);
            $table->date('data_period_from')->nullable();
            $table->date('data_period_to')->nullable();
            $table->unsignedInteger('excluded_asking_count')->default(0);
            $table->string('excluded_asking_note', 64);
            $table->string('no_valuation_reason', 64)->nullable();

            // Spec 36 Step 6 exit criterion: "valuation history is preserved."
            // Rows are appended, never updated, so an owner can see how the
            // estimate moved and why.
            $table->timestamp('calculated_at')->useCurrent();

            $table->index(['portfolio_property_id', 'calculated_at']);
        });

        Schema::create('saved_searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->json('criteria');
            $table->string('scope', 24)->default('offers');
            $table->boolean('alerts_enabled')->default(false);
            $table->string('alert_frequency', 16)->default('daily');
            $table->timestamp('last_matched_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'alerts_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('portfolio_valuations');
        Schema::dropIfExists('portfolio_properties');
    }
};

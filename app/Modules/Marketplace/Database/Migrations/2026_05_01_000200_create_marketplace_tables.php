<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace offers and advertising (spec 19, 18.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('company_branch_id')->nullable()->constrained('company_branches')->nullOnDelete();
            $table->foreignId('agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Spec 19.1 allows owner-published offers, which have no company.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();

            $table->string('title_ckb');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->string('search_key', 191)->nullable()->index();
            $table->text('description_ckb')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            $table->string('offer_type', 24)->index();
            $table->string('property_type', 32)->index();
            $table->string('unit_type', 64)->nullable();

            // "Approximate location" is a first-class option: an owner listing
            // their occupied home should not have to publish its exact
            // coordinates to appear on the map (spec 19.4).
            $table->string('location_precision', 16)->default('approximate');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->decimal('size_sqm', 10, 2)->nullable();
            $table->unsignedTinyInteger('rooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->smallInteger('floor')->nullable();
            $table->string('orientation', 16)->nullable();
            $table->string('furnishing', 16)->nullable();

            $table->decimal('price', 18, 4)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->json('payment_plan')->nullable();
            $table->string('availability', 24)->default('available');

            $table->string('contact_method', 24)->default('platform');
            $table->json('verification_evidence')->nullable();
            $table->string('source', 191)->nullable();

            // Spec 19.3 workflow.
            $table->string('status', 24)->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('moderation_notes')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();

            // Sponsorship is stored on the offer but is NOT an input to the
            // organic score. OfferRanker never reads it when scoring.
            $table->boolean('is_sponsored')->default(false);
            $table->string('disclosure_label')->nullable();
            $table->unsignedInteger('sponsor_bid')->default(0);

            $table->unsignedTinyInteger('completeness_score')->nullable();
            $table->unsignedTinyInteger('verification_score')->nullable();
            $table->text('terms')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['project_id', 'status']);
            $table->index(['company_id', 'status']);
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('offer_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->string('kind', 24)->default('image');
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('mime_type', 96)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('alt_ckb')->nullable();
            $table->string('alt_ar')->nullable();
            $table->string('alt_en')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->string('moderation_status', 24)->default('pending')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['offer_id', 'sort_order']);
        });

        Schema::create('offer_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label')->nullable();
            $table->boolean('actor_was_moderator')->default(false);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['offer_id', 'created_at']);
        });

        Schema::create('ad_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('placement', 32)->index();
            $table->foreignId('target_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('target_area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->string('locale', 8)->nullable();

            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('daily_cap')->nullable();
            $table->unsignedInteger('impression_cap')->nullable();
            $table->unsignedInteger('click_cap')->nullable();

            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('invoice_reference', 64)->nullable();

            // Spec 18.4 lists sponsored disclosure as a required campaign
            // field, so it is NOT NULL: a campaign cannot exist undisclosed.
            $table->string('disclosure_label');

            $table->string('status', 24)->default('draft')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['placement', 'status', 'starts_on']);
        });

        Schema::create('ad_creatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->string('locale', 8)->default('ckb');
            $table->string('headline');
            $table->string('body')->nullable();
            $table->string('image_path')->nullable();
            $table->string('click_url');
            $table->string('moderation_status', 24)->default('pending');
            $table->timestamps();
        });

        Schema::create('ad_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('ad_creative_id')->nullable()->constrained('ad_creatives')->nullOnDelete();
            $table->string('event_type', 16)->index();
            $table->string('placement', 32)->nullable();
            // Hashed, never raw: an impression log is not a reason to retain an
            // identifiable browsing record (spec 32.2).
            $table->string('viewer_hash', 64)->nullable();
            $table->string('locale', 8)->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['ad_campaign_id', 'event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_events');
        Schema::dropIfExists('ad_creatives');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('offer_status_history');
        Schema::dropIfExists('offer_media');
        Schema::dropIfExists('offers');
    }
};

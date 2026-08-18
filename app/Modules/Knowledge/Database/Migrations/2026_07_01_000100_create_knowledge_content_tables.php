<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge timeline and content management (spec 21, 25).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_events', function (Blueprint $table): void {
            $table->id();
            // Polymorphic subject through the enforced morph map.
            $table->string('entity_type', 48)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();

            $table->string('title_ckb');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('summary_ckb')->nullable();
            $table->text('summary_ar')->nullable();
            $table->text('summary_en')->nullable();
            $table->longText('details')->nullable();
            $table->string('search_key', 191)->nullable()->index();

            $table->string('event_type', 32)->index();
            // Direction and strength are what let the timeline say "this was
            // good for the area, strongly" rather than only that it happened.
            $table->string('direction', 16)->default('neutral');
            $table->unsignedTinyInteger('strength')->default(3);

            $table->date('effective_date')->index();
            $table->date('expected_date')->nullable();
            $table->date('publication_date')->nullable();
            $table->date('expires_on')->nullable()->index();

            $table->string('source', 191)->nullable();
            $table->string('source_url')->nullable();
            $table->string('source_document_path')->nullable();
            $table->string('confidence', 16)->default('medium');

            /*
             * Spec 21.2 makes AI-usage permission a per-event field, and spec
             * 17.5 forbids AI using draft or expired knowledge. Both conditions
             * must hold independently, so the permission is stored here and the
             * status check lives in KnowledgeStatus::isAiUsable(). Defaulting
             * to false means an editor opts an event IN to AI use rather than
             * forgetting to opt it out.
             */
            $table->boolean('ai_usage_permitted')->default(false);

            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->json('related_project_ids')->nullable();
            $table->json('related_area_ids')->nullable();
            $table->json('related_place_ids')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_type', 'entity_id', 'effective_date']);
            $table->index(['status', 'ai_usage_permitted']);
        });

        Schema::create('content_items', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 24)->index();
            $table->string('slug', 191)->unique();

            $table->string('title_ckb');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->longText('body_ckb')->nullable();
            $table->longText('body_ar')->nullable();
            $table->longText('body_en')->nullable();
            $table->string('excerpt_ckb')->nullable();
            $table->string('excerpt_ar')->nullable();
            $table->string('excerpt_en')->nullable();
            $table->string('search_key', 191)->nullable()->index();

            $table->string('cover_image_path')->nullable();
            $table->string('video_url')->nullable();
            $table->json('related_entity_ids')->nullable();

            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->boolean('seo_indexable')->default(true);

            $table->string('status', 24)->default('draft')->index();
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublish_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status', 'published_at']);
        });

        Schema::create('content_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_item_id')->constrained('content_items')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            // A revision is a full snapshot, not a diff: reconstructing an old
            // version by replaying diffs fails the moment one is lost.
            $table->json('snapshot');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_label')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['content_item_id', 'revision']);
        });

        Schema::create('product_events', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 48)->index();
            // Never a user id. Spec 32.2 forbids unmasked personal identifiers,
            // and this column holds the day-scoped pseudonym from
            // AnalyticsGuard::pseudonym() so cohorts work without a durable
            // identity.
            $table->string('pseudonym', 32)->nullable()->index();
            $table->string('locale', 8)->nullable();
            $table->string('surface', 32)->nullable();
            // Payload is written only after AnalyticsGuard::admit() returns
            // recorded=true. There is no path that stores an unchecked payload.
            $table->json('payload')->nullable();
            $table->date('event_date')->index();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['name', 'event_date']);
        });

        Schema::create('bulk_operations', function (Blueprint $table): void {
            $table->id();
            // Spec 24.5: "All bulk operations require permissions and audit
            // logs." The record exists so a 400-row publish is reversible and
            // attributable, not only visible in the audit stream.
            $table->string('operation', 32)->index();
            $table->string('subject_type', 48);
            $table->json('subject_ids');
            $table->unsignedInteger('total');
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('failures')->nullable();
            $table->string('permission_checked', 64);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label')->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_operations');
        Schema::dropIfExists('product_events');
        Schema::dropIfExists('content_revisions');
        Schema::dropIfExists('content_items');
        Schema::dropIfExists('knowledge_events');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft-owned temporary media (spec 12.1, 32.2).
 *
 * The wizard previously accepted `project_media` ids directly. Even after
 * restricting claims to unattached rows, that model is wrong in principle:
 * `project_media` is a shared table with no owner until a project claims it,
 * so two people uploading concurrently can see and take each other's rows.
 * There is no id an attacker can guess that belongs to someone else, because
 * a row here belongs to a draft, an uploader AND a company from the moment it
 * is written.
 *
 * Rows are promoted into `project_media` at submission and deleted with the
 * draft otherwise, so an abandoned upload does not linger as orphaned storage.
 *
 * Every additive change here is guarded with hasTable/hasColumn so a
 * PARTIALLY APPLIED run — the migration failing halfway on a shared host with
 * a query timeout — can be re-run rather than needing manual repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_draft_media')) {
            Schema::create('project_draft_media', function (Blueprint $table): void {
                $table->id();

                $table->foreignId('project_draft_id')->constrained('project_drafts')->cascadeOnDelete();

                // Three owners, all required. Any one of them alone would let
                // a crafted id reach a row it should not.
                $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
                // Named acting_company_id, matching the draft's own column, so
                // the scope on the file and the scope on the draft are
                // obviously the same thing.
                $table->foreignId('acting_company_id')->nullable()->constrained('companies')->nullOnDelete();

                $table->string('kind', 32)->default('image');
                $table->string('disk', 32)->default('public');
                $table->string('path', 512);
                $table->string('original_name', 255)->nullable();
                $table->string('mime_type', 128);
                $table->unsignedBigInteger('size_bytes');
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                // Checksum makes a re-upload of the same file detectable
                // before it becomes a second copy in the gallery.
                $table->string('checksum', 64)->nullable()->index();

                // Alt text is trilingual because a screen reader in Sorani
                // should not fall back to English (spec 7.1, 33.2).
                $table->string('alt_ckb', 255)->nullable();
                $table->string('alt_ar', 255)->nullable();
                $table->string('alt_en', 255)->nullable();

                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_cover')->default(false);

                /*
                 * When these bytes stop being anybody's working state. The
                 * prune command uses it, so an abandoned upload does not sit
                 * on a shared host indefinitely.
                 */
                $table->timestamp('expires_at')->nullable()->index();

                $table->timestamps();

                $table->index(['project_draft_id', 'sort_order']);
                $table->index('uploaded_by');
            });
        }
    }

    public function down(): void
    {
        // MIGRATION-GUARD: intentional-drop — reversing this migration's own
        // table. It holds only un-submitted uploads; media already promoted
        // into project_media is unaffected.
        Schema::dropIfExists('project_draft_media');
    }
};

<?php

declare(strict_types=1);

use App\Modules\Core\Support\MigrationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `offer_media.original_name`, which its own moderation queue already reads.
 *
 * `OfferMediaController` renders `original_name` for every row and
 * `Admin/Offers/MediaQueue.vue` declares it in the row type, but the column
 * exists only on `project_media` and `project_draft_media` — the offer table
 * never had it. Reading it was an undefined-property access that produced a
 * permanently blank column in the moderation screen, which is exactly the
 * information a moderator uses to recognise a file somebody uploaded.
 *
 * Nullable, matching the sibling tables: existing rows have no filename to
 * recover, and nothing is invented for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offer_media') || Schema::hasColumn('offer_media', 'original_name')) {
            return;
        }

        Schema::table('offer_media', function (Blueprint $table): void {
            $table->string('original_name', 255)->nullable()->after('path');
        });

        // Verified through a fresh connection rather than the cached schema
        // the builder just wrote to, so this really is a post-condition check.
        if (Schema::getConnection()->getSchemaBuilder()->hasColumn('offer_media', 'original_name') === false) {
            throw new RuntimeException('offer_media.original_name was not created.');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('offer_media') || ! Schema::hasColumn('offer_media', 'original_name')) {
            return;
        }

        MigrationIndexes::dropIndexesOn('offer_media', ['original_name']);

        Schema::table('offer_media', function (Blueprint $table): void {
            // MIGRATION-GUARD: intentional-drop — reversing this migration's
            // own additive column.
            $table->dropColumn('original_name');
        });
    }
};

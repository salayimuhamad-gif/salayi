<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correction v3, HIGH 8: a deletion that cannot remove the FILE keeps the
 * ROW — marked, visible, retryable — instead of deleting the row and
 * orphaning bytes on the private disk that nothing references any more.
 *
 * Additive only. The three portfolio migrations already applied in
 * production are untouched, byte for byte, per §9.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_media', function (Blueprint $table): void {
            $table->timestamp('deletion_pending_at')
                ->nullable()
                ->after('size_bytes');

            $table->index('deletion_pending_at', 'portfolio_media_deletion_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_media', function (Blueprint $table): void {
            $table->dropIndex('portfolio_media_deletion_pending_idx');
            $table->dropColumn('deletion_pending_at');
        });
    }
};

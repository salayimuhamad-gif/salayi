<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correction v3, CRITICAL 2 — the webhook table becomes an INBOX with an
 * explicit lifecycle instead of a bare replay ledger.
 *
 * The old shape recorded only "an update with this id arrived once", which
 * made two very different situations indistinguishable: an update Telegram
 * redelivered after we finished it, and an update we CLAIMED but then
 * failed to process. The second kind was permanently dropped. These
 * columns give every event a state — processing, completed, failed — so a
 * failed or stuck event is retryable while a completed one stays refused.
 *
 * Additive only. Existing rows predate the lifecycle and were all fully
 * handled under the old semantics (a row only ever existed after its
 * processing), so the column default marks them `completed` at ALTER time
 * and the backfill gives them a completion moment equal to their receipt.
 * New code always writes `status` explicitly; the default exists for the
 * pre-existing rows alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_webhook_events', function (Blueprint $table): void {
            $table->string('status', 12)->default('completed')->after('kind');
            $table->unsignedSmallInteger('attempts')->default(1)->after('status');
            $table->timestamp('processing_at')->nullable()->after('attempts');
            $table->timestamp('completed_at')->nullable()->after('processing_at');
            // Safe metadata only — an exception class name, never payload,
            // never tokens, never contact data.
            $table->string('last_error', 500)->nullable()->after('completed_at');

            $table->index(['status', 'processing_at'], 'telegram_webhook_events_status_index');
        });

        // The pre-lifecycle rows: handled when they were received.
        DB::table('telegram_webhook_events')
            ->whereNull('completed_at')
            ->update([
                'completed_at' => DB::raw('received_at'),
                'processing_at' => DB::raw('received_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('telegram_webhook_events', function (Blueprint $table): void {
            $table->dropIndex('telegram_webhook_events_status_index');
            $table->dropColumn(['status', 'attempts', 'processing_at', 'completed_at', 'last_error']);
        });
    }
};

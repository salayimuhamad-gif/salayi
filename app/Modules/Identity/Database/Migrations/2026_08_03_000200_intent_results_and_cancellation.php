<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections v3, CRITICAL 1 and HIGH 5 — intents get a visible outcome.
 *
 * The browser poll used to see only "not completed yet", which left a
 * person staring at a pending screen while the bot already knew their
 * redemption had collided or their intent was dead. Two additive columns
 * change that:
 *
 *   result       — a terminal outcome the poll cannot infer from the
 *                  existing columns: `conflict`, `failed`, `cancelled`.
 *                  (`completed` and `expired` stay inferable from
 *                  consumed_at/expires_at and are not duplicated here,
 *                  except where a redemption writes them for clarity.)
 *   cancelled_at — the person clicked cancel; the intent is dead by
 *                  their own hand and a new one can be minted.
 *
 * Both nullable, both additive, no existing row changes meaning: a null
 * result on an old intent reads exactly as it always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_login_intents', function (Blueprint $table): void {
            $table->string('result', 20)->nullable()->after('consumed_at');
            $table->timestamp('cancelled_at')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_login_intents', function (Blueprint $table): void {
            $table->dropColumn(['result', 'cancelled_at']);
        });
    }
};

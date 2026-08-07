<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections v4, BLOCKER 1 and §5 — a resumable account link, and a
 * browser confirmation step between Telegram Start and the actual link.
 *
 * BLOCKER 1 needs no new column: resumability is achieved by REUSING the
 * live session-bound intent instead of minting a replacement. What it does
 * need is an index that makes "the live link intent for this user in this
 * session" a keyed lookup rather than a scan, because the page now runs
 * that query on every load instead of writing a new row.
 *
 * §5 (leaked deep-link token) needs four additive columns. A Start no
 * longer links: it parks a CANDIDATE Telegram identity on the intent, and
 * only the original authenticated browser — the one that minted the token
 * and still holds the session — can promote that candidate into a real
 * link. If a token leaks and a stranger presses Start first, the stranger
 * becomes a candidate that the account owner sees and rejects.
 *
 * Every column is nullable and additive. Existing rows keep their exact
 * meaning: a null candidate is an intent from before this release, and
 * `confirmationRequired()` reads a config flag so the flow can be turned
 * off without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_login_intents', function (Blueprint $table): void {
            $table->string('candidate_telegram_id', 64)->nullable()->after('cancelled_at');
            $table->string('candidate_username', 64)->nullable()->after('candidate_telegram_id');
            $table->string('candidate_name', 120)->nullable()->after('candidate_username');
            $table->timestamp('candidate_at')->nullable()->after('candidate_name');
        });

        Schema::table('telegram_login_intents', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'purpose', 'expires_at'],
                'telegram_intents_user_purpose_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('telegram_login_intents', function (Blueprint $table): void {
            $table->dropIndex('telegram_intents_user_purpose_idx');
        });

        Schema::table('telegram_login_intents', function (Blueprint $table): void {
            $table->dropColumn([
                'candidate_telegram_id',
                'candidate_username',
                'candidate_name',
                'candidate_at',
            ]);
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp OTP account verification — the second door to a verified account.
 *
 * The product decision: registration keeps offering Telegram, and adds a
 * WhatsApp one-time code (delivered through Bird) as an equal alternative.
 * Either method verifies the SAME account, and one success is enough.
 *
 * TWO CLAIMS, TWO COLUMNS — the doctrine `telegram_verified_at` established.
 * A WhatsApp code delivered TO the typed number and typed back proves
 * possession of the PHONE (so redemption also sets the pre-existing
 * `phone_verified`, the exact claim Share-Contact sets); and it verifies the
 * ACCOUNT, recorded here as `whatsapp_verified_at`. It proves nothing about
 * any Telegram identity, so the telegram_* columns are never touched by this
 * flow — and the method an account was verified by stays derivable from which
 * timestamp is set, with no enum column to drift.
 *
 * WHY A DEDICATED TABLE, not a reuse — every neighbour's semantics contradict
 * this one's, the same reasoning password_recovery_challenges recorded:
 *
 *   - `telegram_verification_tokens` is PERMANENT by design; an OTP typed
 *     from a chat must die on a short clock and after a few wrong tries.
 *   - `password_recovery_challenges` authorises a password write; this
 *     authorises a verification stamp. Entangling them would let one flow's
 *     rules quietly govern the other's.
 *   - `telegram_return_handoffs` establishes a SESSION. This never does: the
 *     person typing the code is already signed in as the account it verifies.
 *
 * WHAT A ROW AUTHORISES, stated narrowly: marking the ONE account named by
 * `user_id` verified, within a ten-minute window, for as long as the account
 * still holds the SAME phone number the code was sent to, with at most five
 * attempts. It signs nobody in, names no destination, and is worthless for
 * any other account.
 *
 * Forward-only, and it drops cleanly: nothing else references this table, and
 * a code is worthless minutes after it is minted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_otps', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Keyed HMAC of the six digits, never the digits. A six-digit
             * space is trivially brute-forced OFFLINE from a plain digest, so
             * the hash is keyed with the platform's blind-index key: a
             * database dump alone yields nothing checkable. ONLINE guessing
             * is bounded by the attempts counter below and the route
             * throttle, not by the hash.
             */
            $table->char('code_hash', 64);

            /*
             * The blind index of the phone the code was DELIVERED to —
             * compared against the account's live `phone_index` at
             * redemption, so a number changed between send and confirm
             * refuses rather than stamping a claim about the wrong phone.
             * Same discipline as the handoff's telegram_id_hash.
             */
            $table->char('phone_hash', 64);

            // The language the person chose; the sender replies in it.
            $table->string('locale', 8);

            // NOT NULL: a code without a clock would be a standing
            // verification key, which is the outcome this table refuses.
            $table->timestamp('expires_at');

            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            /*
             * Wrong-guess budget, enforced in the service under the user-row
             * lock. A column rather than a cache key so the count survives
             * anything short of the row itself being retired.
             */
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamps();

            // "The live code for this account" is the hottest lookup.
            $table->index(['user_id', 'consumed_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            /*
             * Guarded like every recent column addition: a migration that
             * failed halfway on a shared host must be re-runnable.
             */
            if (! Schema::hasColumn('users', 'whatsapp_verified_at')) {
                $table->timestamp('whatsapp_verified_at')->nullable()->after('telegram_verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_otps');

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'whatsapp_verified_at')) {
                $table->dropColumn('whatsapp_verified_at');
            }
        });
    }
};

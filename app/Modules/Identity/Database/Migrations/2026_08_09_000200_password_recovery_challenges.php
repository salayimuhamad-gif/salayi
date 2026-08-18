<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram password recovery challenges.
 *
 * A DEDICATED table, deliberately not a reuse of any existing token store,
 * because every neighbouring table's semantics contradict this one's:
 *
 *   - `telegram_verification_tokens` is PERMANENT by design — its missing
 *     `expires_at` is the feature. A recovery credential must die on a short
 *     clock, and grafting a clock onto that table would quietly reintroduce
 *     the expiring-verification failure it exists to prevent.
 *   - `telegram_login_intents` is bound to a browser session fingerprint and
 *     stores its bearer token in the clear. Recovery starts phone-in-hand
 *     with no session worth binding to, and a credential that can change a
 *     password must never sit in a column in usable form.
 *   - `telegram_return_handoffs` establishes a SESSION. Recovery must never
 *     do that: it authorises exactly one password write, and the person then
 *     signs in at the front door with the password they chose.
 *   - `password_reset_tokens` is Laravel's email-broker store, keyed by
 *     email. Customers register with no email; entangling the two flows
 *     would make the broker's semantics govern a flow it knows nothing
 *     about.
 *
 * WHAT A ROW AUTHORISES, stated narrowly: one password change, for one named
 * account, within a short window, provided the account is still active and
 * still linked to the SAME Telegram identity the challenge was sent to. It is
 * not a login, carries no destination, and never establishes a session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_recovery_challenges', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // sha256 of the raw token. The raw value exists only inside the
            // chat message's URL; the store can never produce a usable link.
            $table->char('token_hash', 64)->unique();

            // The Telegram identity the challenge was DELIVERED to, hashed.
            // Re-checked against the live account at redemption, so a link or
            // identity change between mint and use refuses the reset.
            $table->char('telegram_id_hash', 64);

            $table->string('locale', 5)->default('ckb');

            // NOT NULL: a recovery credential without a clock is a standing
            // password-reset key, which is the outcome this table exists to
            // prevent.
            $table->timestamp('expires_at');

            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_recovery_challenges');
    }
};

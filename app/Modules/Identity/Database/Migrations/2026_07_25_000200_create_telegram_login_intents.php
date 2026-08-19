<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram login intents and webhook replay protection (File one §7.1, §7.2, §7.9).
 *
 * §7 asks for Telegram AUTHENTICATION, not another notification channel. The
 * difference is entirely in this table: a login intent is a single-use token,
 * created by a browser, redeemed exactly once by a Telegram callback, and bound
 * to the session that created it.
 *
 * Without that binding the flow is trivially hijackable. An attacker who
 * obtains a login link — from a forwarded message, a shoulder-glance at a QR
 * code, a shared screen — could complete it in their own browser and be signed
 * in as the victim. Binding to `session_fingerprint` means redeeming the token
 * in a different session proves nothing and grants nothing.
 *
 * `telegram_webhook_events` exists for §7.9 replay protection. Telegram
 * retries a webhook it believes failed, and a retried contact-share must not
 * create a second account or re-verify a phone somebody has since removed. The
 * update id is unique, so a replay is a duplicate-key violation rather than a
 * second execution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_login_intents', function (Blueprint $table): void {
            $table->id();

            // The token the browser shows and Telegram echoes back. Indexed
            // and unique because it is the lookup key on redemption.
            $table->string('token', 64)->unique();

            /*
             * A hash of the session id, never the id itself. If this table
             * leaked, holding the raw session identifiers would let an attacker
             * impersonate every pending session; a hash is useless to them and
             * equally usable for the equality check this needs.
             */
            $table->string('session_fingerprint', 64)->index();

            // Set once redemption succeeds. Non-null means spent.
            $table->timestamp('consumed_at')->nullable();

            $table->timestamp('expires_at')->index();

            // Populated by the callback, so an intent records what redeemed it.
            $table->string('telegram_id', 32)->nullable()->index();
            $table->string('telegram_username', 64)->nullable();

            /*
             * Whether the phone arrived via Telegram's Share Contact button.
             * §7.4 forbids treating a typed number as verified, and the only
             * way to honour that later is to record which route it came by.
             */
            $table->boolean('phone_shared')->default(false);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Bound at creation for the consent evidence §7.8 requires.
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->string('locale', 8)->default('ckb');

            $table->timestamps();

            // The sweep for expired, unconsumed intents.
            $table->index(['consumed_at', 'expires_at']);
        });

        Schema::create('telegram_webhook_events', function (Blueprint $table): void {
            $table->id();

            /*
             * Telegram's own update id. Unique so a replayed delivery collides
             * instead of executing twice — the database enforces idempotency
             * rather than application code remembering to check.
             */
            $table->unsignedBigInteger('update_id')->unique();

            $table->string('kind', 32)->nullable();
            $table->timestamp('received_at');

            $table->index('received_at');
        });
    }

    public function down(): void
    {
        // MIGRATION-GUARD: intentional-drop — reversing this migration's own
        // tables. Login intents are short-lived by design and webhook events
        // are a replay ledger; neither holds anything not reconstructible.
        Schema::dropIfExists('telegram_webhook_events');
        Schema::dropIfExists('telegram_login_intents');
    }
};

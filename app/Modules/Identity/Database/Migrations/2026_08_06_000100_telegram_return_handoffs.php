<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable, single-use Telegram return handoffs (v7).
 *
 * The first implementation kept handoffs in the cache and consumed them with
 * `Cache::pull()`, claiming that made redemption atomic. It does not: on the
 * database cache store — which is what this deployment uses — `pull()` is a
 * read followed by a delete, so two requests arriving together can both read a
 * live token before either deletes it, and both would authenticate. The token
 * authenticates a cold browser, so "both win" means an extra session for
 * whoever else holds the link.
 *
 * A row with a UNIQUE token hash and a `consumed_at` column lets redemption
 * happen inside a transaction under `SELECT ... FOR UPDATE`, where exactly one
 * caller can move the row from unconsumed to consumed. The loser sees
 * `consumed_at` already set and is refused.
 *
 * Forward-only, and it drops cleanly: nothing else references this table, and
 * a handoff is worthless a minute after it is minted, so losing the table
 * loses nothing but a few in-flight return links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_return_handoffs', function (Blueprint $table): void {
            $table->id();

            /*
             * Only the HASH is stored. A database read must not yield a usable
             * credential, and the raw token exists only in the URL that went
             * to the person's own chat. Unique, because the hash is the
             * identity of the handoff and a collision would be a second token
             * claiming to be the first.
             */
            $table->char('token_hash', 64)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Hashed too: the Telegram id is personal data and this table does
            // not need to be able to reveal it, only to compare it.
            $table->char('telegram_id_hash', 64);

            $table->string('locale', 8);
            $table->string('destination', 32);

            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_return_handoffs');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The permanent registration verification token.
 *
 * WHY THIS IS NOT `telegram_login_intents`.
 *
 * That table already carries a Telegram token, and reusing it was the obvious
 * move. It is the wrong move, and the difference is not cosmetic:
 *
 *   - every intent EXPIRES (`expires_at` is NOT NULL and `mint()` sets it ten
 *     minutes out). The whole point of this table is a token with no clock;
 *   - every intent is bound to a browser SESSION (`session_fingerprint`), and
 *     every read is scoped to it. This token has to survive the browser being
 *     closed for a month, so it is bound to the ACCOUNT and to nothing else;
 *   - an intent is a bearer credential stored in the clear, because the flow
 *     needs to look a token up by its own value. Here the lookup runs on a
 *     digest and the value itself is encrypted;
 *   - `telegram_login_intents` grew a login purpose, a registration purpose and
 *     an account-link purpose, each with its own redemption rules. Adding a
 *     fourth set of rules to a row whose expiry semantics contradict them is
 *     how the next reader gets it wrong.
 *
 * WHAT THIS TOKEN MAY DO, stated narrowly because the answer is the security
 * model: it authorises linking ONE Telegram identity to ONE named account that
 * currently has none. It is not a login. It carries no destination, so it can
 * never become an open redirect, and it never establishes a browser session —
 * that remains the job of the short-lived, single-use
 * `telegram_return_handoffs` row, which is deliberately left untouched.
 *
 * THE MISSING COLUMN IS THE FEATURE. There is no `expires_at` here, and adding
 * one later would silently reintroduce the failure this replaces: somebody
 * registers on Thursday, cannot find their phone, comes back after the weekend
 * and is told their link is dead. Validity is state, not time:
 *
 *     VALID  ==  used_at IS NULL  AND  revoked_at IS NULL
 *
 * Forward-only. Nothing else references this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_verification_tokens', function (Blueprint $table): void {
            $table->id();

            /*
             * The lookup key: sha256 of the raw token. UNIQUE because the hash
             * IS the identity of the token — redemption finds a row by it in
             * one indexed read, and two rows claiming one hash would make that
             * lookup ambiguous at exactly the wrong moment.
             *
             * Irreversible, so a database read alone yields nothing forgeable.
             */
            $table->char('token_hash', 64)->unique();

            /*
             * The same token, ENCRYPTED, and this pair needs justifying
             * because "store only the hash" is the reflex and it is wrong
             * here.
             *
             * A hash-only token can never be shown again. That is fine for the
             * five-minute return handoff, which is displayed once and dies. It
             * is not fine for this one: somebody who registers, closes the tab,
             * and logs back in three weeks later must be shown THE SAME link —
             * not a fresh one, because minting a replacement silently kills the
             * link already sitting in their Telegram chat. That exact bug was
             * found and fixed once before, in the account-link flow, when
             * re-rendering the page destroyed the token the person had already
             * opened.
             *
             * So the row carries both forms, which is precisely the pattern
             * `users` already uses for phone numbers: `phone_index` (a keyed
             * digest, for lookup) beside `phone_encrypted` (for the value
             * itself). Reading the token back requires the ciphertext AND
             * APP_KEY; the database on its own gives neither a usable token nor
             * a way to derive one.
             */
            $table->text('token_encrypted');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The language the person chose on the website. The bot replies in
             * it, which is the whole reason it is stored: somebody who
             * registered in Sorani with an English Telegram client must be
             * answered in Sorani, and by the time the webhook runs the browser
             * that knew their choice is long gone.
             */
            $table->string('locale', 8);

            /*
             * Consumption and revocation, as two nullable timestamps rather
             * than one status column. They answer different questions — "was
             * this used, and when" versus "was it withdrawn, and when" — and a
             * single enum would lose one answer whenever both happened.
             */
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            /*
             * "The live token for this account" is the single hottest query
             * here: it runs on every render of the verification page. Indexed
             * on the pair rather than on user_id alone so the common lookup —
             * unused rows for one user — is served by the index.
             *
             * Deliberately NOT unique. One-live-token-per-account is enforced
             * in the service under a row lock (a used or revoked row keeps its
             * user_id forever, so a unique index here would refuse the second
             * legitimate token an account is ever issued).
             */
            $table->index(['user_id', 'used_at'], 'telegram_verification_tokens_user_live_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_verification_tokens');
    }
};

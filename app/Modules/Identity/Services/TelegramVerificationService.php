<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\TelegramVerificationToken;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\WhatsAppOtp;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Registration verification: one Start, one link, no clock.
 *
 * WHAT PRESSING START PROVES, stated first because everything below enforces
 * exactly this and nothing more: it proves control of a TELEGRAM ACCOUNT. It
 * proves nothing about the phone number typed on the website, so `phone_verified`
 * is never touched here — only {@see TelegramAuthenticator}'s Share-Contact flow
 * can set that, because only a self-shared contact actually demonstrates
 * possession. The two claims keep two columns.
 *
 * HOW THIS DIFFERS FROM {@see TelegramRegistrar::redeemAccountLink()}, which is
 * deliberately left in place and unchanged:
 *
 *   That flow re-points an account that ALREADY HAS a Telegram identity, or
 *   links one that may already own a portfolio. A leaked Start token there is
 *   an account-takeover primitive, which is why it parks a candidate and makes
 *   the signed-in browser confirm it on screen.
 *
 *   This flow attaches a Telegram identity to an account that has NONE. The
 *   difference is not a matter of degree. Someone who steals this token and
 *   presses Start binds THEIR OWN Telegram account to a stranger's unverified,
 *   empty account — they gain no session, no password and no data, because the
 *   token authenticates nobody. What they can do is deny the owner a link, and
 *   that is repairable: the collision is refused loudly, audited with both
 *   sides recorded, and support can revoke.
 *
 *   Trading a browser round-trip for that is the product decision this class
 *   implements. It is not a weakening of the other flow, which still refuses to
 *   change a linked identity without an explicit, confirmed act.
 *
 * THE THREE REFUSALS THAT ARE NOT NEGOTIABLE, each enforced under a row lock
 * and again by a UNIQUE index on `users.telegram_id`:
 *
 *   1. a Telegram identity already on another account is never reassigned;
 *   2. an account already linked to a DIFFERENT Telegram identity is never
 *      silently re-pointed;
 *   3. a used token never links a second, different Telegram account — which
 *      is what stops a consumed token from becoming a takeover.
 */
final class TelegramVerificationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The account's live token, minting one only if there is not one already.
     *
     * RESUME, NOT REPLACE. This is called on every render of the verification
     * page, and a page render is not an event. The account-link flow learned
     * this the hard way: minting on render meant a refresh, a restored mobile
     * tab or a second window silently destroyed the token the person had
     * already opened in Telegram, and they pressed Start on a dead link. With
     * no expiry the consequence would be worse here, because the link is
     * supposed to be the one thing that still works a month later.
     *
     * Returns the RAW token. It is written to the row encrypted, so this same
     * value comes back on every later call for as long as the token lives.
     *
     * The user row is taken as the per-account mutex so two tabs arriving in
     * the same millisecond serialise here rather than both minting.
     */
    public function issueFor(User $user, ?string $locale = null): string
    {
        return DB::transaction(function () use ($user, $locale): string {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $live = TelegramVerificationToken::query()
                ->where('user_id', $user->id)
                ->usable()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($live !== null) {
                $raw = $live->raw();

                if ($raw !== null) {
                    return $raw;
                }

                /*
                 * The row exists but its ciphertext will not open — a re-keyed
                 * APP_KEY, or a column written by something that got it wrong.
                 * The token can never be shown or matched again, so it is dead
                 * whatever the state columns say. Revoke it explicitly rather
                 * than leaving an unusable row looking live, and fall through
                 * to mint a replacement.
                 */
                $live->forceFill(['revoked_at' => now()])->save();

                $this->audit->security('identity.verification_token_unreadable', [
                    'token_id' => $live->id,
                    'actor_id' => $user->id,
                ]);
            }

            return $this->mint($user, $locale)['raw'];
        });
    }

    /**
     * Mint a fresh token, retiring every live one this account holds.
     *
     * Called on purpose — from registration, and from the "send me a new link"
     * action — never as a side effect of rendering something. Retiring the
     * others first is what keeps "one live token per account" true without a
     * unique index that would also refuse the second token an account is ever
     * legitimately issued.
     *
     * @return array{raw: string, token: TelegramVerificationToken}
     */
    public function mint(User $user, ?string $locale = null): array
    {
        $issue = function () use ($user, $locale): array {
            TelegramVerificationToken::query()
                ->where('user_id', $user->id)
                ->usable()
                ->lockForUpdate()
                ->get()
                ->each(function (TelegramVerificationToken $stale): void {
                    $stale->forceFill(['revoked_at' => now()])->save();
                });

            $raw = TelegramVerificationToken::generateRaw();

            $token = new TelegramVerificationToken;
            $token->forceFill([
                'token_hash' => TelegramVerificationToken::hash($raw),
                'token_encrypted' => Crypt::encryptString($raw),
                'user_id' => $user->id,
                'locale' => $this->locale($locale ?? $user->preferred_locale),
                'used_at' => null,
                'revoked_at' => null,
            ])->save();

            /*
             * The token id, never the token and never its hash. An audit trail
             * that can be replayed into a working credential is not an audit
             * trail.
             */
            $this->audit->record('identity.verification_token_issued', $user, [], [
                'token_id' => $token->id,
            ]);

            return ['raw' => $raw, 'token' => $token];
        };

        // Reuse the caller's transaction when there is one (issueFor already
        // holds the account lock); open one otherwise.
        return DB::transactionLevel() > 0 ? $issue() : DB::transaction($issue);
    }

    /**
     * Withdraw every live token for this account.
     *
     * The explicit revocation half of "permanent until used OR revoked". Used
     * when a registration is abandoned, and available to support for the case
     * this design accepts: somebody else pressed Start on a link that leaked.
     */
    public function revokeAllFor(User $user, string $reason): int
    {
        return DB::transaction(function () use ($user, $reason): int {
            $tokens = TelegramVerificationToken::query()
                ->where('user_id', $user->id)
                ->usable()
                ->lockForUpdate()
                ->get();

            foreach ($tokens as $token) {
                $token->forceFill(['revoked_at' => now()])->save();
            }

            if ($tokens->isNotEmpty()) {
                $this->audit->security('identity.verification_token_revoked', [
                    'actor_id' => $user->id,
                    'count' => $tokens->count(),
                    'reason' => $reason,
                ]);
            }

            return $tokens->count();
        });
    }

    /**
     * Redeem `/start TOKEN` — the entire verification, in one transaction.
     *
     * Called from the webhook once the secret header and the replay ledger have
     * both passed, with the raw `from` block Telegram sent.
     *
     * IDEMPOTENCY IS THE DEFAULT, NOT A SPECIAL CASE. Telegram redelivers
     * updates, and a person who taps Start twice is not doing anything wrong.
     * So the same Telegram account presenting the same already-used token gets
     * the same success it got the first time — `already_verified` — and nothing
     * is written. A DIFFERENT Telegram account presenting that used token is
     * the attack this shape exists to refuse, and it is refused.
     *
     * Everything that decides or changes state happens inside ONE transaction
     * under row locks on the token and on its account: state checks, collision
     * checks, the link, the timestamp, consumption and the audit record. A
     * failure anywhere rolls back all of it — consumption included — so a
     * transient error leaves the person holding a token that still works, not a
     * dead one.
     *
     * @param  array<string, mixed>  $from  Telegram's `message.from`
     * @return array{ok: bool, reason?: string, user_id?: int, locale?: string, already_verified?: bool}
     */
    public function redeem(string $rawToken, array $from): array
    {
        /*
         * The numeric id, and only ever the numeric id. A username is
         * changeable and may not exist at all, so it is display metadata here
         * and never an identity.
         */
        $telegramId = (string) ($from['id'] ?? '');

        if ($telegramId === '' || $rawToken === '') {
            return ['ok' => false, 'reason' => 'no_sender'];
        }

        try {
            return DB::transaction(function () use ($rawToken, $telegramId, $from): array {
                $token = TelegramVerificationToken::query()
                    ->where('token_hash', TelegramVerificationToken::hash($rawToken))
                    ->lockForUpdate()
                    ->first();

                if ($token === null) {
                    /*
                     * An unknown token. Nothing is logged about its value — a
                     * caller guessing tokens must not be able to farm the log
                     * for near misses.
                     */
                    return ['ok' => false, 'reason' => 'unknown_token'];
                }

                $locale = $this->locale($token->locale);

                if ($token->revoked_at !== null) {
                    /*
                     * One refinement for the dual-method model: a token that
                     * was retired BECAUSE the account verified through
                     * WhatsApp is not a failure story. The person pressing
                     * the old link in their chat is told the truth — the
                     * account is fine, nothing further is needed — instead
                     * of a generic refusal that sends them to support. Only
                     * the reply differs; the token stays exactly as dead.
                     */
                    $owner = User::query()->find($token->user_id);

                    if ($owner !== null && $owner->hasVerifiedAccount()) {
                        return ['ok' => false, 'reason' => 'already_verified', 'locale' => $locale];
                    }

                    $this->audit->security('identity.verification_refused', [
                        'token_id' => $token->id,
                        'via' => 'revoked',
                    ]);

                    return ['ok' => false, 'reason' => 'revoked', 'locale' => $locale];
                }

                $user = User::query()->whereKey($token->user_id)->lockForUpdate()->first();

                if ($user === null || ! $user->mayAuthenticate()) {
                    $this->audit->security('identity.verification_refused', [
                        'token_id' => $token->id,
                        'via' => 'account_unavailable',
                    ]);

                    return ['ok' => false, 'reason' => 'account_unavailable', 'locale' => $locale];
                }

                if ($token->used_at !== null) {
                    /*
                     * Already spent. The ONLY sender allowed a success here is
                     * the one that spent it, and the account's own live link is
                     * what proves that — not anything the sender claims.
                     */
                    if ($user->telegram_id !== null
                        && hash_equals((string) $user->telegram_id, $telegramId)) {
                        return [
                            'ok' => true,
                            'user_id' => (int) $user->id,
                            'locale' => $locale,
                            'already_verified' => true,
                        ];
                    }

                    $this->audit->security('identity.verification_refused', [
                        'token_id' => $token->id,
                        'via' => 'used_token_replay_by_other_sender',
                    ]);

                    return ['ok' => false, 'reason' => 'used', 'locale' => $locale];
                }

                if ($user->telegram_id !== null) {
                    /*
                     * The account already has an identity. If it is this one,
                     * the token has simply caught up with reality — consume it
                     * and report success. If it is a different one, refuse:
                     * changing which Telegram account owns a MyHawler account
                     * is an explicit security operation with its own confirmed
                     * flow, never a side effect of an old link being pressed.
                     */
                    if (hash_equals((string) $user->telegram_id, $telegramId)) {
                        $token->forceFill(['used_at' => now()])->save();

                        /*
                         * Self-healing, and it is not theoretical: an account
                         * can hold a `telegram_id` with no `telegram_verified_at`
                         * — that is what a Share-Contact sign-in produced before
                         * the fix in TelegramAuthenticator, and those rows are
                         * already in the database. The gate reads the timestamp,
                         * so without this line the same person presses Start,
                         * gets told it worked, and is bounced straight back to
                         * the verification page.
                         *
                         * The identity has been re-proven by this very Start, so
                         * stamping it is recording what just happened.
                         */
                        if ($user->telegram_verified_at === null) {
                            $user->forceFill(['telegram_verified_at' => now()])->save();

                            // The account just became verified; any WhatsApp
                            // code still in flight is for a question that no
                            // longer exists.
                            WhatsAppOtp::retireAllFor((int) $user->id);

                            $this->audit->record('identity.telegram_verified', $user, [], [
                                'token_id' => $token->id,
                                'via' => 'repaired_missing_timestamp',
                            ]);
                        }

                        return [
                            'ok' => true,
                            'user_id' => (int) $user->id,
                            'locale' => $locale,
                            'already_verified' => true,
                        ];
                    }

                    $this->audit->security('identity.verification_refused', [
                        'token_id' => $token->id,
                        'via' => 'account_linked_elsewhere',
                    ]);

                    return ['ok' => false, 'reason' => 'conflict', 'locale' => $locale];
                }

                if ($user->whatsapp_verified_at !== null) {
                    /*
                     * The account verified through the OTHER door while this
                     * link sat in the chat — the race the user-row lock
                     * serialises, caught here for the token the WhatsApp win
                     * had not yet revoked. The token's one job (verifying an
                     * unverified account) no longer exists, and attaching a
                     * Telegram identity now would be a SECOND verification
                     * event nobody asked for. The token is retired, nothing
                     * about the account is written, and the reply says
                     * "already verified". Linking Telegram to this account
                     * later remains possible through the explicit,
                     * confirmed account-link flow — never as a side effect
                     * of an old registration link being pressed.
                     */
                    $token->forceFill(['revoked_at' => now()])->save();

                    $this->audit->security('identity.verification_refused', [
                        'token_id' => $token->id,
                        'via' => 'account_already_verified',
                    ]);

                    return ['ok' => false, 'reason' => 'already_verified', 'locale' => $locale];
                }

                $takenByAnother = User::query()
                    ->where('telegram_id', $telegramId)
                    ->whereKeyNot($user->id)
                    ->exists();

                if ($takenByAnother) {
                    $this->audit->security('identity.verification_refused', [
                        'token_id' => $token->id,
                        'via' => 'telegram_id_taken',
                    ]);

                    return ['ok' => false, 'reason' => 'conflict', 'locale' => $locale];
                }

                // Everything below mutates, inside THIS transaction.
                $token->forceFill(['used_at' => now()])->save();

                $user->forceFill([
                    'telegram_id' => $telegramId,
                    'telegram_username' => $this->shortText($from['username'] ?? null, 64),
                    'telegram_verified_at' => now(),
                ])->save();

                /*
                 * Telegram won: the road not taken is closed. Any WhatsApp
                 * code still in flight is retired under this same user lock,
                 * so the pending OTHER method can never mint a second
                 * verification event — the mirror of what the WhatsApp
                 * service does to this flow's tokens when IT wins.
                 */
                WhatsAppOtp::retireAllFor((int) $user->id);

                $this->audit->record('identity.telegram_verified', $user, [], [
                    'token_id' => $token->id,
                    'via' => 'registration_verification',
                ]);

                return ['ok' => true, 'user_id' => (int) $user->id, 'locale' => $locale];
            });
        } catch (UniqueConstraintViolationException) {
            /*
             * The race the locks cannot see: the same Telegram identity winning
             * a DIFFERENT account in the same instant, refused by the UNIQUE
             * index on `users.telegram_id`. The transaction has rolled back
             * completely — this token included — so it is still usable, and the
             * audit lands outside the dead transaction so the refusal survives.
             */
            $this->audit->security('identity.verification_refused', [
                'via' => 'unique_constraint',
            ]);

            return ['ok' => false, 'reason' => 'conflict'];
        }
    }

    /** One of the three languages this platform speaks, or the site default. */
    private function locale(?string $locale): string
    {
        return in_array($locale, ['ckb', 'ar', 'en'], true)
            ? $locale
            : (string) config('app.locale', 'ckb');
    }

    /**
     * Telegram-supplied display text, bounded and stripped of control
     * characters. Shown back to the person, trusted for nothing.
     */
    private function shortText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1f]+/u', '', $value));

        return $clean === '' ? null : mb_substr($clean, 0, $limit);
    }
}

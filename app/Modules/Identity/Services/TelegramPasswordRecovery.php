<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\PasswordRecoveryChallenge;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PhoneNumber;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Telegram password recovery — a SEPARATE security mechanism.
 *
 * The boundary this service must never blur: the permanent initial
 * verification token (`telegram_verification_tokens`), the login intents and
 * the return handoffs keep exactly the semantics they have. None of them can
 * reset a password; nothing minted here can verify an account, link a
 * Telegram identity or establish a session. The challenge lives in its own
 * table with its own clock and dies on first use.
 *
 * Properties, mirroring {@see TelegramReturnHandoff}'s discipline:
 *
 *   - 32 random bytes; stored HASHED, raw value only in the chat message;
 *   - fifteen-minute lifetime — read-your-chat-now, not a standing key;
 *   - single-use, consumed atomically under a row lock;
 *   - bound to the account AND the Telegram identity it was DELIVERED to,
 *     re-checked against the live row at redemption;
 *   - per-token attempt budget on top of the route throttles, so one
 *     leaked-hash guess cannot be retried for the whole window;
 *   - every refusal is the same silent outcome from outside — the request
 *     form answers identically whether the phone exists, is unlinked or is
 *     suspended, and the reset endpoint shows one neutral failure page;
 *   - on success: password set, remember token rotated, EVERY database
 *     session of the account deleted, audit trail written, account_security
 *     notice sent by the caller.
 */
final class TelegramPasswordRecovery
{
    /** Fifteen minutes: the message is read now or the link is dead. */
    public const TTL_SECONDS = 900;

    /** Redemption attempts allowed per token before it is revoked outright. */
    private const MAX_ATTEMPTS_PER_TOKEN = 5;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TelegramBotResponder $bot,
    ) {}

    /**
     * Handle a recovery request for a typed phone number.
     *
     * ALWAYS silent. The caller shows the same "if this account can recover
     * by Telegram, a message was sent" notice no matter what happened here —
     * this form must not become a phone-number oracle, and "has Telegram
     * linked" is itself account information.
     */
    public function requestFor(string $phoneInput, string $locale): void
    {
        $index = User::blindIndex(PhoneNumber::toE164($phoneInput));

        $user = User::query()->where('phone_index', $index)->first();

        if ($user === null || ! $user->mayAuthenticate()) {
            $this->audit->security('identity.password_recovery_refused', ['via' => 'account_unavailable']);

            return;
        }

        if ($user->telegram_verified_at === null || $user->telegram_id === null) {
            $this->audit->security('identity.password_recovery_refused', ['via' => 'not_linked']);

            return;
        }

        $this->deliverChallenge($user, $locale);
    }

    /**
     * Mint and deliver a challenge for a KNOWN account — the admin "send a
     * reset link" action. The caller has already authorised and identified
     * the account; eligibility is still re-checked here because an admin
     * screen can be stale. Returns false when the account cannot receive
     * one (unlinked or unavailable), so the admin gets an honest answer —
     * this path has no enumeration concern, the admin already sees the row.
     */
    public function requestForUser(User $user, string $locale): bool
    {
        if (! $user->mayAuthenticate()
            || $user->telegram_verified_at === null
            || $user->telegram_id === null) {
            return false;
        }

        $this->deliverChallenge($user, $locale);

        return true;
    }

    private function deliverChallenge(User $user, string $locale): void
    {
        $raw = PasswordRecoveryChallenge::generateRaw();

        DB::transaction(function () use ($user, $raw, $locale): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // One live challenge per account: a fresh request retires every
            // earlier one, so the newest chat message is the only working
            // link and an old message cannot be raced against it.
            PasswordRecoveryChallenge::query()
                ->where('user_id', $locked->getKey())
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            PasswordRecoveryChallenge::query()->create([
                'user_id' => $locked->getKey(),
                'token_hash' => PasswordRecoveryChallenge::hashOf($raw),
                'telegram_id_hash' => hash('sha256', (string) $locked->telegram_id),
                'locale' => $locale,
                'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            ]);
        });

        $this->audit->record('identity.password_recovery_requested', $user, [], [
            'ttl_seconds' => self::TTL_SECONDS,
        ]);

        /*
         * The URL is built from the configured origin only — never from the
         * request — so the link in the chat can only ever point here. Sent
         * AFTER the transaction committed: a chat message for a challenge
         * that rolled back would be a dead link with an audit row missing.
         */
        $this->bot->sendPasswordRecovery(
            (string) $user->telegram_id,
            rtrim((string) config('app.url'), '/').'/recover/'.$raw,
            $locale,
        );
    }

    /**
     * Is this raw token currently redeemable? Read-only; nothing is consumed.
     *
     * Used by the GET form so a dead link shows the neutral page instead of a
     * password form that can only fail. Every refusal is the same null.
     */
    public function usable(string $raw): ?PasswordRecoveryChallenge
    {
        if (! $this->withinAttemptBudget($raw)) {
            return null;
        }

        $challenge = PasswordRecoveryChallenge::query()
            ->where('token_hash', PasswordRecoveryChallenge::hashOf($raw))
            ->first();

        if ($challenge === null || ! $challenge->isUsable()) {
            return null;
        }

        $user = $challenge->user;

        if (! $this->challengeStillBinds($challenge, $user)) {
            return null;
        }

        return $challenge;
    }

    /**
     * Redeem the challenge and set the new password. Returns the user on
     * success, null on every refusal — the caller shows one neutral failure.
     */
    public function reset(string $raw, string $password): ?User
    {
        if (! $this->withinAttemptBudget($raw, hit: true)) {
            $this->audit->security('identity.password_recovery_refused', ['via' => 'attempt_budget']);

            return null;
        }

        $outcome = DB::transaction(function () use ($raw, $password): array {
            $challenge = PasswordRecoveryChallenge::query()
                ->where('token_hash', PasswordRecoveryChallenge::hashOf($raw))
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                return ['refused' => 'unknown'];
            }

            if (! $challenge->isUsable()) {
                return ['refused' => $challenge->consumed_at !== null ? 'already_spent' : 'expired_or_revoked'];
            }

            /** @var User|null $user */
            $user = User::query()->whereKey($challenge->user_id)->lockForUpdate()->first();

            if ($user === null || ! $user->mayAuthenticate()) {
                return ['refused' => 'account_unavailable'];
            }

            if (! $this->challengeStillBinds($challenge, $user)) {
                return ['refused' => 'identity_mismatch'];
            }

            // Consumed BEFORE the password write: whatever happens after
            // this, the link is dead.
            $challenge->forceFill(['consumed_at' => now()])->save();

            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            /*
             * SAFE SESSION INVALIDATION. The account may have live sessions —
             * including whoever made the recovery necessary. The sessions
             * table is the session store on this deployment, so deleting the
             * account's rows ends every one of them at the next request. The
             * person resetting is a guest here; they lose nothing.
             */
            DB::table('sessions')->where('user_id', $user->getKey())->delete();

            return ['user' => $user];
        });

        if (! isset($outcome['user'])) {
            $this->audit->security('identity.password_recovery_refused', ['via' => $outcome['refused']]);

            return null;
        }

        /** @var User $user */
        $user = $outcome['user'];

        RateLimiter::clear($this->attemptKey($raw));

        event(new PasswordReset($user));

        $this->audit->record('identity.password_recovery_completed', $user, [], [
            'sessions_invalidated' => true,
        ], severity: 'warning');

        return $user;
    }

    /**
     * The identity binding, in one place: the account must still be linked,
     * and to the SAME Telegram identity the challenge was delivered to.
     */
    private function challengeStillBinds(PasswordRecoveryChallenge $challenge, ?User $user): bool
    {
        return $user !== null
            && $user->telegram_verified_at !== null
            && $user->telegram_id !== null
            && hash_equals($challenge->telegram_id_hash, hash('sha256', (string) $user->telegram_id));
    }

    /**
     * A per-token attempt budget, independent of the per-IP route throttle:
     * five tries and the token itself stops answering for its lifetime.
     */
    private function withinAttemptBudget(string $raw, bool $hit = false): bool
    {
        $key = $this->attemptKey($raw);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS_PER_TOKEN)) {
            return false;
        }

        if ($hit) {
            RateLimiter::hit($key, self::TTL_SECONDS);
        }

        return true;
    }

    private function attemptKey(string $raw): string
    {
        return 'pwrecover:token:'.hash('sha256', $raw);
    }

    /** Remove challenges long past expiry; nothing depends on them. */
    public static function pruneExpired(): int
    {
        return PasswordRecoveryChallenge::query()
            ->where('expires_at', '<', now()->subDay())
            ->delete();
    }
}

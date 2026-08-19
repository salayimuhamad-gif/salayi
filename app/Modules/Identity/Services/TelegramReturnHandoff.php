<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PostLinkDestination;
use App\Modules\Identity\Support\TelegramReturnUrl;
use App\Modules\Operations\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one-time handoff behind the "Return to MyHawler" button.
 *
 * Why this exists: a Telegram inline button usually opens Telegram's in-app
 * browser, which carries NONE of the website's cookies. A button pointing at a
 * plain `/account/onboarding` therefore lands an unauthenticated visitor on the
 * login page — while the original tab, which does have the session, is the one
 * that quietly succeeded. Testing that button from the already-authenticated
 * Playwright context proved only that an authenticated browser can open
 * onboarding; it proved nothing about the journey a real person takes.
 *
 * So the button carries an opaque one-time token that can establish a session
 * in a cold browser. That is a credential, and it is treated as one:
 *
 *   - 32 random bytes, generated server-side, never derived from anything
 *     about the user;
 *   - stored HASHED (sha256), so the store never holds a usable token;
 *   - five-minute lifetime — long enough to press a button, short enough that
 *     a leaked chat message ages out quickly;
 *   - single-use, consumed atomically through a row lock, so two requests
 *     racing the same token cannot both win;
 *   - bound to the user AND to the Telegram identity that was just confirmed,
 *     and re-checked against the live row at redemption;
 *   - refuses if the account has since become unlinked, been suspended, or had
 *     its Telegram identity changed;
 *   - the destination is not carried in the token at all — it is recomputed
 *     from {@see PostLinkDestination}, so the token cannot name a target and
 *     can never become an open redirect;
 *   - the URL contains no user id, phone, Telegram id or session id in any
 *     readable form.
 *
 * Durable rows, not cache entries — and that WAS a schema change.
 *
 * An earlier version kept the payload in the cache and consumed it with
 * `Cache::pull()`, on the reasoning that five minutes of state does not deserve
 * a table. That reasoning was wrong on the point that mattered: on the
 * database-backed cache store this project runs, `pull()` is a read followed by
 * a delete, so two requests arriving together can both read a live token and
 * both authenticate. This token establishes a session in a cold browser, so
 * "both win" means handing an extra session to whoever else holds the link.
 *
 * The handoff therefore lives in `telegram_return_handoffs` (migration
 * 2026_08_06_000100), with a UNIQUE token hash and a `consumed_at` column.
 * Redemption runs inside a transaction under `SELECT ... FOR UPDATE`: the row
 * is locked, every condition — including expiry — is evaluated while the lock
 * is held, and the winner stamps `consumed_at` before releasing it. A second
 * request blocks, then sees a consumed row and is refused. Exactly one of two
 * simultaneous redemptions can succeed, and that is asserted across two real
 * processes in TelegramReturnHandoffConcurrencyTest.
 *
 * Rows do not accumulate: `mulkihawler:telegram:prune-return-handoffs` removes
 * them once they are past expiry plus a grace period, daily.
 */
final class TelegramReturnHandoff
{
    /** Five minutes: a person presses the button immediately or not at all. */
    public const TTL_SECONDS = 300;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Mint a token for a user whose Telegram identity has just been confirmed.
     *
     * @return string the raw token — held only in the URL that goes to the
     *                chat, never stored anywhere in this form
     */
    public function mint(User $user, string $telegramId, string $locale): string
    {
        $raw = Str::random(64);

        /*
         * A durable row, not a cache entry. Redemption has to be atomic, and
         * on the database cache store `Cache::pull()` is a read followed by a
         * delete — two requests arriving together can both read a live token
         * before either deletes it, and both would authenticate. A row with a
         * UNIQUE token hash and a `consumed_at` column can be moved from
         * unconsumed to consumed by exactly one transaction.
         */
        DB::table('telegram_return_handoffs')->insert([
            'token_hash' => self::hashOf($raw),
            'user_id' => $user->getKey(),
            // Hashed, so the row cannot be read back into a Telegram identity.
            'telegram_id_hash' => hash('sha256', $telegramId),
            'locale' => $locale,
            'destination' => 'onboarding',
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (app()->environment(['testing', 'local'])) {
            // Browser-test introspection only; see the guarded route in
            // Identity/Routes/web.php. Never recorded in production.
            Cache::put('testing:last-return-handoff',
                TelegramReturnUrl::for('handoff', $locale, $raw),
                self::TTL_SECONDS);
        }

        $this->audit->security('identity.telegram_return_handoff_minted', [
            'user_id' => $user->getKey(),
            'ttl_seconds' => self::TTL_SECONDS,
        ]);

        return $raw;
    }

    /**
     * Redeem a token. Returns the user on success, null on every failure.
     *
     * Every refusal returns the same null: the caller shows one neutral page,
     * so a wrong, expired, replayed or foreign token are indistinguishable
     * from outside.
     *
     * @return array{user: User, locale: string}|null
     */
    public function redeem(string $raw): ?array
    {
        if ($raw === '' || ! ctype_alnum($raw)) {
            return null;
        }

        /*
         * One transaction, one row lock. The row is selected FOR UPDATE, every
         * condition is checked while it is held, and the winner stamps
         * `consumed_at` before the lock is released. A second request arriving
         * at the same instant blocks on the lock, then sees a consumed row and
         * is refused — so exactly one of two simultaneous redemptions
         * authenticates. Expiry is evaluated under the same lock, so a token
         * cannot be revived by racing its own expiry.
         */
        $outcome = DB::transaction(function () use ($raw): array {
            $row = DB::table('telegram_return_handoffs')
                ->where('token_hash', self::hashOf($raw))
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ['refused' => 'unknown'];
            }

            if ($row->consumed_at !== null) {
                return ['refused' => 'already_spent'];
            }

            if (Carbon::parse($row->expires_at)->isPast()) {
                return ['refused' => 'expired'];
            }

            // Consumed BEFORE any further work: whatever happens after this,
            // the token can never be used again.
            DB::table('telegram_return_handoffs')
                ->where('id', $row->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            return ['row' => $row];
        });

        if (! isset($outcome['row'])) {
            $this->audit->security('identity.telegram_return_handoff_refused', [
                'via' => $outcome['refused'],
            ]);

            return null;
        }

        $row = $outcome['row'];
        $user = User::query()->find($row->user_id);

        if ($user === null || ! $user->mayAuthenticate()) {
            $this->audit->security('identity.telegram_return_handoff_refused', ['via' => 'account_unavailable']);

            return null;
        }

        // The link must still be in place, and must still be the SAME Telegram
        // identity the token was minted for.
        if ($user->telegram_verified_at === null || $user->telegram_id === null) {
            $this->audit->security('identity.telegram_return_handoff_refused', ['via' => 'not_linked']);

            return null;
        }

        if (! hash_equals(
            (string) $row->telegram_id_hash,
            hash('sha256', (string) $user->telegram_id)
        )) {
            $this->audit->security('identity.telegram_return_handoff_refused', ['via' => 'identity_mismatch']);

            return null;
        }

        $locale = (string) $row->locale;

        if (! in_array($locale, ['ckb', 'ar', 'en'], true)) {
            $locale = (string) config('app.locale', 'ckb');
        }

        $this->audit->record('identity.telegram_return_handoff_redeemed', $user, [], ['locale' => $locale]);

        return ['user' => $user, 'locale' => $locale];
    }

    /**
     * Remove handoffs that expired long ago. Nothing depends on them once they
     * are past expiry; this only stops the table growing without bound.
     */
    public static function pruneExpired(): int
    {
        return DB::table('telegram_return_handoffs')
            ->where('expires_at', '<', now()->subDay())
            ->delete();
    }

    private static function hashOf(string $raw): string
    {
        return hash('sha256', $raw);
    }
}

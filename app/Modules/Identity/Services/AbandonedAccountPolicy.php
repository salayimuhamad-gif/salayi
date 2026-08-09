<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\TelegramVerificationToken;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\UserReferenceContract;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Who may be reclaimed, and what survives when they are.
 *
 * Account-first registration creates a row from a form submission, so rows now
 * exist that nobody ever proved they own. Two things follow. Their phone number
 * is held by a UNIQUE index, locking out the real owner; and their personal
 * data is retained on the strength of nothing.
 *
 * Reclaiming is destructive, so this class is deliberately paranoid:
 *
 *   - the table list is EXPLICIT, not discovered from relation names. A
 *     relation that is renamed or a module whose method is spelled differently
 *     would silently make a heuristic check pass, and the failure mode of a
 *     silent pass here is deleting somebody's data.
 *   - every table is verified to EXIST before it is queried. If one is
 *     missing — a partial deploy, a module removed, a migration not run — the
 *     account is NOT eligible. Unverifiable means keep.
 *   - any error at all means not eligible.
 *
 * The safe direction is always "keep the row". Keeping one too long is an
 * inconvenience; deleting one wrongly cannot be undone.
 */
final class AbandonedAccountPolicy
{
    /**
     * The blocking set now comes from {@see UserReferenceContract}, which
     * classifies EVERY user reference in the schema into exactly one of two
     * groups. The previous inline list held 22 pairs and could not notice a
     * user-owned column that was never added to it — and a missed column means
     * an account is anonymized and deleted while it still owns something.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function ownedData(): array
    {
        return UserReferenceContract::BLOCK_RECLAMATION;
    }

    public function __construct(private readonly AuditLogger $audit) {}

    public static function retentionHours(): int
    {
        return max(1, (int) config('mulkihawler.registration.unlinked_retention_hours', 72));
    }

    /**
     * @return array{eligible: bool, reason: string}
     */
    /**
     * @param  int|null  $retentionHours  the window ACTUALLY in force for this
     *                                    run. The command's --hours override
     *                                    used to reach only the candidate
     *                                    query while this method re-read the
     *                                    configured value, so an override
     *                                    shorter than the configured window
     *                                    silently did nothing. One value now
     *                                    flows through both.
     * @return array{eligible: bool, reason: string}
     */
    public function assess(User $user, ?int $retentionHours = null): array
    {
        if ($user->telegram_verified_at !== null || $user->telegram_id !== null) {
            return ['eligible' => false, 'reason' => 'linked'];
        }

        if ($user->trashed()) {
            return ['eligible' => false, 'reason' => 'already_reclaimed'];
        }

        /*
         * A REACHABLE ACCOUNT IS NOT AN ABANDONED ONE.
         *
         * This sweep was written when an unlinked account had no email and no
         * password, so the browser session that created it was genuinely the
         * only way back in. Once that session ended, the row was unreachable
         * by anybody, and reclaiming it after three days freed a phone number
         * that nobody could otherwise use.
         *
         * Neither half of that is true now. The account has a password, so its
         * owner can sign in and resume whenever they like; and its verification
         * link has no expiry, so pressing Start next month is a supported
         * journey rather than a lost cause. Deleting the account underneath
         * that link would make the no-expiry promise a lie — the token would
         * still be valid, and its account would be gone.
         *
         * So the sweep now reclaims only what is genuinely unreachable: an
         * unlinked account with no password AND no live verification token.
         * That is exactly the pre-existing population it was built for, plus
         * anyone who explicitly revoked their own link. The retention window
         * still applies to them, unchanged.
         */
        if ($user->password !== null && $user->password !== '') {
            return ['eligible' => false, 'reason' => 'reachable_by_password'];
        }

        if (TelegramVerificationToken::query()->where('user_id', $user->getKey())->usable()->exists()) {
            return ['eligible' => false, 'reason' => 'verification_pending'];
        }

        $hours = $retentionHours === null ? self::retentionHours() : max(1, $retentionHours);

        if ($user->created_at === null || $user->created_at->gt(now()->subHours($hours))) {
            return ['eligible' => false, 'reason' => 'within_retention'];
        }

        return $this->assessContent($user);
    }

    /**
     * Eligibility for an explicit, self-service abandonment: the person is
     * signed in, unlinked, and asking to release the registration so they can
     * start again. Retention does not apply — they are here and they are
     * asking — but the content check still does, because "I want to start
     * over" must never become a way to erase data.
     *
     * @return array{eligible: bool, reason: string}
     */
    public function assessSelfAbandon(User $user): array
    {
        if ($user->telegram_verified_at !== null || $user->telegram_id !== null) {
            return ['eligible' => false, 'reason' => 'linked'];
        }

        return $this->assessContent($user);
    }

    /**
     * @return array{eligible: bool, reason: string}
     */
    private function assessContent(User $user): array
    {
        foreach (self::ownedData() as [$table, $column]) {
            try {
                if (! Schema::hasTable($table)) {
                    // Cannot verify this table, so cannot claim the account is
                    // empty. Fail closed.
                    return ['eligible' => false, 'reason' => 'unverifiable:'.$table];
                }

                if (! Schema::hasColumn($table, $column)) {
                    return ['eligible' => false, 'reason' => 'unverifiable_column:'.$table];
                }

                if (DB::table($table)->where($column, $user->getKey())->exists()) {
                    return ['eligible' => false, 'reason' => 'owns:'.$table];
                }
            } catch (Throwable) {
                return ['eligible' => false, 'reason' => 'error:'.$table];
            }
        }

        return ['eligible' => true, 'reason' => 'abandoned'];
    }

    /**
     * Release the account: anonymize, then soft-delete.
     *
     * `User` soft-deletes, and a soft-deleted row KEEPS every column it had —
     * including the UNIQUE `phone_index`. Deleting alone would therefore leave
     * the phone number just as locked as before and quietly accomplish
     * nothing, while retaining the person's name indefinitely.
     *
     * What is cleared: name, phone (cipher and index), email, Telegram
     * handles, avatar, locale preference, and the remember token.
     *
     * What SURVIVES, and why:
     *   - `id`, so audit rows that reference `user_id` keep resolving. A hard
     *     delete would either break those references or cascade into an audit
     *     trail nobody agreed to lose.
     *   - `created_at` / `deleted_at`, so the retention decision itself stays
     *     auditable.
     *   - `is_active` and `preferred_locale`, neither of which identifies
     *     anyone once every reclaimed row shares the same default.
     * Nothing else is retained.
     *
     * Idempotent: an already-reclaimed row is left exactly as it is.
     */
    public function release(User $user, string $via): bool
    {
        if ($user->trashed()) {
            return false;
        }

        return DB::transaction(function () use ($user, $via): bool {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->trashed()) {
                return false;
            }

            // Re-checked under the lock: a Telegram link could have landed
            // between the assessment and this write, and reclaiming a
            // just-linked account would delete a real one.
            if ($fresh->telegram_verified_at !== null || $fresh->telegram_id !== null) {
                return false;
            }

            /*
             * The OWNED-CONTENT check is repeated here, inside the transaction
             * and after the row lock, and it is repeated in full.
             *
             * Re-checking only the link state left a time-of-check/time-of-use
             * gap: a role, a company membership, a saved search, a
             * notification or an assignment could be attached between an
             * earlier assess() and this write, and the account would then be
             * anonymized and deleted while owning content. Callers must not be
             * trusted to have checked, and an earlier check must not be
             * trusted to still hold.
             *
             * This method is therefore independently fail-closed: it refuses on
             * owned content, on an unverifiable table or column, and on any
             * error — exactly like assess(), because it calls the same code.
             */
            $recheck = $this->assessContent($fresh);

            if (! $recheck['eligible']) {
                $this->audit->security('identity.unlinked_account_release_aborted', [
                    'user_id' => $fresh->getKey(),
                    'reason' => $recheck['reason'],
                    'via' => $via,
                ]);

                return false;
            }

            $fresh->consents()->delete();

            $anonymous = [
                'name' => '',
                'email' => null,
                'phone_encrypted' => null,
                'phone_index' => null,
                'telegram_id' => null,
                'telegram_username' => null,
                'telegram_photo_path' => null,
                /*
                 * NOT NULL in the schema, so it is reset to the application
                 * default rather than cleared. A default language shared by
                 * every reclaimed row identifies nobody.
                 */
                'preferred_locale' => (string) config('app.locale', 'ckb'),
                'remember_token' => null,
            ];

            foreach (array_keys($anonymous) as $column) {
                if (! Schema::hasColumn($fresh->getTable(), $column)) {
                    unset($anonymous[$column]);
                }
            }

            $fresh->forceFill($anonymous)->save();
            $fresh->delete();

            $this->audit->security('identity.unlinked_account_reclaimed', [
                'user_id' => $fresh->getKey(),
                'via' => $via,
            ]);

            return true;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\TelegramLoginIntent;
use App\Modules\Identity\Models\TelegramVerificationToken;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\WhatsAppOtp;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Moving a Telegram identity claim between accounts — the ownership rule.
 *
 * THE PRODUCT RULE THIS IMPLEMENTS: a Telegram identity belongs to whoever can
 * currently prove control of that Telegram account. Someone who registered a
 * new MULK account and presses Start with the Telegram they used on an older
 * account is not an attacker to be refused — they are the owner, moving house.
 *
 * WHAT MOVES, EXACTLY: the identity claim. Three columns leave the old
 * account (`telegram_id`, `telegram_username`, `telegram_verified_at`) and
 * land on the new one, with a fresh `telegram_verified_at` because the proof
 * is fresh. Nothing else moves — no portfolio, no properties, no history, no
 * consents, no password, no phone, no merge, no deletion. The old account
 * keeps everything it owns; it merely loses one verification claim, and the
 * existing verified-account gate ({@see User::hasVerifiedAccount()}) decides
 * what that means: still WhatsApp-verified, it continues untouched; left with
 * no claim, it can still sign in with its password but reaches only the
 * verification flow until it verifies again — the same state every
 * account-first registration starts in, expressed with the existing columns
 * and middleware, no new status anywhere.
 *
 * WHAT A START MAY DO, AND MAY NOT: pressing Start proves control of the
 * Telegram account and PARKS a transfer candidate. It never moves the claim.
 * Only an explicit confirmation from the destination's authenticated browser
 * — carrying both the candidate handle it was shown and a deliberate
 * `accept_transfer` acknowledgement — executes the move. The refusals that
 * guard the old flows are unchanged for everything that is not this flow: a
 * spent token still cannot be replayed by a stranger, and an account already
 * linked to a different Telegram identity is still never silently re-pointed.
 *
 * THE RACE MODEL, because two accounts can both prove control (one human with
 * two MULK accounts racing themself, or a shared/hijacked Telegram):
 *
 *   - a Start is the FRESHEST proof of control, so parking a candidate
 *     WITHDRAWS every other account's parked question about the same
 *     identity ({@see sweepCompetingCandidates()}). At most one live
 *     transfer decision exists per Telegram identity at any moment, and the
 *     racer whose question was withdrawn is refused at confirm by the same
 *     candidate-handle machinery that refuses a swapped candidate — by
 *     STATE, never by comparing second-resolution timestamps, which cannot
 *     order two events inside the same second;
 *   - execution happens in ONE transaction under row locks on the intent and
 *     on BOTH user rows, the user rows always locked in ascending-id order so
 *     two concurrent transfers can never deadlock each other;
 *   - every assumption is re-checked AFTER the locks are held, on the LOCKED
 *     rows;
 *   - the UNIQUE index on `users.telegram_id` stays the final word: the old
 *     claim is cleared before the new one is written, inside the same
 *     transaction, so the same id never sits on two rows even transiently.
 *
 * WHATSAPP SYMMETRY, for whoever implements it next: this is the shape a
 * WhatsApp reassignment must take — fresh proof of control (an OTP to the
 * number, as Start is to Telegram), an explicit confirmation in the
 * destination's authenticated browser, one transaction, ordered locks,
 * re-checks, and ONLY the WhatsApp claim columns moving. The two claims stay
 * strictly separate: nothing here writes `whatsapp_verified_at`, and a
 * WhatsApp transfer must never write `telegram_verified_at`.
 */
final class TelegramOwnershipTransfer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TelegramVerificationService $verification,
    ) {}

    /**
     * Park a transfer candidate after a Start proved control of a Telegram
     * identity that currently belongs to another account.
     *
     * The intent row is the same hardened vehicle the browser-confirmation
     * flow uses — candidate columns, HMAC handle, expiry, cancellation — with
     * its own purpose so no legacy account-link path can ever pick it up. It
     * is bound to the destination ACCOUNT rather than to a browser session,
     * because the permanent verification token that carried the Start has no
     * session; the confirmation gate is the destination's authenticated
     * browser itself, which is the only place {@see confirm()} is callable
     * from.
     *
     * Parking decides nothing. Every fact it is based on is re-checked under
     * locks at confirmation time, so a stale park can mislead nobody.
     *
     * @param  array<string, mixed>  $from  Telegram's `message.from`
     */
    public function park(TelegramVerificationToken $token, User $destination, string $telegramId, array $from): TelegramLoginIntent
    {
        return DB::transaction(function () use ($token, $destination, $telegramId, $from): TelegramLoginIntent {
            /*
             * Converge on ONE pending transfer question per account. A person
             * pressing Start twice, or with a second Telegram account, must
             * always be answering the question that is actually on screen.
             */
            TelegramLoginIntent::query()
                ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_TRANSFER)
                ->where('user_id', $destination->id)
                ->whereNull('consumed_at')
                ->whereNull('cancelled_at')
                ->lockForUpdate()
                ->get()
                ->each(function (TelegramLoginIntent $stale): void {
                    $stale->forceFill([
                        'cancelled_at' => now(),
                        'result' => TelegramLoginIntent::RESULT_CANCELLED,
                    ])->save();
                });

            /*
             * Not a browser session: the fingerprint is derived from the
             * verification token that carried the Start, namespaced so it can
             * never collide with a hash of a real session id.
             */
            $intent = TelegramLoginIntent::mint(
                'transfer:vtok:'.$token->id,
                $this->locale($token->locale),
                null,
                null,
            );

            $intent->forceFill([
                'purpose' => TelegramLoginIntent::PURPOSE_ACCOUNT_TRANSFER,
                'user_id' => $destination->id,
                'candidate_telegram_id' => $telegramId,
                'candidate_username' => $this->shortText($from['username'] ?? null, 64),
                'candidate_name' => $this->candidateName($from),
                'candidate_at' => now(),
            ])->save();

            $this->sweepCompetingCandidates($telegramId, (int) $intent->id);

            $source = $this->currentOwner($telegramId, $destination->id);

            /*
             * Internal ids only — the audit trail is for support to untangle
             * deliberately. Nothing about the source account ever reaches the
             * browser: the poll and the page see the candidate's own Telegram
             * display identity and a yes/no question, never a name, phone or
             * anything else belonging to the old account.
             */
            $this->audit->record('identity.telegram_transfer_candidate', $destination, [], [
                'intent_id' => $intent->id,
                'source_user_id' => $source?->id,
            ]);

            return $intent;
        });
    }

    /**
     * The live transfer question for this account, if one is parked.
     */
    public function pending(User $destination): ?TelegramLoginIntent
    {
        return TelegramLoginIntent::query()
            ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_TRANSFER)
            ->where('user_id', $destination->id)
            ->whereNull('consumed_at')
            ->whereNull('cancelled_at')
            ->whereNotNull('candidate_telegram_id')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Execute the transfer the destination's authenticated browser confirmed.
     *
     * `$expected` is the Telegram id whose HMAC handle the browser echoed —
     * the identity the person was actually looking at. It is compared against
     * the candidate again under the intent lock, so a Start that re-parked a
     * different identity between render and click is refused exactly as the
     * link flow refuses it.
     *
     * @return array{ok: bool, reason?: string, user_id?: int}
     */
    public function confirm(User $destination, string $expected): array
    {
        if ($expected === '') {
            return ['ok' => false, 'reason' => 'not_applicable'];
        }

        try {
            return DB::transaction(function () use ($destination, $expected): array {
                $locked = TelegramLoginIntent::query()
                    ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_TRANSFER)
                    ->where('user_id', $destination->id)
                    ->whereNull('consumed_at')
                    ->whereNull('cancelled_at')
                    ->whereNotNull('candidate_telegram_id')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || ! $locked->expires_at->isFuture()) {
                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                if (! hash_equals((string) $locked->candidate_telegram_id, $expected)) {
                    // A different Start re-parked between render and click.
                    // Clear it and make the person look again.
                    $locked->forceFill([
                        'candidate_telegram_id' => null,
                        'candidate_username' => null,
                        'candidate_name' => null,
                        'candidate_at' => null,
                    ])->save();

                    $this->audit->security('identity.telegram_transfer_refused', [
                        'intent_id' => $locked->id,
                        'via' => 'candidate_changed',
                    ]);

                    return ['ok' => false, 'reason' => 'candidate_changed'];
                }

                return $this->execute($locked, $destination->id, $expected);
            });
        } catch (UniqueConstraintViolationException) {
            /*
             * The same Telegram identity landing on a different account in
             * the same instant — refused by the UNIQUE index, fully rolled
             * back, and auditable because this record lands outside the dead
             * transaction.
             */
            $this->audit->security('identity.telegram_transfer_refused', [
                'actor_id' => $destination->id,
                'via' => 'unique_constraint',
            ]);

            return ['ok' => false, 'reason' => 'conflict'];
        }
    }

    /**
     * The claim move itself. Runs inside the CALLER's transaction, which must
     * already hold a row lock on `$lockedIntent`; both flows that confirm a
     * transfer (the account-transfer intent above, the legacy session-bound
     * account-link intent in {@see TelegramRegistrar::confirmAccountLink()})
     * funnel through here so the locks, the re-checks and the audit trail
     * cannot drift apart.
     *
     * @return array{ok: bool, reason?: string, user_id?: int}
     */
    public function execute(TelegramLoginIntent $lockedIntent, int $destinationId, string $expected): array
    {
        /*
         * Advisory reads first, to learn WHICH rows to lock; every decision
         * below is made again on the locked rows. Both user rows are locked
         * in ascending-id order — always, in every code path that locks two
         * users — so two concurrent transfers serialise instead of
         * deadlocking.
         */
        $peekSource = $this->currentOwner($expected, $destinationId);

        $ids = $peekSource === null
            ? [$destinationId]
            : ($destinationId < $peekSource->id
                ? [$destinationId, $peekSource->id]
                : [$peekSource->id, $destinationId]);

        $locked = User::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $destination = $locked->get($destinationId);

        // Re-check 1: the destination still exists and may still act.
        if ($destination === null || ! $destination->mayAuthenticate()) {
            $this->audit->security('identity.telegram_transfer_refused', [
                'intent_id' => $lockedIntent->id,
                'via' => 'destination_unavailable',
            ]);

            return ['ok' => false, 'reason' => 'expired_or_consumed'];
        }

        // Re-check 2: the destination gained no Telegram identity meanwhile.
        if ($destination->telegram_id !== null) {
            if (hash_equals((string) $destination->telegram_id, $expected)) {
                // Somebody finished this exact link already — the idempotent
                // answer, and the intent completes so the poll moves on.
                $this->consume($lockedIntent, $expected);

                return ['ok' => true, 'user_id' => (int) $destination->id];
            }

            $this->audit->security('identity.telegram_transfer_refused', [
                'intent_id' => $lockedIntent->id,
                'via' => 'destination_linked_elsewhere',
            ]);

            return ['ok' => false, 'reason' => 'conflict'];
        }

        /*
         * Re-check 3: does the row the advisory read named still own the
         * claim? Decided on the LOCKED instance only — under REPEATABLE READ
         * a plain re-read here would return this transaction's snapshot, not
         * the committed present, so the locking read is the one source of
         * truth. A row this transaction has NOT locked is never consulted
         * and never chased: if ownership moved there, the paths below either
         * refuse cleanly or are stopped by the UNIQUE index.
         */
        $source = $peekSource === null ? null : $locked->get($peekSource->id);

        if ($source !== null
            && ($source->telegram_id === null || ! hash_equals((string) $source->telegram_id, $expected))) {
            /*
             * The account that owned the claim when the question was asked no
             * longer owns it — it moved, or was released, after the advisory
             * read. Refuse and let a fresh Start park a candidate against the
             * ownership as it now is.
             */
            $this->audit->security('identity.telegram_transfer_refused', [
                'intent_id' => $lockedIntent->id,
                'via' => 'owner_changed',
            ]);

            return ['ok' => false, 'reason' => 'candidate_changed'];
        }

        if ($source === null) {
            /*
             * No owner was visible when the locks were chosen: nothing to
             * transfer, so this is an ordinary link of an unclaimed identity
             * — which is what the person was trying to achieve all along. If
             * a concurrent claim this transaction cannot see does exist, the
             * UNIQUE index on `users.telegram_id` rejects the write and the
             * caller's catch translates it to the same coarse conflict.
             */
            $this->claim($destination, $lockedIntent, $expected);
            $this->consume($lockedIntent, $expected);

            $this->audit->record('identity.telegram_linked', $destination, [], [
                'intent_id' => $lockedIntent->id,
                'via' => 'transfer_confirm_unowned',
            ]);

            return ['ok' => true, 'user_id' => (int) $destination->id];
        }

        /*
         * There is deliberately no timestamp comparison here. The
         * stale-decision problem — a racer confirming a question that was
         * asked about an ownership state that no longer exists — is closed
         * at PARK time by {@see sweepCompetingCandidates()}: the moment a
         * fresh Start parks a candidate for this identity, every other
         * account's parked question about it is withdrawn, so a stale
         * confirm arrives with no candidate to match and is refused by the
         * handle machinery before this method is ever reached.
         */

        /*
         * The move. Old claim cleared FIRST, new claim written second, both
         * inside this transaction — the UNIQUE index on `users.telegram_id`
         * never sees the same identity on two rows, and a failure anywhere
         * rolls back both sides so the claim cannot be lost in the middle.
         */
        $sourceKeepsVerification = $source->whatsapp_verified_at !== null;

        $source->forceFill([
            'telegram_id' => null,
            'telegram_username' => null,
            'telegram_verified_at' => null,
        ])->save();

        $this->claim($destination, $lockedIntent, $expected);
        $this->consume($lockedIntent, $expected);

        /*
         * Two audit records, because two accounts changed. Internal ids and
         * flags only — either side's audit row must be enough for support to
         * reconstruct the move without ever having put one account's details
         * in front of the other.
         */
        $this->audit->record('identity.telegram_ownership_transferred', $destination, [], [
            'intent_id' => $lockedIntent->id,
            'source_user_id' => $source->id,
            'source_retains_verification' => $sourceKeepsVerification,
        ]);

        return ['ok' => true, 'user_id' => (int) $destination->id];
    }

    /**
     * Cancel the parked transfer question — the destination looked at it and
     * chose "no". The claim stays exactly where it is.
     */
    public function cancel(User $destination): void
    {
        DB::transaction(function () use ($destination): void {
            $locked = TelegramLoginIntent::query()
                ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_TRANSFER)
                ->where('user_id', $destination->id)
                ->whereNull('consumed_at')
                ->whereNull('cancelled_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            $source = $locked->candidate_telegram_id === null
                ? null
                : $this->currentOwner((string) $locked->candidate_telegram_id, $destination->id);

            $locked->forceFill([
                'cancelled_at' => now(),
                'result' => TelegramLoginIntent::RESULT_CANCELLED,
                'candidate_telegram_id' => null,
                'candidate_username' => null,
                'candidate_name' => null,
                'candidate_at' => null,
            ])->save();

            $this->audit->security('identity.telegram_transfer_cancelled', [
                'intent_id' => $locked->id,
                'actor_id' => $destination->id,
                'source_user_id' => $source?->id,
            ]);
        });
    }

    /**
     * Withdraw every OTHER parked question about this Telegram identity —
     * transfer intents and legacy account-link candidates alike.
     *
     * A Start is the freshest proof of control, so the question it parks is
     * the only one that may still be answered: leaving an older account's
     * parked question alive would let a stale confirmation move the claim
     * on the strength of a proof that has since been superseded. Withdrawal
     * clears ONLY the candidate columns — the competing intents themselves
     * survive, exactly as they do when a candidate is rejected, so the
     * other browser's poll simply returns to waiting for a fresh Start.
     *
     * Runs inside the caller's transaction. Two simultaneous parks for the
     * same identity can in principle sweep each other's rows in opposite
     * orders; InnoDB resolves that by rolling one webhook delivery back,
     * the inbox marks it failed, and Telegram's redelivery completes it —
     * self-healing by the same path every webhook error takes.
     */
    public function sweepCompetingCandidates(string $telegramId, int $keepIntentId): void
    {
        TelegramLoginIntent::query()
            ->where('candidate_telegram_id', $telegramId)
            ->whereKeyNot($keepIntentId)
            ->whereNull('consumed_at')
            ->whereNull('cancelled_at')
            ->update([
                'candidate_telegram_id' => null,
                'candidate_username' => null,
                'candidate_name' => null,
                'candidate_at' => null,
            ]);
    }

    /** The account currently holding this Telegram identity, if any other. */
    private function currentOwner(string $telegramId, int $exceptUserId): ?User
    {
        return User::query()
            ->where('telegram_id', $telegramId)
            ->whereKeyNot($exceptUserId)
            ->first();
    }

    /**
     * Write the claim onto the destination — the same three columns, the
     * same fresh timestamp, every path.
     */
    private function claim(User $destination, TelegramLoginIntent $intent, string $telegramId): void
    {
        $destination->forceFill([
            'telegram_id' => $telegramId,
            'telegram_username' => $intent->candidate_username,
            'telegram_verified_at' => now(),
        ])->save();

        /*
         * Telegram just won this account's verification: the road not taken
         * closes, exactly as it does when a registration Start wins. Any
         * WhatsApp code in flight is for a question that no longer exists,
         * and the account's own live verification link — pressed weeks later
         * — must not read as an invitation to a second event.
         */
        WhatsAppOtp::retireAllFor((int) $destination->id);
        $this->verification->revokeAllFor($destination, 'verified_by_transfer');
    }

    private function consume(TelegramLoginIntent $intent, string $telegramId): void
    {
        $intent->forceFill([
            'consumed_at' => now(),
            'telegram_id' => $telegramId,
            'telegram_username' => $intent->candidate_username,
            'result' => TelegramLoginIntent::RESULT_COMPLETED,
        ])->save();
    }

    /** One of the three languages this platform speaks, or the site default. */
    private function locale(?string $locale): string
    {
        return in_array($locale, ['ckb', 'ar', 'en'], true)
            ? $locale
            : (string) config('app.locale', 'ckb');
    }

    /**
     * A display name for the candidate, assembled from what Telegram sent and
     * bounded. Shown so the person can recognise their own account — never
     * trusted for anything else.
     *
     * @param  array<string, mixed>  $from
     */
    private function candidateName(array $from): ?string
    {
        $name = trim(implode(' ', array_filter([
            is_string($from['first_name'] ?? null) ? $from['first_name'] : null,
            is_string($from['last_name'] ?? null) ? $from['last_name'] : null,
        ])));

        return $this->shortText($name === '' ? null : $name, 120);
    }

    private function shortText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1f]+/u', '', $value));

        return $clean === '' ? null : mb_substr($clean, 0, $limit);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Leads\Services;

use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\User;
use App\Modules\Leads\Enums\RevealReason;
use App\Modules\Leads\Models\PhoneReveal;
use App\Modules\Leads\Support\ConsentGate;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phone Reveal (File one §9 Leads.3, §9 Leads.5).
 *
 * The single most sensitive operation in the platform: turning an encrypted
 * column into a number on a salesperson's screen.
 *
 * §9 requires four things at once — permission, consent, reason and audit — and
 * the order matters. Each is checked BEFORE the value is decrypted, so a refused
 * reveal never decrypts anything at all. Checking afterwards would mean the
 * plaintext existed in memory, and in a stack trace, for a request that had
 * already been denied.
 *
 * §9 Leads.5 is stated as an absolute: *never expose contact details without
 * valid consent.* This class is the only sanctioned path to a number, and it
 * refuses rather than degrades. There is deliberately no "reveal anyway" flag
 * and no Super-Admin override: an override exists to be used, and the first
 * time it is used on a person who withdrew consent, this product has broken a
 * promise it made in writing at the moment they gave it.
 */
final class PhoneRevealService
{
    /** A single agent may not exceed this many reveals in an hour. */
    private const HOURLY_LIMIT = 40;

    public function __construct(
        private readonly ConsentGate $consent,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Reveal a subject's phone number, or explain why not.
     *
     * Correction v4, BLOCKER 2 — ONE transactionally consistent decision.
     *
     * The v3 shape counted reveals, read consent, decided, found the owner
     * and DECRYPTED THE NUMBER, and only then opened a transaction to write
     * the ledger. Five separate failures lived in that gap: consent could be
     * withdrawn between the check and the record; forty parallel requests
     * could each observe thirty-nine and all proceed; the consent row backing
     * the decision could change before its id was resolved; the plaintext
     * existed in memory before any durable evidence that it had been
     * released; and a ledger or audit failure happened with the number
     * already decrypted.
     *
     * Everything that decides now happens inside ONE transaction, in this
     * order, and the order is the design:
     *
     *   1. The ACTOR row is locked. That is the per-actor rate-limit mutex —
     *      parallel reveals by the same agent serialise here, so the count
     *      each one reads includes every reveal the others have committed.
     *      Locking the actor rather than a counter table means no schema
     *      change and no second thing to keep in step.
     *   2. Permission is re-read from the LOCKED actor, not from the
     *      possibly-stale model the caller passed in.
     *   3. The SUBJECT user row is locked, and the company scope is
     *      re-checked against it — a lead reassigned between the
     *      controller's authorisation and this moment is refused here.
     *   4. The consent rows are locked and the gate decides on THAT read.
     *      The id stored on the ledger is resolved from the same locked
     *      set, so the row recorded is provably the row relied upon.
     *   5. The ledger row and the audit record are written.
     *   6. ONLY THEN is anything decrypted.
     *
     * Failure semantics, stated so they are testable rather than emergent:
     * any exception — ledger insert, audit write, decryption — rolls the
     * whole transaction back and returns NO phone. The audit record is
     * inside the transaction on purpose. An unauditable reveal is not a
     * reveal this product is willing to perform, so audit failure is
     * fail-closed, not best-effort.
     *
     * @return array{ok: bool, phone?: string, reason?: string, reveal_id?: int}
     */
    public function reveal(
        User $actor,
        Model $subject,
        RevealReason $reason,
        ?string $note = null,
        ?int $companyId = null,
        ?string $ipHash = null,
        string $permission = 'leads.contact',
    ): array {
        /*
         * REASON is the one check that stays outside: it inspects only the
         * arguments, touches no shared state, and refusing early keeps a
         * malformed request from taking row locks at all.
         */
        if ($reason->requiresNote() && trim((string) $note) === '') {
            return ['ok' => false, 'reason' => 'reason_note_required'];
        }

        try {
            $outcome = DB::transaction(function () use (
                $actor, $subject, $reason, $note, $companyId, $ipHash, $permission
            ): array {
                // 1 + 2. The per-actor mutex, and permission re-read from it.
                $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();

                if ($lockedActor === null || ! $lockedActor->hasPermission($permission)) {
                    return ['ok' => false, 'reason' => 'no_permission', 'refuse' => 'no_permission'];
                }

                /*
                 * 3. THE SUBJECT ROW ITSELF, LOCKED (v5, BLOCKER 4).
                 *
                 * v4 locked the actor and the OWNER but re-read the lead
                 * with a plain `first()`, so a reassignment could land
                 * between that read and commit, and — worse — the owner
                 * id was derived from the CALLER'S stale model before the
                 * transaction even opened. A lead repointed to a different
                 * user after the controller's authorisation would have had
                 * the WRONG user's consent read under lock.
                 *
                 * Everything below now derives from this one locked row: a
                 * concurrent reassignment either committed before this
                 * lock (and is seen and refused) or waits behind this
                 * transaction (and applies to the next reveal, not this
                 * one). When the subject IS the user, the lock is taken
                 * once in step 4 rather than twice here.
                 */
                $lockedSubject = $subject->newQuery()
                    ->whereKey($subject->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedSubject === null) {
                    // Deleted between authorisation and now. Nothing to
                    // reveal, nothing to ledger.
                    return ['ok' => false, 'reason' => 'no_subject_account', 'refuse' => 'subject_missing'];
                }

                /*
                 * The owner relation must still be the one the caller was
                 * authorised against. A lead whose user_id moved is a
                 * different person's consent, and proceeding would read
                 * (and lock) that stranger's rows on the strength of an
                 * authorisation performed for somebody else.
                 */
                if (! ($subject instanceof User)
                    && (string) $lockedSubject->getAttribute('user_id') !== (string) $subject->getAttribute('user_id')) {
                    return ['ok' => false, 'reason' => 'out_of_scope', 'refuse' => 'subject_owner_changed'];
                }

                if (! $this->companyScopeHolds($lockedSubject, $companyId)) {
                    return ['ok' => false, 'reason' => 'out_of_scope', 'refuse' => 'out_of_scope'];
                }

                $subjectUserId = $this->subjectUserId($lockedSubject);

                if ($subjectUserId === null) {
                    return ['ok' => false, 'reason' => 'no_subject_account', 'refuse' => 'no_subject_account'];
                }

                // 4. The owner account — derived from the LOCKED subject,
                //    never from the stale argument. A User subject IS its
                //    own owner and is already locked above; anything else
                //    takes the owner's row lock here.
                $owner = $lockedSubject instanceof User
                    ? $lockedSubject
                    : User::query()->whereKey($subjectUserId)->lockForUpdate()->first();

                if ($owner === null) {
                    return ['ok' => false, 'reason' => 'no_subject_account', 'refuse' => 'no_subject_account'];
                }

                // 4. RATE, under the actor lock. A legitimate agent does not
                //    need forty numbers an hour, and an exfiltration does.
                if ($this->recentRevealCount($lockedActor) >= self::HOURLY_LIMIT) {
                    return ['ok' => false, 'reason' => 'rate_limited', 'refuse' => 'rate_limited'];
                }

                /*
                 * 5. CONSENT, on LOCKED rows. Locking the consent rows is
                 *    what closes the withdraw-during-reveal window: a
                 *    withdrawal in flight either commits before this read
                 *    (and is seen) or waits behind this transaction (and
                 *    applies to the next reveal, not this one).
                 */
                $consentRows = Consent::query()
                    ->where('user_id', $subjectUserId)
                    ->lockForUpdate()
                    ->get();

                $decision = $this->consent->mayContact(
                    'company_contact',
                    $consentRows->map(static fn (Consent $c): array => [
                        'type' => $c->type,
                        'granted' => (bool) $c->granted,
                        'granted_at' => $c->granted_at?->toIso8601String(),
                        'withdrawn_at' => $c->withdrawn_at?->toIso8601String(),
                    ])->all(),
                );

                if ($decision['allowed'] !== true) {
                    $refusal = 'consent_'.($decision['reason'] ?? 'denied');

                    return [
                        'ok' => false,
                        'reason' => $decision['reason'] ?? 'consent_denied',
                        'refuse' => $refusal,
                    ];
                }

                /*
                 * A number that is not there is not a refusal to audit as a
                 * denial, and it must not produce a ledger row claiming a
                 * reveal happened. Checked on the ENCRYPTED column, so this
                 * costs no decryption.
                 */
                if ($owner->phone_encrypted === null) {
                    return ['ok' => false, 'reason' => 'no_phone_on_record'];
                }

                // 6. The evidence, from the same locked rows the decision
                //    used — the SUBJECT identity included (v5, BLOCKER 4):
                //    the ledger names the row this transaction held, not
                //    the argument the caller happened to pass.
                $reveal = PhoneReveal::query()->create([
                    'user_id' => $lockedActor->id,
                    'subject_type' => $lockedSubject->getMorphClass(),
                    'subject_id' => $lockedSubject->getKey(),
                    'reason_code' => $reason->value,
                    'reason_note' => $note,
                    'consent_id' => $this->lockedConsentId($consentRows, $decision['consent_type'] ?? null),
                    'ip_hash' => $ipHash,
                    'company_id' => $companyId,
                    'revealed_at' => now(),
                ]);

                /*
                 * Audited as well as ledgered, INSIDE the transaction. The
                 * ledger answers "who saw whose number and why"; the audit
                 * log is the tamper-evident stream a security reviewer reads
                 * alongside everything else. The number is in neither, and if
                 * either write fails nobody sees a number at all.
                 */
                $this->audit->record('leads.phone_revealed', $lockedSubject, [], [
                    'reveal_id' => $reveal->id,
                    'reason' => $reason->value,
                    'actor_id' => $lockedActor->id,
                ], severity: 'warning');

                // 7. LAST. The plaintext exists only after the release of it
                //    has been durably recorded.
                $phone = $owner->phone();

                if ($phone === null || $phone === '') {
                    /*
                     * Undecryptable despite a stored ciphertext — a key
                     * rotation problem, not a policy one. Roll back so no
                     * ledger row claims a reveal that never happened.
                     */
                    throw new UndecryptablePhone;
                }

                return ['ok' => true, 'phone' => $phone, 'reveal_id' => (int) $reveal->id];
            });
        } catch (UndecryptablePhone) {
            return ['ok' => false, 'reason' => 'no_phone_on_record'];
        } catch (Throwable $exception) {
            /*
             * The ledger or the audit failed and the transaction is gone.
             * The refusal is recorded OUTSIDE the dead transaction, and the
             * exception class travels rather than its message — a message
             * can carry a bound parameter, and a bound parameter here could
             * be a phone number.
             */
            $this->refuse($actor, $subject, 'write_failed');

            Log::channel('security')->error('leads.phone_reveal_write_failed', [
                'actor_id' => $actor->getKey(),
                'exception' => $exception::class,
            ]);

            return ['ok' => false, 'reason' => 'unavailable'];
        }

        /*
         * Refusals are audited AFTER the transaction commits. Inside it they
         * would share the fate of whatever else the transaction did; a
         * refusal is a fact regardless, and the stream of them is the
         * clearest signal of a misconfigured role or somebody probing.
         */
        if (isset($outcome['refuse'])) {
            $this->refuse($actor, $subject, (string) $outcome['refuse']);
            unset($outcome['refuse']);
        }

        return $outcome;
    }

    /**
     * Whether the LOCKED subject is still inside the company the caller
     * was authorised against.
     *
     * Correction v5, BLOCKER 4. The v4 predicate re-read the row without
     * a lock and then accepted `current === null` — so a lead UNASSIGNED
     * mid-flight passed a check whose whole purpose was to notice exactly
     * that, and a reassignment could still land between the read and the
     * commit. Two rules replace it, both evaluated on the row this
     * transaction holds locked:
     *
     *   A model with NO company column (a bare user account) is not
     *   company-scoped: there is nothing to drift, and the permission
     *   check is the whole of its authorisation.
     *
     *   A model WITH the column must match EXACTLY. Null is not a match:
     *   when the caller was authorised against company N, a row whose
     *   company became null was unassigned after that authorisation, and
     *   "nobody's lead" is not "this company's lead". Reassigned and
     *   unassigned rows are refused alike.
     *
     * `$companyId === null` (a caller not acting for any company — the
     * platform-admin path) applies no company gate; `hasPermission()` on
     * the locked actor remains its authorisation, unchanged from v4.
     */
    private function companyScopeHolds(Model $lockedSubject, ?int $companyId): bool
    {
        if ($companyId === null) {
            return true;
        }

        if (! array_key_exists('company_id', $lockedSubject->getAttributes())) {
            // No company column on this row: not a company-scoped model.
            return true;
        }

        $current = $lockedSubject->getAttribute('company_id');

        return $current !== null && (int) $current === $companyId;
    }

    /**
     * The consent row that authorised this reveal, resolved from the rows
     * that were LOCKED and READ for the decision — never re-queried.
     *
     * Re-querying was the v3 defect: the decision could be made on one row
     * and the id recorded from another that arrived in between. Null stays
     * acceptable and honest — a transactional exemption authorises contact
     * without any consent row existing, and a fabricated id would be worse
     * than none.
     *
     * @param  Collection<int, Consent>  $rows
     */
    private function lockedConsentId(Collection $rows, ?string $consentType): ?int
    {
        if ($consentType === null) {
            return null;
        }

        $match = $rows
            ->filter(static fn (Consent $c): bool => $c->type === $consentType
                && (bool) $c->granted === true
                && $c->withdrawn_at === null)
            ->sortByDesc(static fn (Consent $c): string => (string) $c->granted_at)
            ->first();

        return $match === null ? null : (int) $match->getKey();
    }

    /**
     * Record a refusal.
     *
     * Refusals are logged as loudly as successes. A stream of denied reveals is
     * the clearest signal of either a misconfigured role or somebody probing,
     * and neither is visible if only the successes are recorded.
     */
    private function refuse(User $actor, Model $subject, string $reason): void
    {
        $this->audit->security('leads.phone_reveal_refused', [
            'actor_id' => $actor->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'reason' => $reason,
        ]);
    }

    private function recentRevealCount(User $actor): int
    {
        return PhoneReveal::query()
            ->where('user_id', $actor->id)
            ->where('revealed_at', '>=', now()->subHour())
            ->count();
    }

    /**
     * The account whose consent governs this subject.
     *
     * A lead may exist without an account — an imported enquiry, a walk-in —
     * and in that case there is no consent to rely on and no reveal to make.
     * Returning null is the honest answer rather than falling back to the
     * lead's own stored number.
     */
    private function subjectUserId(Model $subject): ?int
    {
        if ($subject instanceof User) {
            return (int) $subject->getKey();
        }

        $userId = $subject->getAttribute('user_id');

        return $userId === null ? null : (int) $userId;
    }
}

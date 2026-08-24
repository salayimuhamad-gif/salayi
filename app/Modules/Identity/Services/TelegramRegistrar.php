<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\TelegramLoginIntent;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Registration by Telegram Start — the product's chosen sign-up model.
 *
 * The trust statement, spelled out because everything below enforces it:
 * pressing Start proves control of a TELEGRAM ACCOUNT. It proves nothing about
 * the phone number typed on the website. This service therefore sets
 * `telegram_verified_at` and NEVER `phone_verified` — the typed number is
 * stored as user-provided contact data, encrypted with the same primitives the
 * rest of the platform uses, and labelled honestly everywhere it appears.
 *
 * The Share-Contact login flow in {@see TelegramAuthenticator} continues to
 * exist and continues to be the one place a phone can become verified, because
 * a self-shared contact actually proves possession. The two flows share the
 * intent table and the webhook; they deliberately do not share a trust level.
 */
final class TelegramRegistrar
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TelegramOwnershipTransfer $transfer,
    ) {}

    /**
     * Create an account-first registration: the account exists and is signed
     * in BEFORE any Telegram identity is attached (v7 product change).
     *
     * The security property this preserves is the one that mattered in the
     * Telegram-first model: pressing Start proves control of a Telegram
     * account, and nothing else does. So this method creates an account that
     * is deliberately POWERLESS — `telegram_id` and `telegram_verified_at`
     * stay null, and every gated surface still refuses it. What changes is
     * only WHEN the row appears, not what it may do.
     *
     * `phone_verified` remains false for the same reason as before: a typed
     * number proves nothing, and now proves even less, because nothing at all
     * was verified at the moment of creation.
     *
     * Duplicate phone numbers are refused rather than merged or reclaimed. A
     * name and a phone number are not a credential — anyone can type someone
     * else's — so signing a visitor into an existing account on that basis
     * would be an account takeover with extra steps. The refusal reason is
     * coarse for the same non-enumeration reason the Start conflict is coarse:
     * the audit log knows which identifier collided, the browser does not.
     *
     * THE ACCOUNT NOW HAS A PASSWORD, and that is what makes Telegram a
     * one-time step rather than a permanent login mechanism. Before this, an
     * account created here had no email and no password, so the only way back
     * into it was another Telegram Start — which is precisely the "Telegram on
     * every login" behaviour the simplified flow exists to remove. The password
     * is hashed by the model's `hashed` cast; the plaintext exists for the
     * duration of one request and is never logged, audited or echoed.
     *
     * @param  array{name: string, phone: string, password: string, locale: string, consent_contact: bool}  $data
     * @return array{ok: bool, user?: User, reason?: string}
     */
    public function createUnlinkedAccount(array $data, ?string $ipHash, ?string $agentHash): array
    {
        $phone = $this->normalise($data['phone']);
        $index = User::blindIndex($phone);

        try {
            return DB::transaction(function () use ($data, $phone, $index, $ipHash, $agentHash): array {
                /*
                 * Checked inside the transaction, and backed by the UNIQUE
                 * index on `phone_index` — the check is for a clean answer,
                 * the constraint is for correctness under a race.
                 */
                if (User::query()->where('phone_index', $index)->lockForUpdate()->exists()) {
                    $this->audit->security('identity.account_first_registration_conflict', [
                        'phone_index_taken' => true,
                    ]);

                    return ['ok' => false, 'reason' => 'conflict'];
                }

                $user = new User;
                $user->fill([
                    'name' => $data['name'],
                    /*
                     * No email — it stays genuinely optional and is offered
                     * later, during profile completion, where somebody can
                     * decide whether they want a recovery address at all.
                     * A password, however, is set NOW: it is the credential
                     * that makes every later visit an ordinary sign-in.
                     */
                    'email' => null,
                    'password' => $data['password'],
                    'preferred_locale' => $data['locale'],
                    'is_active' => true,
                ]);

                $user->phone_encrypted = Crypt::encryptString($phone);
                $user->phone_index = $index;

                /*
                 * Spelled out rather than left to defaults, because these
                 * three nulls ARE the product change and a future default
                 * flipping underneath them would be silent.
                 */
                $user->forceFill([
                    'telegram_id' => null,
                    'telegram_username' => null,
                    'telegram_verified_at' => null,
                ])->save();

                $this->recordDirectConsent($user, $data['locale'], $ipHash, $agentHash, 'account_registration', true);
                $this->recordDirectConsent($user, $data['locale'], $ipHash, $agentHash, 'company_contact', $data['consent_contact']);

                $this->audit->record('identity.registered_account_first', $user, [], [
                    'linked' => false,
                ]);

                return ['ok' => true, 'user' => $user];
            });
        } catch (UniqueConstraintViolationException) {
            // The race the pre-check cannot close: two submissions for the
            // same number in the same instant. Same coarse answer.
            $this->audit->security('identity.account_first_registration_conflict', [
                'phone_index_taken' => true,
                'via' => 'unique_constraint',
            ]);

            return ['ok' => false, 'reason' => 'conflict'];
        }
    }

    /**
     * Consent evidence for an account created without an intent to hang it on.
     *
     * Deliberately a separate method rather than a nullable parameter on
     * {@see recordConsent()}: that one records what a Start proved, this one
     * records what a form said, and the `method` field must not lie about
     * which happened.
     */
    private function recordDirectConsent(
        User $user,
        string $locale,
        ?string $ipHash,
        ?string $agentHash,
        string $type,
        bool $granted,
    ): void {
        Consent::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'granted' => $granted,
            'source' => 'registration_form',
            'evidence' => [
                'policy_version' => (string) config('mulkihawler.registration.policy_version', '2026-08-01'),
                'method' => 'account_first_registration',
            ],
            'ip_hash' => $ipHash,
            'user_agent_hash' => $agentHash,
            'locale' => $locale,
            'granted_at' => $granted ? now() : null,
            'withdrawn_at' => $granted ? null : now(),
        ]);
    }

    /**
     * Mint a registration intent carrying everything account creation needs.
     *
     * The phone is encrypted and blind-indexed HERE, at intake, so the
     * plaintext number exists for exactly one request. The intent row is the
     * hand-off between the browser (which knows the typed data) and the
     * webhook (which knows the Telegram identity); neither alone can create
     * the account, which is the point.
     *
     * @param  array{name: string, phone: string, locale: string, consent_contact: bool}  $data
     */
    public function begin(array $data, string $sessionId, ?string $ipHash, ?string $agentHash): TelegramLoginIntent
    {
        $phone = $this->normalise($data['phone']);

        /*
         * Correction H5: ONE atomic creation. Mint-then-fill used to be two
         * writes; a failure between them left a blank intent with a live
         * token. Inside a transaction, either the complete registration
         * intent exists or nothing does. Prior pending registrations for
         * this same session are cancelled first, so multiple tabs and
         * re-submissions converge on ONE live intent — the same convergence
         * the account-link flow uses.
         */
        return DB::transaction(function () use ($data, $sessionId, $ipHash, $agentHash, $phone): TelegramLoginIntent {
            TelegramLoginIntent::query()
                ->where('purpose', TelegramLoginIntent::PURPOSE_REGISTRATION)
                ->where('session_fingerprint', TelegramLoginIntent::fingerprint($sessionId))
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

            $intent = TelegramLoginIntent::mint($sessionId, $data['locale'], $ipHash, $agentHash);

            $intent->forceFill([
                'purpose' => TelegramLoginIntent::PURPOSE_REGISTRATION,
                'registration_name' => $data['name'],
                'registration_phone_encrypted' => Crypt::encryptString($phone),
                'registration_phone_index' => User::blindIndex($phone),
                // Terms acceptance is required to submit the form at all, so a
                // minted intent implies it; the contact consent is a separate,
                // genuinely optional decision and is recorded as given.
                'consent_terms' => true,
                'consent_contact' => $data['consent_contact'],
                'consent_policy_version' => (string) config('mulkihawler.registration.policy_version', '2026-08-01'),
            ])->save();

            $this->audit->security('identity.registration_started', [
                'intent_id' => $intent->id,
            ]);

            return $intent;
        });
    }

    /**
     * Redeem a registration Start.
     *
     * Called from the webhook once the secret header and the replay ledger
     * have both passed, with the raw `from` block Telegram sent.
     *
     * Conflicts NEVER merge. A phone index or a Telegram id that already
     * belongs to an account means somebody's data is at stake, and the safe
     * answers are: refuse, tell the person something generic, and leave an
     * audit trail specific enough for support to untangle deliberately.
     *
     * @param  array{id?: int|string, username?: string|null, first_name?: string|null, last_name?: string|null}  $from
     * @return array{ok: bool, reason?: string, user_id?: int}
     */
    /**
     * Redeem a registration Start — ONE atomic database operation.
     *
     * Everything that decides or changes state happens inside a single
     * transaction under a row lock on the intent: usability, conflict
     * checks, consumption, account creation, linking, consent evidence and
     * the audit record. The shape exists because of the failure this
     * replaces: consumption used to commit BEFORE the transaction, so a
     * failure during user creation left the person holding a permanently
     * dead intent. Now a failure anywhere rolls back everything —
     * consumption included — and pressing Start again simply works.
     *
     * Concurrency, in three layers. The lock serialises deliveries of the
     * SAME Start: the second parks until the first commits, then sees the
     * consumed row. The in-lock conflict checks answer for everything the
     * lock can see. And the UNIQUE indexes on users.phone_index and
     * users.telegram_id are the final word for races the lock cannot see —
     * a simultaneous registration of the same number through a DIFFERENT
     * intent — surfacing as a constraint violation that rolls this
     * transaction back and is translated to the same coarse refusal.
     *
     * Telegram redelivers updates, so redemption is IDEMPOTENT for the
     * winning sender: the same account pressing the same already-redeemed
     * Start receives the same success instead of an error.
     *
     * @param  array<string, mixed>  $from
     *                                      v6: `pending_confirmation` is genuinely returned when a Start parks a
     *                                      candidate awaiting the browser gate; omitting it from the annotation
     *                                      made every caller's check read as dead code.
     * @return array{ok: bool, reason?: string, user_id?: int, replayed?: bool, pending_confirmation?: bool}
     */
    public function redeemStart(TelegramLoginIntent $intent, array $from): array
    {
        $telegramId = (string) ($from['id'] ?? '');

        if ($telegramId === '') {
            return ['ok' => false, 'reason' => 'no_sender'];
        }

        try {
            return DB::transaction(function () use ($intent, $telegramId, $from): array {
                $locked = TelegramLoginIntent::query()
                    ->whereKey($intent->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->purpose !== TelegramLoginIntent::PURPOSE_REGISTRATION) {
                    $this->audit->security('identity.registration_intent_unusable', ['intent_id' => $intent->id]);

                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                if ($locked->consumed_at !== null) {
                    /*
                     * Redelivery of a Start that already won: same sender,
                     * same answer. Anyone ELSE presenting a consumed token
                     * gets the coarse refusal.
                     */
                    if ($locked->user_id !== null
                        && $locked->telegram_id !== null
                        && hash_equals((string) $locked->telegram_id, $telegramId)) {
                        return ['ok' => true, 'user_id' => (int) $locked->user_id, 'replayed' => true];
                    }

                    $this->audit->security('identity.registration_intent_unusable', ['intent_id' => $locked->id]);

                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                if ($locked->cancelled_at !== null || ! $locked->expires_at->isFuture()) {
                    $this->audit->security('identity.registration_intent_unusable', ['intent_id' => $locked->id]);

                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                /*
                 * Conflict checks inside the lock window. A refusal here
                 * deliberately leaves the intent alive (this return commits
                 * a transaction that changed nothing but the audit row): the
                 * refusal is auditable and the person keeps their intent.
                 * The response reason stays coarse — WHICH identifier
                 * collided is for the audit log, never for whoever is
                 * holding the phone.
                 */
                $phoneTaken = User::query()->where('phone_index', $locked->registration_phone_index)->exists();
                $telegramTaken = User::query()->where('telegram_id', $telegramId)->exists();

                if ($phoneTaken || $telegramTaken) {
                    /*
                     * H5: the browser must be able to STOP pending. `result`
                     * says only that a conflict happened — WHICH identifier
                     * collided stays in the audit log, never in anything the
                     * poll can read. The intent is deliberately not consumed.
                     */
                    $locked->forceFill(['result' => TelegramLoginIntent::RESULT_CONFLICT])->save();

                    $this->audit->security('identity.registration_conflict', [
                        'intent_id' => $locked->id,
                        'phone_index_taken' => $phoneTaken,
                        'telegram_id_taken' => $telegramTaken,
                    ]);

                    return ['ok' => false, 'reason' => 'conflict'];
                }

                /*
                 * From here on, everything mutates — and everything is inside
                 * THIS transaction, consumption first so no path can create
                 * an account off an unconsumed intent.
                 */
                $locked->forceFill([
                    'consumed_at' => now(),
                    'telegram_id' => $telegramId,
                    'telegram_username' => $from['username'] ?? null,
                ])->save();

                $user = new User;
                $user->fill([
                    'name' => (string) $locked->registration_name,
                    // No email, no password: this account\'s way in is Telegram.
                    'email' => null,
                    'preferred_locale' => $locked->locale,
                    'is_active' => true,
                ]);

                /*
                 * The typed number, moved from the intent to the account under
                 * the SAME encryption. `phone_verified` is intentionally
                 * untouched — it defaults to false and stays false, because
                 * nothing here proved ownership of it.
                 */
                $user->phone_encrypted = $locked->registration_phone_encrypted;
                $user->phone_index = $locked->registration_phone_index;

                $user->forceFill([
                    'telegram_id' => $telegramId,
                    'telegram_username' => $from['username'] ?? null,
                    'telegram_verified_at' => now(),
                ])->save();

                $locked->forceFill(['user_id' => $user->id])->save();

                /*
                 * Consent evidence, recorded at the moment the account exists
                 * to attach it to. Two rows because they are two decisions:
                 * the terms were a precondition, the contact permission was a
                 * choice.
                 */
                $this->recordConsent($user, $locked, 'account_registration', true);
                $this->recordConsent($user, $locked, 'company_contact', (bool) $locked->consent_contact);

                $this->audit->record('identity.registered_via_telegram', $user, [], [
                    'intent_id' => $locked->id,
                ]);

                return ['ok' => true, 'user_id' => (int) $user->id];
            });
        } catch (UniqueConstraintViolationException) {
            /*
             * A race the lock could not see — the same number or the same
             * Telegram account winning through a different intent in the same
             * instant. The transaction has rolled back completely, this
             * intent included, so it is STILL USABLE; the audit lands outside
             * the dead transaction so the refusal survives it.
             */
            $this->audit->security('identity.registration_conflict', [
                'intent_id' => $intent->id,
                'via' => 'unique_constraint',
            ]);

            return ['ok' => false, 'reason' => 'conflict'];
        }
    }

    /**
     * Begin — or RESUME — linking Telegram to an existing signed-in account.
     *
     * Correction v4, BLOCKER 1. The v3 version minted a fresh intent on
     * every page load and cancelled the previous one, which meant a refresh,
     * a mobile tab eviction, a second tab or a back-button navigation all
     * silently killed the token the person had ALREADY opened in Telegram.
     * They then pressed Start on a dead link and were told it failed. The
     * registration flow was resumable; this one was not, for no reason.
     *
     * The rule now, stated once:
     *
     *   A live, unconsumed, uncancelled, unexpired, unresolved link intent
     *   belonging to THIS user and THIS session is REUSED verbatim — same
     *   row, same token, same deep link, same expiry. Nothing about
     *   re-rendering the page invalidates it.
     *
     *   A new intent is minted only when there is no such intent, or when
     *   the caller explicitly asks ($restart = true), which is the Restart
     *   button and nothing else.
     *
     * Session scoping is unchanged and is still the security spine: the
     * lookup is keyed on the session fingerprint, so a DIFFERENT browser
     * session cannot resume, observe or control this session's intent — it
     * gets its own, and the older session's pending intents are retired
     * because only one Telegram identity can ever land on one account.
     *
     * The user row is taken as the per-user mutex so two tabs arriving in
     * the same millisecond serialise here instead of both minting.
     */
    public function resumeOrBeginAccountLink(
        User $user,
        string $sessionId,
        ?string $ipHash,
        ?string $agentHash,
        bool $restart = false,
    ): TelegramLoginIntent {
        return DB::transaction(function () use ($user, $sessionId, $ipHash, $agentHash, $restart): TelegramLoginIntent {
            // Per-user mutex. Two tabs, two requests, one winner deciding
            // whether a live intent exists.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $fingerprint = TelegramLoginIntent::fingerprint($sessionId);

            if (! $restart) {
                $live = TelegramLoginIntent::query()
                    ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_LINK)
                    ->where('user_id', $user->id)
                    ->where('session_fingerprint', $fingerprint)
                    ->whereNull('consumed_at')
                    ->whereNull('cancelled_at')
                    ->whereNull('result')
                    ->where('expires_at', '>', now())
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($live !== null) {
                    /*
                     * Resume. Deliberately NO audit record: a page refresh
                     * is not an event, and writing one per render would
                     * turn the security log into a page-view counter.
                     */
                    return $live;
                }
            }

            /*
             * Nothing live to resume (or an explicit restart). Retire every
             * pending link intent for this user — including other sessions'
             * — before minting, because an account links to exactly one
             * Telegram identity and two live tokens for the same account is
             * a race waiting to be lost.
             */
            TelegramLoginIntent::query()
                ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_LINK)
                ->where('user_id', $user->id)
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

            $intent = TelegramLoginIntent::mint($sessionId, $user->preferred_locale ?? app()->getLocale(), $ipHash, $agentHash);
            $intent->forceFill([
                'purpose' => TelegramLoginIntent::PURPOSE_ACCOUNT_LINK,
                'user_id' => $user->id,
            ])->save();

            $this->audit->record('identity.telegram_link_started', $user, [], [
                'intent_id' => $intent->id,
                'restart' => $restart,
            ]);

            return $intent;
        });
    }

    /**
     * The pre-v4 name, kept so nothing outside this class has to know the
     * flow became resumable. It always restarts, which is what its old
     * callers meant.
     *
     * @deprecated v4 — call {@see resumeOrBeginAccountLink()} instead.
     */
    public function beginAccountLink(User $user, string $sessionId, ?string $ipHash, ?string $agentHash): TelegramLoginIntent
    {
        return $this->resumeOrBeginAccountLink($user, $sessionId, $ipHash, $agentHash, restart: true);
    }

    /**
     * Redeem an account-link Start — the C1 flow, to the registration
     * standard: ONE transaction, row locks on the intent AND the bound
     * user, every check inside, consumption inside, idempotent replay for
     * the winner, generic refusal for everyone else.
     *
     * The rules the review demanded, each enforced here:
     *   - links the Telegram identity to the BOUND user only;
     *   - never creates a second user, never merges;
     *   - a Telegram ID already on another account refuses (result:
     *     conflict, visible to the poll) without consuming;
     *   - an account already linked to a DIFFERENT Telegram identity
     *     refuses the same way — no silent repoint;
     *   - an inactive or suspended account cannot link;
     *   - the same sender pressing the same redeemed Start replays.
     *
     * @param  array<string, mixed>  $from
     *                                      v6: `pending_confirmation` is genuinely returned when a Start parks a
     *                                      candidate awaiting the browser gate; omitting it from the annotation
     *                                      made every caller's check read as dead code.
     * @return array{ok: bool, reason?: string, user_id?: int, replayed?: bool, pending_confirmation?: bool}
     */
    public function redeemAccountLink(TelegramLoginIntent $intent, array $from): array
    {
        $telegramId = (string) ($from['id'] ?? '');

        if ($telegramId === '') {
            return ['ok' => false, 'reason' => 'no_sender'];
        }

        try {
            return DB::transaction(function () use ($intent, $telegramId, $from): array {
                $locked = TelegramLoginIntent::query()
                    ->whereKey($intent->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->purpose !== TelegramLoginIntent::PURPOSE_ACCOUNT_LINK) {
                    return ['ok' => false, 'reason' => 'not_applicable'];
                }

                if ($locked->consumed_at !== null) {
                    if ($locked->telegram_id !== null
                        && hash_equals((string) $locked->telegram_id, $telegramId)) {
                        return ['ok' => true, 'user_id' => (int) $locked->user_id, 'replayed' => true];
                    }

                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                if ($locked->cancelled_at !== null || ! $locked->expires_at->isFuture()) {
                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                // The bound account, under its own row lock: two Starts for
                // the same person serialise here.
                $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->first();

                if ($user === null || ! $user->mayAuthenticate()) {
                    $this->audit->security('identity.telegram_link_refused', [
                        'intent_id' => $locked->id,
                        'via' => 'account_unavailable',
                    ]);

                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                if ($user->telegram_id !== null) {
                    if (hash_equals((string) $user->telegram_id, $telegramId)) {
                        // Already linked to exactly this Telegram account:
                        // finishing is a no-op success, and the intent is
                        // consumed so the poll completes.
                        $locked->forceFill([
                            'consumed_at' => now(),
                            'telegram_id' => $telegramId,
                            'result' => TelegramLoginIntent::RESULT_COMPLETED,
                        ])->save();

                        return ['ok' => true, 'user_id' => (int) $user->id, 'replayed' => true];
                    }

                    // Linked to a DIFFERENT Telegram identity: refuse, never
                    // repoint. `result` makes the browser stop pending.
                    $locked->forceFill(['result' => TelegramLoginIntent::RESULT_CONFLICT])->save();
                    $this->audit->security('identity.telegram_link_refused', [
                        'intent_id' => $locked->id,
                        'via' => 'account_linked_elsewhere',
                    ]);

                    return ['ok' => false, 'reason' => 'conflict'];
                }

                $telegramTaken = User::query()
                    ->where('telegram_id', $telegramId)
                    ->whereKeyNot($user->id)
                    ->exists();

                if ($telegramTaken) {
                    /*
                     * The ownership rule: this Start just proved control of a
                     * Telegram identity another account holds, so instead of
                     * the old terminal conflict the candidate is PARKED and
                     * the original browser is asked whether to move it —
                     * through exactly the §5 confirmation machinery, because
                     * a transfer is strictly more consequential than a link
                     * and gets at least the same gate. A Start alone still
                     * moves nothing.
                     *
                     * Only when the browser gate exists, though: with
                     * confirmation configured off there is no browser step,
                     * and a transfer without an explicit human decision in
                     * the destination's authenticated browser must never
                     * happen — that deployment keeps the old refusal.
                     */
                    if (self::confirmationRequired()) {
                        $locked->forceFill([
                            'candidate_telegram_id' => $telegramId,
                            'candidate_username' => $this->shortText($from['username'] ?? null, 64),
                            'candidate_name' => $this->candidateName($from),
                            'candidate_at' => now(),
                        ])->save();

                        $this->audit->record('identity.telegram_transfer_candidate', $user, [], [
                            'intent_id' => $locked->id,
                        ]);

                        return [
                            'ok' => true,
                            'user_id' => (int) $user->id,
                            'pending_confirmation' => true,
                        ];
                    }

                    $locked->forceFill(['result' => TelegramLoginIntent::RESULT_CONFLICT])->save();
                    $this->audit->security('identity.telegram_link_refused', [
                        'intent_id' => $locked->id,
                        'via' => 'telegram_id_taken',
                    ]);

                    return ['ok' => false, 'reason' => 'conflict'];
                }

                /*
                 * §5 hardening. The Start token is a bearer secret, and
                 * Telegram's webhook cannot prove which browser minted it.
                 * If it leaks before redemption, whoever presses Start
                 * first would otherwise be linked to somebody else's
                 * signed-in account — permanently, silently, and with the
                 * victim's own consent screen having said nothing.
                 *
                 * So a Start no longer links. It parks a CANDIDATE on the
                 * intent, which the original authenticated browser then
                 * shows to the person and asks them to confirm. The intent
                 * is deliberately NOT consumed: it is still the same token,
                 * still the same session binding, and the person can reject
                 * this candidate and restart.
                 *
                 * A second Start from the SAME Telegram account is a no-op
                 * re-park. A Start from a DIFFERENT account overwrites the
                 * candidate — the browser only ever confirms the identity
                 * it is currently being shown, and the confirm call carries
                 * that identity so a swap between render and click cannot
                 * be confirmed blind.
                 */
                if (self::confirmationRequired()) {
                    $locked->forceFill([
                        'candidate_telegram_id' => $telegramId,
                        'candidate_username' => $this->shortText($from['username'] ?? null, 64),
                        'candidate_name' => $this->candidateName($from),
                        'candidate_at' => now(),
                    ])->save();

                    $this->audit->record('identity.telegram_link_candidate', $user, [], [
                        'intent_id' => $locked->id,
                    ]);

                    return [
                        'ok' => true,
                        'user_id' => (int) $user->id,
                        'pending_confirmation' => true,
                    ];
                }

                // Everything below mutates, inside THIS transaction.
                $locked->forceFill([
                    'consumed_at' => now(),
                    'telegram_id' => $telegramId,
                    'telegram_username' => $from['username'] ?? null,
                    'result' => TelegramLoginIntent::RESULT_COMPLETED,
                ])->save();

                $user->forceFill([
                    'telegram_id' => $telegramId,
                    'telegram_username' => $from['username'] ?? null,
                    'telegram_verified_at' => now(),
                ])->save();

                $this->audit->record('identity.telegram_linked', $user, [], [
                    'intent_id' => $locked->id,
                ]);

                return ['ok' => true, 'user_id' => (int) $user->id];
            });
        } catch (UniqueConstraintViolationException) {
            // The same Telegram identity won another account in the same
            // instant. Fully rolled back; the person can retry or resolve.
            $this->audit->security('identity.telegram_link_refused', [
                'intent_id' => $intent->id,
                'via' => 'unique_constraint',
            ]);

            return ['ok' => false, 'reason' => 'conflict'];
        }
    }

    /**
     * Whether a Telegram Start must be confirmed in the original browser
     * before it becomes a link (§5).
     *
     * Config-flagged rather than hard-coded so the residual risk is an
     * operational decision with a documented default of ON, and so a
     * deployment that deliberately accepts the risk does not need a code
     * change to say so.
     */
    public static function confirmationRequired(): bool
    {
        return (bool) config('mulkihawler.telegram.link_requires_browser_confirmation', true);
    }

    /**
     * Promote a parked candidate into a real Telegram link — the §5 step
     * that only the original authenticated browser can take.
     *
     * Every guard `redeemAccountLink()` applies is re-applied HERE, under
     * the same locks, because time passed between the Start and the click:
     * the account may have been suspended, the Telegram identity may have
     * been claimed elsewhere, the intent may have expired or been cancelled
     * in another tab.
     *
     * `$expected` is the Telegram id the BROWSER was showing when the person
     * pressed Confirm. If a different Start has since overwritten the
     * candidate, the ids disagree and the confirmation is refused rather
     * than silently linking whoever arrived last — which is the whole point
     * of asking a human to look at it.
     *
     * `$acceptTransfer` is the ownership-rule extension: when the parked
     * candidate belongs to ANOTHER account, confirming is no longer a plain
     * link but a transfer, and the browser must say so explicitly — a stale
     * page or an old client that posts a bare confirm gets the old conflict,
     * never a silent move. The move itself runs through
     * {@see TelegramOwnershipTransfer::execute()}, the one implementation of
     * ordered locks, post-lock re-checks and the stale-decision guard.
     *
     * @return array{ok: bool, reason?: string, user_id?: int}
     */
    public function confirmAccountLink(User $user, string $sessionId, string $expected, bool $acceptTransfer = false): array
    {
        if ($expected === '') {
            return ['ok' => false, 'reason' => 'not_applicable'];
        }

        try {
            return DB::transaction(function () use ($user, $sessionId, $expected, $acceptTransfer): array {
                $locked = TelegramLoginIntent::query()
                    ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_LINK)
                    ->where('user_id', $user->id)
                    ->where('session_fingerprint', TelegramLoginIntent::fingerprint($sessionId))
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
                    /*
                     * The candidate changed between render and click. Do not
                     * link either one: clear the candidate and make the
                     * person look again.
                     */
                    $locked->forceFill([
                        'candidate_telegram_id' => null,
                        'candidate_username' => null,
                        'candidate_name' => null,
                        'candidate_at' => null,
                    ])->save();

                    $this->audit->security('identity.telegram_link_refused', [
                        'intent_id' => $locked->id,
                        'via' => 'candidate_changed',
                    ]);

                    return ['ok' => false, 'reason' => 'candidate_changed'];
                }

                /*
                 * The explicitly-accepted transfer takes the ownership path
                 * BEFORE any user row is locked here: the executor owns all
                 * user locking (both rows, ascending-id order) so that no
                 * code path can ever acquire the pair in the other order.
                 * Advisory reads decide whether this is a transfer at all;
                 * the executor re-decides everything under its locks.
                 */
                if ($acceptTransfer) {
                    $ownedElsewhere = User::query()
                        ->where('telegram_id', $expected)
                        ->whereKeyNot($user->id)
                        ->exists();

                    if ($ownedElsewhere) {
                        return $this->transfer->execute($locked, (int) $user->id, $expected);
                    }
                    // Not owned by anyone else after all: fall through to the
                    // ordinary confirm, which links or answers idempotently.
                }

                $fresh = User::query()->whereKey($user->id)->lockForUpdate()->first();

                if ($fresh === null || ! $fresh->mayAuthenticate()) {
                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                if ($fresh->telegram_id !== null) {
                    if (hash_equals((string) $fresh->telegram_id, $expected)) {
                        $locked->forceFill([
                            'consumed_at' => now(),
                            'telegram_id' => $expected,
                            'result' => TelegramLoginIntent::RESULT_COMPLETED,
                        ])->save();

                        return ['ok' => true, 'user_id' => (int) $fresh->id];
                    }

                    $locked->forceFill(['result' => TelegramLoginIntent::RESULT_CONFLICT])->save();

                    $this->audit->security('identity.telegram_link_refused', [
                        'intent_id' => $locked->id,
                        'via' => 'account_linked_elsewhere',
                    ]);

                    return ['ok' => false, 'reason' => 'conflict'];
                }

                $taken = User::query()
                    ->where('telegram_id', $expected)
                    ->whereKeyNot($fresh->id)
                    ->exists();

                if ($taken) {
                    /*
                     * Owned by another account and the browser did NOT
                     * explicitly accept a transfer (or the acceptance raced
                     * a claim that appeared only after the advisory read):
                     * the old refusal stands. A transfer decision the person
                     * never saw, or saw and did not take, must never happen
                     * as a side effect of an ordinary confirm.
                     */
                    $locked->forceFill(['result' => TelegramLoginIntent::RESULT_CONFLICT])->save();

                    $this->audit->security('identity.telegram_link_refused', [
                        'intent_id' => $locked->id,
                        'via' => 'telegram_id_taken',
                    ]);

                    if (! $acceptTransfer) {
                        $this->audit->security('identity.telegram_transfer_refused', [
                            'intent_id' => $locked->id,
                            'via' => 'not_accepted',
                        ]);
                    }

                    return ['ok' => false, 'reason' => 'conflict'];
                }

                $locked->forceFill([
                    'consumed_at' => now(),
                    'telegram_id' => $expected,
                    'telegram_username' => $locked->candidate_username,
                    'result' => TelegramLoginIntent::RESULT_COMPLETED,
                ])->save();

                $fresh->forceFill([
                    'telegram_id' => $expected,
                    'telegram_username' => $locked->candidate_username,
                    'telegram_verified_at' => now(),
                ])->save();

                $this->audit->record('identity.telegram_linked', $fresh, [], [
                    'intent_id' => $locked->id,
                    'confirmed_in_browser' => true,
                ]);

                return ['ok' => true, 'user_id' => (int) $fresh->id];
            });
        } catch (UniqueConstraintViolationException) {
            $this->audit->security('identity.telegram_link_refused', [
                'actor_id' => $user->id,
                'via' => 'unique_constraint',
            ]);

            return ['ok' => false, 'reason' => 'conflict'];
        }
    }

    /**
     * Reject the parked candidate. The intent survives — the person is
     * still trying to link, they just are not linking to THAT.
     */
    public function rejectAccountLinkCandidate(User $user, string $sessionId): void
    {
        DB::transaction(function () use ($user, $sessionId): void {
            $locked = TelegramLoginIntent::query()
                ->where('purpose', TelegramLoginIntent::PURPOSE_ACCOUNT_LINK)
                ->where('user_id', $user->id)
                ->where('session_fingerprint', TelegramLoginIntent::fingerprint($sessionId))
                ->whereNull('consumed_at')
                ->whereNull('cancelled_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            $locked->forceFill([
                'cancelled_at' => now(),
                'result' => TelegramLoginIntent::RESULT_CANCELLED,
                'candidate_telegram_id' => null,
                'candidate_username' => null,
                'candidate_name' => null,
                'candidate_at' => null,
            ])->save();

            $this->audit->security('identity.telegram_link_candidate_rejected', [
                'intent_id' => $locked->id,
                'actor_id' => $user->id,
            ]);
        });
    }

    /**
     * A display name for the candidate, assembled from what Telegram sent
     * and bounded. Shown so the person can recognise their own account —
     * never trusted for anything else.
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

    /**
     * A returning, already-linked account pressing Start on a LOGIN intent.
     *
     * Correction H3: the same atomicity standard as registration. The
     * intent is re-fetched under a row lock inside ONE transaction; purpose,
     * expiry, consumption, the sender's identity and the account's standing
     * are all re-checked under that lock; consumption happens INSIDE, so a
     * failure in the linking write or the audit rolls it back and the person
     * can simply press Start again. Redelivery to the winner is idempotent.
     *
     * v6: `pending_confirmation` is genuinely returned when a Start parks a
     * candidate awaiting the browser gate; omitting it from the annotation
     * made every caller's check read as dead code.
     *
     * @return array{ok: bool, reason?: string, user_id?: int, replayed?: bool, pending_confirmation?: bool}
     */
    public function redeemReturningLogin(TelegramLoginIntent $intent, string $telegramId): array
    {
        if ($telegramId === '') {
            return ['ok' => false, 'reason' => 'not_applicable'];
        }

        return DB::transaction(function () use ($intent, $telegramId): array {
            $locked = TelegramLoginIntent::query()
                ->whereKey($intent->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->purpose !== TelegramLoginIntent::PURPOSE_LOGIN) {
                return ['ok' => false, 'reason' => 'not_applicable'];
            }

            if ($locked->consumed_at !== null) {
                if ($locked->user_id !== null
                    && $locked->telegram_id !== null
                    && hash_equals((string) $locked->telegram_id, $telegramId)) {
                    return ['ok' => true, 'user_id' => (int) $locked->user_id, 'replayed' => true];
                }

                return ['ok' => false, 'reason' => 'not_applicable'];
            }

            if (! $locked->expires_at->isFuture()) {
                return ['ok' => false, 'reason' => 'not_applicable'];
            }

            $user = User::query()
                ->where('telegram_id', $telegramId)
                ->whereNotNull('telegram_verified_at')
                ->first();

            // One rule, one place: an inactive OR suspended account cannot
            // come in through this door either. The refusal is the same
            // generic one an unknown sender gets — existence stays private.
            if ($user === null || ! $user->mayAuthenticate()) {
                return ['ok' => false, 'reason' => 'unknown_telegram'];
            }

            $locked->forceFill([
                'consumed_at' => now(),
                'telegram_id' => $telegramId,
                'user_id' => $user->id,
            ])->save();

            $this->audit->record('identity.telegram_returning_login', $user, [], [
                'intent_id' => $locked->id,
            ]);

            return ['ok' => true, 'user_id' => (int) $user->id];
        });
    }

    private function recordConsent(User $user, TelegramLoginIntent $intent, string $type, bool $granted): void
    {
        Consent::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'granted' => $granted,
            'source' => 'registration_form',
            'evidence' => [
                'intent_id' => $intent->id,
                'policy_version' => $intent->consent_policy_version,
                'method' => 'telegram_start_registration',
            ],
            'ip_hash' => $intent->ip_hash,
            'user_agent_hash' => $intent->user_agent_hash,
            'locale' => $intent->locale,
            'granted_at' => $granted ? now() : null,
            'withdrawn_at' => $granted ? null : now(),
        ]);
    }

    /**
     * Identical to {@see TelegramAuthenticator::normalise} so the blind index
     * matches across both flows — the same person typing the same number must
     * land on the same index or duplicate detection is fiction.
     */
    private function normalise(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        return '+'.ltrim($digits, '0');
    }
}

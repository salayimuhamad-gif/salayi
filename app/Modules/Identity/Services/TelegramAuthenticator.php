<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\TelegramLoginIntent;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Telegram authentication (File one §7).
 *
 * Turns a webhook delivery into a verified sign-in, or refuses.
 *
 * The rule that shapes everything here is §7.4: **a typed number is never
 * verified.** Telegram's Share Contact button is the only route that proves
 * possession, because Telegram itself vouches for the number attached to the
 * account. A number the person typed into a chat proves they can type — and a
 * verified phone in this product gates portfolio access, saved searches and
 * lead contact details, so the distinction is not academic.
 *
 * Replay protection (§7.9) is enforced by a unique key on the update id rather
 * than by an application check. Telegram retries deliveries it believes failed,
 * and a retried contact-share must not create a second account or re-verify a
 * number somebody has since removed. A duplicate is a database collision, not a
 * branch somebody has to remember to write.
 */
final class TelegramAuthenticator
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Verify the webhook came from Telegram (§7.9).
     *
     * Telegram sends the secret configured with setWebhook in a header. A
     * constant-time comparison, and an unconfigured secret refuses everything
     * rather than accepting everything — an endpoint that authenticates nobody
     * is worse than one that is switched off, because it looks protected.
     */
    public function verifySignature(?string $providedSecret): bool
    {
        $expected = (string) config('services.telegram.webhook_secret', '');

        if ($expected === '') {
            Log::channel('security')->warning('telegram.webhook_secret_not_configured');

            return false;
        }

        return is_string($providedSecret)
            && $providedSecret !== ''
            && hash_equals($expected, $providedSecret);
    }

    /**
     * Redeem a login intent with a shared contact — ONE atomic operation.
     *
     * Correction H3 applied to the legacy flow: the intent is re-fetched
     * under a row lock inside a single transaction; purpose, expiry,
     * consumption, the contact proof, cross-identity conflicts and the
     * account's standing are all decided under that lock; consumption
     * happens INSIDE, so a failure in user resolution, the consent write or
     * the audit rolls everything back — the person shares the contact again
     * and it simply works. Redelivery to the winner is idempotent.
     *
     * Identity safety, stated plainly: a Telegram ID is NEVER silently
     * repointed between accounts, and a phone that belongs to one account
     * while the Telegram identity belongs to another refuses generically —
     * merging is a decision for a human, not a webhook.
     *
     * @param  array{phone_number?: string|null, user_id?: int|string|null}  $contact  shared-contact block
     * @param  array{id?: int|string, username?: string|null, language_code?: string|null, first_name?: string|null, last_name?: string|null}  $from  Telegram sender block; every field is optional
     * @return array{ok: bool, reason?: string, user_id?: int, intent_id?: int, replayed?: bool}
     */
    public function redeem(string $token, array $contact, array $from): array
    {
        try {
            return DB::transaction(function () use ($token, $contact, $from): array {
                $locked = TelegramLoginIntent::query()
                    ->where('token', $token)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    $this->audit->security('telegram.login_unknown_token');

                    return ['ok' => false, 'reason' => 'unknown_token'];
                }

                if ($locked->purpose !== TelegramLoginIntent::PURPOSE_LOGIN) {
                    $this->audit->security('telegram.login_wrong_purpose', ['intent_id' => $locked->id]);

                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                $telegramUserId = (string) ($from['id'] ?? '');

                if ($locked->consumed_at !== null) {
                    if ($locked->user_id !== null
                        && $locked->telegram_id !== null
                        && $telegramUserId !== ''
                        && hash_equals((string) $locked->telegram_id, $telegramUserId)) {
                        return ['ok' => true, 'user_id' => (int) $locked->user_id, 'intent_id' => (int) $locked->id, 'replayed' => true];
                    }

                    $this->audit->security('telegram.login_intent_unusable', ['intent_id' => $locked->id]);

                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                if (! $locked->expires_at->isFuture()) {
                    $this->audit->security('telegram.login_intent_unusable', ['intent_id' => $locked->id]);

                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                /*
                 * §7.4. Telegram sets `user_id` on a contact only when the
                 * person used the Share Contact button AND the contact is
                 * their own. A forwarded contact, or one typed by hand, fails
                 * this and is refused — it may be a real number, but it is
                 * not a proof of possession.
                 */
                $contactOwnerId = (string) ($contact['user_id'] ?? '');
                $phone = (string) ($contact['phone_number'] ?? '');

                if ($phone === '' || $contactOwnerId === '' || $contactOwnerId !== $telegramUserId) {
                    $this->audit->security('telegram.contact_not_self_shared', ['intent_id' => $locked->id]);

                    return ['ok' => false, 'reason' => 'contact_not_verified'];
                }

                $index = User::blindIndex($this->normalise($phone));
                $byPhone = User::query()->where('phone_index', $index)->first();
                $byTelegram = User::query()->where('telegram_id', $telegramUserId)->first();

                /*
                 * Cross-identity conflicts refuse, generically, WITHOUT
                 * consuming — the person keeps a live intent and a human can
                 * untangle the accounts. Which identifier collided is for the
                 * audit log only.
                 */
                if ($byPhone !== null && $byTelegram !== null && $byPhone->id !== $byTelegram->id) {
                    $this->audit->security('telegram.identity_conflict', [
                        'intent_id' => $locked->id,
                        'via' => 'phone_and_telegram_belong_to_different_accounts',
                    ]);

                    return ['ok' => false, 'reason' => 'conflict'];
                }

                if ($byPhone !== null
                    && $byPhone->telegram_id !== null
                    && ! hash_equals((string) $byPhone->telegram_id, $telegramUserId)) {
                    $this->audit->security('telegram.identity_conflict', [
                        'intent_id' => $locked->id,
                        'via' => 'phone_account_already_linked_elsewhere',
                    ]);

                    return ['ok' => false, 'reason' => 'conflict'];
                }

                $target = $byPhone ?? $byTelegram;

                // One rule, one place: an inactive or suspended account does
                // not come in through the legacy door either, and the refusal
                // does not reveal that the account exists.
                if ($target !== null && ! $target->mayAuthenticate()) {
                    $this->audit->security('telegram.login_account_unavailable', ['intent_id' => $locked->id]);

                    return ['ok' => false, 'reason' => 'expired_or_consumed'];
                }

                // Everything below mutates, inside THIS transaction —
                // consumption first.
                $locked->forceFill([
                    'consumed_at' => now(),
                    'telegram_id' => $telegramUserId,
                    'telegram_username' => $from['username'] ?? null,
                    'phone_shared' => true,
                ])->save();

                $user = $this->applyVerifiedContact($target, $phone, $telegramUserId, $from);

                $locked->forceFill(['user_id' => $user->id])->save();

                // §7.8: consent evidence, recorded at the moment it was given.
                Consent::query()->create([
                    'user_id' => $user->id,
                    'type' => 'telegram_contact',
                    'granted' => true,
                    'source' => 'telegram_share_contact',
                    'evidence' => [
                        'intent_id' => $locked->id,
                        'telegram_id' => $telegramUserId,
                        // The number itself is NOT in the evidence blob. It
                        // lives encrypted on the user row; duplicating it into
                        // a JSON column would put it somewhere the encryption
                        // does not reach.
                        'method' => 'share_contact_button',
                    ],
                    'ip_hash' => $locked->ip_hash,
                    'user_agent_hash' => $locked->user_agent_hash,
                    'locale' => $locked->locale,
                    'granted_at' => now(),
                ]);

                $this->audit->record('telegram.login_verified', $user, [], [
                    'intent_id' => $locked->id,
                ]);

                return ['ok' => true, 'user_id' => (int) $user->id, 'intent_id' => (int) $locked->id];
            });
        } catch (UniqueConstraintViolationException) {
            // A race the in-lock reads could not see. Fully rolled back —
            // the intent is STILL USABLE — and refused generically.
            $this->audit->security('telegram.identity_conflict', ['via' => 'unique_constraint']);

            return ['ok' => false, 'reason' => 'conflict'];
        }
    }

    /**
     * Apply a proven contact to the resolved account, or create one.
     *
     * The caller has already refused every cross-identity conflict, so the
     * three branches here are the SAFE ones only: the phone-matched account
     * that is unlinked or linked to this same sender; the telegram-matched
     * account updating its own number; or a brand-new account.
     *
     * ALL THREE now stamp `telegram_verified_at`, which they did not before,
     * and its absence was a live defect rather than a nicety. That column — not
     * `telegram_id` — is what `EnsureTelegramLinked` reads. An account created
     * or linked here therefore held a Telegram identity while the gate still
     * considered it unlinked, so every personal surface bounced it to the
     * verification page, where pressing Start found the identity already
     * present, reported success, and changed nothing the gate looks at. That is
     * a redirect loop with no exit.
     *
     * Setting it is recording a fact, not granting anything: a shared contact
     * proves strictly MORE than a Start does, because it demonstrates control
     * of the phone number as well as the Telegram account. The 2026_08_02
     * migration backfilled exactly this column on exactly this reasoning for
     * the accounts that already existed; these are the ones created since.
     *
     * `?? now()` rather than a plain assignment so an account that was already
     * verified keeps its ORIGINAL timestamp. When the link was first proven is
     * a fact about the past, and re-sharing a contact today does not change it.
     *
     * @param  array<string, mixed>  $from
     */
    private function applyVerifiedContact(?User $target, string $phone, string $telegramId, array $from): User
    {
        if ($target !== null && $target->phone_index === User::blindIndex($this->normalise($phone))) {
            $target->forceFill([
                'telegram_id' => $telegramId,
                'telegram_username' => $from['username'] ?? null,
                'phone_verified' => true,
                'telegram_verified_at' => $target->telegram_verified_at ?? now(),
            ])->save();

            return $target;
        }

        if ($target !== null) {
            // Same Telegram account, new number: update the number rather
            // than stranding the person with an account they can no longer
            // reach.
            $target->setPhone($this->normalise($phone));
            $target->forceFill([
                'phone_verified' => true,
                'telegram_verified_at' => $target->telegram_verified_at ?? now(),
            ])->save();

            return $target;
        }

        $user = new User;
        $user->fill([
            'name' => trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: 'Telegram user',
            // No email and no password: this account is reachable only
            // through a proven phone. Inventing a placeholder email would
            // create a second, weaker way in.
            'email' => null,
            'is_active' => true,
        ]);
        $user->setPhone($this->normalise($phone));
        $user->forceFill([
            'telegram_id' => $telegramId,
            'telegram_username' => $from['username'] ?? null,
            'phone_verified' => true,
            'telegram_verified_at' => now(),
        ])->save();

        return $user;
    }

    /**
     * E.164 normalisation.
     *
     * The blind index is an exact-match HMAC, so `+964 750 123 4567` and
     * `9647501234567` must normalise identically or the same person gets two
     * accounts.
     */
    private function normalise(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        return '+'.ltrim($digits, '0');
    }
}

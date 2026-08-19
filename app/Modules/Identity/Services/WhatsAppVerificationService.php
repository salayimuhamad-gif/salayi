<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\WhatsAppOtp;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Account verification by WhatsApp one-time code — the second door.
 *
 * WHAT TYPING THE CODE PROVES, stated first because everything below enforces
 * exactly this and nothing more: it proves possession of the PHONE NUMBER the
 * account was registered with, because the code was delivered to that number
 * over WhatsApp and typed back. So redemption sets `phone_verified` — the
 * claim it genuinely demonstrates, the same one Share-Contact sets — and
 * stamps `whatsapp_verified_at`, the account-verification fact the gate
 * reads. It proves NOTHING about any Telegram identity, so `telegram_id`,
 * `telegram_username` and `telegram_verified_at` are never written here.
 * Distinct claims, distinct columns — the doctrine the Telegram flow
 * established, honoured from the other side.
 *
 * ONE SUCCESS IS ENOUGH, IN EITHER ORDER. Both verification methods verify
 * the same account, and whichever lands first wins:
 *
 *   - WhatsApp wins → every live Telegram verification token is revoked
 *     (via {@see TelegramVerificationService::revokeAllFor()}), so the deep
 *     link in the person's chat resolves to a polite "already verified"
 *     instead of minting a SECOND verification event or attaching a Telegram
 *     identity nobody asked for.
 *   - Telegram wins → {@see verify()} answers `already_verified` WITHOUT
 *     stamping anything, and every live code is retired. A pending code can
 *     never overwrite or duplicate what the other method already proved.
 *
 * Both decisions are taken under a `SELECT … FOR UPDATE` on the user row —
 * the same per-account mutex the Telegram redemption holds — so two methods
 * finishing in the same instant serialise instead of both stamping. (The two
 * transactions take the user lock and token-row locks in opposite orders, so
 * InnoDB may occasionally break a genuine tie by aborting one side; the
 * webhook side is redelivered by Telegram's retry and the browser side
 * surfaces a retryable error. A tie needs the same person pressing Start and
 * submitting a code in the same instant.)
 *
 * THE CODE IS A CREDENTIAL AND IS TREATED AS ONE: six CSPRNG digits, stored
 * only as a keyed digest, ten-minute lifetime, five attempts, one live code
 * per account, retired on success, on replacement, and on either method
 * winning. Refusals are audited; the code value never reaches a log, an
 * audit row or a response.
 */
final class WhatsAppVerificationService
{
    /** A fresh code cannot be requested faster than this. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly BirdWhatsAppClient $bird,
        private readonly TelegramVerificationService $telegramTokens,
        private readonly AuditLogger $audit,
    ) {}

    public function isConfigured(): bool
    {
        return $this->bird->isConfigured();
    }

    /**
     * Mint and deliver a code for the signed-in account.
     *
     * The row is committed BEFORE Bird is called: a provider that answers
     * slowly must not hold a row lock on the user, and a provider that
     * accepts the message must find the code already redeemable. If delivery
     * then fails, the fresh row is revoked so no live code exists that nobody
     * received.
     *
     * @return array{ok: bool, reason?: string, retry_in?: int}
     */
    public function send(User $user, ?string $locale = null): array
    {
        if (! $this->bird->isConfigured()) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        $phone = $user->phone();

        if ($phone === null || $phone === '') {
            return ['ok' => false, 'reason' => 'no_phone'];
        }

        $minted = DB::transaction(function () use ($user, $locale): array {
            // The user row is the per-account mutex, exactly as issueFor()
            // holds it: two tabs asking together serialise here.
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->hasVerifiedAccount()) {
                return ['ok' => false, 'reason' => 'already_verified'];
            }

            $live = WhatsAppOtp::query()
                ->where('user_id', $fresh->getKey())
                ->usable()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($live !== null && $live->created_at !== null) {
                $retryAt = $live->created_at->addSeconds(self::RESEND_COOLDOWN_SECONDS);

                if ($retryAt->isFuture()) {
                    /*
                     * Every send costs a paid message into somebody's chat,
                     * and a person double-tapping the button is not asking
                     * for two. The live code they already have keeps working.
                     */
                    return [
                        'ok' => false,
                        'reason' => 'cooldown',
                        'retry_in' => max(1, (int) now()->diffInSeconds($retryAt, false)),
                    ];
                }
            }

            // One live code per account: replacing retires the predecessor,
            // so a stale code cannot race its own successor.
            WhatsAppOtp::query()
                ->where('user_id', $fresh->getKey())
                ->usable()
                ->lockForUpdate()
                ->get()
                ->each(function (WhatsAppOtp $stale): void {
                    $stale->forceFill(['revoked_at' => now()])->save();
                });

            $code = WhatsAppOtp::generateCode();

            $otp = new WhatsAppOtp;
            $otp->forceFill([
                'user_id' => $fresh->getKey(),
                'code_hash' => WhatsAppOtp::hashOf($code),
                // The number the code is being sent to, pinned for redemption.
                'phone_hash' => (string) $fresh->phone_index,
                'locale' => $this->locale($locale ?? $fresh->preferred_locale),
                'expires_at' => now()->addSeconds(WhatsAppOtp::TTL_SECONDS),
                'consumed_at' => null,
                'revoked_at' => null,
                'attempts' => 0,
            ])->save();

            // The row id, never the code and never its digest.
            $this->audit->security('identity.whatsapp_otp_issued', [
                'otp_id' => $otp->id,
                'actor_id' => $fresh->getKey(),
            ]);

            return ['ok' => true, 'otp_id' => $otp->id, 'code' => $code];
        });

        if (! $minted['ok']) {
            return $minted;
        }

        if (! $this->bird->sendOtp($phone, (string) $minted['code'])) {
            /*
             * Bird refused or was unreachable. The code was never delivered,
             * so it must not stay live — a person retrying immediately gets a
             * fresh mint instead of a cooldown protecting a ghost.
             */
            WhatsAppOtp::query()
                ->whereKey($minted['otp_id'])
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $this->audit->security('identity.whatsapp_otp_send_failed', [
                'otp_id' => $minted['otp_id'],
                'actor_id' => $user->getKey(),
            ]);

            return ['ok' => false, 'reason' => 'send_failed'];
        }

        return ['ok' => true];
    }

    /**
     * Redeem a typed code — the entire verification, in one transaction.
     *
     * Attempts are counted BEFORE the comparison, under the lock, so a wrong
     * guess can never be retried for free by racing the counter; and the
     * phone binding is re-checked against the LIVE row, so the stamp always
     * describes the number that actually received the code.
     *
     * @return array{ok: bool, reason?: string, already_verified?: bool}
     */
    public function verify(User $user, string $code): array
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        return DB::transaction(function () use ($user, $code): array {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->hasVerifiedAccount()) {
                /*
                 * The other method won while this code was in flight — or the
                 * person simply typed a code twice. Either way the account is
                 * verified and NOTHING further may be stamped: a second
                 * verification event is exactly what this branch refuses.
                 * The pending material is retired and the caller sends the
                 * person onward.
                 */
                WhatsAppOtp::retireAllFor((int) $fresh->getKey());

                return ['ok' => true, 'already_verified' => true];
            }

            $otp = WhatsAppOtp::query()
                ->where('user_id', $fresh->getKey())
                ->usable()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($otp === null) {
                $this->audit->security('identity.whatsapp_verification_refused', [
                    'actor_id' => $fresh->getKey(),
                    'via' => 'no_live_code',
                ]);

                return ['ok' => false, 'reason' => 'no_challenge'];
            }

            // Counted first, so the guess being judged is already paid for.
            $otp->forceFill(['attempts' => $otp->attempts + 1])->save();

            if ($otp->attempts > WhatsAppOtp::MAX_ATTEMPTS) {
                $otp->forceFill(['revoked_at' => now()])->save();

                $this->audit->security('identity.whatsapp_verification_refused', [
                    'otp_id' => $otp->id,
                    'actor_id' => $fresh->getKey(),
                    'via' => 'attempts_exhausted',
                ]);

                return ['ok' => false, 'reason' => 'too_many_attempts'];
            }

            if (! hash_equals($otp->code_hash, WhatsAppOtp::hashOf($code))) {
                $burnt = $otp->attempts >= WhatsAppOtp::MAX_ATTEMPTS;

                if ($burnt) {
                    // The budget is spent on this wrong guess; the code dies
                    // now rather than answering one more time.
                    $otp->forceFill(['revoked_at' => now()])->save();
                }

                $this->audit->security('identity.whatsapp_verification_refused', [
                    'otp_id' => $otp->id,
                    'actor_id' => $fresh->getKey(),
                    'via' => $burnt ? 'attempts_exhausted' : 'code_mismatch',
                ]);

                return ['ok' => false, 'reason' => $burnt ? 'too_many_attempts' : 'mismatch'];
            }

            /*
             * The number must still be the one the code was DELIVERED to. A
             * phone changed between send and confirm means the possession
             * this code proves belongs to the old number; stamping the new
             * one would verify a claim nobody demonstrated.
             */
            if ($fresh->phone_index === null
                || ! hash_equals((string) $otp->phone_hash, (string) $fresh->phone_index)) {
                $otp->forceFill(['revoked_at' => now()])->save();

                $this->audit->security('identity.whatsapp_verification_refused', [
                    'otp_id' => $otp->id,
                    'actor_id' => $fresh->getKey(),
                    'via' => 'phone_changed',
                ]);

                return ['ok' => false, 'reason' => 'phone_changed'];
            }

            // Everything below mutates, inside THIS transaction.
            $otp->forceFill(['consumed_at' => now()])->save();

            /*
             * The two claims this code proves, and ONLY those. No telegram_*
             * column is named here, by design: verifying over WhatsApp must
             * never invent, overwrite or duplicate a Telegram identity.
             */
            $fresh->forceFill([
                'whatsapp_verified_at' => now(),
                'phone_verified' => true,
            ])->save();

            WhatsAppOtp::retireAllFor((int) $fresh->getKey());

            /*
             * The road not taken is closed politely: the permanent Telegram
             * deep link in this person's chat now answers "already verified"
             * instead of attaching an identity to an account that no longer
             * needs one.
             */
            $this->telegramTokens->revokeAllFor($fresh, 'verified_by_whatsapp');

            $this->audit->record('identity.whatsapp_verified', $fresh, [], [
                'otp_id' => $otp->id,
            ]);

            return ['ok' => true];
        });
    }

    /** One of the three languages this platform speaks, or the site default. */
    private function locale(?string $locale): string
    {
        return in_array($locale, ['ckb', 'ar', 'en'], true)
            ? $locale
            : (string) config('app.locale', 'ckb');
    }
}

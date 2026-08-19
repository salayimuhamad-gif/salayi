<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One WhatsApp verification code (see the migration for why this is its own
 * table and what a row may authorise).
 *
 * One sentence of authority: this row authorises stamping the ONE account
 * named by `user_id` as verified, within ten minutes, for as long as that
 * account still holds the phone the code was sent to, with at most five
 * attempts. It signs nobody in and never touches a Telegram column.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property string $phone_hash
 * @property string $locale
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $revoked_at
 * @property int $attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class WhatsAppOtp extends Model
{
    protected $table = 'whatsapp_otps';

    /**
     * Everything is written through explicit `forceFill()` in
     * WhatsAppVerificationService. `consumed_at`, `revoked_at` and `attempts`
     * are the entire state machine, and no request payload has any business
     * reaching them.
     */
    protected $guarded = ['*'];

    /** Neither digest may ever reach a response, a log or a payload. */
    protected $hidden = ['code_hash', 'phone_hash'];

    /** Ten minutes: long enough for a slow delivery, short for a credential. */
    public const TTL_SECONDS = 600;

    /** Wrong guesses before the code is burnt. */
    public const MAX_ATTEMPTS = 5;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Six digits from the CSPRNG, leading zeros kept. Derived from randomness
     * alone: nothing about the person can be read out of it.
     */
    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The stored form of a code: HMAC-SHA256 under the blind-index key.
     *
     * A plain digest of a six-digit space is a lookup table, not a hash — an
     * offline attacker with a dump would recover every live code in
     * microseconds. Keying it means a dump alone yields nothing checkable,
     * exactly the property the phone blind index already relies on. A missing
     * key fails loudly rather than falling back to something unkeyed that
     * looks like it works.
     */
    public static function hashOf(string $code): string
    {
        $key = (string) config('mulkihawler.security.blind_index_key');

        if ($key === '') {
            throw new RuntimeException('MULKIHAWLER_BLIND_INDEX_KEY is not configured; refusing to build an unkeyed OTP digest.');
        }

        return hash_hmac('sha256', $code, $key);
    }

    /** Usable = unconsumed, unrevoked AND inside its window. */
    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->revoked_at === null
            && ! $this->expires_at->isPast();
    }

    /**
     * @param  Builder<WhatsAppOtp>  $query
     * @return Builder<WhatsAppOtp>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Retire every live code this account holds. Called when the account
     * becomes verified — through EITHER method — so no pending code from the
     * road not taken can ever mint a second verification event.
     *
     * A static on the model rather than service-to-service wiring, so the
     * Telegram redemption can retire these without depending on the WhatsApp
     * service (or its clock words, which the standalone contract test bans
     * from that file).
     */
    public static function retireAllFor(int $userId): int
    {
        return self::query()
            ->where('user_id', $userId)
            ->usable()
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The permanent-until-used Telegram verification token.
 *
 * One sentence of authority, and it is the whole security model: this token
 * authorises attaching ONE Telegram identity to the ONE account named by
 * `user_id`, and only while that account has no Telegram identity at all. It
 * signs nobody in, it names no destination, and holding it grants no access to
 * the account it belongs to.
 *
 * THE TOKEN IS STORED TWICE, IN TWO FORMS, FOR TWO JOBS. `token_hash` is a
 * plain SHA-256 and is the lookup key — the webhook holds a raw token and must
 * find its row in one indexed read. `token_encrypted` is the same value under
 * the platform's own encryption, and exists so the owner's page can render THE
 * SAME deep link weeks later instead of minting a replacement that would kill
 * the link already open in their Telegram chat. Neither form is a usable token
 * on its own: the digest is irreversible, and the ciphertext needs APP_KEY.
 *
 * VALIDITY IS STATE, NOT TIME:
 *
 *     usable  ==  used_at IS NULL  AND  revoked_at IS NULL
 *
 * There is no `expires_at` column, and {@see isUsable()} deliberately consults
 * no clock. Somebody who registers tonight and presses Start next month is not
 * an edge case to be tolerated — it is the flow this exists to support.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string $token_hash
 * @property string $token_encrypted
 * @property int $user_id
 * @property string $locale
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class TelegramVerificationToken extends Model
{
    /**
     * Everything is written through explicit assignment or `forceFill()` in
     * TelegramVerificationService. Mass assignment is left closed rather than
     * listing columns: `used_at` and `revoked_at` are the entire state machine,
     * and no request payload has any business reaching them.
     */
    protected $guarded = ['*'];

    /**
     * Neither stored form may ever reach a response, a log or an analytics
     * payload. The ciphertext is the token itself to anyone holding APP_KEY,
     * and the digest is the lookup key for a credential.
     */
    protected $hidden = ['token_hash', 'token_encrypted'];

    /** How many random bytes back a token. 32 → 64 hex characters. */
    public const TOKEN_BYTES = 32;

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The stored form of a raw token.
     *
     * A plain SHA-256 with no salt and no stretching, on purpose. The input is
     * 32 bytes from a CSPRNG, so there is no dictionary to attack and nothing
     * for a work factor to protect; what IS needed is a deterministic value the
     * webhook can look up in one indexed read, which a salted hash cannot give.
     * This is the same reasoning — and the same primitive — the return-handoff
     * table already uses.
     */
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * 64 hex characters from a CSPRNG.
     *
     * Opaque by construction: it is derived from randomness alone, so it
     * encodes no user id, no phone number, no sequence and no timestamp.
     * Nothing about the person can be read out of the deep link that carries
     * it, which is the requirement that rules out every "just sign the user id"
     * shortcut.
     */
    public static function generateRaw(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * The raw token again, for re-rendering the owner's own deep link.
     *
     * Returns null if the ciphertext cannot be decrypted — a re-keyed
     * APP_KEY, a truncated column. The callers treat that as "no link to
     * show" and mint a fresh one rather than rendering something broken, so a
     * decryption failure degrades to the old behaviour instead of a 500.
     */
    public function raw(): ?string
    {
        try {
            $raw = Crypt::decryptString($this->token_encrypted);
        } catch (Throwable) {
            return null;
        }

        /*
         * Belt and braces: a decrypted value that does not hash back to this
         * row's lookup key is not this row's token, whatever the column says.
         * That can only happen if the two columns were written apart, so it
         * should be impossible — which is exactly why it is worth catching
         * here rather than discovering it in a chat message that silently
         * never verifies.
         */
        return hash_equals($this->token_hash, self::hash($raw)) ? $raw : null;
    }

    /**
     * Usable means unused and unrevoked. It does NOT mean recent, and no clock
     * is consulted here — see the class docblock.
     */
    public function isUsable(): bool
    {
        return $this->used_at === null && $this->revoked_at === null;
    }

    /**
     * @param  Builder<TelegramVerificationToken>  $query
     * @return Builder<TelegramVerificationToken>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('used_at')->whereNull('revoked_at');
    }
}

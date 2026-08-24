<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single-use Telegram login intent (File one §7.1, §7.2).
 *
 * Created by a browser, redeemed once by a Telegram callback, and valid only in
 * the session that created it.
 *
 * The session binding is the security property. A login link that works in any
 * browser is a bearer token for somebody else's account: forwarded, screenshotted
 * or glimpsed on a shared screen, it would sign the finder in as the victim.
 * Binding to a session fingerprint makes a stolen link inert.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property string $token
 * @property string $session_fingerprint
 * @property Carbon|null $consumed_at
 * @property Carbon $expires_at
 * @property string|null $telegram_id
 * @property string|null $telegram_username
 * @property bool $phone_shared
 * @property int|null $user_id
 * @property string|null $ip_hash
 * @property string|null $user_agent_hash
 * @property string $locale
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $purpose
 * @property string|null $registration_name
 * @property string|null $registration_phone_encrypted
 * @property string|null $registration_phone_index
 * @property bool $consent_terms
 * @property bool $consent_contact
 * @property string|null $consent_policy_version
 * @property string|null $result
 * @property Carbon|null $cancelled_at
 * @property string|null $candidate_telegram_id
 * @property string|null $candidate_username
 * @property string|null $candidate_name
 * @property Carbon|null $candidate_at
 *
 * ---- end generated model properties
 */
final class TelegramLoginIntent extends Model
{
    protected $fillable = [
        'token', 'session_fingerprint', 'consumed_at', 'expires_at',
        'telegram_id', 'telegram_username', 'phone_shared', 'user_id',
        'ip_hash', 'user_agent_hash', 'locale',
    ];

    /**
     * The token is the credential. Hiding it means a model serialised into a
     * response or a log cannot hand somebody a working login.
     */
    /*
     * What this intent is FOR. Login intents behave exactly as they always
     * have; registration intents additionally carry the typed details and are
     * redeemed by a bare Start rather than by a shared contact.
     */
    public const PURPOSE_LOGIN = 'login';

    /** Correction C1: linking Telegram to an EXISTING signed-in account. */
    public const PURPOSE_ACCOUNT_LINK = 'account_link';

    /**
     * Moving a Telegram identity claim from the account that holds it to the
     * signed-in account that just re-proved control of it. Minted by the
     * webhook when a verification Start collides with an existing claim,
     * bound to the destination ACCOUNT (the fingerprint derives from the
     * verification token, not from a browser session), and completed only by
     * an explicit confirmation in that account's authenticated browser. Its
     * own purpose so no account-link or login path can ever redeem one.
     */
    public const PURPOSE_ACCOUNT_TRANSFER = 'account_transfer';

    public const RESULT_COMPLETED = 'completed';

    public const RESULT_CONFLICT = 'conflict';

    public const RESULT_CANCELLED = 'cancelled';

    public const RESULT_EXPIRED = 'expired';

    public const RESULT_FAILED = 'failed';

    public const PURPOSE_REGISTRATION = 'registration';

    protected $hidden = [
        'token', 'session_fingerprint', 'ip_hash', 'user_agent_hash',
        // The typed details are for redemption, never for serialisation.
        'registration_phone_encrypted', 'registration_phone_index',
        // v4 §5: the parked Telegram identity is shown through a curated
        // payload, never by serialising the model.
        'candidate_telegram_id',
    ];

    /**
     * The safe display identity of a parked candidate (§5), or null when
     * nothing is parked.
     *
     * Deliberately NOT the Telegram id: the person recognises their own
     * account by its name and @username, and the numeric id is an
     * identifier the page has no reason to carry. The id the browser sends
     * back on confirm is an opaque digest of it, so a swapped candidate is
     * still detectable without ever putting the real id in a page.
     *
     * @return array{name: string|null, username: string|null, handle: string, at: string|null}|null
     */
    public function candidatePayload(): ?array
    {
        if ($this->candidate_telegram_id === null) {
            return null;
        }

        return [
            'name' => $this->candidate_name,
            'username' => $this->candidate_username,
            'handle' => $this->candidateHandle(),
            'at' => $this->candidate_at?->toIso8601String(),
        ];
    }

    /**
     * A stable, non-reversible handle for the parked candidate, bound to
     * this intent's token so it cannot be replayed against another intent.
     */
    public function candidateHandle(): string
    {
        return hash_hmac('sha256', (string) $this->candidate_telegram_id, $this->token);
    }

    /** Constant-time comparison of a browser-supplied handle. */
    public function candidateHandleMatches(string $handle): bool
    {
        return $this->candidate_telegram_id !== null
            && hash_equals($this->candidateHandle(), $handle);
    }

    protected function casts(): array
    {
        return [
            'consumed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'candidate_at' => 'datetime',
            'expires_at' => 'datetime',
            'phone_shared' => 'boolean',
            'consent_terms' => 'boolean',
            'consent_contact' => 'boolean',
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
     * Mint an intent for this session.
     *
     * Ten minutes: long enough to switch to Telegram, find the bot and press a
     * button; short enough that an abandoned intent is not a standing invitation.
     */
    public static function mint(string $sessionId, string $locale, ?string $ipHash, ?string $agentHash): self
    {
        return self::query()->create([
            // 64 hex characters from a CSPRNG. This is a bearer credential
            // until it is consumed, so it is sized like one.
            'token' => bin2hex(random_bytes(32)),
            'session_fingerprint' => self::fingerprint($sessionId),
            'expires_at' => now()->addMinutes(10),
            'ip_hash' => $ipHash,
            'user_agent_hash' => $agentHash,
            'locale' => $locale,
        ]);
    }

    /**
     * A hash of the session id, never the id itself.
     *
     * If this table leaked, raw session identifiers would let an attacker
     * impersonate every pending session. A hash is useless to them and equally
     * usable for the equality check this needs.
     */
    public static function fingerprint(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->cancelled_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * Whether this intent belongs to the session presenting it.
     *
     * hash_equals rather than ===: the comparison is on a secret-derived value
     * and a timing side-channel here is cheap to avoid.
     */
    public function belongsToSession(string $sessionId): bool
    {
        return hash_equals($this->session_fingerprint, self::fingerprint($sessionId));
    }

    /**
     * @param  Builder<TelegramLoginIntent>  $query
     * @return Builder<TelegramLoginIntent>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    /**
     * Spend the intent.
     *
     * Guarded by a conditional update rather than a read-then-write: two
     * simultaneous callbacks would both pass an `isUsable()` check and both
     * proceed. The database decides the winner, and only the request whose
     * update affected a row may continue.
     */
    public function consume(): bool
    {
        $affected = self::query()
            ->whereKey($this->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($affected === 1) {
            $this->consumed_at = now();

            return true;
        }

        return false;
    }

    /** A short, human-readable code for the person to send to the bot. */
    public function code(): string
    {
        return Str::upper(substr($this->token, 0, 8));
    }
}

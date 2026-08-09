<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One Telegram password-recovery challenge (see the migration for why this is
 * its own table and what a row may authorise).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $telegram_id_hash
 * @property string $locale
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
final class PasswordRecoveryChallenge extends Model
{
    protected $guarded = [];

    /** Never serialised anywhere, but hidden as defence in depth. */
    protected $hidden = ['token_hash', 'telegram_id_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 32 CSPRNG bytes as 64 alphanumeric characters. */
    public static function generateRaw(): string
    {
        return Str::random(64);
    }

    public static function hashOf(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /** Usable = unconsumed, unrevoked AND inside its window. */
    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->revoked_at === null
            && ! $this->expires_at->isPast();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An in-app notification row (spec 22.1).
 *
 * `DatabaseChannel` has been writing this table since the transport slice and
 * nothing read it, so the fallback channel — the one that exists precisely so
 * a notice is never lost — was delivering into a void.
 *
 * Deliberately NOT wired to the `Notifiable` trait's `notifications()`
 * relation. Laravel's own database notifications use a uuid key and a
 * `notifiable_type`/`notifiable_id` morph; this table uses a bigint id and a
 * direct `user_id`, and adding a same-named relation to `User` would silently
 * shadow the framework's. Queries go through this model instead.
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string $channel
 * @property string $subject
 * @property string $body
 * @property Carbon|null $read_at
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string $channel
 * @property string $locale
 * @property string $subject
 * @property string $body
 * @property string|null $action_url
 * @property array<string, mixed>|null $payload
 * @property string $priority
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $digest_state
 * @property Carbon|null $digest_sent_at
 *
 * ---- end generated model properties
 */
final class Notification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'key', 'channel', 'locale', 'subject', 'body',
        'action_url', 'payload', 'priority', 'read_at',
        'digest_state', 'digest_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
            'digest_sent_at' => 'datetime',
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
     * Scope to one recipient.
     *
     * Every read path goes through this rather than a bare `where`. A
     * notification list that forgets its owner filter shows one company its
     * competitor's moderation outcomes, so the filter is a named scope that is
     * hard to omit rather than a line to remember.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Rows waiting to be rolled into a daily digest (spec 22.2).
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeAwaitingDigest(Builder $query): Builder
    {
        return $query->where('digest_state', 'pending');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Mark read, idempotently.
     *
     * Returns whether it changed anything, so a caller can count what it
     * actually did rather than what it attempted.
     */
    public function markRead(): bool
    {
        if ($this->read_at !== null) {
            return false;
        }

        $this->forceFill(['read_at' => now()])->save();

        return true;
    }

    /**
     * The reason line, recovered from the stored payload.
     *
     * Spec 22.3 requires the recipient to be told why they got this. `body`
     * already contains it because the envelope renders it in, but the detail
     * screen shows it as its own labelled field — a reason buried at the foot
     * of a paragraph is technically present and practically invisible.
     */
    public function reason(): ?string
    {
        $payload = $this->payload;

        return is_array($payload) ? ($payload['reason'] ?? null) : null;
    }

    public function unsubscribeUrl(): ?string
    {
        $payload = $this->payload;

        return is_array($payload) ? ($payload['unsubscribe_url'] ?? null) : null;
    }
}

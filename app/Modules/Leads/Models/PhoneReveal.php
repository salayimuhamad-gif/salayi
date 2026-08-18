<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Leads\Enums\RevealReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One record of a phone number being shown to a person (File one §9 Leads.3).
 *
 * Append-only by intent. There is no update path and no delete path on this
 * model: a compliance ledger that can be edited proves nothing, and the whole
 * value of the row is that it was written at the moment it happened and has not
 * moved since.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $user_id
 * @property string $subject_type
 * @property int $subject_id
 * @property RevealReason $reason_code
 * @property string|null $reason_note
 * @property int|null $consent_id
 * @property string|null $ip_hash
 * @property string|null $company_id
 * @property Carbon $revealed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class PhoneReveal extends Model
{
    protected $fillable = [
        'user_id', 'subject_type', 'subject_id', 'reason_code', 'reason_note',
        'consent_id', 'ip_hash', 'company_id', 'revealed_at',
    ];

    protected function casts(): array
    {
        return [
            'reason_code' => RevealReason::class,
            'revealed_at' => 'datetime',
        ];
    }

    /**
     * The person who looked.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Whose number was shown.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Reveals that were not initiated by the subject.
     *
     * The review queue. Nothing here is forbidden — an agent may legitimately
     * call somebody who did not ask — but an agent whose reveals are
     * overwhelmingly agent-initiated is doing something different from one
     * following up callbacks, and that difference should be visible.
     */
    /**
     * @param  Builder<PhoneReveal>  $query
     * @return Builder<PhoneReveal>
     */
    public function scopeAgentInitiated(Builder $query): Builder
    {
        return $query->whereIn('reason_code', [
            RevealReason::ExistingCustomer->value,
            RevealReason::Other->value,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Leads\Enums\LeadOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * A follow-up task and its outcome (File one §9 Leads 2).
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $demand_profile_id
 * @property int|null $assigned_to_user_id
 * @property int|null $created_by_user_id
 * @property int|null $company_id
 * @property string $title
 * @property string $kind
 * @property Carbon|null $due_on
 * @property string $status
 * @property LeadOutcome|null $outcome
 * @property string|null $outcome_notes_encrypted
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class LeadTask extends Model
{
    protected $fillable = [
        'demand_profile_id', 'assigned_to_user_id', 'created_by_user_id', 'company_id',
        'title', 'kind', 'due_on', 'status', 'outcome', 'outcome_notes_encrypted', 'completed_at',
    ];

    protected $hidden = ['outcome_notes_encrypted'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'outcome' => LeadOutcome::class,
        ];
    }

    /**
     * @return BelongsTo<DemandProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(DemandProfile::class, 'demand_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @param  Builder<LeadTask>  $query
     * @return Builder<LeadTask>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * Overdue open tasks.
     *
     * Tasks with no due date are deliberately excluded: an undated task is a
     * reminder, not a commitment, and sweeping it into "overdue" makes the
     * overdue list something people stop reading.
     *
     * @param  Builder<LeadTask>  $query
     * @return Builder<LeadTask>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_on')->whereDate('due_on', '<', now());
    }

    /**
     * Complete the task with an outcome.
     *
     * The outcome is required. A task closed without one tells a manager
     * reviewing a lost deal precisely nothing, which is the moment the record
     * exists for.
     */
    public function complete(LeadOutcome $outcome, ?string $notes = null): void
    {
        $this->status = 'completed';
        $this->outcome = $outcome;
        $this->completed_at = now();

        if ($notes !== null && $notes !== '') {
            $this->outcome_notes_encrypted = Crypt::encryptString($notes);
        }

        $this->save();
    }

    public function outcomeNotes(): ?string
    {
        if ($this->outcome_notes_encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($this->outcome_notes_encrypted);
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Projects\Support\WizardStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A resumable Project Creation Wizard draft (spec 12.1).
 *
 * @property array<string, mixed> $payload
 * @property list<string> $completed_steps
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 * @property int $id
 * @property int $user_id
 * @property int|null $company_id
 * @property int|null $project_id
 * @property string $current_step
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $completed_steps
 * @property Carbon|null $last_touched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $submitted_at
 * @property int $version
 * @property int|null $acting_company_id
 * @property string|null $purge_status
 * @property Carbon|null $purging_at
 *
 * ---- end generated model properties
 */
final class ProjectDraft extends Model
{
    protected $table = 'project_drafts';

    /*
     * `acting_company_id`, `submitted_at` and `version` were ABSENT here while
     * being passed to ProjectDraft::query()->create(). Mass assignment dropped
     * all three without a word, so the acting company chosen at start() was
     * never actually persisted — the draft looked correctly scoped in the
     * request that created it and was unscoped in every request afterwards.
     */
    protected $fillable = [
        'user_id', 'company_id', 'acting_company_id', 'project_id',
        'current_step', 'payload', 'completed_steps',
        'last_touched_at', 'submitted_at', 'version',
        'purge_status', 'purging_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'completed_steps' => 'array',
            'last_touched_at' => 'datetime',
            'submitted_at' => 'datetime',
            'purging_at' => 'datetime',
            'version' => 'integer',
            'acting_company_id' => 'integer',
            'company_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Drafts this user may see.
     *
     * Ownership, not company membership. Two colleagues editing one draft
     * would overwrite each other with no conflict signal, so a draft is
     * private to the person who started it even within a company.
     *
     * @param  Builder<ProjectDraft>  $query
     * @return Builder<ProjectDraft>
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * The company this draft is authoritatively scoped to.
     *
     * `acting_company_id` records the deliberate choice; `company_id` records
     * the resulting scope. They should always agree, but the explicit choice
     * wins when they do not — a draft written before acting_company_id existed
     * has only the latter.
     */
    public function scopedCompanyId(): ?int
    {
        $id = $this->acting_company_id ?? $this->company_id;

        return $id === null ? null : (int) $id;
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null || $this->project_id !== null;
    }

    /**
     * @return HasMany<ProjectDraftMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ProjectDraftMedia::class, 'project_draft_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Values for one step, or an empty array.
     *
     * @return array<string, mixed> the values captured for that step
     */
    public function step(string $step): array
    {
        return $this->payload[$step] ?? [];
    }

    /**
     * Every step's values merged into one flat array.
     *
     * @return array<string, mixed>
     */
    public function flattened(): array
    {
        $flat = [];

        foreach (WizardStep::all() as $step) {
            $flat = array_merge($flat, $this->step($step));
        }

        return $flat;
    }

    public function hasCompleted(string $step): bool
    {
        return in_array($step, $this->completed_steps ?? [], true);
    }

    /**
     * Whether every required step has passed validation.
     *
     * Asked before submission and again during it. A draft that was complete
     * yesterday may not be today — an area can be deleted between the two.
     */
    public function isSubmittable(): bool
    {
        foreach (WizardStep::required() as $step) {
            if (! $this->hasCompleted($step)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> required steps still outstanding */
    public function missingSteps(): array
    {
        return array_values(array_filter(
            WizardStep::required(),
            fn (string $step): bool => ! $this->hasCompleted($step),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A household's stated requirements (File two §8).
 *
 * The table existed from the start and nothing read or wrote it. This is the
 * model that finally turns `lifestyle_profiles` into a feature.
 *
 * A profile may belong to a signed-in user OR to an anonymous session. That is
 * deliberate and matters commercially: a visitor can describe their household
 * and see ranked results before creating an account, which is the difference
 * between a tool people try and a form people abandon. When they do register,
 * the session profile is claimed rather than re-typed.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $session_key
 * @property string|null $label
 * @property string|null $budget_min
 * @property string|null $budget_max
 * @property string $budget_currency
 * @property array<string, mixed>|null $property_types
 * @property array<string, mixed>|null $lifestyle_factors
 * @property int|null $household_adults
 * @property int|null $household_children
 * @property string $locale
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * ---- end generated model properties
 */
final class LifestyleProfile extends Model
{
    /*
     * NO `HasFactory`.
     *
     * There is no LifestyleProfileFactory in database/factories, and nothing in the
     * application or the suite calls `LifestyleProfile::factory()` — the trait was
     * declaring a capability that does not exist. Annotating it with a factory
     * class that is absent would be a fiction; removing it states the truth.
     * If these models ever need factories, add the factory first and the trait
     * with it.
     */
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'session_key', 'label',
        'budget_min', 'budget_max', 'budget_currency',
        'property_types', 'lifestyle_factors',
        'household_adults', 'household_children',
        'locale', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'property_types' => 'array',
            'lifestyle_factors' => 'array',
            'is_active' => 'boolean',
            'household_adults' => 'integer',
            'household_children' => 'integer',
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
     * @return HasMany<LifestylePriority, $this>
     */
    public function priorities(): HasMany
    {
        return $this->hasMany(LifestylePriority::class);
    }

    /**
     * @param  Builder<LifestyleProfile>  $query
     * @return Builder<LifestyleProfile>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to whoever is asking — a signed-in user or an anonymous session.
     *
     * Written as one scope rather than two call sites because getting it wrong
     * in either direction is a data leak: an OR without grouping would return
     * every profile whose session_key is null, which is every registered user's.
     *
     * @param  Builder<LifestyleProfile>  $query
     * @return Builder<LifestyleProfile>
     */
    public function scopeOwnedBy(Builder $query, ?int $userId, ?string $sessionKey): Builder
    {
        return $query->where(function (Builder $inner) use ($userId, $sessionKey): void {
            if ($userId !== null) {
                $inner->where('user_id', $userId);
            }

            if ($sessionKey !== null && $sessionKey !== '') {
                // A signed-in user may still own a profile started anonymously
                // in this same browser, so both are matched — but only ever
                // within this grouped clause.
                $inner->orWhere(function (Builder $anon) use ($sessionKey): void {
                    $anon->whereNull('user_id')->where('session_key', $sessionKey);
                });
            }

            // Neither identifier: match nothing rather than everything.
            if ($userId === null && ($sessionKey === null || $sessionKey === '')) {
                $inner->whereRaw('1 = 0');
            }
        });
    }

    /**
     * The shape LifestyleMatcher::score() expects as its `$profile` argument.
     *
     * Built here so the translation lives next to the model rather than being
     * re-derived in a controller, a job and the advisor — three copies that
     * would drift.
     *
     * @return array{budget_min: ?string, budget_max: ?string, property_types: list<string>, priorities: list<array<string, mixed>>}
     */
    public function toMatcherProfile(): array
    {
        return [
            'budget_min' => $this->budget_min === null ? null : (string) $this->budget_min,
            'budget_max' => $this->budget_max === null ? null : (string) $this->budget_max,
            'property_types' => array_values((array) ($this->property_types ?? [])),
            'priorities' => $this->priorities
                ->map(static fn (LifestylePriority $priority): array => [
                    'kind' => $priority->kind->value,
                    'max_distance_m' => $priority->max_distance_m,
                    'importance' => $priority->importance,
                    'required' => (bool) $priority->is_required,
                ])
                ->values()
                ->all(),
        ];
    }
}

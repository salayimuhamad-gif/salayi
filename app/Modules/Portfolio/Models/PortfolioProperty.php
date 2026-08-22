<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Models;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Geography\Models\Area;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * A privately-held property (spec 20.1).
 *
 * "Ownership remains private by default" (spec 20.3) is enforced structurally:
 * there is no publication status, no public scope, and the label and notes are
 * encrypted. The only query scope provided is `ownedBy()`, so a query that
 * forgets to scope by user returns nothing useful rather than everyone's
 * portfolio.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $user_id
 * @property string $label_encrypted
 * @property string|null $notes_encrypted
 * @property string $property_type
 * @property int|null $project_id
 * @property int|null $project_phase_id
 * @property int|null $area_id
 * @property string|null $unit_type
 * @property string|null $size_sqm
 * @property int|null $rooms
 * @property int|null $floor
 * @property string|null $orientation
 * @property string|null $purchase_price
 * @property Carbon|null $purchase_date
 * @property string $currency
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string $location_precision
 * @property array<string, mixed>|null $alert_preferences
 * @property bool $consent_valuation
 * @property bool $consent_contact
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $ownership_percent
 * @property string|null $occupancy_status
 *
 * ---- end generated model properties
 */
final class PortfolioProperty extends Model
{
    use SoftDeletes;

    /*
     * H9: the vocabularies live on the model, in one place, so the
     * controller's validation rules and the form's option lists cannot
     * drift apart. Free-form strings here were the review's finding: a
     * "type" that can hold anything is a label, not a type.
     */
    public const PROPERTY_TYPES = ['apartment', 'villa', 'house', 'land', 'commercial'];

    public const UNIT_TYPES = [
        'studio', 'one_bedroom', 'two_bedroom', 'three_bedroom',
        'four_plus_bedroom', 'duplex', 'penthouse', 'shop', 'office', 'other',
    ];

    /** The currencies this market actually trades property in. */
    public const CURRENCIES = ['USD', 'IQD'];

    /**
     * The platform's one precision vocabulary (Marketplace uses the same
     * three words). `area_only` is the no-pin state: the area reference IS
     * the location information. The old portfolio-only 'none' is retired.
     */
    public const PRECISION_EXACT = 'exact';

    public const PRECISION_APPROXIMATE = 'approximate';

    public const PRECISION_AREA_ONLY = 'area_only';

    public const PIN_PRECISIONS = [self::PRECISION_EXACT, self::PRECISION_APPROXIMATE];

    protected $table = 'portfolio_properties';

    protected $fillable = [
        'user_id', 'property_type', 'project_id', 'project_phase_id', 'area_id',
        'unit_type', 'size_sqm', 'rooms', 'floor', 'orientation',
        'purchase_price', 'purchase_date', 'ownership_percent', 'occupancy_status', 'currency',
        'latitude', 'longitude', 'location_precision',
        'alert_preferences', 'consent_valuation', 'consent_contact',
    ];

    protected $hidden = ['label_encrypted', 'notes_encrypted'];

    protected function casts(): array
    {
        return [
            'alert_preferences' => 'array',
            'purchase_price' => 'decimal:4',
            'size_sqm' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'consent_valuation' => 'boolean',
            'consent_contact' => 'boolean',
            'purchase_date' => 'date',
            'ownership_percent' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<PortfolioValuation, $this>
     */
    /**
     * EVERY media row for this property, whatever state it is in.
     *
     * Used by the deletion sweep and by anything that must reason about
     * bytes on disk. Read paths that put media in front of a person want
     * {@see readyMedia()} instead.
     *
     * @return HasMany<PortfolioMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(PortfolioMedia::class, 'portfolio_property_id');
    }

    /**
     * The media a person may actually see or download (v4, BLOCKER 8).
     *
     * A `staging` row holds a slot but its bytes are not at the path the
     * row names; an `orphaned` row's bytes are not there at all. Serving
     * either produces the one failure this whole two-phase design exists to
     * prevent — a download that 404s from a row the UI listed as present.
     *
     * `deletion_pending_at` rows are excluded for the same reason from the
     * other direction: the person asked for them to be gone, and showing
     * them because the disk is misbehaving is the platform's problem
     * leaking into their portfolio.
     *
     * @return HasMany<PortfolioMedia, $this>
     */
    public function readyMedia(): HasMany
    {
        return $this->media()
            ->where('status', PortfolioMedia::STATUS_READY)
            ->whereNull('deletion_pending_at');
    }

    /** @return HasMany<PortfolioValuation, $this> */
    public function valuations(): HasMany
    {
        return $this->hasMany(PortfolioValuation::class)->orderByDesc('calculated_at');
    }

    /**
     * The owner's persisted valuation-question answers (Wave 6) — option
     * IDs only; every percentage stays server-side on the option rows.
     *
     * @return HasMany<PortfolioPropertyAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(PortfolioPropertyAnswer::class, 'portfolio_property_id');
    }

    /**
     * The current estimate.
     *
     * A separate relation rather than `valuations->first()` so an index page
     * loads one valuation per property instead of every valuation ever
     * computed for every property — the difference between one query and a
     * portfolio-sized one.
     *
     * H9: `latestOfMany()` defaults to MAX(id) — insertion order. A history
     * that is ever backfilled or recomputed out of chronological order would
     * then show a STALE figure as current. `calculated_at` is the moment
     * that makes a valuation "latest", so it is named explicitly (the key
     * remains the automatic tiebreaker for two valuations in one second).
     *
     * @return HasOne<PortfolioValuation, $this>
     */
    public function latestValuation(): HasOne
    {
        return $this->hasOne(PortfolioValuation::class)->ofMany('calculated_at', 'max');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * The only scope. There is deliberately no public or published scope.
     *
     * @param  Builder<PortfolioProperty>  $query
     * @return Builder<PortfolioProperty>
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function setLabel(string $label): void
    {
        $this->label_encrypted = Crypt::encryptString($label);
    }

    public function label(): ?string
    {
        return $this->decrypt($this->label_encrypted);
    }

    public function setNotes(?string $notes): void
    {
        $this->notes_encrypted = $notes === null || $notes === '' ? null : Crypt::encryptString($notes);
    }

    public function notes(): ?string
    {
        return $this->decrypt($this->notes_encrypted);
    }

    public function purchasePriceValue(): ?Decimal
    {
        return $this->purchase_price === null ? null : Decimal::of((string) $this->purchase_price, 4);
    }

    /**
     * Valuation requires explicit consent (spec 20.1 lists consent as a field).
     * Estimating the worth of someone's home without their agreement is not a
     * service they asked for.
     */
    public function mayBeValued(): bool
    {
        return $this->consent_valuation === true;
    }

    private function decrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return null;
        }
    }
}

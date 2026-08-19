<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Models;

use App\Modules\Advisor\Enums\LifestylePriorityKind;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\ValueObjects\Coordinates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One place a household needs to be near (File two §8).
 *
 * These rows are the most sensitive thing this product stores about a person.
 * A workplace, a spouse's workplace and two children's schools, with a home
 * budget attached, is a description of where a specific family will be at
 * specific times of day. Spec 32.2 lists exactly this class of data as
 * never-public, and the consequences of leaking it are not commercial.
 *
 * Three protections, none of them optional:
 *
 *   1. `$hidden` keeps coordinates out of any array/JSON serialisation by
 *      default, so a controller that returns the model directly cannot leak
 *      them by omission.
 *   2. `forPublicDisplay()` is the only sanctioned way to send one of these to
 *      a browser, and it returns no coordinate at all for sensitive kinds.
 *   3. The distance, not the location, is what the interface needs — a
 *      household already knows where their own office is.
 *
 * ---- generated model properties (scripts/generate-model-annotations.php)
 *
 * @property int $id
 * @property int $lifestyle_profile_id
 * @property LifestylePriorityKind $kind
 * @property string|null $label
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $place_id
 * @property int $importance
 * @property int|null $max_distance_m
 * @property int|null $max_travel_time_s
 * @property bool $is_required
 * @property string $travel_mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * ---- end generated model properties
 */
final class LifestylePriority extends Model
{
    /*
     * NO `HasFactory`.
     *
     * There is no LifestylePriorityFactory in database/factories, and nothing in the
     * application or the suite calls `LifestylePriority::factory()` — the trait was
     * declaring a capability that does not exist. Annotating it with a factory
     * class that is absent would be a fiction; removing it states the truth.
     * If these models ever need factories, add the factory first and the trait
     * with it.
     */

    protected $fillable = [
        'lifestyle_profile_id', 'kind', 'label',
        'latitude', 'longitude', 'place_id',
        'importance', 'max_distance_m', 'max_travel_time_s',
        'is_required', 'travel_mode',
    ];

    /**
     * Coordinates never serialise by accident.
     *
     * This is the protection that survives a careless `return $priority;` in a
     * controller written six months from now.
     */
    protected $hidden = ['latitude', 'longitude'];

    protected function casts(): array
    {
        return [
            'kind' => LifestylePriorityKind::class,
            'importance' => 'integer',
            'max_distance_m' => 'integer',
            'max_travel_time_s' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<LifestyleProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(LifestyleProfile::class, 'lifestyle_profile_id');
    }

    /**
     * A priority may point at a known place instead of a raw coordinate.
     *
     * @return BelongsTo<Place, $this>
     */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * The point to measure from, whether it was pinned or chosen from places.
     *
     * A linked place wins over stored coordinates: if an administrator corrects
     * a school's location, every household that selected that school should get
     * the corrected distance without re-entering anything.
     */
    public function coordinates(): ?Coordinates
    {
        if ($this->place !== null) {
            return Coordinates::tryMake($this->place->latitude, $this->place->longitude);
        }

        // tryMake returns null for a null or out-of-range pair, which is the
        // same answer this method owes its caller.
        return Coordinates::tryMake($this->latitude, $this->longitude);
    }

    /**
     * The only sanctioned browser-facing shape.
     *
     * A sensitive kind reports that it HAS a location without saying where.
     * The household knows where their own office is; the interface only needs
     * to confirm the pin is set, and shipping the coordinate would mean a
     * cached page or a shared screenshot carries a child's school.
     *
     * @return array<string, mixed>
     */
    public function forPublicDisplay(): array
    {
        $sensitive = $this->kind->isSensitive();

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'label' => $this->label,
            'importance' => $this->importance,
            'max_distance_m' => $this->max_distance_m,
            'is_required' => (bool) $this->is_required,
            'component' => $this->kind->component(),
            'has_location' => $this->coordinates() !== null,
            // Non-sensitive kinds (a park, a market) may show their pin; a
            // workplace or a school never does.
            'latitude' => $sensitive ? null : ($this->latitude === null ? null : (float) $this->latitude),
            'longitude' => $sensitive ? null : ($this->longitude === null ? null : (float) $this->longitude),
            'place_name' => $this->place?->name(),
        ];
    }
}

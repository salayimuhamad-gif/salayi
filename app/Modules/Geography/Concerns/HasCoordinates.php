<?php

declare(strict_types=1);

namespace App\Modules\Geography\Concerns;

use App\Modules\Geography\Support\Geodesy;
use App\Modules\Geography\Support\Polygon;
use App\Modules\Geography\Support\Wkt;
use App\Modules\Geography\ValueObjects\BoundingBox;
use App\Modules\Geography\ValueObjects\Coordinates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Coordinate and boundary behaviour shared by Area, Place and Project.
 *
 * Everything here works on the DECIMAL latitude/longitude columns and the
 * portable `boundary_wkt` text, so it behaves identically on MySQL and SQLite.
 * The MySQL GEOMETRY columns are a derived index used only by query scopes that
 * check the driver first.
 */
trait HasCoordinates
{
    public function coordinates(): ?Coordinates
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return Coordinates::tryMake((string) $this->latitude, (string) $this->longitude);
    }

    /**
     * Store a point in the column's own representation.
     *
     * `latitude` and `longitude` are `decimal:7` columns, which Eloquent reads
     * back as STRINGS, while `Coordinates` carries floats. Assigning the float
     * straight through left the model holding a different type from the one it
     * would have after a round trip, so a value compared before and after a
     * save could differ for no visible reason.
     *
     * `number_format` at exactly the column's scale is used rather than a plain
     * cast: `(string) 4.0E-5` yields scientific notation, which is not a valid
     * decimal literal, and seven places is the precision the schema promises —
     * roughly a centimetre, far beyond anything this product needs.
     */
    public function setCoordinates(?Coordinates $coordinates): void
    {
        $this->setAttribute(
            'latitude',
            $coordinates === null ? null : number_format($coordinates->latitude, 7, '.', ''),
        );

        $this->setAttribute(
            'longitude',
            $coordinates === null ? null : number_format($coordinates->longitude, 7, '.', ''),
        );
    }

    /** @return list<list<Coordinates>>|null */
    public function boundaryRings(): ?array
    {
        if (empty($this->boundary_wkt)) {
            return null;
        }

        $type = Wkt::type((string) $this->boundary_wkt);

        try {
            return match ($type) {
                'POLYGON' => Wkt::parsePolygon((string) $this->boundary_wkt),
                // A multipolygon is flattened to its first polygon's rings for
                // containment purposes. Areas made of disjoint parts are rare
                // in Erbil and are handled explicitly where they occur.
                'MULTIPOLYGON' => Wkt::parseMultiPolygon((string) $this->boundary_wkt)[0] ?? null,
                default => null,
            };
        } catch (InvalidArgumentException) {
            // A malformed stored boundary must not break a page render. The
            // data-quality report is where it surfaces.
            return null;
        }
    }

    public function hasBoundary(): bool
    {
        return $this->boundaryRings() !== null;
    }

    public function containsPoint(Coordinates $point): bool
    {
        $rings = $this->boundaryRings();

        return $rings !== null && Polygon::polygonContains($rings, $point);
    }

    public function boundingBox(): ?BoundingBox
    {
        /*
         * THE CACHED BOX IS OPTIONAL, because not every model using this trait
         * has one. `places` carries coordinates but no `bbox_*` columns, so
         * reading them there is an undefined-property access that only stayed
         * invisible because the branch is never reached for a place. Asking the
         * model whether it HAS the attribute keeps the fast path for areas and
         * makes the trait honest about the models that lack it.
         */
        if ($this->hasCachedBoundingBox()) {
            return BoundingBox::make(
                (float) $this->getAttribute('bbox_min_lat'),
                (float) $this->getAttribute('bbox_min_lng'),
                (float) $this->getAttribute('bbox_max_lat'),
                (float) $this->getAttribute('bbox_max_lng'),
            );
        }

        $rings = $this->boundaryRings();

        return $rings === null ? null : Polygon::boundingBox($rings[0]);
    }

    /** Whether this model's table has the given column. */
    private function hasColumn(string $column): bool
    {
        return array_key_exists($column, $this->getAttributes())
            || Schema::hasColumn($this->getTable(), $column);
    }

    /** Whether this model stores a cached bounding box at all. */
    private function storesBoundingBox(): bool
    {
        foreach (['bbox_min_lat', 'bbox_min_lng', 'bbox_max_lat', 'bbox_max_lng'] as $column) {
            if (! $this->hasColumn($column)) {
                return false;
            }
        }

        return true;
    }

    /** Whether this model stores a precomputed bounding box. */
    private function hasCachedBoundingBox(): bool
    {
        foreach (['bbox_min_lat', 'bbox_min_lng', 'bbox_max_lat', 'bbox_max_lng'] as $column) {
            if (! array_key_exists($column, $this->getAttributes())) {
                return false;
            }
        }

        return $this->getAttribute('bbox_min_lat') !== null
            && $this->getAttribute('bbox_max_lng') !== null;
    }

    /**
     * Recompute the cached bounding box, centroid and area from the boundary.
     *
     * Called on save rather than on read: map fitting happens on every page
     * view, and re-parsing a 400-vertex polygon each time to find its extent
     * would be the slowest thing on the page.
     */
    public function syncDerivedGeometry(): void
    {
        $rings = $this->boundaryRings();

        if ($rings === null) {
            return;
        }

        $box = Polygon::boundingBox($rings[0]);

        /*
         * ONLY WRITE COLUMNS THIS MODEL HAS.
         *
         * The trait is shared by Area, Place, Project and CompanyBranch, and
         * only some of them store a cached bounding box. Assigning
         * unconditionally sets an attribute the table has no column for, which
         * SQLite and MySQL both reject on save — the read path was already
         * guarded, the write path was not.
         */
        if ($box !== null && $this->storesBoundingBox()) {
            /*
             * `setAttribute()` rather than `$this->bbox_min_lat = ...`, because
             * these columns exist on some models using this trait and not
             * others. A dynamic property assignment claims every consumer has
             * them; the attribute API says what is actually happening — an
             * optional column being written when the model has it.
             */
            $this->setAttribute('bbox_min_lat', $box->minLatitude);
            $this->setAttribute('bbox_min_lng', $box->minLongitude);
            $this->setAttribute('bbox_max_lat', $box->maxLatitude);
            $this->setAttribute('bbox_max_lng', $box->maxLongitude);
        }

        // Only derive a centre when none was set by hand. An administrator who
        // placed a marker at a project's entrance meant that, not the
        // mathematical centre of its plot.
        if ($this->latitude === null || $this->longitude === null) {
            $centroid = Polygon::centroid($rings[0]);

            if ($centroid !== null) {
                $this->setCoordinates($centroid);
            }
        }

        // Same reasoning: `projects` carries a boundary but no area column.
        // Same reasoning: `projects` carries a boundary but no area column.
        if ($this->hasColumn('area_sqm')) {
            $this->setAttribute('area_sqm', (int) round(Polygon::areaSquareMetres($rings[0])));
        }
    }

    public function distanceTo(Coordinates $point): ?float
    {
        $own = $this->coordinates();

        return $own === null ? null : Geodesy::distanceMetres($own, $point);
    }

    /**
     * Bounding-box pre-filter.
     *
     * Four indexed range predicates on DECIMAL columns. This runs identically
     * on MySQL and SQLite, which is the whole point of not depending on
     * ST_Distance_Sphere for the hot path. Exact distance is then computed in
     * PHP over the much smaller surviving set.
     *
     * `static` is the model USING this trait — Area, Place, Project and
     * CompanyBranch each get a builder for themselves, which is what these
     * scopes actually return. Naming `Model` instead would be broad enough to
     * be useless and would lose the model behaviour every caller relies on.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithinBox(Builder $query, BoundingBox $box): Builder
    {
        return $query
            ->whereBetween('latitude', [$box->minLatitude, $box->maxLatitude])
            ->whereBetween('longitude', [$box->minLongitude, $box->maxLongitude]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithinRadius(Builder $query, Coordinates $centre, float $radiusMetres): Builder
    {
        return $this->scopeWithinBox($query, BoundingBox::aroundRadius($centre, $radiusMetres));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeHasCoordinates(Builder $query): Builder
    {
        return $query->whereNotNull('latitude')->whereNotNull('longitude');
    }
}

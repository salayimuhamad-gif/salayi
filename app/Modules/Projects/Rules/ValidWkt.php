<?php

declare(strict_types=1);

namespace App\Modules\Projects\Rules;

use App\Modules\Geography\Support\Topology;
use App\Modules\Geography\Support\Wkt;
use App\Modules\Geography\ValueObjects\Coordinates;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * A boundary must be well-formed, valid POLYGON or MULTIPOLYGON geometry.
 *
 * `['string', 'max:200000']` accepts anything. A malformed ring then reaches
 * the map layer, where it is skipped silently — the project simply has no
 * outline and nobody is told why. Rejecting at entry puts the error in front
 * of the person who can fix it.
 *
 * REJECT, NEVER REPAIR. Closing an open ring silently turns the shape somebody
 * drew into a different one and stores it as though they had drawn it.
 *
 * Every predicate below lives in {@see Topology}, which is also what the
 * framework-free geometry suite calls. That matters: the suite previously
 * reimplemented each test as a local closure, so it passed whether or not this
 * rule was correct — and it was green while MultiPolygon components were never
 * compared at all.
 */
final class ValidWkt implements ValidationRule
{
    /** Below this, in squared degrees, a ring encloses nothing usable. */
    private const MIN_AREA = 1e-12;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('projects.wizard.creation.error_wkt_invalid'))->translate();

            return;
        }

        $type = Wkt::type($value);

        if (! in_array($type, ['POLYGON', 'MULTIPOLYGON'], true)) {
            $fail(__('projects.wizard.creation.error_wkt_type'))->translate();

            return;
        }

        try {
            $polygons = $type === 'MULTIPOLYGON'
                ? Wkt::parseMultiPolygon($value)
                : [Wkt::parsePolygon($value)];
        } catch (Throwable) {
            $fail(__('projects.wizard.creation.error_wkt_invalid'))->translate();

            return;
        }

        if ($polygons === []) {
            $fail(__('projects.wizard.creation.error_wkt_invalid'))->translate();

            return;
        }

        foreach ($polygons as $rings) {
            if (! $this->validatePolygon($rings, $fail)) {
                return;
            }
        }

        /*
         * MULTIPOLYGON COMPONENTS must be disjoint. Two components describing
         * the same ground double-count area and make point-in-polygon
         * order-dependent. Exteriors only: a hole in one component has no
         * bearing on another.
         */
        for ($i = 0; $i < count($polygons); $i++) {
            for ($j = $i + 1; $j < count($polygons); $j++) {
                /*
                 * COMPLETE components, holes included. Comparing exteriors
                 * alone rejected a valid enclave — a component legitimately
                 * sited inside another's hole — while still missing nothing
                 * that genuinely overlaps.
                 */
                if (Topology::componentsOverlap($polygons[$i], $polygons[$j])) {
                    $fail(__('projects.wizard.creation.error_wkt_parts_overlap'))->translate();

                    return;
                }
            }
        }
    }

    /**
     * @param  list<list<Coordinates>>  $rings
     * @return bool false when the polygon was rejected
     */
    private function validatePolygon(array $rings, Closure $fail): bool
    {
        if ($rings === []) {
            $fail(__('projects.wizard.creation.error_wkt_invalid'))->translate();

            return false;
        }

        foreach ($rings as $index => $ring) {
            if (! Topology::isClosed($ring)) {
                $fail(__('projects.wizard.creation.error_wkt_open'))->translate();

                return false;
            }

            if (Topology::distinctVertexCount($ring) < 3) {
                $fail(__('projects.wizard.creation.error_wkt_degenerate'))->translate();

                return false;
            }

            foreach ($ring as $point) {
                // A longitude typed into a latitude field renders a plausible
                // polygon in the wrong hemisphere, so the range check is
                // per-ordinate rather than a general sanity test.
                if (abs($point->latitude) > 90.0 || abs($point->longitude) > 180.0) {
                    $fail(__('projects.wizard.creation.error_wkt_range'))->translate();

                    return false;
                }
            }

            // Collinear: three distinct points on one line enclose nothing and
            // render as a hairline.
            if (abs(Topology::signedArea($ring)) < self::MIN_AREA) {
                $fail(__('projects.wizard.creation.error_wkt_collinear'))->translate();

                return false;
            }

            if (Topology::ringSelfIntersects($ring)) {
                $fail(__('projects.wizard.creation.error_wkt_self_intersect'))->translate();

                return false;
            }

            // A hole outside its exterior is not a hole; it is a second shape
            // claiming to be a void, and renderers disagree about it.
            if ($index > 0 && ! Topology::ringInside($ring, $rings[0])) {
                $fail(__('projects.wizard.creation.error_wkt_hole_outside'))->translate();

                return false;
            }
        }

        // Holes overlapping each other produce undefined fill.
        for ($i = 1; $i < count($rings); $i++) {
            for ($j = $i + 1; $j < count($rings); $j++) {
                if (Topology::ringsOverlap($rings[$i], $rings[$j])) {
                    $fail(__('projects.wizard.creation.error_wkt_hole_overlap'))->translate();

                    return false;
                }
            }
        }

        return true;
    }
}

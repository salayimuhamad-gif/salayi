<?php

declare(strict_types=1);

namespace App\Modules\Geography\Enums;

/**
 * The geographic hierarchy (spec 10.2).
 *
 * Ordered coarse to fine. `depth()` is what enforces that a district cannot be
 * the child of a neighbourhood — the hierarchy is a containment relationship,
 * not a free-form tree, and letting it invert would make every "projects in
 * this district" query silently wrong.
 */
enum AreaType: string
{
    case City = 'city';
    case District = 'district';
    /*
     * ناحیە / ناحية. File two §4.1 lists قضاء (District) and ناحية as separate
     * administrative levels, and in Erbil they genuinely are: a nahiya sits
     * inside a qadha and above the sectors. Without it the two collapse and
     * "every project in this qadha" cannot be distinguished from "every project
     * in this nahiya".
     */
    case Nahiya = 'nahiya';
    case Sector = 'sector';
    case Zone = 'zone';
    case Neighborhood = 'neighborhood';
    case Mahalla = 'mahalla';

    public function depth(): int
    {
        return match ($this) {
            self::City => 0,
            self::District => 1,
            self::Nahiya => 2,
            self::Sector => 3,
            self::Zone => 4,
            self::Neighborhood => 5,
            self::Mahalla => 6,
        };
    }

    /** A parent must be strictly coarser than its child. */
    public function canContain(self $child): bool
    {
        return $this->depth() < $child->depth();
    }

    /** Types that are normally drawn as a boundary rather than a point. */
    public function expectsBoundary(): bool
    {
        return $this !== self::Mahalla;
    }

    public function label(): string
    {
        return __('geography.area_types.'.$this->value);
    }
}

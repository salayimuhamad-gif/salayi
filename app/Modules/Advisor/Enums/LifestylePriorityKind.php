<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Enums;

/**
 * The places a household actually organises its life around (File two §8).
 *
 * §8 is the requirement that separates this product from a price filter: a
 * family does not choose a home by budget and district, they choose it by
 * whether one parent can reach work, the other can reach their own work, the
 * children can reach school, and somebody can reach the grandparents.
 *
 * The kinds are an enum rather than free text because `LifestyleMatcher`
 * groups them into scoring components by name — `workplace` and
 * `spouse_workplace` feed commute_fit, the three education kinds feed
 * school_fit, `parents` and `relatives` feed family_proximity. A typo in a
 * stored string would silently drop that priority out of scoring: the profile
 * would look complete, the score would be computed from fewer inputs, and
 * nothing would report it.
 *
 * The string values are frozen. They are persisted in `lifestyle_priorities.kind`
 * and read by the matcher; renaming one would orphan every stored priority.
 */
enum LifestylePriorityKind: string
{
    // Work — the two commute anchors of a two-income household.
    case Workplace = 'workplace';
    case SpouseWorkplace = 'spouse_workplace';

    // Education. Two children are addressable separately because families
    // routinely have them at different schools, and averaging the two would
    // hide a home that is excellent for one and impossible for the other.
    case ChildOneSchool = 'child_one_school';
    case ChildTwoSchool = 'child_two_school';
    case University = 'university';

    // Family. §8 lists parents and relatives separately; in Erbil these are
    // frequently the deciding factor and are not interchangeable.
    case Parents = 'parents';
    case Relatives = 'relatives';

    // Health, retail and leisure.
    case Hospital = 'hospital';
    case Market = 'market';
    case Mall = 'mall';
    case Park = 'park';

    case CustomLocation = 'custom_location';

    /**
     * The scoring component this kind contributes to.
     *
     * Mirrors the grouping inside LifestyleMatcher::score(). Exposed so the
     * interface can show a household WHY a priority matters and which part of
     * the score it moves — §8 requires the result be explainable, and "this
     * affects your commute score" is part of that explanation.
     */
    public function component(): string
    {
        return match ($this) {
            self::Workplace, self::SpouseWorkplace => 'commute_fit',
            self::ChildOneSchool, self::ChildTwoSchool, self::University => 'school_fit',
            self::Parents, self::Relatives => 'family_proximity',
            default => 'service_coverage',
        };
    }

    /**
     * Whether this kind is normally pinned to a specific coordinate rather
     * than satisfied by any nearby example.
     *
     * A workplace is one building. A park is any park. The distinction changes
     * what the interface should ask for: a map pin for the first, a maximum
     * distance for the second.
     */
    public function isSpecificLocation(): bool
    {
        return match ($this) {
            self::Workplace, self::SpouseWorkplace, self::ChildOneSchool,
            self::ChildTwoSchool, self::University, self::Parents,
            self::Relatives, self::CustomLocation => true,
            default => false,
        };
    }

    /**
     * Sensitivity of the stored coordinate.
     *
     * A workplace and a child's school are among the most sensitive locations
     * this product holds (spec 32.2). They must never reach a public payload,
     * an analytics event or another user's view, and this flag is what lets a
     * serializer decide that without hard-coding a list in three places.
     */
    public function isSensitive(): bool
    {
        return match ($this) {
            self::Workplace, self::SpouseWorkplace, self::ChildOneSchool,
            self::ChildTwoSchool, self::Parents, self::Relatives, self::CustomLocation => true,
            default => false,
        };
    }

    public function label(): string
    {
        return __('advisor.priority_kinds.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}

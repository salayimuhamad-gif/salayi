<?php

declare(strict_types=1);

namespace App\Modules\Advertising\Enums;

/**
 * Where an advertisement may appear (File two §13).
 *
 * An enum rather than free text because a typo would create a surface nothing
 * ever renders — the campaign would look live, be billed, and show nowhere.
 */
enum AdSurface: string
{
    case Home = 'home';
    case Project = 'project';
    case Area = 'area';
    case Map = 'map';
    case Search = 'search';
    case Offers = 'offers';
    case News = 'news';
    case Companies = 'companies';
    case Custom = 'custom';

    /**
     * Whether this surface sits beside ranked organic results.
     *
     * These are the surfaces where the §8.9 separation matters most: a slot on
     * the map or in search results is adjacent to output a scorer produced, so
     * the label has to be unambiguous and the slot visually distinct. A banner
     * on the news page has no ranking to be confused with.
     */
    public function isAdjacentToRankedResults(): bool
    {
        return match ($this) {
            self::Search, self::Map, self::Project, self::Area => true,
            default => false,
        };
    }

    public function label(): string
    {
        return __('advertising.surfaces.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}

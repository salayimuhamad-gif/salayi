<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use Illuminate\Support\Collection;

/**
 * The city and area choices offered on the profile screens.
 *
 * NOTHING HERE IS HARD-CODED, and that is the requirement rather than a
 * preference. Erbil's neighbourhoods are administrator data: they arrive
 * through the existing Geography admin, they are published or not through the
 * existing `publication_status`, and this class only reads what is there. Ship
 * with an empty `areas` table and the city picker is empty and the area picker
 * is hidden; publish one city tomorrow and it appears with no code change, no
 * migration and no deploy.
 *
 * WHY THERE IS NO `profile_city_id` COLUMN. `users.profile_area_id` already
 * exists and already points at this table, and the table is a hierarchy — city
 * contains district contains nahiya, and so on down to mahalla, materialised as
 * a path like "/1/7/23/". A city column beside the area column would be a
 * second source of truth for a fact the first one already determines, and the
 * two would eventually disagree. So exactly one value is stored — the finest
 * choice the person actually made — and the city is DERIVED from its path.
 *
 * That also answers "what if there are no neighbourhoods yet": the person picks
 * a city, the city IS their selection, and the same column holds it. Nothing
 * needs a placeholder and no fake list is invented to fill a dropdown.
 */
final class ProfileLocationOptions
{
    /**
     * Published cities, as `{id, name}` in the requested language.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function cities(?string $locale = null): array
    {
        return Area::query()
            ->published()
            ->ofType(AreaType::City)
            ->orderBy('name_ckb')
            ->get()
            ->map(static fn (Area $area): array => [
                'id' => (int) $area->id,
                'name' => $area->name($locale),
            ])
            ->all();
    }

    /**
     * Published areas BELOW a city, each tagged with the city it belongs to.
     *
     * Returned for every city at once rather than per-city on demand: the whole
     * published set for one deployment is small, sending it with the page keeps
     * the picker instant, and it avoids a request-per-keystroke endpoint that
     * would need its own rate limit and its own authorisation story.
     *
     * The city id comes from the FIRST segment of the materialised path, which
     * is the root ancestor — the same derivation the hierarchy itself uses, so
     * a re-parented area follows its new city without anything being rebuilt.
     *
     * @return array<int, array{id: int, city_id: int, name: string}>
     */
    public static function areas(?string $locale = null): array
    {
        /** @var Collection<int, Area> $areas */
        $areas = Area::query()
            ->published()
            ->where('type', '!=', AreaType::City->value)
            ->orderBy('name_ckb')
            ->get();

        return $areas
            ->map(static function (Area $area) use ($locale): ?array {
                $root = self::rootId($area);

                // An area whose path names no ancestor is orphaned data. It is
                // skipped rather than shown under a city it does not belong to
                // — a wrong grouping is worse than an absent one.
                return $root === null ? null : [
                    'id' => (int) $area->id,
                    'city_id' => $root,
                    'name' => $area->name($locale),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The city an area sits under, or the area itself when it IS a city.
     *
     * Used to re-open the picker on the city the person chose last time,
     * without storing that city anywhere.
     */
    public static function cityIdFor(?int $areaId): ?int
    {
        if ($areaId === null) {
            return null;
        }

        $area = Area::query()->find($areaId);

        if ($area === null) {
            return null;
        }

        return $area->type === AreaType::City ? (int) $area->id : self::rootId($area);
    }

    /** The outermost ancestor id in a materialised path like "/1/7/23/". */
    private static function rootId(Area $area): ?int
    {
        $segments = array_values(array_filter(explode('/', trim((string) $area->path, '/')), static fn (string $s): bool => $s !== ''));

        return $segments === [] ? null : (int) $segments[0];
    }
}

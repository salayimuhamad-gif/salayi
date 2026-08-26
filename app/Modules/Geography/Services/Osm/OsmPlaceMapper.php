<?php

declare(strict_types=1);

namespace App\Modules\Geography\Services\Osm;

use App\Modules\Geography\Enums\PlaceCategoryKey;
use App\Modules\Geography\ValueObjects\BoundingBox;
use App\Modules\Geography\ValueObjects\Coordinates;

/**
 * OSM object -> MULK place candidate (Map Phase 2).
 *
 * An EXPLICIT whitelist in both directions: the selector sets below decide
 * what is even asked of Overpass, and the rule table decides what an answer
 * may become. Anything that matches no rule — an amenity we never asked
 * about, a place of worship of unstated religion — is SKIPPED with a counted
 * reason, never guessed into a category.
 *
 * Names follow spec 7.1's honesty rule: `name:ckb` is the only value that is
 * actually a Kurdish name. When it is absent the canonical `name` (or an
 * ar/en name) fills `name_ckb` purely because the schema requires a primary
 * name — and the record then carries `tags.name_fallback` naming the tag the
 * text really came from, so the reviewing administrator sees a fallback, not
 * a translation. Machine translation is never involved. Objects with no name
 * at all are skipped: inventing "School" for an anonymous building would be
 * fabrication, and the map already shows unnamed context through the
 * basemap itself.
 *
 * Provenance: `source=openstreetmap`, `source_url` pointing at the object,
 * and only the mapped tags (plus religion) preserved — no editor usernames,
 * uids or changesets, which the client already strips before caching.
 */
final class OsmPlaceMapper
{
    public const SOURCE = 'openstreetmap';

    /** Skip-reason keys, closed set (the preview reports these). */
    public const SKIP_UNMAPPED = 'unmapped';

    public const SKIP_UNNAMED = 'unnamed';

    public const SKIP_OUT_OF_BOUNDS = 'out_of_bounds';

    public const SKIP_INVALID_COORDS = 'invalid_coords';

    /**
     * Import groups the admin can pick, each with its own Overpass selector
     * set. Group-stable queries are what make the 24h response cache reusable
     * across previews: education always asks the same question, whichever
     * subset of it the operator later accepts.
     *
     * @var array<string, list<string>>
     */
    private const GROUPS = [
        'education' => [
            '["amenity"="school"]',
            '["amenity"="kindergarten"]',
            '["amenity"="university"]',
            '["amenity"="college"]',
        ],
        'health' => [
            '["amenity"="hospital"]',
            '["amenity"="clinic"]',
            '["amenity"="pharmacy"]',
        ],
        'shopping' => [
            '["shop"="mall"]',
            '["shop"="supermarket"]',
            '["amenity"="marketplace"]',
        ],
        'food' => [
            '["amenity"="restaurant"]',
            '["amenity"="cafe"]',
        ],
        'recreation' => [
            '["leisure"="park"]',
            '["leisure"="sports_centre"]',
            '["leisure"="stadium"]',
            '["leisure"="pitch"]',
        ],
        'worship' => [
            '["amenity"="place_of_worship"]',
        ],
        'civic' => [
            '["office"="government"]',
            '["amenity"="townhall"]',
            '["amenity"="courthouse"]',
            '["amenity"="police"]',
            '["amenity"="fire_station"]',
        ],
        'finance' => [
            '["amenity"="bank"]',
            '["amenity"="atm"]',
        ],
        'transport' => [
            '["amenity"="bus_station"]',
            '["highway"="bus_stop"]',
            '["public_transport"="platform"]',
            '["amenity"="fuel"]',
            '["aeroway"="aerodrome"]',
        ],
        'hospitality' => [
            '["tourism"="hotel"]',
        ],
    ];

    /** @return list<string> */
    public static function groups(): array
    {
        return array_keys(self::GROUPS);
    }

    /** @return list<string> */
    public static function selectorsFor(string $group): array
    {
        return self::GROUPS[$group] ?? [];
    }

    /**
     * Map one pruned Overpass element to an import candidate.
     *
     * A wide, single shape rather than a union: candidate is null exactly
     * when the element was skipped, and reason says why.
     *
     * @param  array<string, mixed>  $element
     * @return array{candidate: array<string, mixed>|null, reason: string|null}
     */
    public function map(array $element): array
    {
        /** @var array<string, mixed> $tags */
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];

        $mapped = $this->category($tags);

        if ($mapped === null) {
            return ['candidate' => null, 'reason' => self::SKIP_UNMAPPED];
        }

        [$category, $subcategory, $provenanceTags] = $mapped;

        $point = Coordinates::tryMake(
            (string) ($element['lat'] ?? ''),
            (string) ($element['lon'] ?? ''),
        );

        if ($point === null || $point->isNullIsland()) {
            return ['candidate' => null, 'reason' => self::SKIP_INVALID_COORDS];
        }

        if (! BoundingBox::operatingArea()->contains($point)) {
            return ['candidate' => null, 'reason' => self::SKIP_OUT_OF_BOUNDS];
        }

        $names = $this->names($tags);

        if ($names === null) {
            return ['candidate' => null, 'reason' => self::SKIP_UNNAMED];
        }

        if ($names['fallback'] !== null) {
            $provenanceTags['name_fallback'] = $names['fallback'];
        }

        $type = (string) $element['type'];
        $id = (int) $element['id'];

        $website = $this->website($tags);

        return ['reason' => null, 'candidate' => [
            'external_id' => sprintf('osm:%s:%d', $type, $id),
            'category_key' => $category->value,
            'subcategory' => $subcategory,
            'name_ckb' => $names['ckb'],
            'name_ar' => $names['ar'],
            'name_en' => $names['en'],
            'aliases' => $names['aliases'],
            'lat' => $point->latitude,
            'lng' => $point->longitude,
            'website' => $website,
            'tags' => $provenanceTags,
            'source_url' => sprintf('https://www.openstreetmap.org/%s/%d', $type, $id),
        ]];
    }

    /**
     * The rule table. First match wins, so an object tagged both shop and
     * amenity resolves the same way every run.
     *
     * @param  array<string, mixed>  $tags
     * @return array{0: PlaceCategoryKey, 1: ?string, 2: array<string, string>}|null
     */
    private function category(array $tags): ?array
    {
        $amenity = $this->tag($tags, 'amenity');
        $shop = $this->tag($tags, 'shop');
        $leisure = $this->tag($tags, 'leisure');
        $tourism = $this->tag($tags, 'tourism');
        $office = $this->tag($tags, 'office');
        $highway = $this->tag($tags, 'highway');
        $publicTransport = $this->tag($tags, 'public_transport');
        $aeroway = $this->tag($tags, 'aeroway');
        $religion = $this->tag($tags, 'religion');

        $simpleAmenity = match ($amenity) {
            'school' => PlaceCategoryKey::School,
            'kindergarten' => PlaceCategoryKey::Kindergarten,
            'university' => PlaceCategoryKey::University,
            // The closest existing key: a college is a post-secondary
            // institute, and inventing a new category is not this importer's
            // call.
            'college' => PlaceCategoryKey::Institute,
            'hospital' => PlaceCategoryKey::Hospital,
            'clinic' => PlaceCategoryKey::Clinic,
            'pharmacy' => PlaceCategoryKey::Pharmacy,
            'marketplace' => PlaceCategoryKey::Market,
            'restaurant' => PlaceCategoryKey::Restaurant,
            'cafe' => PlaceCategoryKey::Cafe,
            'townhall', 'courthouse' => PlaceCategoryKey::GovernmentOffice,
            'police' => PlaceCategoryKey::Police,
            'fire_station' => PlaceCategoryKey::FireStation,
            'bank' => PlaceCategoryKey::Bank,
            'atm' => PlaceCategoryKey::Atm,
            'bus_station' => PlaceCategoryKey::BusStation,
            'fuel' => PlaceCategoryKey::FuelStation,
            default => null,
        };

        if ($simpleAmenity !== null) {
            return [$simpleAmenity, null, ['amenity' => (string) $amenity]];
        }

        if ($amenity === 'place_of_worship') {
            // An unknown religion is never guessed into a mosque or church.
            return match ($religion) {
                'muslim' => [PlaceCategoryKey::Mosque, null,
                    ['amenity' => 'place_of_worship', 'religion' => 'muslim']],
                'christian' => [PlaceCategoryKey::Church, null,
                    ['amenity' => 'place_of_worship', 'religion' => 'christian']],
                default => null,
            };
        }

        if ($shop === 'mall') {
            return [PlaceCategoryKey::Mall, null, ['shop' => 'mall']];
        }

        if ($shop === 'supermarket') {
            return [PlaceCategoryKey::Supermarket, null, ['shop' => 'supermarket']];
        }

        if (in_array($leisure, ['sports_centre', 'stadium', 'pitch'], true)) {
            return [PlaceCategoryKey::SportsFacility, null, ['leisure' => (string) $leisure]];
        }

        if ($leisure === 'park') {
            return [PlaceCategoryKey::Park, null, ['leisure' => 'park']];
        }

        if ($tourism === 'hotel') {
            return [PlaceCategoryKey::Hotel, null, ['tourism' => 'hotel']];
        }

        if ($office === 'government') {
            return [PlaceCategoryKey::GovernmentOffice, null, ['office' => 'government']];
        }

        // A bus stop is the existing transport category with a subcategory —
        // deliberately NOT a parallel model. Platforms count only when they
        // are unambiguously bus platforms.
        if ($highway === 'bus_stop') {
            return [PlaceCategoryKey::BusStation, 'bus_stop', ['highway' => 'bus_stop']];
        }

        if ($publicTransport === 'platform' && $this->tag($tags, 'bus') === 'yes') {
            return [PlaceCategoryKey::BusStation, 'bus_stop',
                ['public_transport' => 'platform', 'bus' => 'yes']];
        }

        if ($aeroway === 'aerodrome') {
            return [PlaceCategoryKey::Airport, null, ['aeroway' => 'aerodrome']];
        }

        return null;
    }

    /**
     * Name extraction per the honesty rules in the class docblock.
     *
     * @param  array<string, mixed>  $tags
     * @return array{ckb: string, ar: ?string, en: ?string, fallback: ?string, aliases: list<string>}|null
     */
    private function names(array $tags): ?array
    {
        $nameCkb = $this->tag($tags, 'name:ckb');
        $name = $this->tag($tags, 'name');
        $nameAr = $this->tag($tags, 'name:ar');
        $nameEn = $this->tag($tags, 'name:en');

        $fallback = null;
        $primary = $nameCkb;

        if ($primary === null) {
            foreach (['name' => $name, 'name:ar' => $nameAr, 'name:en' => $nameEn] as $source => $value) {
                if ($value !== null) {
                    $primary = $value;
                    $fallback = $source;
                    break;
                }
            }
        }

        if ($primary === null) {
            return null;
        }

        // Alternate spellings feed the search key; the primary trio is
        // excluded so aliases add reach instead of repeating the row.
        $aliases = [];

        foreach (['name:ku', 'alt_name', 'old_name', 'int_name'] as $key) {
            $value = $this->tag($tags, $key);

            if ($value !== null && ! in_array($value, [$primary, $nameAr, $nameEn], true)) {
                $aliases[] = $value;
            }
        }

        if ($name !== null && $fallback === null && $name !== $primary) {
            // A canonical name that differs from the Kurdish one is worth
            // finding by.
            $aliases[] = $name;
        }

        return [
            'ckb' => mb_substr($primary, 0, 191),
            'ar' => $nameAr === null ? null : mb_substr($nameAr, 0, 191),
            'en' => $nameEn === null ? null : mb_substr($nameEn, 0, 191),
            'fallback' => $fallback,
            'aliases' => array_values(array_unique($aliases)),
        ];
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function website(array $tags): ?string
    {
        $value = $this->tag($tags, 'website') ?? $this->tag($tags, 'contact:website');

        if ($value === null || mb_strlen($value) > 255) {
            return null;
        }

        return str_starts_with($value, 'https://') || str_starts_with($value, 'http://')
            ? $value
            : null;
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function tag(array $tags, string $key): ?string
    {
        $value = $tags[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

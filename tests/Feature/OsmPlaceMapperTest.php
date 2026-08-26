<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Services\Osm\OsmPlaceMapper;
use Tests\TestCase;

/**
 * The OSM tag whitelist (Map Phase 2).
 *
 * Every supported mapping is pinned individually so a "small" edit to the
 * rule table shows up as a named failure, and the refusals are pinned just
 * as hard: unknown tags, unknown religions and nameless objects are skipped,
 * never guessed. Names follow the honesty rules — name:ckb is the only
 * value treated as Kurdish; anything else filling the required column is
 * flagged as a fallback for the reviewing admin.
 */
final class OsmPlaceMapperTest extends TestCase
{
    private function mapper(): OsmPlaceMapper
    {
        return new OsmPlaceMapper;
    }

    /**
     * Narrowed candidate access: fails the test with a message when the
     * element was skipped, and hands PHPStan a plain array either way.
     *
     * @param  array{candidate: array<string, mixed>|null, reason: string|null}  $result
     * @return array<string, mixed>
     */
    private function candidate(array $result): array
    {
        $this->assertNull($result['reason'], 'expected a mapped candidate, got skip: '.($result['reason'] ?? ''));

        return $result['candidate'] ?? [];
    }

    /**
     * @param  array<string, string>  $tags
     * @return array<string, mixed>
     */
    private function node(array $tags, float $lat = 36.19, float $lon = 44.01, int $id = 1): array
    {
        return ['type' => 'node', 'id' => $id, 'lat' => $lat, 'lon' => $lon,
            'tags' => $tags + ['name' => 'Fixture']];
    }

    public function test_every_supported_tag_mapping(): void
    {
        $table = [
            [['amenity' => 'school'], 'school', null],
            [['amenity' => 'kindergarten'], 'kindergarten', null],
            [['amenity' => 'university'], 'university', null],
            [['amenity' => 'college'], 'institute', null],
            [['amenity' => 'hospital'], 'hospital', null],
            [['amenity' => 'clinic'], 'clinic', null],
            [['amenity' => 'pharmacy'], 'pharmacy', null],
            [['shop' => 'mall'], 'mall', null],
            [['shop' => 'supermarket'], 'supermarket', null],
            [['amenity' => 'marketplace'], 'market', null],
            [['amenity' => 'restaurant'], 'restaurant', null],
            [['amenity' => 'cafe'], 'cafe', null],
            [['leisure' => 'park'], 'park', null],
            [['leisure' => 'sports_centre'], 'sports_facility', null],
            [['leisure' => 'stadium'], 'sports_facility', null],
            [['leisure' => 'pitch'], 'sports_facility', null],
            [['amenity' => 'place_of_worship', 'religion' => 'muslim'], 'mosque', null],
            [['amenity' => 'place_of_worship', 'religion' => 'christian'], 'church', null],
            [['office' => 'government'], 'government_office', null],
            [['amenity' => 'townhall'], 'government_office', null],
            [['amenity' => 'courthouse'], 'government_office', null],
            [['amenity' => 'police'], 'police', null],
            [['amenity' => 'fire_station'], 'fire_station', null],
            [['amenity' => 'bank'], 'bank', null],
            [['amenity' => 'atm'], 'atm', null],
            [['amenity' => 'bus_station'], 'bus_station', null],
            [['highway' => 'bus_stop'], 'bus_station', 'bus_stop'],
            [['public_transport' => 'platform', 'bus' => 'yes'], 'bus_station', 'bus_stop'],
            [['amenity' => 'fuel'], 'fuel_station', null],
            [['aeroway' => 'aerodrome'], 'airport', null],
            [['tourism' => 'hotel'], 'hotel', null],
        ];

        foreach ($table as [$tags, $category, $subcategory]) {
            $candidate = $this->candidate($this->mapper()->map($this->node($tags)));

            $this->assertSame($category, $candidate['category_key'], (string) json_encode($tags));
            $this->assertSame($subcategory, $candidate['subcategory'], (string) json_encode($tags));
        }
    }

    public function test_refusals_are_skips_never_guesses(): void
    {
        $mapper = $this->mapper();

        // An amenity we never whitelisted.
        $unmapped = $mapper->map($this->node(['amenity' => 'nightclub']));
        $this->assertNull($unmapped['candidate']);
        $this->assertSame(OsmPlaceMapper::SKIP_UNMAPPED, $unmapped['reason']);

        // Worship of unstated or other religion is never a mosque or church.
        $noReligion = $mapper->map($this->node(['amenity' => 'place_of_worship']));
        $this->assertSame(OsmPlaceMapper::SKIP_UNMAPPED, $noReligion['reason']);

        $otherReligion = $mapper->map($this->node(['amenity' => 'place_of_worship', 'religion' => 'yazidi']));
        $this->assertSame(OsmPlaceMapper::SKIP_UNMAPPED, $otherReligion['reason']);

        // A platform that is not clearly a bus platform is not a bus stop.
        $platform = $mapper->map($this->node(['public_transport' => 'platform']));
        $this->assertSame(OsmPlaceMapper::SKIP_UNMAPPED, $platform['reason']);

        // A nameless object would need an invented name; skipped instead.
        $unnamed = $mapper->map([
            'type' => 'node', 'id' => 9, 'lat' => 36.19, 'lon' => 44.01,
            'tags' => ['amenity' => 'school'],
        ]);
        $this->assertSame(OsmPlaceMapper::SKIP_UNNAMED, $unnamed['reason']);

        // Baghdad is real and firmly outside the Erbil operating area.
        $outside = $mapper->map($this->node(['amenity' => 'school'], lat: 33.31, lon: 44.36));
        $this->assertSame(OsmPlaceMapper::SKIP_OUT_OF_BOUNDS, $outside['reason']);

        // Null island and garbage coordinates.
        $nullIsland = $mapper->map($this->node(['amenity' => 'school'], lat: 0.0, lon: 0.0));
        $this->assertSame(OsmPlaceMapper::SKIP_INVALID_COORDS, $nullIsland['reason']);
    }

    public function test_identity_and_provenance(): void
    {
        $way = $this->candidate($this->mapper()->map([
            'type' => 'way', 'id' => 445566, 'lat' => 36.20, 'lon' => 44.02,
            'tags' => ['amenity' => 'hospital', 'name' => 'West Emergency'],
        ]));

        $this->assertSame('osm:way:445566', $way['external_id']);
        $this->assertSame('https://www.openstreetmap.org/way/445566', $way['source_url']);
        // Only the matched mapping tags travel — plus the REQUIRED honesty
        // marker: this fixture has no name:ckb, so the canonical OSM name
        // filled the primary column and the provenance says exactly that.
        $this->assertSame(['amenity' => 'hospital', 'name_fallback' => 'name'], $way['tags']);

        $relation = $this->candidate($this->mapper()->map([
            'type' => 'relation', 'id' => 7, 'lat' => 36.21, 'lon' => 44.03,
            'tags' => ['amenity' => 'university', 'name' => 'Campus'],
        ]));
        $this->assertSame('osm:relation:7', $relation['external_id']);
    }

    public function test_names_are_honest(): void
    {
        $mapper = $this->mapper();

        // A real Kurdish name is used as itself — no fallback flag.
        $kurdish = $this->candidate($mapper->map($this->node([
            'amenity' => 'school',
            'name:ckb' => 'قوتابخانەی ئازادی',
            'name' => 'Azadi School',
            'name:ar' => 'مدرسة آزادي',
            'name:en' => 'Azadi School',
        ])));

        $this->assertSame('قوتابخانەی ئازادی', $kurdish['name_ckb']);
        $this->assertSame('مدرسة آزادي', $kurdish['name_ar']);
        $this->assertSame('Azadi School', $kurdish['name_en']);
        $this->assertIsArray($kurdish['tags']);
        $this->assertArrayNotHasKey('name_fallback', $kurdish['tags']);
        // The differing canonical name is preserved as an alias for search.
        $this->assertIsArray($kurdish['aliases']);
        $this->assertContains('Azadi School', $kurdish['aliases']);

        // No name:ckb -> the canonical name fills the required column, and
        // the record SAYS SO instead of posing as a translation.
        $fallback = $this->candidate($mapper->map($this->node(['amenity' => 'school', 'name' => 'Runaki School'])));
        $this->assertSame('Runaki School', $fallback['name_ckb']);
        $this->assertIsArray($fallback['tags']);
        $this->assertSame('name', $fallback['tags']['name_fallback']);
        $this->assertNull($fallback['name_en']);

        // name:ar-only object: primary falls back to Arabic and is flagged.
        $arabicOnly = $this->candidate($mapper->map([
            'type' => 'node', 'id' => 3, 'lat' => 36.19, 'lon' => 44.01,
            'tags' => ['amenity' => 'pharmacy', 'name:ar' => 'صيدلية النور'],
        ]));
        $this->assertSame('صيدلية النور', $arabicOnly['name_ckb']);
        $this->assertIsArray($arabicOnly['tags']);
        $this->assertSame('name:ar', $arabicOnly['tags']['name_fallback']);
        $this->assertSame('صيدلية النور', $arabicOnly['name_ar']);
    }

    public function test_websites_are_taken_only_when_they_are_urls(): void
    {
        $good = $this->candidate($this->mapper()->map($this->node(['amenity' => 'bank', 'website' => 'https://bank.example.com'])));
        $this->assertSame('https://bank.example.com', $good['website']);

        $bad = $this->candidate($this->mapper()->map($this->node(['amenity' => 'bank', 'website' => 'bank.example.com'])));
        $this->assertNull($bad['website']);
    }
}

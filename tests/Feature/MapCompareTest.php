<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /map/compare — 2–3 areas side by side (Map Phase 6).
 *
 * The contract under proof: the comparison COMPOSES the existing
 * authorities and invents nothing — publication and ancestry are the
 * profile's rule, services are AreaServiceSummary's counts, prices are the
 * extracted Wave 3 lookup field-for-field, movement is the heatmap's one
 * deterministic claim per area — and DIRECT comparison happens only under
 * an exactly matching evidence identity: asking never meets verified, USD
 * never meets IQD, bases and methodology versions never cross, absence is
 * never zero or flat, and no winner, score or weight exists anywhere.
 */
final class MapCompareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures([
            'map.explorer' => true,
            'market.intelligence' => true,
            'market.indices' => true,
            'places.database' => true,
        ]);
    }

    /* -------------------------------------------------------- fixtures */

    /** @param array<model-property<Area>, mixed> $overrides */
    private function area(string $slug, array $overrides = [], bool $bounded = false): Area
    {
        $area = Area::query()->create($overrides + [
            'type' => 'district',
            'slug' => $slug,
            'name_ckb' => 'ناوچە '.$slug,
            'latitude' => 36.19,
            'longitude' => 44.01,
            'publication_status' => 'published',
        ]);

        if ($bounded) {
            $area->forceFill([
                'boundary_wkt' => 'POLYGON((44.005 36.180, 44.025 36.180, 44.025 36.205, 44.005 36.205, 44.005 36.180))',
            ])->save();
        }

        return $area->refresh();
    }

    /** @param array<model-property<MarketIndex>, mixed> $overrides */
    private function index(Area $area, array $overrides = []): MarketIndex
    {
        static $sequence = 0;

        return MarketIndex::query()->create($overrides + [
            'key' => 'cmp-'.$area->slug.'-'.(++$sequence),
            'name_ckb' => 'پێوەری '.$area->slug,
            'scope_type' => 'area',
            'scope_id' => $area->id,
            'property_type' => null,
            'price_type' => 'sale_asking',
            'basis' => 'median',
            'currency' => 'USD',
            'methodology_version' => 'v1',
            'minimum_sample' => 3,
            'publication_status' => 'published',
        ]);
    }

    /** @param array<model-property<MarketIndexValue>, mixed> $overrides */
    private function value(MarketIndex $index, string $period, ?string $value, array $overrides = []): MarketIndexValue
    {
        return MarketIndexValue::query()->create($overrides + [
            'market_index_id' => $index->id,
            'period' => $period,
            'effective_date' => $period.'-01',
            'value' => $value,
            'sample_size' => 12,
            'excluded_outliers' => 0,
            'confidence' => 'moderate',
            'is_limited' => false,
            'warning' => null,
            'methodology_version' => 'v1',
            'revision_status' => 'initial',
            'revision_number' => 0,
            'publication_status' => 'published',
        ]);
    }

    /**
     * A rising 100 → 105 series claimable under the default "all" window.
     *
     * @param  array<model-property<MarketIndex>, mixed>  $indexOverrides
     */
    private function risingSeries(Area $area, string $current = '105.0000', array $indexOverrides = []): MarketIndex
    {
        $index = $this->index($area, $indexOverrides);
        $this->value($index, '2026-01', '100.0000');
        $this->value($index, '2026-07', $current);

        return $index;
    }

    private function category(string $key = 'school'): PlaceCategory
    {
        return PlaceCategory::query()->firstOrCreate(
            ['key' => $key],
            ['group' => 'education', 'name_ckb' => 'ckb '.$key, 'name_en' => 'en '.$key, 'is_active' => true, 'sort_order' => 1],
        );
    }

    /** @param array<model-property<Place>, mixed> $overrides */
    private function place(Area $area, string $slug, array $overrides = []): Place
    {
        return Place::query()->create($overrides + [
            'slug' => $slug,
            'name_ckb' => $slug,
            'area_id' => $area->id,
            'place_category_id' => $this->category()->id,
            'latitude' => 36.21,
            'longitude' => 44.03,
            'publication_status' => 'published',
            'is_public' => true,
            'is_duplicate_primary' => true,
            'operational_status' => 'operating',
        ]);
    }

    /**
     * The response's fact list, keyed by fact key.
     *
     * @param  TestResponse<JsonResponse>  $response
     * @return array<string, array{key: string, params: array<string, string|null>}>
     */
    private function factsByKey(TestResponse $response): array
    {
        /** @var list<array{key: string, params: array<string, string|null>}> $facts */
        $facts = $response->json('facts');

        $byKey = [];

        foreach ($facts as $fact) {
            $byKey[$fact['key']] = $fact;
        }

        return $byKey;
    }

    /**
     * @param  list<string>  $slugs
     * @param  array<string, string>  $extra
     * @return TestResponse<JsonResponse>
     */
    private function compare(array $slugs, array $extra = []): TestResponse
    {
        $parts = array_map(static fn (string $slug): string => 'areas[]='.rawurlencode($slug), $slugs);

        foreach ($extra as $key => $value) {
            $parts[] = $key.'='.rawurlencode($value);
        }

        return $this->getJson('/map/compare?'.implode('&', $parts));
    }

    /* ------------------------------------------------------------ gates */

    public function test_the_endpoint_sits_behind_the_explorer_feature_flag(): void
    {
        $this->setFeatures(['map.explorer' => false]);
        $this->area('a');
        $this->area('b');

        $this->compare(['a', 'b'])->assertNotFound();
    }

    public function test_one_and_four_areas_are_rejected(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $slug) {
            $this->area($slug);
        }

        $this->compare(['a'])->assertStatus(422);
        $this->compare(['a', 'b', 'c', 'd'])->assertStatus(422);
        $this->getJson('/map/compare')->assertStatus(422);
    }

    public function test_duplicate_areas_are_rejected_with_a_validation_error(): void
    {
        $this->area('a');

        $this->compare(['a', 'a'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['areas.0', 'areas.1']);
    }

    public function test_two_and_three_areas_compare_and_the_submitted_order_is_preserved(): void
    {
        foreach (['alpha', 'beta', 'gamma'] as $slug) {
            $this->area($slug);
        }

        $this->compare(['beta', 'alpha'])
            ->assertOk()
            ->assertJsonPath('areas.0.slug', 'beta')
            ->assertJsonPath('areas.1.slug', 'alpha')
            ->assertJsonCount(2, 'areas');

        $this->compare(['gamma', 'alpha', 'beta'])
            ->assertOk()
            ->assertJsonPath('areas.0.slug', 'gamma')
            ->assertJsonPath('areas.1.slug', 'alpha')
            ->assertJsonPath('areas.2.slug', 'beta')
            ->assertJsonCount(3, 'areas');
    }

    public function test_unknown_and_unpublished_slugs_read_as_the_same_not_found(): void
    {
        $this->area('published-a');
        $this->area('draft-b', ['publication_status' => 'draft']);

        $this->compare(['published-a', 'missing'])->assertNotFound();
        $this->compare(['published-a', 'draft-b'])->assertNotFound();
    }

    public function test_a_published_area_under_an_unpublished_parent_stays_hidden(): void
    {
        $this->area('open');
        $draftParent = $this->area('draft-parent', ['publication_status' => 'draft']);
        $this->area('leaky-child', [
            'parent_id' => $draftParent->id,
            // A parent must be strictly coarser than its child (Area::booted).
            'type' => 'neighborhood',
        ]);

        $this->compare(['open', 'leaky-child'])->assertNotFound();
    }

    /* ------------------------------------------------------ area payload */

    public function test_an_area_column_carries_navigation_fields_and_never_geometry(): void
    {
        $this->area('bounded', [], true);
        $this->area('plain');

        $response = $this->compare(['bounded', 'plain'])
            ->assertOk()
            ->assertJsonPath('areas.0.bounds.north', 36.205)
            ->assertJsonPath('areas.0.bounds.west', 44.005)
            ->assertJsonPath('areas.1.bounds', null);

        $row = $response->json('areas.0');
        $this->assertSame(
            ['slug', 'name', 'type', 'type_label', 'breadcrumb', 'lat', 'lng', 'bounds',
                'services', 'services_reason', 'prices', 'movement'],
            array_keys($row),
        );

        $raw = $response->getContent() ?: '';
        $this->assertStringNotContainsString('POLYGON', $raw);
        $this->assertStringNotContainsString('phone', $raw);
    }

    /* --------------------------------------------------------- services */

    public function test_service_counts_come_from_the_same_summary_the_profile_uses(): void
    {
        $a = $this->area('mufti');
        $b = $this->area('ankawa');

        $this->place($a, 'school-1');
        $this->place($a, 'school-2');
        $this->place($a, 'hidden-school', ['is_public' => false]);
        $this->place($a, 'closed-school', ['operational_status' => 'permanently_closed']);
        $this->place($b, 'school-3');

        $this->compare(['mufti', 'ankawa'])
            ->assertOk()
            ->assertJsonPath('areas.0.services.0.key', 'education')
            ->assertJsonPath('areas.0.services.0.count', 2)
            ->assertJsonPath('areas.1.services.0.count', 1)
            ->assertJsonPath('areas.0.services_reason', null);
    }

    public function test_a_disabled_places_feature_is_unavailable_never_zero(): void
    {
        $this->setFeatures([
            'map.explorer' => true,
            'places.database' => false,
        ]);

        $a = $this->area('a');
        $this->area('b');
        $this->place($a, 'invisible-school');

        $this->compare(['a', 'b'])
            ->assertOk()
            ->assertJsonPath('areas.0.services', null)
            ->assertJsonPath('areas.0.services_reason', 'feature_disabled');
    }

    /* ----------------------------------------------------------- prices */

    public function test_price_rows_are_field_for_field_the_location_resolve_contract(): void
    {
        $area = $this->area('priced');
        $this->area('other');
        $this->risingSeries($area);

        $compare = $this->compare(['priced', 'other'])->assertOk();
        $resolve = $this->getJson('/location/resolve?area=priced')->assertOk();

        // The extraction's regression pinned end to end: the comparison's
        // price block equals the location card's, byte for byte.
        $this->assertSame(
            $resolve->json('prices.indices'),
            $compare->json('areas.0.prices.indices'),
        );
        $this->assertTrue((bool) $compare->json('areas.0.prices.available'));
        $this->assertSame('105.0000', $compare->json('areas.0.prices.indices.0.value'));
        $this->assertTrue((bool) $compare->json('areas.0.prices.indices.0.requires_qualifier'));

        // The second area has no evidence: absence, never zero.
        $this->assertFalse((bool) $compare->json('areas.1.prices.available'));
        $this->assertSame('no_published_values', $compare->json('areas.1.prices.reason'));
    }

    public function test_prices_fall_back_to_a_published_ancestor_and_name_it(): void
    {
        $parent = $this->area('erbil', ['type' => 'city', 'name_ckb' => 'هەولێر']);
        $this->area('child-a', ['parent_id' => $parent->id, 'name_ckb' => 'منداڵ ئەی']);
        $this->area('child-b', ['parent_id' => $parent->id, 'name_ckb' => 'منداڵ بی']);
        $this->risingSeries($parent);

        $response = $this->compare(['child-a', 'child-b'])->assertOk();

        $this->assertTrue((bool) $response->json('areas.0.prices.available'));
        $this->assertSame('هەولێر', $response->json('areas.0.prices.area_name'));

        // Both answered by the SAME ancestor: no fabricated 0% difference
        // between the children — the shared source is its own honest reason.
        $keys = array_column($response->json('facts'), 'key');
        $this->assertNotContains('price_higher', $keys);
        $this->assertNotContains('price_equal', $keys);
        $this->assertContains('price_not_comparable', $keys);
        $facts = $this->factsByKey($response);
        $this->assertSame('shared_source', ($facts['price_not_comparable'] ?? null)['params']['reason']);
    }

    public function test_a_disabled_market_indices_flag_reads_as_feature_disabled(): void
    {
        $this->setFeatures([
            'map.explorer' => true,
            'market.indices' => false,
        ]);

        $area = $this->area('a');
        $this->area('b');
        $this->risingSeries($area);

        $this->compare(['a', 'b'])
            ->assertOk()
            ->assertJsonPath('areas.0.prices.available', false)
            ->assertJsonPath('areas.0.prices.reason', 'feature_disabled');
    }

    /* --------------------------------------------------------- movement */

    public function test_compatible_movement_compares_with_factual_differences(): void
    {
        $a = $this->area('mufti');
        $b = $this->area('ankawa');
        $this->risingSeries($a, '105.0000');
        $this->risingSeries($b, '102.0000');

        $response = $this->compare(['mufti', 'ankawa'])->assertOk();

        $this->assertTrue((bool) $response->json('movement.available'));
        $this->assertSame('up', $response->json('areas.0.movement.direction'));
        $this->assertSame('5.00', $response->json('areas.0.movement.change_percent'));
        $this->assertSame('2.00', $response->json('areas.1.movement.change_percent'));
        $this->assertSame('v1', $response->json('areas.0.movement.methodology_version'));

        $this->assertTrue((bool) $response->json('market_comparison.comparable'));
        $this->assertSame('sale_asking', $response->json('market_comparison.signature.price_type'));
        $this->assertSame('USD', $response->json('market_comparison.signature.currency'));

        $facts = $this->factsByKey($response);

        // The larger recorded increase, named factually — never a winner.
        $movement = ($facts['movement_larger_increase'] ?? null);
        $this->assertNotNull($movement);
        $this->assertSame('mufti', $movement['params']['stronger']);
        $this->assertSame('5.00', $movement['params']['a_percent']);

        // Compatible current figures: 105 vs 102 → 2.86% apart, 3.0000 absolute.
        $price = ($facts['price_higher'] ?? null);
        $this->assertNotNull($price);
        $this->assertSame('mufti', $price['params']['higher']);
        $this->assertSame('ankawa', $price['params']['lower']);
        $this->assertSame('2.86', $price['params']['percent']);
        $this->assertSame('3.0000', $price['params']['amount']);
        $this->assertSame('USD', $price['params']['currency']);

        $raw = $response->getContent() ?: '';
        $this->assertStringNotContainsString('winner', $raw);
        $this->assertStringNotContainsString('score', $raw);
    }

    public function test_sale_and_rent_stay_isolated(): void
    {
        $a = $this->area('a');
        $this->area('b');
        $this->risingSeries($a);

        $response = $this->compare(['a', 'b'], ['transaction' => 'rent'])->assertOk();

        $this->assertFalse((bool) $response->json('movement.available'));
        $this->assertNull($response->json('areas.0.movement'));
        // The current sale price still shows — §19: movement missing never
        // hides a real current figure.
        $this->assertTrue((bool) $response->json('areas.0.prices.available'));
    }

    public function test_a_property_type_filter_matches_only_indices_declaring_it(): void
    {
        $a = $this->area('a');
        $b = $this->area('b');
        $this->risingSeries($a);
        $this->risingSeries($b);

        $filtered = $this->compare(['a', 'b'], ['property_type' => 'apartment'])->assertOk();
        $this->assertFalse((bool) $filtered->json('movement.available'));
        $this->assertSame('no_data_for_filters', $filtered->json('movement.reason'));

        $this->risingSeries($a, '110.0000', ['property_type' => 'apartment']);
        $this->risingSeries($b, '101.0000', ['property_type' => 'apartment']);

        $typed = $this->compare(['a', 'b'], ['property_type' => 'apartment'])->assertOk();
        $this->assertTrue((bool) $typed->json('movement.available'));
        $this->assertSame('apartment', $typed->json('areas.0.movement.property_type'));
        $this->assertTrue((bool) $typed->json('market_comparison.comparable'));
    }

    public function test_asking_and_verified_evidence_is_never_directly_compared(): void
    {
        $a = $this->area('a');
        $b = $this->area('b');
        $this->risingSeries($a, '105.0000', ['price_type' => 'sale_asking']);
        $this->risingSeries($b, '102.0000', ['price_type' => 'sale_verified']);

        $response = $this->compare(['a', 'b'])->assertOk();

        // Both claims exist independently…
        $this->assertNotNull($response->json('areas.0.movement'));
        $this->assertNotNull($response->json('areas.1.movement'));

        // …but nothing ranks them, and the reason names the dimension.
        $this->assertFalse((bool) $response->json('market_comparison.comparable'));
        $this->assertSame('price_type', $response->json('market_comparison.reason'));

        $keys = array_column($response->json('facts'), 'key');
        $this->assertNotContains('movement_larger_increase', $keys);
        $this->assertNotContains('price_higher', $keys);
        $this->assertContains('movement_not_comparable', $keys);
        $this->assertContains('price_not_comparable', $keys);
    }

    public function test_a_currency_mismatch_is_never_converted_or_compared(): void
    {
        $a = $this->area('a');
        $b = $this->area('b');
        $this->risingSeries($a, '105.0000', ['currency' => 'USD']);
        $this->risingSeries($b, '150000.0000', ['currency' => 'IQD']);

        $response = $this->compare(['a', 'b'])->assertOk();

        $this->assertFalse((bool) $response->json('market_comparison.comparable'));
        $this->assertSame('currency', $response->json('market_comparison.reason'));

        $facts = $this->factsByKey($response);
        $this->assertNull(($facts['price_higher'] ?? null));
        $this->assertSame('currency', ($facts['price_not_comparable'] ?? null)['params']['reason']);
        // Both figures still display, separately, in their own currencies.
        $this->assertSame('USD', $response->json('areas.0.prices.indices.0.currency'));
        $this->assertSame('IQD', $response->json('areas.1.prices.indices.0.currency'));
    }

    public function test_a_basis_mismatch_is_not_comparable(): void
    {
        $a = $this->area('a');
        $b = $this->area('b');
        $this->risingSeries($a, '105.0000', ['basis' => 'median']);
        $this->risingSeries($b, '102.0000', ['basis' => 'mean']);

        $response = $this->compare(['a', 'b'])->assertOk();

        $this->assertFalse((bool) $response->json('market_comparison.comparable'));
        $this->assertSame('basis', $response->json('market_comparison.reason'));
    }

    public function test_a_methodology_version_mismatch_is_not_comparable(): void
    {
        $a = $this->area('a');
        $b = $this->area('b');
        $this->risingSeries($a);

        $indexB = $this->index($b, ['methodology_version' => 'v2']);
        $this->value($indexB, '2026-01', '100.0000', ['methodology_version' => 'v2']);
        $this->value($indexB, '2026-07', '102.0000', ['methodology_version' => 'v2']);

        $response = $this->compare(['a', 'b'])->assertOk();

        $this->assertFalse((bool) $response->json('market_comparison.comparable'));
        $this->assertSame('methodology_version', $response->json('market_comparison.reason'));

        $facts = $this->factsByKey($response);
        $this->assertNull(($facts['price_higher'] ?? null));
        $this->assertSame('methodology_version', ($facts['price_not_comparable'] ?? null)['params']['reason']);
    }

    public function test_missing_history_leaves_movement_unknown_while_the_current_price_stands(): void
    {
        $a = $this->area('a');
        $b = $this->area('b');
        $this->risingSeries($a);

        // One observation is a price, not a direction.
        $single = $this->index($b);
        $this->value($single, '2026-07', '99.0000');

        $response = $this->compare(['a', 'b'])->assertOk();

        $this->assertNull($response->json('areas.1.movement'));
        $this->assertTrue((bool) $response->json('areas.1.prices.available'));
        $this->assertSame('99.0000', $response->json('areas.1.prices.indices.0.value'));

        // One claim cannot compare with itself.
        $this->assertFalse((bool) $response->json('market_comparison.comparable'));
        $this->assertSame('insufficient_claims', $response->json('market_comparison.reason'));

        $keys = array_column($response->json('facts'), 'key');
        $this->assertNotContains('movement_not_comparable', $keys);

        // Nowhere does missing become flat or zero.
        $raw = $response->getContent() ?: '';
        $this->assertStringNotContainsString('"direction":"flat"', $raw);
    }

    public function test_a_disabled_movement_feature_is_unavailable_never_insufficient(): void
    {
        $this->setFeatures([
            'map.explorer' => true,
            'market.intelligence' => false,
            'market.indices' => true,
        ]);

        $a = $this->area('a');
        $this->area('b');
        $this->risingSeries($a);

        $response = $this->compare(['a', 'b'])->assertOk();

        $this->assertFalse((bool) $response->json('movement.available'));
        $this->assertSame('feature_disabled', $response->json('movement.reason'));
        $this->assertNull($response->json('areas.0.movement'));
        // Prices ride their own flag and still answer.
        $this->assertTrue((bool) $response->json('areas.0.prices.available'));
    }

    /* ---------------------------------------------------------- limiter */

    public function test_the_named_rate_limiter_answers_429_beyond_the_budget(): void
    {
        $this->area('a');
        $this->area('b');

        foreach (range(1, 30) as $i) {
            $this->compare(['a', 'b'])->assertOk();
        }

        $this->compare(['a', 'b'])->assertStatus(429);
    }
}

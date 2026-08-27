<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /map/market — the heatmap's data (Map Phase 4).
 *
 * The contract under proof: every direction on the map is the movement
 * engine's own verdict (MarketMovementService → IndexCalculator::change),
 * scoped to the published, BOUNDED areas inside the viewport — and an area
 * the engine cannot honestly compare has NO row at all. Absence is the
 * wire form of "unknown": it is never dressed up as flat, never a zero,
 * and no pair ever crosses sale/rent, currencies, bases or methodology
 * versions. The "all categories" filter means the spanning
 * property_type-NULL index only — a typed index never stands in for it,
 * and it never stands in for a typed one.
 */
final class MarketMapTest extends TestCase
{
    use RefreshDatabase;

    /** Covers every fixture ring below. */
    private const BBOX = 'north=36.5&south=35.9&east=44.4&west=43.7';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures(['market.intelligence' => true]);
    }

    /**
     * A published area with a real boundary — the only kind the heatmap may
     * paint. Rings are placed by an offset so tests can put areas in or out
     * of a narrowed viewport.
     *
     * @param  array<model-property<Area>, mixed>  $overrides
     */
    private function boundedArea(string $slug, float $lngOffset = 0.0, array $overrides = []): Area
    {
        $west = 44.00 + $lngOffset;
        $east = $west + 0.02;

        $area = Area::query()->create($overrides + [
            'type' => 'district',
            'slug' => $slug,
            'name_ckb' => 'ناوچە '.$slug,
            'latitude' => 36.19,
            'longitude' => $west + 0.01,
            'publication_status' => 'published',
        ]);

        $area->forceFill([
            'boundary_wkt' => sprintf(
                'POLYGON((%.3F 36.180, %.3F 36.180, %.3F 36.200, %.3F 36.200, %.3F 36.180))',
                $west, $east, $east, $west, $west,
            ),
        ])->save();

        return $area->refresh();
    }

    /** @param array<model-property<MarketIndex>, mixed> $overrides */
    private function index(Area $area, array $overrides = []): MarketIndex
    {
        static $sequence = 0;

        return MarketIndex::query()->create($overrides + [
            'key' => 'heat-'.$area->slug.'-'.(++$sequence),
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
     * @param  array<string, string>  $extra
     * @return TestResponse<JsonResponse>
     */
    private function get_(array $extra = []): TestResponse
    {
        $query = self::BBOX;

        foreach ($extra as $key => $value) {
            $query .= '&'.$key.'='.rawurlencode($value);
        }

        return $this->getJson('/map/market?'.$query);
    }

    /* ------------------------------------------------------------ gates */

    public function test_the_endpoint_sits_behind_the_market_feature_flag(): void
    {
        $this->setFeatures(['market.intelligence' => false]);

        $this->get_()->assertNotFound();
    }

    public function test_bbox_and_vocabularies_are_validated_at_the_boundary(): void
    {
        $this->getJson('/map/market')->assertStatus(422);
        $this->get_(['transaction' => 'both'])->assertStatus(422);
        $this->get_(['period' => '2w'])->assertStatus(422);
        $this->get_(['property_type' => 'castle'])->assertStatus(422);
    }

    /* -------------------------------------------------- honest directions */

    public function test_a_rising_area_paints_up_with_the_engines_exact_figures(): void
    {
        $area = $this->boundedArea('heat-up');
        $index = $this->index($area);
        $this->value($index, '2026-06', '100000');
        $this->value($index, '2026-07', '110000');

        $this->get_()
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('rows.0.area_slug', 'heat-up')
            ->assertJsonPath('rows.0.direction', 'up')
            ->assertJsonPath('rows.0.change_percent', '10.00')
            ->assertJsonPath('rows.0.current_value', '110000.0000')
            ->assertJsonPath('rows.0.previous_value', '100000.0000')
            ->assertJsonPath('rows.0.currency', 'USD')
            ->assertJsonPath('rows.0.basis', 'median')
            ->assertJsonPath('rows.0.transaction', 'sale')
            ->assertJsonPath('rows.0.price_type', 'sale_asking')
            ->assertJsonPath('rows.0.property_type', null)
            ->assertJsonPath('rows.0.requires_qualifier', true)
            ->assertJsonPath('rows.0.period_current', '2026-07')
            ->assertJsonPath('rows.0.period_previous', '2026-06')
            ->assertJsonPath('rows.0.sample_size', 12)
            ->assertJsonPath('rows.0.confidence', 'moderate')
            ->assertJsonPath('truncated', false)
            ->assertJsonCount(1, 'rows');
    }

    public function test_each_area_paints_its_own_direction_and_rows_sort_by_slug(): void
    {
        $down = $this->index($this->boundedArea('a-down', 0.05));
        $this->value($down, '2026-06', '110000');
        $this->value($down, '2026-07', '99000');

        $flat = $this->index($this->boundedArea('b-flat', 0.10));
        $this->value($flat, '2026-06', '100000');
        $this->value($flat, '2026-07', '100000');

        $up = $this->index($this->boundedArea('c-up', 0.15));
        $this->value($up, '2026-06', '100000');
        $this->value($up, '2026-07', '104000');

        $this->get_()
            ->assertOk()
            ->assertJsonCount(3, 'rows')
            ->assertJsonPath('rows.0.area_slug', 'a-down')
            ->assertJsonPath('rows.0.direction', 'down')
            ->assertJsonPath('rows.0.change_percent', '-10.00')
            ->assertJsonPath('rows.1.area_slug', 'b-flat')
            ->assertJsonPath('rows.1.direction', 'flat')
            ->assertJsonPath('rows.1.change_percent', '0.00')
            ->assertJsonPath('rows.2.area_slug', 'c-up')
            ->assertJsonPath('rows.2.direction', 'up');
    }

    /* ------------------------------------------------ unknown is absence */

    public function test_a_single_observation_is_unknown_by_absence_never_flat(): void
    {
        $index = $this->index($this->boundedArea('one-point'));
        $this->value($index, '2026-07', '100000');

        $this->get_()
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'insufficient_history')
            ->assertJsonCount(0, 'rows');
    }

    public function test_a_methodology_change_is_honestly_incomparable_never_zero(): void
    {
        $index = $this->index($this->boundedArea('method-change'));
        $this->value($index, '2026-06', '100000', ['methodology_version' => 'v1']);
        $this->value($index, '2026-07', '104000', ['methodology_version' => 'v2']);

        $this->get_()
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'no_compatible_pair')
            ->assertJsonCount(0, 'rows');
    }

    public function test_short_windows_refuse_monthly_only_evidence(): void
    {
        $index = $this->index($this->boundedArea('monthly-only'));
        $this->value($index, '2026-03', '100000');
        $this->value($index, '2026-07', '104000');

        $this->get_(['period' => '7d'])
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'unsupported_short_window')
            ->assertJsonPath('windows.7d', false)
            ->assertJsonPath('windows.1m', false)
            ->assertJsonPath('windows.all', true)
            ->assertJsonCount(0, 'rows');
    }

    public function test_a_calendar_gap_disables_the_window_rather_than_sliding(): void
    {
        $index = $this->index($this->boundedArea('gapped'));
        $this->value($index, '2026-03', '100000');
        $this->value($index, '2026-07', '104000');

        // 1m needs EXACTLY 2026-06 — 2026-03 must never slide in.
        $this->get_(['period' => '1m'])
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'no_compatible_pair')
            ->assertJsonCount(0, 'rows');
    }

    /* --------------------------------------------------- sale/rent split */

    public function test_sale_and_rent_never_mix(): void
    {
        $area = $this->boundedArea('rent-only');
        $rent = $this->index($area, ['price_type' => 'rent_asking']);
        $this->value($rent, '2026-06', '700');
        $this->value($rent, '2026-07', '665');

        $this->get_(['transaction' => 'sale'])
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonCount(0, 'rows');

        $this->get_(['transaction' => 'rent'])
            ->assertOk()
            ->assertJsonPath('rows.0.area_slug', 'rent-only')
            ->assertJsonPath('rows.0.direction', 'down')
            ->assertJsonPath('rows.0.transaction', 'rent')
            ->assertJsonCount(1, 'rows');
    }

    /* ------------------------------------------- the honest "all" filter */

    public function test_all_categories_means_the_spanning_index_only(): void
    {
        $area = $this->boundedArea('typed-only');
        $apartment = $this->index($area, ['property_type' => 'apartment']);
        $this->value($apartment, '2026-06', '100000');
        $this->value($apartment, '2026-07', '110000');

        // "All" never lets a typed index stand in — no spanning index, no
        // claim, rather than an average of incomparable categories.
        $this->get_()
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonCount(0, 'rows');

        // The typed filter is where the typed index honestly answers.
        $this->get_(['property_type' => 'apartment'])
            ->assertOk()
            ->assertJsonPath('rows.0.area_slug', 'typed-only')
            ->assertJsonPath('rows.0.property_type', 'apartment')
            ->assertJsonCount(1, 'rows');
    }

    public function test_the_spanning_index_never_masquerades_as_one_category(): void
    {
        $area = $this->boundedArea('spanning-only');
        $spanning = $this->index($area);
        $this->value($spanning, '2026-06', '100000');
        $this->value($spanning, '2026-07', '110000');

        $this->get_(['property_type' => 'apartment'])
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'no_data_for_filters')
            ->assertJsonCount(0, 'rows');
    }

    /* ------------------------------------------------------ viewport scope */

    public function test_the_viewport_scopes_the_answer_and_boundless_areas_never_paint(): void
    {
        $inside = $this->index($this->boundedArea('inside'));
        $this->value($inside, '2026-06', '100000');
        $this->value($inside, '2026-07', '110000');

        $outside = $this->index($this->boundedArea('outside-view', 0.30));
        $this->value($outside, '2026-06', '100000');
        $this->value($outside, '2026-07', '110000');

        // Published, moving — but with no boundary there is no polygon to
        // paint, so the heatmap has nothing to claim on.
        $boundless = Area::query()->create([
            'type' => 'district',
            'slug' => 'boundless',
            'name_ckb' => 'ناوچە boundless',
            'latitude' => 36.19,
            'longitude' => 44.01,
            'publication_status' => 'published',
        ]);
        $moving = $this->index($boundless);
        $this->value($moving, '2026-06', '100000');
        $this->value($moving, '2026-07', '110000');

        // A narrowed viewport that holds only the first ring.
        $this->getJson('/map/market?north=36.25&south=36.15&east=44.05&west=43.95')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.area_slug', 'inside');
    }

    /* -------------------------------------------------- publication gates */

    public function test_unpublished_and_limited_evidence_never_paints(): void
    {
        $draftIndex = $this->index($this->boundedArea('draft-index'), ['publication_status' => 'draft']);
        $this->value($draftIndex, '2026-06', '100000');
        $this->value($draftIndex, '2026-07', '110000');

        $draftValue = $this->index($this->boundedArea('draft-value', 0.05));
        $this->value($draftValue, '2026-06', '100000');
        $this->value($draftValue, '2026-07', '110000', ['publication_status' => 'draft']);

        $limited = $this->index($this->boundedArea('limited-value', 0.10));
        $this->value($limited, '2026-06', '100000');
        $this->value($limited, '2026-07', '110000', ['is_limited' => true]);

        $draftArea = $this->boundedArea('draft-area', 0.15, ['publication_status' => 'draft']);
        $hidden = $this->index($draftArea);
        $this->value($hidden, '2026-06', '100000');
        $this->value($hidden, '2026-07', '110000');

        $this->get_()
            ->assertOk()
            ->assertJsonCount(0, 'rows');
    }

    /* --------------------------------------------- one claim per polygon */

    public function test_one_deterministic_claim_per_area_with_its_identity_stated(): void
    {
        $area = $this->boundedArea('two-series');

        $first = $this->index($area, ['key' => 'heat-a-first', 'currency' => 'USD']);
        $this->value($first, '2026-06', '100000');
        $this->value($first, '2026-07', '110000');

        $second = $this->index($area, ['key' => 'heat-b-second', 'currency' => 'IQD']);
        $this->value($second, '2026-06', '150000000');
        $this->value($second, '2026-07', '135000000');

        // Key order decides, deterministically; the row carries the claiming
        // series' full identity so the polygon's tint is a scoped statement,
        // never an anonymous blend of a USD rise and an IQD fall.
        $this->get_()
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.area_slug', 'two-series')
            ->assertJsonPath('rows.0.direction', 'up')
            ->assertJsonPath('rows.0.currency', 'USD');
    }

    /* ----------------------------------------------------------- ceiling */

    public function test_the_area_ceiling_is_detected_and_stated(): void
    {
        foreach (range(0, 40) as $i) {
            $this->boundedArea('cap-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $i * 0.001);
        }

        $this->get_()
            ->assertOk()
            ->assertJsonPath('truncated', true);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Imports\Services\PriceImportService;
use App\Modules\Market\Enums\PropertyType;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Market\Services\IndexBuilder;
use App\Modules\Market\Services\MarketMovementService;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Market movement derives, never invents (Wave 4, spec 14.1/15.2/15.3).
 *
 * Every proof here runs against the REAL published index series through the
 * real service, so it holds identically on sqlite and MariaDB — CI runs this
 * file on both engines, which is the equivalence proof itself.
 *
 * The contracts pinned: sale and rent never meet in one movement, asking and
 * verified never meet in one pair, an official-snapshot series (whose
 * transaction basis lives per record, not per series) moves under neither
 * mode; every comparability refusal of IndexCalculator::change() —
 * methodology change, missing value, zero previous — produces an honest
 * empty answer and NEVER a 0%; windows pair only what the stored evidence
 * genuinely supports, with the dated 7D/30D windows proven in both
 * directions off real effective-date spacing; rankings hold only exact
 * comparable changes with deterministic ties; and the whole pipeline is
 * reachable from an imported, scope_id-resolved price record — the Wave 4
 * prerequisite — without any unrelated scope leaking in.
 */
final class MarketMovementTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MarketMovementService
    {
        return app(MarketMovementService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function area(string $slug, array $overrides = []): Area
    {
        return Area::query()->create($overrides + [
            'type' => 'district',
            'slug' => $slug,
            'name_ckb' => 'ناوچە '.$slug,
            'publication_status' => 'published',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function project(string $slug, array $overrides = []): Project
    {
        return Project::query()->create($overrides + [
            'slug' => $slug,
            'name_ckb' => 'پڕۆژە '.$slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => 'published',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function index(Area|Project $entity, array $overrides = []): MarketIndex
    {
        static $sequence = 0;

        return MarketIndex::query()->create($overrides + [
            'key' => 'movement-'.$entity->slug.'-'.(++$sequence),
            'name_ckb' => 'پێوەری '.$entity->slug,
            'scope_type' => $entity instanceof Area ? 'area' : 'project',
            'scope_id' => $entity->id,
            'property_type' => 'apartment',
            'price_type' => 'sale_asking',
            'basis' => 'median',
            'currency' => 'USD',
            'methodology_version' => 'v1',
            'minimum_sample' => 3,
            'publication_status' => 'published',
        ]);
    }

    /** @param array<string, mixed> $overrides */
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
     * @param  array<string, mixed>  $result
     * @return list<string> every entity slug in every bucket
     */
    private function slugs(array $result): array
    {
        $slugs = [];

        foreach (['gainers', 'losers', 'flat'] as $bucket) {
            foreach ($result[$bucket] as $mover) {
                $slugs[] = $mover['entity']['slug'];
            }
        }

        return $slugs;
    }

    /* -------------------------------------------- sale and rent never mix */

    public function test_sale_movement_never_contains_rent_and_rent_never_contains_sale(): void
    {
        $saleArea = $this->area('sale-area');
        $rentArea = $this->area('rent-area');

        $sale = $this->index($saleArea, ['price_type' => 'sale_asking']);
        $this->value($sale, '2026-06', '100000');
        $this->value($sale, '2026-07', '110000');

        $rent = $this->index($rentArea, ['price_type' => 'rent_asking']);
        $this->value($rent, '2026-06', '500');
        $this->value($rent, '2026-07', '450');

        $saleResult = $this->service()->movement('sale', 'all');
        $this->assertTrue($saleResult['available']);
        $this->assertSame(['sale-area'], $this->slugs($saleResult));
        $this->assertSame('sale', $saleResult['gainers'][0]['transaction']);

        $rentResult = $this->service()->movement('rent', 'all');
        $this->assertTrue($rentResult['available']);
        $this->assertSame(['rent-area'], $this->slugs($rentResult));
        $this->assertSame('rent', $rentResult['losers'][0]['transaction']);
    }

    public function test_asking_and_verified_series_move_separately_never_as_one_pair(): void
    {
        $area = $this->area('two-families');

        $asking = $this->index($area, ['price_type' => 'sale_asking']);
        $this->value($asking, '2026-06', '100000');
        $this->value($asking, '2026-07', '120000');

        $verified = $this->index($area, ['price_type' => 'sale_verified']);
        $this->value($verified, '2026-06', '100000');
        $this->value($verified, '2026-07', '105000');

        $result = $this->service()->movement('sale', 'all');

        // Two independent movers — one per family — each computed strictly
        // inside its own series. A pair straddling the two would have shown
        // a single blended figure; instead each keeps its own percentage
        // and its own label.
        $this->assertCount(2, $result['gainers']);

        $byFamily = collect($result['gainers'])->keyBy('family');
        $this->assertSame('20.00', $byFamily['asking']['change_percent']);
        $this->assertSame('5.00', $byFamily['verified']['change_percent']);
        $this->assertTrue($byFamily['asking']['requires_qualifier']);
        $this->assertFalse($byFamily['verified']['requires_qualifier']);
    }

    public function test_official_snapshot_series_move_under_neither_sale_nor_rent(): void
    {
        $area = $this->area('official-area');

        // An official snapshot declares sale-or-rent per RECORD; the index
        // series carries no such pin, so movement excludes it outright
        // rather than guessing a basis for it.
        $official = $this->index($area, ['price_type' => 'official_snapshot']);
        $this->value($official, '2026-06', '100000');
        $this->value($official, '2026-07', '90000');

        $this->assertFalse($this->service()->movement('sale', 'all')['available']);
        $this->assertFalse($this->service()->movement('rent', 'all')['available']);
    }

    /* ------------------------------------- pinned currency, basis, series */

    public function test_currencies_and_bases_never_cross_between_series(): void
    {
        $area = $this->area('pinned-area');

        $usd = $this->index($area, ['currency' => 'USD', 'basis' => 'median']);
        $this->value($usd, '2026-06', '100000');
        $this->value($usd, '2026-07', '110000');

        $iqd = $this->index($area, ['currency' => 'IQD', 'basis' => 'per_sqm']);
        $this->value($iqd, '2026-06', '1450000');
        $this->value($iqd, '2026-07', '1500000');

        $result = $this->service()->movement('sale', 'all');

        // Two movers, each pinned to its own currency and basis; no synthetic
        // cross-currency or cross-basis figure exists anywhere in the answer.
        $this->assertCount(2, $result['gainers']);

        $byCurrency = collect($result['gainers'])->keyBy('currency');
        $this->assertSame('10.00', $byCurrency['USD']['change_percent']);
        $this->assertSame('median', $byCurrency['USD']['basis']);
        $this->assertSame('3.45', $byCurrency['IQD']['change_percent']);
        $this->assertSame('per_sqm', $byCurrency['IQD']['basis']);
    }

    public function test_a_methodology_change_is_honestly_incomparable_never_zero(): void
    {
        $area = $this->area('methodology-area');
        $index = $this->index($area);

        $this->value($index, '2026-06', '100000', ['methodology_version' => 'v1']);
        $this->value($index, '2026-07', '150000', ['methodology_version' => 'v2']);

        $result = $this->service()->movement('sale', '1m');

        // The calculator refuses the pair; the service converts that refusal
        // into an honest empty answer — never into a figure.
        $this->assertFalse($result['available']);
        $this->assertSame('no_compatible_pair', $result['reason']);
        $this->assertFalse($result['windows']['1m']);
        $this->assertSame([], $result['gainers']);
        $this->assertSame([], $result['losers']);
    }

    public function test_missing_values_leave_the_series_too_thin_to_compare(): void
    {
        $area = $this->area('null-value-area');
        $index = $this->index($area);

        $this->value($index, '2026-06', null);
        $this->value($index, '2026-07', '100000');

        $result = $this->service()->movement('sale', 'all');

        $this->assertFalse($result['available']);
        $this->assertSame('insufficient_history', $result['reason']);
    }

    public function test_a_zero_previous_value_never_produces_a_percentage(): void
    {
        $area = $this->area('zero-previous');
        $index = $this->index($area);

        $this->value($index, '2026-06', '0');
        $this->value($index, '2026-07', '100000');

        $result = $this->service()->movement('sale', '1m');

        $this->assertFalse($result['available']);
        $this->assertSame('no_compatible_pair', $result['reason']);
    }

    public function test_a_genuinely_flat_pair_is_a_real_zero_not_a_gap_filler(): void
    {
        $area = $this->area('flat-area');
        $index = $this->index($area);

        $this->value($index, '2026-06', '100000');
        $this->value($index, '2026-07', '100000');

        $result = $this->service()->movement('sale', '1m');

        $this->assertTrue($result['available']);
        $this->assertSame([], $result['gainers']);
        $this->assertSame([], $result['losers']);
        $this->assertCount(1, $result['flat']);
        $this->assertSame('0.00', $result['flat'][0]['change_percent']);
        $this->assertSame('flat', $result['flat'][0]['direction']);
    }

    /* ------------------------------------------------- windows, data-driven */

    public function test_short_windows_refuse_monthly_only_evidence(): void
    {
        $area = $this->area('monthly-area');
        $index = $this->index($area);

        // A healthy monthly series: July and August 2026 sit 31 real days
        // apart, so neither 7 nor 30 days contains a second observation.
        $this->value($index, '2026-06', '100000');
        $this->value($index, '2026-07', '105000');
        $this->value($index, '2026-08', '110000');

        foreach (['7d', '30d'] as $window) {
            $result = $this->service()->movement('sale', $window);

            $this->assertFalse($result['available'], $window);
            $this->assertSame('unsupported_short_window', $result['reason'], $window);
            $this->assertFalse($result['windows'][$window], $window);
            $this->assertSame([], $result['gainers'], $window);
        }

        // The same series honestly supports the calendar windows.
        $this->assertTrue($this->service()->movement('sale', '1m')['available']);
    }

    public function test_30d_pairs_only_observations_genuinely_dated_within_the_window(): void
    {
        // February to March: the two stored observations are 28 real days
        // apart — a genuine dated pair inside 30 days — so 30D is honestly
        // supportable there and only there.
        $near = $this->area('near-pair');
        $nearIndex = $this->index($near);
        $this->value($nearIndex, '2026-02', '100000');
        $this->value($nearIndex, '2026-03', '104000');

        $result = $this->service()->movement('sale', '30d');

        $this->assertTrue($result['available']);
        $this->assertTrue($result['windows']['30d']);
        $this->assertSame('near-pair', $result['gainers'][0]['entity']['slug']);
        $this->assertSame('4.00', $result['gainers'][0]['change_percent']);
        $this->assertSame('2026-02', $result['gainers'][0]['previous']['period']);

        // 7 days still cannot be satisfied by observations 28 days apart.
        $this->assertFalse($result['windows']['7d']);
        $this->assertFalse($this->service()->movement('sale', '7d')['available']);
    }

    public function test_calendar_windows_select_the_exact_stored_period_never_a_neighbour(): void
    {
        $area = $this->area('calendar-area');
        $index = $this->index($area);

        $this->value($index, '2025-04', '80000');   // exactly 1y before current
        $this->value($index, '2025-10', '90000');   // exactly 6m before current
        $this->value($index, '2026-01', '96000');   // exactly 3m before current
        $this->value($index, '2026-03', '99000');   // exactly 1m before current
        $this->value($index, '2026-04', '100000');  // current

        $service = $this->service();

        $oneMonth = $service->movement('sale', '1m')['gainers'][0];
        $this->assertSame('2026-03', $oneMonth['previous']['period']);
        $this->assertSame('1.01', $oneMonth['change_percent']);

        $threeMonths = $service->movement('sale', '3m')['gainers'][0];
        $this->assertSame('2026-01', $threeMonths['previous']['period']);
        $this->assertSame('4.17', $threeMonths['change_percent']);

        $sixMonths = $service->movement('sale', '6m')['gainers'][0];
        $this->assertSame('2025-10', $sixMonths['previous']['period']);
        $this->assertSame('11.11', $sixMonths['change_percent']);

        $oneYear = $service->movement('sale', '1y')['gainers'][0];
        $this->assertSame('2025-04', $oneYear['previous']['period']);
        $this->assertSame('25.00', $oneYear['change_percent']);

        $all = $service->movement('sale', 'all')['gainers'][0];
        $this->assertSame('2025-04', $all['previous']['period']);
        $this->assertSame('2026-04', $all['current']['period']);
        $this->assertSame('25.00', $all['change_percent']);
    }

    public function test_a_gap_disables_a_calendar_window_rather_than_sliding(): void
    {
        $area = $this->area('gap-area');
        $index = $this->index($area);

        // 2026-01 is absent: 3M has no exact partner for 2026-04, and the
        // neighbouring 2026-02 must never be presented as "three months".
        $this->value($index, '2026-02', '95000');
        $this->value($index, '2026-04', '100000');

        $result = $this->service()->movement('sale', '3m');

        $this->assertFalse($result['available']);
        $this->assertSame('no_compatible_pair', $result['reason']);
        $this->assertFalse($result['windows']['3m']);
        // The evidence still honestly supports "all".
        $this->assertTrue($result['windows']['all']);
    }

    /* --------------------------------------------------- category filtering */

    public function test_the_property_filter_is_dynamic_and_exact(): void
    {
        $apartments = $this->area('apartments-area');
        $offices = $this->area('offices-area');

        $apartmentIndex = $this->index($apartments, ['property_type' => 'apartment']);
        $this->value($apartmentIndex, '2026-06', '100000');
        $this->value($apartmentIndex, '2026-07', '105000');

        $officeIndex = $this->index($offices, ['property_type' => 'office']);
        $this->value($officeIndex, '2026-06', '200000');
        $this->value($officeIndex, '2026-07', '190000');

        $service = $this->service();

        $office = $service->movement('sale', 'all', ['office']);
        $this->assertSame(['offices-area'], $this->slugs($office));

        $apartment = $service->movement('sale', 'all', ['apartment']);
        $this->assertSame(['apartments-area'], $this->slugs($apartment));

        // The vocabulary is the enum itself, so a case added to PropertyType
        // surfaces without anyone editing a list.
        $this->assertSame(
            array_map(static fn (PropertyType $t): string => $t->value, PropertyType::cases()),
            $office['property_types'],
        );

        // A filter nothing matches is answered with its own reason — never
        // with zeros.
        $warehouse = $service->movement('sale', 'all', ['warehouse']);
        $this->assertFalse($warehouse['available']);
        $this->assertSame('no_data_for_filters', $warehouse['reason']);
    }

    public function test_multiple_categories_stay_independent_series_never_a_blend(): void
    {
        $area = $this->area('multi-category');

        $apartmentIndex = $this->index($area, ['property_type' => 'apartment']);
        $this->value($apartmentIndex, '2026-06', '100000');
        $this->value($apartmentIndex, '2026-07', '110000');

        $officeIndex = $this->index($area, ['property_type' => 'office']);
        $this->value($officeIndex, '2026-06', '200000');
        $this->value($officeIndex, '2026-07', '210000');

        $result = $this->service()->movement('sale', 'all', ['apartment', 'office']);

        // Two independent movers, one per category — never one synthetic
        // "mixed" series averaging a flat against an office.
        $this->assertCount(2, $result['gainers']);
        $this->assertSame(
            ['apartment', 'office'],
            collect($result['gainers'])->pluck('property_type')->sort()->values()->all(),
        );
        $this->assertSame('10.00', collect($result['gainers'])->keyBy('property_type')['apartment']['change_percent']);
        $this->assertSame('5.00', collect($result['gainers'])->keyBy('property_type')['office']['change_percent']);
    }

    public function test_an_all_categories_index_never_masquerades_as_one_category(): void
    {
        $area = $this->area('null-category');
        $index = $this->index($area, ['property_type' => null]);
        $this->value($index, '2026-06', '100000');
        $this->value($index, '2026-07', '110000');

        // Visible without a filter…
        $this->assertTrue($this->service()->movement('sale', 'all')['available']);

        // …but never under one: it spans every category and cannot honestly
        // claim to BE apartments.
        $filtered = $this->service()->movement('sale', 'all', ['apartment']);
        $this->assertFalse($filtered['available']);
        $this->assertSame('no_data_for_filters', $filtered['reason']);
    }

    /* -------------------------------------------------------- the rankings */

    public function test_gainers_and_losers_rank_by_exact_change_and_ties_break_on_slug(): void
    {
        $values = [
            'gain-big' => ['100000', '110000'],   // +10.00
            'gain-small' => ['100000', '102000'], // +2.00
            'tie-beta' => ['100000', '105000'],   // +5.00, slug tie-beta
            'tie-alpha' => ['100000', '105000'],  // +5.00, slug tie-alpha
            'lose-big' => ['100000', '92000'],    // -8.00
            'lose-small' => ['100000', '97000'],  // -3.00
        ];

        foreach ($values as $slug => [$from, $to]) {
            $index = $this->index($this->area($slug));
            $this->value($index, '2026-06', $from);
            $this->value($index, '2026-07', $to);
        }

        $result = $this->service()->movement('sale', 'all');

        $this->assertSame(
            ['gain-big', 'tie-alpha', 'tie-beta', 'gain-small'],
            array_column(array_column($result['gainers'], 'entity'), 'slug'),
            'strongest gain first; equal changes ordered by slug',
        );

        $this->assertSame(
            ['lose-big', 'lose-small'],
            array_column(array_column($result['losers'], 'entity'), 'slug'),
            'strongest loss first',
        );
    }

    public function test_incomparable_series_never_enter_the_rankings(): void
    {
        $good = $this->index($this->area('good-area'));
        $this->value($good, '2026-06', '100000');
        $this->value($good, '2026-07', '104000');

        $broken = $this->index($this->area('broken-area'));
        $this->value($broken, '2026-06', '100000', ['methodology_version' => 'v1']);
        $this->value($broken, '2026-07', '190000', ['methodology_version' => 'v2']);

        $result = $this->service()->movement('sale', '1m');

        // The 90% "movement" the methodology change would have faked is
        // nowhere; only the honest 4% ranks.
        $this->assertSame(['good-area'], $this->slugs($result));
        $this->assertSame('4.00', $result['gainers'][0]['change_percent']);
    }

    /* ------------------------------------------- eligibility and exposure */

    public function test_unpublished_draft_and_limited_data_is_never_exposed(): void
    {
        // A draft index with a perfect series.
        $draftIndex = $this->index($this->area('draft-index-area'), ['publication_status' => 'draft']);
        $this->value($draftIndex, '2026-06', '100000');
        $this->value($draftIndex, '2026-07', '110000');

        // A published index whose second value is only a draft.
        $draftValue = $this->index($this->area('draft-value-area'));
        $this->value($draftValue, '2026-06', '100000');
        $this->value($draftValue, '2026-07', '110000', ['publication_status' => 'draft']);

        // A published index whose second value is limited — §15.3's
        // reliability rule keeps limited values off every public surface.
        $limited = $this->index($this->area('limited-area'));
        $this->value($limited, '2026-06', '100000');
        $this->value($limited, '2026-07', '110000', ['is_limited' => true, 'warning' => 'sample_below_minimum']);

        // A perfect series whose area is not published.
        $hidden = $this->index($this->area('hidden-area', ['publication_status' => 'draft']));
        $this->value($hidden, '2026-06', '100000');
        $this->value($hidden, '2026-07', '110000');

        // A city-level index (scope_id NULL): no entity to present.
        $city = MarketIndex::query()->create([
            'key' => 'movement-city',
            'name_ckb' => 'پێوەری شار',
            'scope_type' => 'city',
            'scope_id' => null,
            'property_type' => 'apartment',
            'price_type' => 'sale_asking',
            'basis' => 'median',
            'currency' => 'USD',
            'methodology_version' => 'v1',
            'minimum_sample' => 3,
            'publication_status' => 'published',
        ]);
        $this->value($city, '2026-06', '100000');
        $this->value($city, '2026-07', '110000');

        $result = $this->service()->movement('sale', 'all');

        $this->assertFalse($result['available']);
        $this->assertSame([], $result['gainers']);
        $this->assertSame([], $result['losers']);
    }

    public function test_entities_carry_slug_type_and_localized_name_never_raw_ids(): void
    {
        $area = $this->area('named-area', ['name_ckb' => 'ئەنکاوە', 'name_en' => 'Ankawa']);
        $areaIndex = $this->index($area);
        $this->value($areaIndex, '2026-06', '100000');
        $this->value($areaIndex, '2026-07', '104000');

        $project = $this->project('named-project', ['name_ckb' => 'پڕۆژەی ڕووناکی']);
        $projectIndex = $this->index($project);
        $this->value($projectIndex, '2026-06', '200000');
        $this->value($projectIndex, '2026-07', '196000');

        $result = $this->service()->movement('sale', 'all');

        $gainer = $result['gainers'][0]['entity'];
        $this->assertSame(['slug' => 'named-area', 'type' => 'area', 'name' => 'ئەنکاوە'], $gainer);

        $loser = $result['losers'][0]['entity'];
        $this->assertSame(['slug' => 'named-project', 'type' => 'project', 'name' => 'پڕۆژەی ڕووناکی'], $loser);

        // Nothing anywhere in the payload is a bare database id.
        $this->assertArrayNotHasKey('id', $gainer);
        $this->assertArrayNotHasKey('scope_id', $result['gainers'][0]);
    }

    public function test_sparklines_hold_only_compatible_real_points(): void
    {
        $area = $this->area('sparkline-area');
        $index = $this->index($area);

        // Two v0 points predate a methodology change; three v1 points follow.
        $this->value($index, '2026-01', '90000', ['methodology_version' => 'v0']);
        $this->value($index, '2026-02', '91000', ['methodology_version' => 'v0']);
        $this->value($index, '2026-05', '100000');
        $this->value($index, '2026-06', '102000');
        $this->value($index, '2026-07', '104000');

        $mover = $this->service()->movement('sale', '1m')['gainers'][0];

        // Only the points sharing the CURRENT methodology draw the line: a
        // curve across a methodology change would chart two incomparable
        // rulers as one shape.
        $this->assertSame(
            ['2026-05', '2026-06', '2026-07'],
            array_column($mover['sparkline'], 'period'),
        );
        $this->assertSame(['100000.0000', '102000.0000', '104000.0000'], array_column($mover['sparkline'], 'value'));
    }

    public function test_an_empty_market_returns_a_reason_never_zeros(): void
    {
        $result = $this->service()->movement('sale', 'all');

        $this->assertFalse($result['available']);
        $this->assertSame('insufficient_history', $result['reason']);
        $this->assertSame([], $result['gainers']);
        $this->assertSame([], $result['losers']);
        $this->assertSame([], $result['flat']);
        $this->assertSame(array_fill_keys(MarketMovementService::WINDOWS, false), $result['windows']);
    }

    /* --------------------------- the full pipeline, from import to mover */

    public function test_imported_scope_resolved_prices_can_become_a_movement_series(): void
    {
        $ankawa = $this->area('ankawa-import', ['external_id' => 'AR-MOVE']);
        $other = $this->area('other-import', ['external_id' => 'AR-LEAK']);

        $imports = app(PriceImportService::class);

        // Two monthly batches, three unit types each (the calculator's
        // publication floor), plus a decoy record in the other area each
        // month that must never leak into Ankawa's series.
        foreach ([['2026-06-15', '100000', '110000', '120000', '900000'], ['2026-07-15', '110000', '121000', '132000', '400000']] as [$date, $a, $b, $c, $leak]) {
            $rows = [
                ['scope_type' => 'area', 'scope_external_id' => 'AR-MOVE', 'property_type' => 'apartment', 'unit_type' => '1br', 'price_type' => 'sale_asking', 'currency' => 'USD', 'price' => $a, 'effective_date' => $date],
                ['scope_type' => 'area', 'scope_external_id' => 'AR-MOVE', 'property_type' => 'apartment', 'unit_type' => '2br', 'price_type' => 'sale_asking', 'currency' => 'USD', 'price' => $b, 'effective_date' => $date],
                ['scope_type' => 'area', 'scope_external_id' => 'AR-MOVE', 'property_type' => 'apartment', 'unit_type' => '3br', 'price_type' => 'sale_asking', 'currency' => 'USD', 'price' => $c, 'effective_date' => $date],
                ['scope_type' => 'area', 'scope_external_id' => 'AR-LEAK', 'property_type' => 'apartment', 'unit_type' => '2br', 'price_type' => 'sale_asking', 'currency' => 'USD', 'price' => $leak, 'effective_date' => $date],
            ];

            $preview = $imports->preview($rows, ['area' => ['AR-MOVE', 'AR-LEAK']]);
            $this->assertSame(4, $preview['valid']);

            $accepted = $imports->accept($preview['reference'], array_map(
                static fn (array $row): array => $row['normalised'],
                $preview['rows'],
            ));
            $this->assertSame(4, $accepted['written']);
        }

        PriceRecord::query()->toBase()->update(['publication_status' => 'published']);

        $index = $this->index($ankawa);

        foreach (['2026-06', '2026-07'] as $period) {
            app(IndexBuilder::class)->buildPeriod($index, $period);
        }

        // buildPeriod stores drafts; publication is the human step.
        MarketIndexValue::query()->toBase()->update(['publication_status' => 'published']);

        $result = $this->service()->movement('sale', '1m');

        $this->assertTrue($result['available']);
        $this->assertSame(['ankawa-import'], $this->slugs($result));

        $mover = $result['gainers'][0];
        // Medians 110000 -> 121000: exactly +10%, from Ankawa's records
        // alone. The decoy prices (900000 falling to 400000) would have
        // wrecked both the level and the direction had the other scope
        // leaked in through anything but scope_id.
        $this->assertSame('110000.0000', $mover['previous']['value']);
        $this->assertSame('121000.0000', $mover['current']['value']);
        $this->assertSame('10.00', $mover['change_percent']);
        $this->assertSame('up', $mover['direction']);
        // The decoy area has published records but no index — and no mover.
        $this->assertSame(1, MarketIndex::query()->count());
    }

    /* -------------------------------------------------- the HTTP boundary */

    public function test_the_endpoint_validates_serves_and_stays_honest(): void
    {
        $this->setFeatures(['market.intelligence' => true]);

        $area = $this->area('http-area');
        $index = $this->index($area);
        $this->value($index, '2026-06', '100000');
        $this->value($index, '2026-07', '104000');

        $this->getJson('/market/movement?transaction=sale&period=all')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('transaction', 'sale')
            ->assertJsonPath('window', 'all')
            ->assertJsonPath('gainers.0.entity.slug', 'http-area')
            ->assertJsonPath('gainers.0.change_percent', '4.00')
            ->assertJsonPath('gainers.0.direction', 'up');

        // Unknown vocabulary is refused at the boundary, never silently
        // emptied.
        $this->getJson('/market/movement?transaction=lease')->assertStatus(422);
        $this->getJson('/market/movement?period=2w')->assertStatus(422);
        $this->getJson('/market/movement?property_types[]=castle')->assertStatus(422);

        // A valid request with nothing to say is a 200 with its reason.
        $this->getJson('/market/movement?transaction=rent&period=all')
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'insufficient_history');
    }

    public function test_the_endpoint_sits_behind_the_market_feature_flag(): void
    {
        $this->setFeatures(['market.intelligence' => false]);

        $this->getJson('/market/movement')->assertNotFound();
    }
}

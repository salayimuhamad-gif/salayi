<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Enums\ScopeType;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Models\PortfolioValuation;
use App\Modules\Portfolio\Services\PortfolioValuer;
use App\Modules\Portfolio\Services\ValuationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pre-Wave-6 baseline integrity gate: the comparable pool feeds the
 * valuation engine COMPATIBLE evidence only, chosen deterministically.
 *
 * PriceType::mayAggregateWith() has always declared the rule — same family
 * AND same transaction, because "a $240,000 asking sale price and a $500
 * asking monthly rent … averaging them produces a number that means
 * nothing" — and currencies are not summable either. This suite pins that
 * discipline at the evidence boundary, pins the deterministic 400-row
 * window, and adds the first direct regression coverage of the engine's
 * tier and confidence semantics so the mini-fix provably changed neither.
 *
 * Everything here runs identically on the CI matrix's SQLite and MariaDB
 * lanes; every money assertion compares exact scale-4 decimal STRINGS
 * through the repository's Decimal conventions — no floats, no tolerances.
 */
final class ValuationBaselineTest extends TestCase
{
    use RefreshDatabase;

    private Area $city;

    private Area $district;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * A two-level lineage: the property sits in a district under a city,
         * so the engine's wider-area tier (the only tier reachable while
         * evidence carries no unit size) has a real parent path to test
         * against — exactly the production shape.
         */
        $this->city = Area::query()->create([
            'type' => 'city',
            'slug' => 'vb-erbil',
            'name_ckb' => 'هەولێر',
            'publication_status' => 'published',
        ]);

        $this->district = Area::query()->create([
            'type' => 'district',
            'slug' => 'vb-kasnazan',
            'name_ckb' => 'کەسنەزان',
            'parent_id' => $this->city->id,
            'publication_status' => 'published',
        ]);
    }

    /** @param array<model-property<PortfolioProperty>, mixed> $overrides */
    private function property(array $overrides = []): PortfolioProperty
    {
        $property = new PortfolioProperty;
        $property->fill($overrides + [
            'user_id' => User::factory()->create()->id,
            'property_type' => 'apartment',
            'area_id' => $this->district->id,
            'currency' => 'USD',
            'location_precision' => PortfolioProperty::PRECISION_AREA_ONLY,
            'consent_valuation' => true,
        ]);
        $property->setLabel('Baseline fixture');
        $property->setNotes(null);
        $property->save();

        return $property;
    }

    /** @param array<model-property<PriceRecord>, mixed> $overrides */
    private function record(string $price, array $overrides = []): PriceRecord
    {
        return PriceRecord::query()->create($overrides + [
            'scope_type' => ScopeType::Area,
            'scope_id' => $this->district->id,
            'property_type' => 'apartment',
            'transaction_type' => 'sale',
            'price_type' => PriceType::SaleVerified,
            'currency' => 'USD',
            'price' => $price,
            'effective_date' => '2026-06-01',
            'period' => '2026-06',
            'publication_status' => 'published',
        ]);
    }

    private function value(PortfolioProperty $property): PortfolioValuation
    {
        return app(PortfolioValuer::class)->value($property);
    }

    /* ---------------------------------------------------------------------
     * A. Currency isolation
     * ------------------------------------------------------------------- */

    public function test_a_usd_property_never_consumes_iqd_or_eur_evidence(): void
    {
        $this->record('100000.0000');
        $this->record('110000.0000');
        $this->record('120000.0000');

        // Poison, in-lineage, same transaction, wrong currency: any of these
        // entering the pool would move the exact median below.
        $this->record('900000000.0000', ['currency' => 'IQD']);
        $this->record('910000000.0000', ['currency' => 'IQD']);
        $this->record('555555.0000', ['currency' => 'EUR']);

        $valuation = $this->value($this->property());

        $this->assertSame('110000.0000', decimal((string) $valuation->midpoint, 4)->toString());
        $this->assertSame(3, $valuation->comparison_count);
        $this->assertSame('USD', $valuation->currency);
        // No asking rows existed in the compatible pool, and the disclosure
        // says exactly that.
        $this->assertSame(0, $valuation->excluded_asking_count);
        $this->assertSame('no_asking_prices_present', $valuation->excluded_asking_note);
    }

    public function test_an_iqd_property_never_consumes_usd_evidence(): void
    {
        $this->record('130000000.0000', ['currency' => 'IQD']);
        $this->record('140000000.0000', ['currency' => 'IQD']);
        $this->record('150000000.0000', ['currency' => 'IQD']);

        $this->record('100000.0000');
        $this->record('120000.0000');

        $valuation = $this->value($this->property(['currency' => 'IQD']));

        $this->assertSame('140000000.0000', decimal((string) $valuation->midpoint, 4)->toString());
        $this->assertSame(3, $valuation->comparison_count);
        $this->assertSame('IQD', $valuation->currency);
    }

    /* ---------------------------------------------------------------------
     * B. Transaction isolation
     * ------------------------------------------------------------------- */

    public function test_a_sale_valuation_never_consumes_rent_evidence(): void
    {
        $this->record('100000.0000');
        $this->record('110000.0000');
        $this->record('120000.0000');

        // Monthly rents in the same lineage and currency. Blended, the six-row
        // median would be (700 + 100000) / 2 — the "number that means
        // nothing" the PriceType guard describes.
        $this->record('500.0000', ['transaction_type' => 'rent', 'price_type' => PriceType::RentVerified]);
        $this->record('600.0000', ['transaction_type' => 'rent', 'price_type' => PriceType::RentVerified]);
        $this->record('700.0000', ['transaction_type' => 'rent', 'price_type' => PriceType::RentVerified]);

        $valuation = $this->value($this->property());

        // Same-transaction verified evidence still values normally, through
        // the unchanged ladder: the size-blind pool reaches the wider-area
        // tier, reported at its true strength.
        $this->assertSame('110000.0000', decimal((string) $valuation->midpoint, 4)->toString());
        $this->assertSame(3, $valuation->comparison_count);
        $this->assertSame(4, $valuation->match_level);
        $this->assertSame('wider_area_fallback', $valuation->match_label);
        $this->assertSame('low', $valuation->confidence);
        $this->assertSame('comparable_median_v1', $valuation->methodology);
        $this->assertNull($valuation->no_valuation_reason);
    }

    public function test_official_snapshots_stored_as_either_are_honestly_excluded(): void
    {
        /*
         * HONEST CURRENT-STATE LIMITATION, not a conceptual ban on official
         * evidence: the import pipeline writes PriceType::transaction()
         * verbatim into price_records.transaction_type, and for
         * OfficialSnapshot that value is 'either' — the record never
         * declares whether it covers sale or rent. A transaction-specific
         * median cannot ASSUME the answer, so 'either' rows are excluded
         * until snapshot write-path semantics are resolved in a separately
         * approved task. The filter is on the COLUMN, so resolved snapshots
         * re-enter with no valuation-code change.
         */
        $this->record('100000.0000');
        $this->record('120000.0000');
        $this->record('105000.0000', [
            'transaction_type' => 'either',
            'price_type' => PriceType::OfficialSnapshot,
        ]);

        // Two compatible rows + one undeclared snapshot: if the snapshot were
        // assumed sale-compatible the pool would reach the three-comparable
        // minimum and produce a number. It must refuse instead.
        $refused = $this->value($this->property());

        $this->assertNull($refused->midpoint);
        $this->assertSame(0, $refused->comparison_count);
        $this->assertSame('insufficient_comparables_at_every_match_level', $refused->no_valuation_reason);

        // And with a full compatible pool the snapshot still contributes
        // nothing: the count is exactly the declared-sale rows.
        $this->record('110000.0000');

        $valued = $this->value($this->property());

        $this->assertSame('110000.0000', decimal((string) $valued->midpoint, 4)->toString());
        $this->assertSame(3, $valued->comparison_count);
    }

    /* ---------------------------------------------------------------------
     * C. Asking-family behaviour unchanged
     * ------------------------------------------------------------------- */

    public function test_same_currency_sale_asking_still_reaches_the_engine_and_its_disclosure(): void
    {
        $this->record('100000.0000');
        $this->record('110000.0000');
        $this->record('120000.0000');

        // Same currency, sale basis, asking family: passes the evidence
        // boundary exactly as before, and the ENGINE excludes and counts it.
        $this->record('200000.0000', ['price_type' => PriceType::SaleAsking]);
        $this->record('210000.0000', ['price_type' => PriceType::SaleAsking]);

        // A rent asking row is out at the boundary (wrong transaction), so it
        // must NOT inflate the asking disclosure either.
        $this->record('800.0000', ['transaction_type' => 'rent', 'price_type' => PriceType::RentAsking]);

        $valuation = $this->value($this->property());

        $this->assertSame('110000.0000', decimal((string) $valuation->midpoint, 4)->toString());
        $this->assertSame(3, $valuation->comparison_count);
        $this->assertSame(2, $valuation->excluded_asking_count);
        $this->assertSame('asking_prices_excluded_from_valuation', $valuation->excluded_asking_note);
    }

    /* ---------------------------------------------------------------------
     * D. Honest insufficiency
     * ------------------------------------------------------------------- */

    public function test_only_incompatible_evidence_refuses_honestly(): void
    {
        // A lineage rich in evidence — none of it compatible: rents, foreign
        // currencies, an undeclared snapshot.
        $this->record('500.0000', ['transaction_type' => 'rent', 'price_type' => PriceType::RentVerified]);
        $this->record('600.0000', ['transaction_type' => 'rent', 'price_type' => PriceType::RentVerified]);
        $this->record('130000000.0000', ['currency' => 'IQD']);
        $this->record('555555.0000', ['currency' => 'EUR']);
        $this->record('105000.0000', [
            'transaction_type' => 'either',
            'price_type' => PriceType::OfficialSnapshot,
        ]);

        $valuation = $this->value($this->property());

        $this->assertNull($valuation->midpoint);
        $this->assertNull($valuation->low);
        $this->assertNull($valuation->high);
        $this->assertSame('insufficient', $valuation->confidence);
        $this->assertSame('none', $valuation->match_label);
        $this->assertSame('no_transaction_evidence_available', $valuation->no_valuation_reason);
    }

    /* ---------------------------------------------------------------------
     * E. Deterministic 400-row window
     * ------------------------------------------------------------------- */

    public function test_the_comparable_window_is_the_highest_id_rows_on_every_run(): void
    {
        /*
         * 401 compatible rows sharing ONE effective_date, each with a
         * distinguishable price keyed to insertion order. The window keeps
         * the 400 HIGHEST ids — rows 2..401, prices 100002..100401 — whose
         * exact median is (100201 + 100202) / 2 = 100201.5000. Had the
         * lowest-id row survived instead, the median would be 100200.5000:
         * the assertion below distinguishes the two windows precisely.
         */
        for ($i = 1; $i <= 401; $i++) {
            $this->record((string) (100000 + $i).'.0000');
        }

        $property = $this->property();

        $first = $this->value($property);
        $second = $this->value($property);

        foreach ([$first, $second] as $valuation) {
            $this->assertSame('100201.5000', decimal((string) $valuation->midpoint, 4)->toString());
            $this->assertSame(400, $valuation->comparison_count);
        }

        // Identical DB state, identical result — field by field.
        $this->assertSame(
            decimal((string) $first->low, 4)->toString(),
            decimal((string) $second->low, 4)->toString(),
        );
        $this->assertSame(
            decimal((string) $first->high, 4)->toString(),
            decimal((string) $second->high, 4)->toString(),
        );
        $this->assertSame($first->confidence, $second->confidence);
        $this->assertSame($first->match_level, $second->match_level);
        $this->assertSame($first->data_period_from?->toDateString(), $second->data_period_from?->toDateString());
        $this->assertSame($first->data_period_to?->toDateString(), $second->data_period_to?->toDateString());
    }

    /* ---------------------------------------------------------------------
     * F. Engine semantics pinned (first direct coverage — unchanged by
     *    this fix, and now provably so)
     * ------------------------------------------------------------------- */

    public function test_the_tier_ladder_still_matches_exactly(): void
    {
        $engine = app(ValuationEngine::class);

        $property = [
            'project_id' => 7, 'area_id' => null, 'area_path' => null,
            'property_type' => 'apartment', 'unit_type' => 'two_bedroom', 'size_sqm' => '100',
        ];

        $comparable = static fn (string $price): array => [
            'price' => $price, 'price_type' => PriceType::SaleVerified,
            'project_id' => 7, 'unit_type' => 'two_bedroom', 'size_sqm' => '100',
            'effective_date' => '2026-06-01',
        ];

        $result = $engine->value($property, [
            $comparable('100000.0000'), $comparable('105000.0000'), $comparable('110000.0000'),
        ]);

        $this->assertSame(1, $result['match_level']);
        $this->assertSame('same_project_unit_type_and_size', $result['match_label']);
        $this->assertSame('105000.0000', $result['midpoint']);
        $this->assertSame('moderate', $result['confidence']);

        // Too few comparables at every tier: the honest refusal, unchanged.
        $short = $engine->value($property, [
            $comparable('100000.0000'), $comparable('110000.0000'),
        ]);

        $this->assertNull($short['midpoint']);
        $this->assertSame('insufficient_comparables_at_every_match_level', $short['reason']);
    }

    public function test_the_confidence_matrix_is_unchanged(): void
    {
        $engine = app(ValuationEngine::class);

        $this->assertSame('high', $engine->confidence(1, 8));
        $this->assertSame('moderate', $engine->confidence(1, 7));
        $this->assertSame('moderate', $engine->confidence(2, 8));
        $this->assertSame('low', $engine->confidence(2, 7));
        $this->assertSame('moderate', $engine->confidence(3, 12));
        $this->assertSame('low', $engine->confidence(3, 11));
        $this->assertSame('low', $engine->confidence(4, 100));
    }

    public function test_the_engines_asking_exclusion_is_unchanged(): void
    {
        $engine = app(ValuationEngine::class);

        $property = ['project_id' => 7, 'unit_type' => 'two_bedroom', 'size_sqm' => '100'];

        $verified = static fn (string $price): array => [
            'price' => $price, 'price_type' => PriceType::SaleVerified,
            'project_id' => 7, 'unit_type' => 'two_bedroom', 'size_sqm' => '100',
        ];
        $asking = static fn (string $price): array => [
            'price' => $price, 'price_type' => PriceType::SaleAsking,
            'project_id' => 7, 'unit_type' => 'two_bedroom', 'size_sqm' => '100',
        ];

        $result = $engine->value($property, [
            $verified('100000.0000'), $verified('105000.0000'), $verified('110000.0000'),
            $asking('200000.0000'), $asking('210000.0000'),
        ]);

        $this->assertSame('105000.0000', $result['midpoint']);
        $this->assertSame(3, $result['comparison_count']);
        $this->assertSame(2, $result['excluded_asking_count']);
        $this->assertSame('asking_prices_excluded_from_valuation', $result['excluded_asking_note']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Models\PortfolioValuation;
use App\Modules\Portfolio\Services\PortfolioSummaryService;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The portfolio's regression floor (redesign Wave 5 §19) — the coverage the
 * audit found missing.
 *
 * Everything here runs identically on the CI matrix's SQLite and MariaDB
 * lanes; every money assertion compares exact scale-4 decimal STRINGS, never
 * floats, so an engine that trims trailing zeros cannot fake a pass and a
 * binary-float sum cannot sneak one through.
 */
final class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->setFeatures(['portfolio' => true]);
    }

    /** A member who has completed the Telegram link — the portfolio's gate. */
    private function member(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['telegram_verified_at' => now()])->save();

        return $user;
    }

    /** @param array<model-property<PortfolioProperty>, mixed> $overrides */
    private function property(User $owner, string $label, array $overrides = []): PortfolioProperty
    {
        $property = new PortfolioProperty;
        $property->fill($overrides + [
            'user_id' => $owner->id,
            'property_type' => 'apartment',
            'currency' => 'USD',
            'location_precision' => PortfolioProperty::PRECISION_AREA_ONLY,
            'consent_valuation' => true,
        ]);
        $property->setLabel($label);
        $property->setNotes(null);
        $property->save();

        return $property;
    }

    /** @param array<model-property<PortfolioValuation>, mixed> $overrides */
    private function valuation(PortfolioProperty $property, string $midpoint, string $calculatedAt, array $overrides = []): PortfolioValuation
    {
        return PortfolioValuation::query()->create($overrides + [
            'portfolio_property_id' => $property->id,
            'midpoint' => $midpoint,
            'low' => $midpoint,
            'high' => $midpoint,
            'currency' => 'USD',
            'confidence' => 'moderate',
            'match_level' => 2,
            'match_label' => 'area',
            'methodology' => 'median_comparables_v1',
            'comparison_count' => 8,
            'excluded_asking_count' => 3,
            'excluded_asking_note' => 'Asking prices were excluded from this estimate.',
            'no_valuation_reason' => null,
            'calculated_at' => $calculatedAt,
        ]);
    }

    /** @param array<model-property<Area>, mixed> $overrides */
    private function area(string $slug, array $overrides = []): Area
    {
        return Area::query()->create($overrides + [
            'type' => 'district',
            'slug' => $slug,
            'name_ckb' => 'ناوچە '.$slug,
            'publication_status' => 'published',
        ]);
    }

    /** @param array<model-property<Project>, mixed> $overrides */
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

    /* ---------------------------------------------------------------------
     * Gating (§19.1, §19.2)
     * ------------------------------------------------------------------- */

    public function test_the_feature_flag_off_keeps_every_portfolio_route_a_404(): void
    {
        $this->setFeatures(['portfolio' => false]);

        $owner = $this->member();
        $property = $this->property($owner, 'Family home');

        $this->actingAs($owner);
        $this->get('/account/portfolio')->assertNotFound();
        $this->get('/account/portfolio/'.$property->id)->assertNotFound();
    }

    public function test_the_feature_flag_on_opens_the_portfolio_to_its_authorised_owner(): void
    {
        $owner = $this->member();
        $this->property($owner, 'Family home');

        $this->actingAs($owner)
            ->get('/account/portfolio')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Account/Portfolio/Index')
                ->where('summary.property_count', 1));
    }

    /* ---------------------------------------------------------------------
     * Ownership (§19.3–§19.5, §19.18)
     * ------------------------------------------------------------------- */

    public function test_another_account_sees_none_of_the_owners_portfolio(): void
    {
        $owner = $this->member();
        $property = $this->property($owner, 'Family home');
        $this->valuation($property, '150000.0000', '2026-07-01 12:00:00');

        $stranger = $this->member();

        // The index lists ONLY the session owner's rows — the stranger's
        // portfolio is empty, and the summary derives from that same nothing.
        $this->actingAs($stranger)
            ->get('/account/portfolio')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.property_count', 0)
                ->where('summary.totals', [])
                ->where('properties.data', []));
    }

    public function test_another_accounts_item_urls_answer_404_not_403(): void
    {
        $owner = $this->member();
        $property = $this->property($owner, 'Family home');
        $this->valuation($property, '150000.0000', '2026-07-01 12:00:00');

        $stranger = $this->member();
        $this->actingAs($stranger);

        // Show (which is also the only door to the valuation history), edit
        // and the valuation trigger: a stranger must not even learn that a
        // property with this id exists.
        $this->get('/account/portfolio/'.$property->id)->assertNotFound();
        $this->put('/account/portfolio/'.$property->id, [])->assertNotFound();
        $this->post('/account/portfolio/'.$property->id.'/valuation')->assertNotFound();
        $this->delete('/account/portfolio/'.$property->id)->assertNotFound();

        // And nothing was altered by the attempts.
        $this->assertSame(1, PortfolioValuation::query()->count());
        $this->assertNotNull(PortfolioProperty::query()->find($property->id));
    }

    public function test_an_unrelated_users_valuations_never_enter_the_summary(): void
    {
        $owner = $this->member();
        $mine = $this->property($owner, 'Mine');
        $this->valuation($mine, '100000.0000', '2026-07-01 12:00:00');

        $other = $this->member();
        $theirs = $this->property($other, 'Theirs');
        $this->valuation($theirs, '900000.0000', '2026-07-02 12:00:00');

        $summary = app(PortfolioSummaryService::class)->summarise($owner->id);

        $this->assertSame(1, $summary['property_count']);
        $this->assertSame('100000.0000', $summary['totals'][0]['total']);
        // The other account's later valuation must not even move the date.
        $this->assertSame('2026-07-01', $summary['latest_valued_at']);
    }

    /* ---------------------------------------------------------------------
     * Current value and append-only history (§19.6–§19.9, §19.16, §19.17)
     * ------------------------------------------------------------------- */

    public function test_current_value_is_the_latest_by_calculated_at_not_by_insertion_order(): void
    {
        $owner = $this->member();
        $property = $this->property($owner, 'Family home');

        // Inserted NEWEST FIRST, so MAX(id) would pick the stale figure —
        // the relation must order by calculated_at, exactly as documented.
        $this->valuation($property, '180000.0000', '2026-07-01 12:00:00');
        $this->valuation($property, '150000.0000', '2026-03-01 12:00:00');

        $this->actingAs($owner)
            ->get('/account/portfolio')
            ->assertInertia(fn ($page) => $page
                ->where('properties.data.0.valuation.midpoint', '180000.0000')
                ->where('summary.totals.0.total', '180000.0000')
                ->where('summary.latest_valued_at', '2026-07-01'));
    }

    public function test_valuation_history_is_append_only_at_the_model_boundary(): void
    {
        $owner = $this->member();
        $property = $this->property($owner, 'Family home');
        $valuation = $this->valuation($property, '150000.0000', '2026-03-01 12:00:00');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $valuation->update(['midpoint' => '999999.0000']);
    }

    public function test_a_new_valuation_never_rewrites_the_older_row(): void
    {
        $owner = $this->member();
        $property = $this->property($owner, 'Family home');
        $first = $this->valuation($property, '150000.0000', '2026-03-01 12:00:00');
        $this->valuation($property, '180000.0000', '2026-07-01 12:00:00');

        $this->assertSame(2, $property->valuations()->count());

        $kept = PortfolioValuation::query()->findOrFail($first->id);
        $this->assertSame('150000.0000', decimal((string) $kept->midpoint, 4)->toString());
        $this->assertSame('2026-03-01', $kept->calculated_at->toDateString());
    }

    public function test_the_history_is_ordered_newest_first_deterministically(): void
    {
        $owner = $this->member();
        $property = $this->property($owner, 'Family home');
        $this->valuation($property, '150000.0000', '2026-03-01 12:00:00');
        $this->valuation($property, '180000.0000', '2026-07-01 12:00:00');
        $this->valuation($property, '160000.0000', '2026-05-01 12:00:00');

        $this->actingAs($owner)
            ->get('/account/portfolio/'.$property->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Account/Portfolio/Show')
                ->where('history.0.midpoint', '180000.0000')
                ->where('history.1.midpoint', '160000.0000')
                ->where('history.2.midpoint', '150000.0000'));
    }

    /* ---------------------------------------------------------------------
     * Honest empty and partial states (§19.10, §19.11)
     * ------------------------------------------------------------------- */

    public function test_no_valuations_is_an_honest_state_never_a_zero(): void
    {
        $owner = $this->member();
        $this->property($owner, 'Family home');

        $summary = app(PortfolioSummaryService::class)->summarise($owner->id);

        $this->assertSame('no_valuations', $summary['state']);
        $this->assertSame([], $summary['totals']);
        $this->assertNull($summary['latest_valued_at']);
        $this->assertSame(1, $summary['awaiting_count']);
    }

    public function test_a_latest_refusal_keeps_the_property_awaiting_not_zero(): void
    {
        $owner = $this->member();
        $property = $this->property($owner, 'Family home');

        // An older REAL figure, then a newer refusal: the latest word is the
        // refusal, so the property must leave the totals entirely rather
        // than contribute the stale number or a fabricated zero.
        $this->valuation($property, '150000.0000', '2026-03-01 12:00:00');
        $this->valuation($property, '150000.0000', '2026-07-01 12:00:00', [
            'midpoint' => null,
            'low' => null,
            'high' => null,
            'no_valuation_reason' => 'insufficient_comparables',
        ]);

        $summary = app(PortfolioSummaryService::class)->summarise($owner->id);

        $this->assertSame('no_valuations', $summary['state']);
        $this->assertSame([], $summary['totals']);
        $this->assertSame(1, $summary['awaiting_count']);
    }

    public function test_partial_coverage_is_reported_exactly(): void
    {
        $owner = $this->member();
        $valued = $this->property($owner, 'Valued');
        $this->property($owner, 'Awaiting');
        $this->valuation($valued, '150000.0000', '2026-07-01 12:00:00');

        $summary = app(PortfolioSummaryService::class)->summarise($owner->id);

        $this->assertSame('ready', $summary['state']);
        $this->assertSame(2, $summary['property_count']);
        $this->assertSame(1, $summary['valued_count']);
        $this->assertSame(1, $summary['awaiting_count']);
    }

    /* ---------------------------------------------------------------------
     * Money (§19.12–§19.15)
     * ------------------------------------------------------------------- */

    public function test_same_currency_totals_are_exact_at_scale_four(): void
    {
        $owner = $this->member();
        $a = $this->property($owner, 'A');
        $b = $this->property($owner, 'B');
        $this->valuation($a, '150000.1234', '2026-07-01 12:00:00');
        $this->valuation($b, '200000.0001', '2026-07-02 12:00:00');

        $summary = app(PortfolioSummaryService::class)->summarise($owner->id);

        $this->assertCount(1, $summary['totals']);
        $this->assertSame('USD', $summary['totals'][0]['currency']);
        $this->assertSame('350000.1235', $summary['totals'][0]['total']);
        $this->assertSame(2, $summary['totals'][0]['count']);
    }

    public function test_money_never_travels_through_binary_floats(): void
    {
        $owner = $this->member();
        $a = $this->property($owner, 'A');
        $b = $this->property($owner, 'B');

        // The canonical float trap: 0.1 + 0.2. A float path yields
        // 0.30000000000000004; the platform's Decimal yields the truth.
        $this->valuation($a, '0.1000', '2026-07-01 12:00:00');
        $this->valuation($b, '0.2000', '2026-07-02 12:00:00');

        $summary = app(PortfolioSummaryService::class)->summarise($owner->id);

        $this->assertSame('0.3000', $summary['totals'][0]['total']);
    }

    public function test_different_currencies_are_never_summed_together(): void
    {
        $owner = $this->member();
        $usd = $this->property($owner, 'USD home');
        $iqd = $this->property($owner, 'IQD home', ['currency' => 'IQD']);
        $this->valuation($usd, '150000.0000', '2026-07-01 12:00:00');
        $this->valuation($iqd, '200000000.0000', '2026-07-02 12:00:00', ['currency' => 'IQD']);

        $summary = app(PortfolioSummaryService::class)->summarise($owner->id);

        $this->assertTrue($summary['multi_currency']);
        $this->assertCount(2, $summary['totals']);

        // Deterministic order (currency code ascending), one exact figure
        // per currency, and no combined number anywhere in the payload.
        $this->assertSame('IQD', $summary['totals'][0]['currency']);
        $this->assertSame('200000000.0000', $summary['totals'][0]['total']);
        $this->assertSame('USD', $summary['totals'][1]['currency']);
        $this->assertSame('150000.0000', $summary['totals'][1]['total']);
    }

    public function test_the_latest_valued_timestamp_spans_the_whole_portfolio(): void
    {
        $owner = $this->member();
        $a = $this->property($owner, 'A');
        $b = $this->property($owner, 'B');
        $this->valuation($a, '150000.0000', '2026-02-01 12:00:00');
        $this->valuation($b, '200000.0000', '2026-06-15 12:00:00');

        $summary = app(PortfolioSummaryService::class)->summarise($owner->id);

        $this->assertSame('2026-06-15', $summary['latest_valued_at']);
    }

    /* ---------------------------------------------------------------------
     * Identity and composition (§19.6, §19.19, §19.20)
     * ------------------------------------------------------------------- */

    public function test_counts_and_composition_reflect_the_real_assets(): void
    {
        $owner = $this->member();
        $this->property($owner, 'A');
        $this->property($owner, 'B');
        $this->property($owner, 'C', ['property_type' => 'land']);

        $summary = app(PortfolioSummaryService::class)->summarise($owner->id);

        $this->assertSame(3, $summary['property_count']);
        $this->assertSame([
            ['property_type' => 'apartment', 'count' => 2],
            ['property_type' => 'land', 'count' => 1],
        ], $summary['composition']);
    }

    public function test_project_and_area_identity_resolve_from_stored_references(): void
    {
        $owner = $this->member();
        $area = $this->area('pf-kasnazan', [
            'name_ckb' => 'کەسنەزان', 'name_ar' => 'كسنزان', 'name_en' => 'Kasnazan',
        ]);
        $project = $this->project('pf-towers', [
            'name_ckb' => 'بورجەکانی ئازادی', 'name_ar' => 'أبراج الحرية', 'name_en' => 'Azadi Towers',
        ]);

        $this->property($owner, 'Family home', [
            'project_id' => $project->id,
            'area_id' => $area->id,
        ]);

        // The page receives the stored references plus the published option
        // lists carrying all three name columns — the client resolves the
        // display name through the same ckb-floor fallback Profile uses.
        $this->actingAs($owner)
            ->get('/account/portfolio')
            ->assertInertia(fn ($page) => $page
                ->where('properties.data.0.project_id', $project->id)
                ->where('properties.data.0.area_id', $area->id)
                ->where('projects.0.name_ckb', 'بورجەکانی ئازادی')
                ->where('projects.0.name_en', 'Azadi Towers')
                ->where('areas.0.name_ckb', 'کەسنەزان')
                ->where('areas.0.name_ar', 'كسنزان'));
    }

    /* ---------------------------------------------------------------------
     * Destructive action (§19.21)
     * ------------------------------------------------------------------- */

    public function test_deleting_a_property_follows_the_documented_semantics_and_is_audited(): void
    {
        $owner = $this->member();
        $gone = $this->property($owner, 'Sold home');
        $kept = $this->property($owner, 'Kept home');
        $this->valuation($gone, '150000.0000', '2026-03-01 12:00:00');
        $keptValuation = $this->valuation($kept, '200000.0000', '2026-04-01 12:00:00');

        $this->actingAs($owner)
            ->delete('/account/portfolio/'.$gone->id)
            ->assertRedirect();

        /*
         * §9.7's documented semantics: a REAL delete. The removed home's
         * encrypted label and its valuation rows are gone — a hidden row
         * holding an address has not honoured the request — while every
         * OTHER property's append-only history stands untouched, and the
         * audit trail records that the deletion happened.
         */
        $this->assertNull(PortfolioProperty::query()->withTrashed()->find($gone->id));
        $this->assertSame(0, PortfolioValuation::query()->where('portfolio_property_id', $gone->id)->count());

        $this->assertNotNull(PortfolioProperty::query()->find($kept->id));

        $keptRow = PortfolioValuation::query()->findOrFail($keptValuation->id);
        $this->assertSame('200000.0000', decimal((string) $keptRow->midpoint, 4)->toString());

        $this->assertTrue(
            DB::table('audit_logs')->where('action', 'portfolio.property_deleted')->exists(),
            'the deletion must leave an audit record',
        );
    }
}

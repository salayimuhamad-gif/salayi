<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Enums\ScopeType;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Portfolio\Exceptions\ValuationRulePublishException;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Models\PortfolioPropertyAnswer;
use App\Modules\Portfolio\Models\PortfolioValuation;
use App\Modules\Portfolio\Models\PortfolioValuationAdjustment;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use App\Modules\Portfolio\Services\PortfolioSummaryService;
use App\Modules\Portfolio\Services\PortfolioValuer;
use App\Modules\Portfolio\Services\ValuationAdjustments;
use App\Modules\Portfolio\Services\ValuationRulePublisher;
use App\Modules\Portfolio\Services\ValuationRuleResolver;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

/**
 * The Wave 6 question-driven rule engine, proven end to end: server-owned
 * percentages, deterministic scope, exact Decimal arithmetic, honest
 * refusals, snapshot-only history, and — above everything — that the whole
 * surface is INERT unless a rule set applies and valid answers exist.
 *
 * Every money assertion compares exact scale-4 decimal STRINGS; every
 * percent compares exact scale-3 strings. No floats, no tolerances, and
 * identical semantics on the CI matrix's SQLite and MariaDB lanes.
 */
final class ValuationRulesTest extends TestCase
{
    use RefreshDatabase;

    private Area $city;

    private Area $district;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->setFeatures(['portfolio' => true, 'portfolio.valuation_rules' => true]);

        $this->city = Area::query()->create([
            'type' => 'city',
            'slug' => 'vr-erbil',
            'name_ckb' => 'هەولێر',
            'publication_status' => 'published',
        ]);

        $this->district = Area::query()->create([
            'type' => 'district',
            'slug' => 'vr-kasnazan',
            'name_ckb' => 'کەسنەزان',
            'parent_id' => $this->city->id,
            'publication_status' => 'published',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* fixtures                                                            */
    /* ------------------------------------------------------------------ */

    private function member(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['telegram_verified_at' => now()])->save();

        return $user;
    }

    /** @param array<model-property<PortfolioProperty>, mixed> $overrides */
    private function property(array $overrides = [], ?User $owner = null): PortfolioProperty
    {
        $property = new PortfolioProperty;
        $property->fill($overrides + [
            'user_id' => ($owner ?? $this->member())->id,
            'property_type' => 'apartment',
            'area_id' => $this->district->id,
            'currency' => 'USD',
            'location_precision' => PortfolioProperty::PRECISION_AREA_ONLY,
            'consent_valuation' => true,
        ]);
        $property->setLabel('Rules fixture');
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

    /** The standard three-comparable pool: median 110000, L4, count 3. */
    private function evidence(): void
    {
        $this->record('100000.0000');
        $this->record('110000.0000');
        $this->record('120000.0000');
    }

    private function project(string $slug): Project
    {
        return Project::query()->create([
            'slug' => $slug,
            'name_ckb' => 'پڕۆژەی '.$slug,
            'name_en' => 'Project '.$slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'area_id' => $this->district->id,
            'publication_status' => 'published',
        ]);
    }

    /** @param array<model-property<ValuationRuleSet>, mixed> $overrides */
    private function draftSet(array $overrides = []): ValuationRuleSet
    {
        $projectId = $overrides['project_id'] ?? null;

        return ValuationRuleSet::query()->create($overrides + [
            'name' => 'Wave 6 rules',
            'scope_transaction' => ValuationRuleSet::SCOPE_TRANSACTION_SALE,
            'project_id' => $projectId,
            'version' => app(ValuationRulePublisher::class)
                ->nextVersion(ValuationRuleSet::SCOPE_TRANSACTION_SALE, is_int($projectId) ? $projectId : null),
            'status' => ValuationRuleSet::STATUS_DRAFT,
        ]);
    }

    /** @param array<model-property<ValuationQuestion>, mixed> $overrides */
    private function question(ValuationRuleSet $set, string $key, array $overrides = []): ValuationQuestion
    {
        return ValuationQuestion::query()->create($overrides + [
            'valuation_rule_set_id' => $set->id,
            'key' => $key,
            'question_type' => ValuationQuestion::TYPE_SINGLE_SELECT,
            'label_ckb' => 'پرسیار '.$key,
            'label_ar' => 'سؤال '.$key,
            'label_en' => 'Question '.$key,
            'sort_order' => (int) ValuationQuestion::query()
                ->where('valuation_rule_set_id', $set->id)->count(),
            'is_active' => true,
        ]);
    }

    /** @param array<model-property<ValuationQuestionOption>, mixed> $overrides */
    private function option(ValuationQuestion $question, string $key, string $percent, array $overrides = []): ValuationQuestionOption
    {
        return ValuationQuestionOption::query()->create($overrides + [
            'valuation_question_id' => $question->id,
            'key' => $key,
            'label_ckb' => 'هەڵبژاردە '.$key,
            'label_ar' => 'خيار '.$key,
            'label_en' => 'Option '.$key,
            'adjustment_percent' => $percent,
            'sort_order' => (int) ValuationQuestionOption::query()
                ->where('valuation_question_id', $question->id)->count(),
            'is_active' => true,
        ]);
    }

    /**
     * One published set with one question and two options: 'plus' at +5.000
     * and 'minus' at -3.500. Returns [set, question, plus, minus].
     *
     * @return array{0: ValuationRuleSet, 1: ValuationQuestion, 2: ValuationQuestionOption, 3: ValuationQuestionOption}
     */
    private function activeSet(): array
    {
        $set = $this->draftSet();
        $question = $this->question($set, 'renovation');
        $plus = $this->option($question, 'renovated', '5.000');
        $minus = $this->option($question, 'original', '-3.500');

        app(ValuationRulePublisher::class)->publish($set);
        $set->refresh();

        return [$set, $question, $plus, $minus];
    }

    private function answer(PortfolioProperty $property, ValuationQuestion $question, ValuationQuestionOption $option): PortfolioPropertyAnswer
    {
        return PortfolioPropertyAnswer::query()->create([
            'portfolio_property_id' => $property->id,
            'valuation_question_id' => $question->id,
            'valuation_question_option_id' => $option->id,
        ]);
    }

    private function value(PortfolioProperty $property): PortfolioValuation
    {
        return app(PortfolioValuer::class)->value($property);
    }

    /** Asserts a valuation row is byte-identical to pre-Wave-6 behaviour. */
    private function assertBaselineRow(PortfolioValuation $valuation): void
    {
        $this->assertSame('110000.0000', (string) $valuation->midpoint);
        $this->assertNull($valuation->base_midpoint);
        $this->assertNull($valuation->base_low);
        $this->assertNull($valuation->base_high);
        $this->assertNull($valuation->adjustment_total_percent);
        $this->assertSame('comparable_median_v1', $valuation->methodology);
        $this->assertNull($valuation->no_valuation_reason);
        $this->assertSame(0, $valuation->adjustments()->count());
    }

    /* ------------------------------------------------------------------ */
    /* inertness — the flag, the scope, the empty answer set               */
    /* ------------------------------------------------------------------ */

    public function test_flag_off_is_byte_identical_even_with_answers_persisted(): void
    {
        $this->evidence();
        [, $question, $plus] = $this->activeSet();

        $property = $this->property();
        $this->answer($property, $question, $plus);

        $this->setFeatures(['portfolio.valuation_rules' => false]);

        $this->assertBaselineRow($this->value($property));
    }

    public function test_zero_answers_is_byte_identical_under_an_applicable_set(): void
    {
        $this->evidence();
        $this->activeSet();

        $this->assertBaselineRow($this->value($this->property()));
    }

    public function test_no_applicable_set_is_inert_and_orphan_answers_never_apply(): void
    {
        $this->evidence();

        // The only active set is scoped to a project this property is not in.
        $project = $this->project('vr-project');

        $set = $this->draftSet(['project_id' => $project->id]);
        $question = $this->question($set, 'renovation');
        $plus = $this->option($question, 'renovated', '5.000');
        app(ValuationRulePublisher::class)->publish($set);

        $property = $this->property();
        // A stored answer pointing into that inapplicable set must not apply.
        $this->answer($property, $question, $plus);

        $this->assertBaselineRow($this->value($property));
    }

    /* ------------------------------------------------------------------ */
    /* the arithmetic — exact, additive, uncapped, refusing at zero        */
    /* ------------------------------------------------------------------ */

    public function test_a_single_adjustment_applies_exact_decimal_math_once(): void
    {
        $this->evidence();
        [, $question, $plus] = $this->activeSet();

        $property = $this->property();
        $this->answer($property, $question, $plus);

        $valuation = $this->value($property);

        // Baseline preserved beside the final figures.
        $this->assertSame('110000.0000', (string) $valuation->base_midpoint);
        $this->assertSame('5.000', (string) $valuation->adjustment_total_percent);
        $this->assertSame('115500.0000', (string) $valuation->midpoint);

        // Low and high move by exactly the same factor as the midpoint.
        $factor = Decimal::of('1', 6)->add(Decimal::of('5.000', 6)->divide('100'));
        $this->assertSame(
            Decimal::of((string) $valuation->base_low, 4)->multiply($factor)->toString(),
            (string) $valuation->low,
        );
        $this->assertSame(
            Decimal::of((string) $valuation->base_high, 4)->multiply($factor)->toString(),
            (string) $valuation->high,
        );

        // Methodology says rules participated; the evidence metadata is the
        // ENGINE's, untouched by the adjustment layer.
        $this->assertSame('comparable_median_rules_v1', $valuation->methodology);
        $this->assertSame(4, $valuation->match_level);
        $this->assertSame('wider_area_fallback', $valuation->match_label);
        $this->assertSame(3, $valuation->comparison_count);
        $this->assertSame('no_asking_prices_present', $valuation->excluded_asking_note);

        // One snapshot row carrying the applied truth.
        $snapshots = $valuation->adjustments()->get();
        $this->assertCount(1, $snapshots);
        $this->assertSame('renovation', $snapshots[0]->question_key);
        $this->assertSame('renovated', $snapshots[0]->option_key);
        $this->assertSame('5.000', (string) $snapshots[0]->adjustment_percent);
        $this->assertSame(0, $snapshots[0]->position);
        $this->assertSame('پرسیار renovation', $snapshots[0]->question_ckb);
        $this->assertSame('Option renovated', $snapshots[0]->option_en);
    }

    public function test_adjustments_sum_additively_and_are_order_independent(): void
    {
        $this->evidence();

        $set = $this->draftSet();
        $first = $this->question($set, 'renovation', ['sort_order' => 0]);
        $second = $this->question($set, 'view', ['sort_order' => 1]);
        $plus = $this->option($first, 'renovated', '5.000');
        $minusOption = $this->option($second, 'blocked', '-3.500');
        app(ValuationRulePublisher::class)->publish($set);

        // Answered in REVERSE question order, deliberately.
        $property = $this->property();
        $this->answer($property, $second, $minusOption);
        $this->answer($property, $first, $plus);

        $valuation = $this->value($property);

        // 5.000 + (-3.500) = 1.500, additively — never compounded.
        $this->assertSame('1.500', (string) $valuation->adjustment_total_percent);
        $this->assertSame('111650.0000', (string) $valuation->midpoint);

        // Snapshot order follows the AUTHORED question order, not insertion.
        $keys = $valuation->adjustments()->get()->pluck('question_key')->all();
        $this->assertSame(['renovation', 'view'], $keys);
    }

    public function test_scale_three_percents_sum_exactly(): void
    {
        $this->evidence();

        $set = $this->draftSet();
        $a = $this->question($set, 'q_a');
        $b = $this->question($set, 'q_b');
        $optionA = $this->option($a, 'a', '1.111');
        $optionB = $this->option($b, 'b', '2.222');
        app(ValuationRulePublisher::class)->publish($set);

        $property = $this->property();
        $this->answer($property, $a, $optionA);
        $this->answer($property, $b, $optionB);

        $valuation = $this->value($property);

        $this->assertSame('3.333', (string) $valuation->adjustment_total_percent);
        // 110000 × 1.033330, half-up at scale 4.
        $this->assertSame('113666.3000', (string) $valuation->midpoint);
    }

    public function test_the_total_is_uncapped_and_the_warning_threshold_is_exact(): void
    {
        $this->evidence();

        /*
         * Every option is authored in the DRAFT — a published set's content
         * is frozen, so the edge-case options exist before publish, like
         * they would in production.
         */
        $set = $this->draftSet();
        $a = $this->question($set, 'q_a');
        $b = $this->question($set, 'q_b');
        $optionA = $this->option($a, 'a', '25.000');
        $optionB = $this->option($b, 'b', '25.000');
        $justUnder = $this->option($a, 'edge_under', '29.999');
        $under = $this->option($a, 'edge_minus', '-5.000');
        $exactly = $this->option($b, 'edge_at', '-25.000');
        app(ValuationRulePublisher::class)->publish($set);

        $property = $this->property();
        $this->answer($property, $a, $optionA);
        $this->answer($property, $b, $optionB);

        // +50% applies IN FULL — warned, never clamped.
        $valuation = $this->value($property);
        $this->assertSame('50.000', (string) $valuation->adjustment_total_percent);
        $this->assertSame('165000.0000', (string) $valuation->midpoint);

        $plan = app(ValuationAdjustments::class)->planFor($property);
        $this->assertTrue($plan['warned']);

        // The threshold is exact: |29.999| stays quiet, |-30.000| warns.
        $quiet = $this->property();
        $this->answer($quiet, $a, $justUnder);
        $this->assertFalse(app(ValuationAdjustments::class)->planFor($quiet)['warned']);

        $loud = $this->property();
        $this->answer($loud, $a, $under);
        $this->answer($loud, $b, $exactly);
        $plan = app(ValuationAdjustments::class)->planFor($loud);
        $this->assertSame('-30.000', $plan['total_percent']);
        $this->assertTrue($plan['warned']);
    }

    public function test_a_factor_at_zero_refuses_instead_of_publishing_nothing(): void
    {
        $this->evidence();

        $set = $this->draftSet();
        $questions = [];
        $options = [];

        foreach (['q_a', 'q_b', 'q_c', 'q_d'] as $key) {
            $questions[$key] = $this->question($set, $key);
            $options[$key] = $this->option($questions[$key], $key.'_min', '-25.000');
        }

        app(ValuationRulePublisher::class)->publish($set);

        $property = $this->property();

        foreach ($questions as $key => $question) {
            $this->answer($property, $question, $options[$key]);
        }

        // Total -100.000 → factor 0.000000 → an explicit refusal, with the
        // baseline and the snapshots preserved as the explanation.
        $valuation = $this->value($property);

        $this->assertNull($valuation->midpoint);
        $this->assertNull($valuation->low);
        $this->assertNull($valuation->high);
        $this->assertSame('adjustments_exceed_valuation_basis', $valuation->no_valuation_reason);
        $this->assertSame('110000.0000', (string) $valuation->base_midpoint);
        $this->assertSame('-100.000', (string) $valuation->adjustment_total_percent);
        $this->assertSame('comparable_median_rules_v1', $valuation->methodology);
        $this->assertSame(3, $valuation->comparison_count);
        $this->assertSame(4, $valuation->adjustments()->count());
    }

    public function test_a_baseline_refusal_is_never_adjusted(): void
    {
        // NO evidence at all — the engine refuses before answers matter.
        [, $question, $plus] = $this->activeSet();

        $property = $this->property();
        $this->answer($property, $question, $plus);

        $valuation = $this->value($property);

        $this->assertNull($valuation->midpoint);
        $this->assertSame('no_transaction_evidence_available', $valuation->no_valuation_reason);
        $this->assertNull($valuation->base_midpoint);
        $this->assertNull($valuation->adjustment_total_percent);
        $this->assertSame('comparable_median_v1', $valuation->methodology);
        $this->assertSame(0, $valuation->adjustments()->count());
    }

    public function test_evidence_discipline_survives_under_adjustment(): void
    {
        $this->evidence();

        // Poison: wrong currency, wrong basis, no declared basis, asking.
        $this->record('900000.0000', ['currency' => 'IQD']);
        $this->record('500.0000', ['transaction_type' => 'rent', 'price_type' => PriceType::RentAsking]);
        $this->record('999999.0000', ['transaction_type' => 'either', 'price_type' => PriceType::OfficialSnapshot]);
        $this->record('300000.0000', ['price_type' => PriceType::SaleAsking]);

        [, $question, $plus] = $this->activeSet();
        $property = $this->property();
        $this->answer($property, $question, $plus);

        $valuation = $this->value($property);

        // The BASE is the clean median: the poison never reached the engine,
        // and the same-currency asking row was engine-excluded and disclosed.
        $this->assertSame('110000.0000', (string) $valuation->base_midpoint);
        $this->assertSame('115500.0000', (string) $valuation->midpoint);
        $this->assertSame(3, $valuation->comparison_count);
        $this->assertSame(1, $valuation->excluded_asking_count);
        $this->assertSame('asking_prices_excluded_from_valuation', $valuation->excluded_asking_note);
    }

    /* ------------------------------------------------------------------ */
    /* scope resolution                                                    */
    /* ------------------------------------------------------------------ */

    public function test_scope_resolution_is_deterministic_and_project_beats_global(): void
    {
        $project = $this->project('vr-project-scope');

        $global = $this->draftSet(['name' => 'Global']);
        $globalQ = $this->question($global, 'renovation');
        $this->option($globalQ, 'renovated', '5.000');
        app(ValuationRulePublisher::class)->publish($global);

        $scoped = $this->draftSet(['name' => 'Project', 'project_id' => $project->id]);
        $scopedQ = $this->question($scoped, 'renovation');
        $this->option($scopedQ, 'renovated', '10.000');
        app(ValuationRulePublisher::class)->publish($scoped);

        $resolver = app(ValuationRuleResolver::class);

        // In the project: the project-scoped set wins. Outside: the global.
        $this->assertSame('Project', $resolver->activeSetFor($project->id, 'apartment')?->name);
        $this->assertSame('Global', $resolver->activeSetFor(null, 'apartment')?->name);

        // An explicit type list matches listed types only.
        $typed = $this->draftSet(['name' => 'Villas', 'property_types' => ['villa']]);
        $typedQ = $this->question($typed, 'garden');
        $this->option($typedQ, 'large', '3.000');
        app(ValuationRulePublisher::class)->publish($typed);

        // Publishing 'Villas' superseded nothing type-disjoint? It DID
        // intersect 'Global' (null types = every type), so Global retired —
        // the family holds one active claim per property, structurally.
        $this->assertSame(
            ValuationRuleSet::STATUS_RETIRED,
            ValuationRuleSet::query()->find($global->id)?->status,
        );
        $this->assertSame('Villas', $resolver->activeSetFor(null, 'villa')?->name);
        $this->assertNull($resolver->activeSetFor(null, 'apartment'));
    }

    public function test_an_anomalous_equal_specificity_tie_breaks_deterministically(): void
    {
        /*
         * Two overlapping actives cannot be produced through the publisher —
         * this seeds them DIRECTLY to prove the resolver still answers
         * deterministically (published_at DESC, id ASC) instead of by
         * query-plan accident.
         */
        $older = ValuationRuleSet::query()->create([
            'name' => 'Older', 'scope_transaction' => 'sale', 'version' => 90,
            'status' => ValuationRuleSet::STATUS_ACTIVE, 'published_at' => now()->subDay(),
        ]);
        $newer = ValuationRuleSet::query()->create([
            'name' => 'Newer', 'scope_transaction' => 'sale', 'version' => 91,
            'status' => ValuationRuleSet::STATUS_ACTIVE, 'published_at' => now(),
        ]);

        $resolver = app(ValuationRuleResolver::class);

        $this->assertSame($newer->id, $resolver->activeSetFor(null, 'apartment')?->id);
        // Stable across repeated calls.
        $this->assertSame($newer->id, $resolver->activeSetFor(null, 'apartment')?->id);
        $this->assertGreaterThan($older->id, $newer->id);
    }

    /* ------------------------------------------------------------------ */
    /* lifecycle and immutability                                          */
    /* ------------------------------------------------------------------ */

    public function test_publish_validates_content_and_the_authoring_bound(): void
    {
        $publisher = app(ValuationRulePublisher::class);

        $empty = $this->draftSet();

        try {
            $publisher->publish($empty);
            $this->fail('an empty draft published');
        } catch (ValuationRulePublishException $e) {
            $this->assertSame('no_active_questions', $e->errorKey);
        }

        $optionless = $this->draftSet();
        $this->question($optionless, 'renovation');

        try {
            $publisher->publish($optionless);
            $this->fail('a question without options published');
        } catch (ValuationRulePublishException $e) {
            $this->assertSame('question_without_options', $e->errorKey);
        }

        $outOfBounds = $this->draftSet();
        $question = $this->question($outOfBounds, 'renovation');
        $this->option($question, 'huge', '25.001');

        try {
            $publisher->publish($outOfBounds);
            $this->fail('an out-of-bounds percent published');
        } catch (ValuationRulePublishException $e) {
            $this->assertSame('adjustment_out_of_bounds', $e->errorKey);
        }

        // All three drafts are still drafts: refusal changed nothing.
        $this->assertSame(0, ValuationRuleSet::query()->where('status', 'active')->count());
    }

    public function test_publishing_a_new_version_retires_the_predecessor_atomically(): void
    {
        [$v1] = $this->activeSet();

        app(ValuationRulePublisher::class)->duplicateAsDraft($v1);
        $v2 = ValuationRuleSet::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame($v1->version + 1, $v2->version);
        $this->assertSame(ValuationRuleSet::STATUS_DRAFT, $v2->status);

        app(ValuationRulePublisher::class)->publish($v2);

        $this->assertSame(ValuationRuleSet::STATUS_RETIRED, ValuationRuleSet::query()->find($v1->id)?->status);
        $this->assertNotNull(ValuationRuleSet::query()->find($v1->id)?->retired_at);
        $this->assertSame(ValuationRuleSet::STATUS_ACTIVE, ValuationRuleSet::query()->find($v2->id)?->status);

        // Exactly one active claim on the family, before and after.
        $this->assertSame(1, ValuationRuleSet::query()->where('status', 'active')->count());
    }

    public function test_published_content_is_structurally_frozen(): void
    {
        [$set, $question, $plus] = $this->activeSet();

        try {
            $set->name = 'Renamed';
            $set->save();
            $this->fail('an active set accepted an edit');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('frozen', $e->getMessage());
        }

        try {
            $question->label_en = 'Rewritten';
            $question->save();
            $this->fail('a question of an active set accepted an edit');
        } catch (RuntimeException) {
        }

        try {
            $plus->adjustment_percent = '24.000';
            $plus->save();
            $this->fail('an option of an active set accepted an edit');
        } catch (RuntimeException) {
        }

        try {
            $set->refresh()->delete();
            $this->fail('an active set accepted deletion');
        } catch (RuntimeException) {
        }

        // Retired content is read-only too.
        app(ValuationRulePublisher::class)->retire($set->refresh());
        $set->refresh();

        try {
            $set->name = 'Renamed after retirement';
            $set->save();
            $this->fail('a retired set accepted an edit');
        } catch (RuntimeException) {
        }

        // The stored truth never moved.
        $this->assertSame('5.000', (string) ValuationQuestionOption::query()->find($plus->id)?->adjustment_percent);
    }

    /* ------------------------------------------------------------------ */
    /* staleness, rehydration, server authority over answers               */
    /* ------------------------------------------------------------------ */

    public function test_a_superseded_answer_is_excluded_and_surfaced_never_applied(): void
    {
        $this->evidence();
        [$v1, $question, $plus] = $this->activeSet();

        $property = $this->property();
        $this->answer($property, $question, $plus);

        // Supersede v1 with v2 (fresh rows, fresh ids).
        app(ValuationRulePublisher::class)->duplicateAsDraft($v1);
        $v2 = ValuationRuleSet::query()->orderByDesc('id')->firstOrFail();
        app(ValuationRulePublisher::class)->publish($v2);

        // The old answer points at v1's question: excluded AND reported.
        $plan = app(ValuationAdjustments::class)->planFor($property);
        $this->assertSame([], $plan['applied']);
        $this->assertCount(1, $plan['stale']);
        $this->assertSame('question_not_in_active_set', $plan['stale'][0]['reason']);

        // And the valuation itself is pure baseline.
        $this->assertBaselineRow($this->value($property));

        // The stored answer row is preserved — excluded is not deleted.
        $this->assertSame(1, PortfolioPropertyAnswer::query()->where('portfolio_property_id', $property->id)->count());
    }

    public function test_the_form_props_rehydrate_ids_and_never_leak_percentages(): void
    {
        $this->evidence();
        [, $question, $plus] = $this->activeSet();

        $owner = $this->member();
        $property = $this->property([], $owner);
        $this->answer($property, $question, $plus);

        $response = $this->actingAs($owner)->get('/account/portfolio/'.$property->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('valuation_rules.questions.0.key', 'renovation')
            ->where('valuation_rules.questions.0.options.0.key', 'renovated')
            // Rehydration: the persisted option id keyed by question id.
            ->where('valuation_rules.answers.'.$question->id, $plus->id)
            ->where('valuation_rules.stale', 0)
            // Server authority made visible: no percentage anywhere in the
            // owner payload — not on options, not on questions.
            ->missing('valuation_rules.questions.0.options.0.adjustment_percent')
            ->missing('valuation_rules.questions.0.adjustment_percent'));
    }

    public function test_submitted_answers_beat_persisted_and_are_persisted_themselves(): void
    {
        $this->evidence();
        [, $question, $plus, $minus] = $this->activeSet();

        $owner = $this->member();
        $property = $this->property([], $owner);
        $this->answer($property, $question, $plus);

        $this->actingAs($owner)
            ->post('/account/portfolio/'.$property->id.'/valuation', [
                'answers' => [$question->id => $minus->id],
            ])
            ->assertRedirect();

        // The valuation used the SUBMITTED option (-3.500), and the stored
        // answer row now says the same thing.
        $valuation = PortfolioValuation::query()
            ->where('portfolio_property_id', $property->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('-3.500', (string) $valuation->adjustment_total_percent);
        $this->assertSame('106150.0000', (string) $valuation->midpoint);

        $stored = PortfolioPropertyAnswer::query()
            ->where('portfolio_property_id', $property->id)
            ->where('valuation_question_id', $question->id)
            ->firstOrFail();
        $this->assertSame($minus->id, $stored->valuation_question_option_id);
    }

    public function test_tampered_answers_are_refused_atomically_with_nothing_persisted(): void
    {
        $this->evidence();
        [, $question] = $this->activeSet();

        // A second, unrelated active family to steal an option id from.
        $project = $this->project('vr-cross');
        $foreignSet = $this->draftSet(['project_id' => $project->id]);
        $foreignQ = $this->question($foreignSet, 'foreign');
        $foreignOption = $this->option($foreignQ, 'foreign_opt', '10.000');
        app(ValuationRulePublisher::class)->publish($foreignSet);

        $owner = $this->member();
        $property = $this->property([], $owner);

        $payload = [
            'label' => 'Tampered attempt',
            'property_type' => 'apartment',
            'currency' => 'USD',
            'consent_valuation' => true,
        ];

        // (a) an option that belongs to another set's question;
        $this->actingAs($owner)
            ->putJson('/account/portfolio/'.$property->id, $payload + [
                'answers' => [$question->id => $foreignOption->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answers']);

        // (b) a question that is not part of the applicable active set;
        $this->actingAs($owner)
            ->putJson('/account/portfolio/'.$property->id, $payload + [
                'answers' => [$foreignQ->id => $foreignOption->id],
            ])
            ->assertStatus(422);

        // (c) an option id that does not exist at all.
        $this->actingAs($owner)
            ->putJson('/account/portfolio/'.$property->id, $payload + [
                'answers' => [$question->id => 999999],
            ])
            ->assertStatus(422);

        // NOTHING persisted by any refusal: no answers, and the property's
        // label never became "Tampered attempt".
        $this->assertSame(0, PortfolioPropertyAnswer::query()->where('portfolio_property_id', $property->id)->count());
        $this->assertSame('Rules fixture', $property->refresh()->label());

        // On CREATE, a refused answer set refuses the property row itself.
        $before = PortfolioProperty::query()->count();
        $this->actingAs($owner)
            ->postJson('/account/portfolio', $payload + [
                'answers' => [$question->id => $foreignOption->id],
            ])
            ->assertStatus(422);
        $this->assertSame($before, PortfolioProperty::query()->count());
    }

    public function test_client_supplied_percentages_have_no_field_to_arrive_in(): void
    {
        $this->evidence();
        [, $question, $plus] = $this->activeSet();

        $owner = $this->member();
        $property = $this->property([], $owner);

        // A hostile payload claiming percentages everywhere it can think of.
        $this->actingAs($owner)
            ->post('/account/portfolio/'.$property->id.'/valuation', [
                'answers' => [$question->id => $plus->id],
                'adjustment_percent' => '99.000',
                'adjustment_total_percent' => '99.000',
                'answers_percent' => ['99.000'],
            ])
            ->assertRedirect();

        // The DB row carries the OPTION's percent, read server-side.
        $valuation = PortfolioValuation::query()
            ->where('portfolio_property_id', $property->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('5.000', (string) $valuation->adjustment_total_percent);
        $this->assertSame('115500.0000', (string) $valuation->midpoint);
    }

    public function test_clearing_an_answer_removes_it_and_returns_to_baseline(): void
    {
        $this->evidence();
        [, $question, $plus] = $this->activeSet();

        $owner = $this->member();
        $property = $this->property([], $owner);
        $this->answer($property, $question, $plus);

        $this->actingAs($owner)
            ->put('/account/portfolio/'.$property->id, [
                'label' => 'Cleared',
                'property_type' => 'apartment',
                'currency' => 'USD',
                'consent_valuation' => true,
                'answers' => [$question->id => null],
            ])
            ->assertRedirect();

        $this->assertSame(0, PortfolioPropertyAnswer::query()->where('portfolio_property_id', $property->id)->count());
        $this->assertBaselineRow($this->value($property->refresh()));
    }

    /* ------------------------------------------------------------------ */
    /* history — snapshots, append-only, survival                          */
    /* ------------------------------------------------------------------ */

    public function test_snapshots_are_append_only(): void
    {
        $this->evidence();
        [, $question, $plus] = $this->activeSet();

        $property = $this->property();
        $this->answer($property, $question, $plus);
        $valuation = $this->value($property);

        $snapshot = $valuation->adjustments()->firstOrFail();

        $this->expectException(RuntimeException::class);
        $snapshot->adjustment_percent = '20.000';
        $snapshot->save();
    }

    public function test_history_survives_rule_retirement_and_deletion_unchanged(): void
    {
        $this->evidence();
        [$set, $question, $plus] = $this->activeSet();

        $property = $this->property();
        $this->answer($property, $question, $plus);
        $valuation = $this->value($property);

        $rowBefore = $valuation->refresh()->getAttributes();
        $snapshotBefore = $valuation->adjustments()->firstOrFail()->getAttributes();

        // Retire the set, then DELETE it outright — questions and options
        // cascade away, and the owner's answer rows cascade with them.
        app(ValuationRulePublisher::class)->retire($set->refresh());
        $set->refresh()->delete();

        $this->assertNull(ValuationQuestion::query()->find($question->id));
        $this->assertSame(0, PortfolioPropertyAnswer::query()->count());

        // The valuation and its snapshot are byte-identical: history never
        // depended on the live rules existing.
        $this->assertSame($rowBefore, $valuation->refresh()->getAttributes());
        $this->assertSame($snapshotBefore, $valuation->adjustments()->firstOrFail()->getAttributes());

        // A NEW valuation after the deletion is simply baseline again.
        $this->assertBaselineRow($this->value($property->refresh()));
    }

    public function test_old_rows_keep_their_totals_after_a_new_version_applies(): void
    {
        $this->evidence();
        [$v1, $question, $plus] = $this->activeSet();

        $property = $this->property();
        $this->answer($property, $question, $plus);
        $first = $this->value($property);
        $this->assertSame('5.000', (string) $first->adjustment_total_percent);

        // Version 2 doubles the percent; the owner re-answers under it.
        app(ValuationRulePublisher::class)->duplicateAsDraft($v1);
        $v2 = ValuationRuleSet::query()->orderByDesc('id')->firstOrFail();
        /** @var ValuationQuestion $newQuestion */
        $newQuestion = $v2->questions()->where('key', 'renovation')->firstOrFail();
        /** @var ValuationQuestionOption $newOption */
        $newOption = $newQuestion->options()->where('key', 'renovated')->firstOrFail();
        $newOption->adjustment_percent = '10.000';
        $newOption->save();
        app(ValuationRulePublisher::class)->publish($v2);

        $this->answer($property, $newQuestion, $newOption);
        $second = $this->value($property->refresh());

        $this->assertSame('10.000', (string) $second->adjustment_total_percent);
        $this->assertSame('121000.0000', (string) $second->midpoint);

        // The first row still says 5.000 / 115500 — never reinterpreted.
        $this->assertSame('5.000', (string) $first->refresh()->adjustment_total_percent);
        $this->assertSame('115500.0000', (string) $first->midpoint);
        $this->assertSame('5.000', (string) $first->adjustments()->firstOrFail()->adjustment_percent);
    }

    public function test_property_deletion_cascades_answers_valuations_and_snapshots(): void
    {
        $this->evidence();
        [, $question, $plus] = $this->activeSet();

        $owner = $this->member();
        $property = $this->property([], $owner);
        $this->answer($property, $question, $plus);
        $this->value($property);

        $this->assertSame(1, PortfolioValuationAdjustment::query()->count());

        $this->actingAs($owner)
            ->delete('/account/portfolio/'.$property->id)
            ->assertRedirect();

        $this->assertSame(0, PortfolioProperty::query()->count());
        $this->assertSame(0, PortfolioPropertyAnswer::query()->count());
        $this->assertSame(0, PortfolioValuation::query()->count());
        $this->assertSame(0, PortfolioValuationAdjustment::query()->count());
    }

    public function test_the_portfolio_summary_uses_the_final_adjusted_midpoint(): void
    {
        $this->evidence();
        [, $question, $plus] = $this->activeSet();

        $owner = $this->member();
        $property = $this->property([], $owner);
        $this->answer($property, $question, $plus);
        $this->value($property);

        $summary = app(PortfolioSummaryService::class)->summarise($owner->id);

        // The Wave 5 band totals the FINAL midpoint — what the owner sees on
        // the card is what the band sums.
        $this->assertSame('115500.0000', $summary['totals'][0]['total']);
        $this->assertSame('USD', $summary['totals'][0]['currency']);
    }
}

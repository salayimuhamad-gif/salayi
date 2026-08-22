<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Models\PortfolioPropertyAnswer;
use App\Modules\Portfolio\Models\PortfolioValuation;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use App\Modules\Portfolio\Services\ValuationRulePublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * PHASE 3 REVIEW PROBES — the owner request's atomicity, plus the consent
 * and flag-off contract pins the review found untested.
 *
 * Probes 6a/6b are in INVARIANT form: each asserts the request-level
 * all-or-nothing contract. On the pre-fix code — where the property save
 * committed before the answer writes ran in their own transaction — both
 * FAILED (run #262: an orphaned property row on store, a torn
 * new-fields/old-answers state on update); under the single outer
 * request transaction they are the permanent regression suite. The
 * failure is injected deterministically with a DB::listen hook that
 * throws on the first statement touching portfolio_property_answers —
 * after the property write, exactly where a real database error (or the
 * concurrent updateOrCreate unique violation) lands. No production code
 * is modified to create the failure.
 *
 *   6a  STORE: a failed answer write must leave NO property row behind —
 *       a property that exists without the answers submitted with it is
 *       a half-applied request.
 *   6b  UPDATE: a failed answer write must leave the property's fields
 *       UNTOUCHED — original label, original scope, original answers.
 *
 * The success path and the real database-exception path are pinned too:
 * a field change plus several answer changes commit together normally,
 * and a genuine unique violation raised by a concurrent answer row rolls
 * the WHOLE request back — property changes included.
 *
 * The remaining tests are permanent contract pins, expected to PASS:
 * consent refusal happens before any answer persistence and any
 * valuation row; withdrawing consent keeps the answers and blocks the
 * calculation; another account's property 404s before consent logic can
 * even run; and with the flag OFF a syntactically plausible answers
 * payload persists nothing while the update behaves exactly as legacy
 * and the form props expose no rule surface.
 */
final class ValuationOwnerSurfaceProbeTest extends TestCase
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
            'slug' => 'op-erbil',
            'name_ckb' => 'هەولێر',
            'publication_status' => 'published',
        ]);

        $this->district = Area::query()->create([
            'type' => 'district',
            'slug' => 'op-kasnazan',
            'name_ckb' => 'کەسنەزان',
            'parent_id' => $this->city->id,
            'publication_status' => 'published',
        ]);
    }

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

    /**
     * One published set with two questions, through the real publisher:
     * the first carries two options (+5.000/-3.500), the second one
     * option (+2.000) — enough for a multi-change submission.
     *
     * @return array{0: ValuationRuleSet, 1: ValuationQuestion, 2: ValuationQuestionOption, 3: ValuationQuestionOption, 4: ValuationQuestion, 5: ValuationQuestionOption}
     */
    private function activeRules(): array
    {
        $set = ValuationRuleSet::query()->create([
            'name' => 'Owner surface rules',
            'scope_transaction' => ValuationRuleSet::SCOPE_TRANSACTION_SALE,
            'project_id' => null,
            'version' => 1,
            'status' => ValuationRuleSet::STATUS_DRAFT,
        ]);

        $question = $this->question($set, 'op_q');
        $plus = $this->option($question, 'op_plus', '5.000');
        $minus = $this->option($question, 'op_minus', '-3.500');

        $second = $this->question($set, 'op_q2');
        $secondOption = $this->option($second, 'op_q2_a', '2.000');

        $publisher = app(ValuationRulePublisher::class);
        $publisher->publish($set);
        $set->refresh();

        return [$set, $question, $plus, $minus, $second, $secondOption];
    }

    private function question(ValuationRuleSet $set, string $key): ValuationQuestion
    {
        return ValuationQuestion::query()->create([
            'valuation_rule_set_id' => $set->id,
            'key' => $key,
            'label_ckb' => 'پرسیار '.$key,
            'label_ar' => 'سؤال '.$key,
            'label_en' => 'Question '.$key,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    private function option(ValuationQuestion $question, string $key, string $percent): ValuationQuestionOption
    {
        return ValuationQuestionOption::query()->create([
            'valuation_question_id' => $question->id,
            'key' => $key,
            'label_ckb' => 'هەڵبژاردە '.$key,
            'label_ar' => 'خيار '.$key,
            'label_en' => 'Option '.$key,
            'adjustment_percent' => $percent,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    private function answer(PortfolioProperty $property, ValuationQuestion $question, ValuationQuestionOption $option): void
    {
        PortfolioPropertyAnswer::query()->create([
            'portfolio_property_id' => $property->id,
            'valuation_question_id' => $question->id,
            'valuation_question_option_id' => $option->id,
        ]);
    }

    /**
     * The deterministic failure: the FIRST statement that touches the
     * answers table throws — after the property write, inside the answer
     * transaction, exactly where a real database error or the concurrent
     * updateOrCreate unique violation would surface.
     */
    private function failOnAnswerWrite(bool &$armed): void
    {
        DB::listen(static function (QueryExecuted $event) use (&$armed): void {
            if (! $armed) {
                return;
            }

            if (! str_contains(strtolower($event->sql), 'portfolio_property_answers')) {
                return;
            }

            $armed = false;

            throw new RuntimeException('answer-write failure injected');
        });
    }

    public function test_probe_6a_a_failed_answer_write_leaves_no_property_row_behind(): void
    {
        [, $question, $plus] = $this->activeRules();
        $owner = $this->member();

        $armed = true;
        $this->failOnAnswerWrite($armed);

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($owner)->post('/account/portfolio', [
                'label' => 'Atomicity probe',
                'property_type' => 'apartment',
                'currency' => 'USD',
                'consent_valuation' => true,
                'answers' => [$question->id => $plus->id],
            ]);
            $this->fail('the injected answer-write failure never fired');
        } catch (RuntimeException $e) {
            $this->assertSame('answer-write failure injected', $e->getMessage());
        }

        $this->assertFalse($armed, 'probe harness: no statement ever touched the answers table');

        // The request-level invariant: all or nothing. A property that
        // exists without the answers submitted with it is a half-applied
        // request the owner never made.
        $this->assertSame(
            0,
            PortfolioProperty::query()->where('user_id', $owner->id)->count(),
            'a failed answer write left an orphaned property row — the property insert and the answer writes are not one transaction',
        );

        $this->assertSame(
            0,
            PortfolioPropertyAnswer::query()->count(),
            'a failed answer transaction still persisted answer rows',
        );
    }

    public function test_probe_6b_a_failed_answer_write_leaves_the_property_untouched(): void
    {
        [, $question, $plus, $minus] = $this->activeRules();
        $owner = $this->member();
        $property = $this->property([], $owner);
        $this->answer($property, $question, $plus);

        $armed = true;
        $this->failOnAnswerWrite($armed);

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($owner)->put('/account/portfolio/'.$property->id, [
                'label' => 'Torn update',
                'property_type' => 'apartment',
                'area_id' => $this->district->id,
                'currency' => 'USD',
                'consent_valuation' => true,
                'answers' => [$question->id => $minus->id],
            ]);
            $this->fail('the injected answer-write failure never fired');
        } catch (RuntimeException $e) {
            $this->assertSame('answer-write failure injected', $e->getMessage());
        }

        $this->assertFalse($armed, 'probe harness: no statement ever touched the answers table');

        // The request-level invariant: the property's fields must remain
        // ORIGINAL when the same request's answer writes failed.
        $this->assertSame(
            'Rules fixture',
            $property->refresh()->label(),
            'a failed answer write left the property half-updated — the save and the answer transaction are separate units',
        );

        // The original answer must also be intact (the transaction rolled
        // the replacement back).
        $stored = PortfolioPropertyAnswer::query()
            ->where('portfolio_property_id', $property->id)
            ->where('valuation_question_id', $question->id)
            ->firstOrFail();

        $this->assertSame($plus->id, $stored->valuation_question_option_id);
    }

    public function test_a_field_change_and_several_answer_changes_commit_together(): void
    {
        [, $question, $plus, $minus, $second, $secondOption] = $this->activeRules();
        $owner = $this->member();
        $property = $this->property([], $owner);
        $this->answer($property, $question, $plus);
        $this->answer($property, $second, $secondOption);

        // The success path of the same unit of work: one request changes a
        // field, REPLACES one answer and CLEARS another — and all of it
        // lands together.
        $this->actingAs($owner)
            ->put('/account/portfolio/'.$property->id, [
                'label' => 'Committed together',
                'property_type' => 'apartment',
                'area_id' => $this->district->id,
                'currency' => 'USD',
                'consent_valuation' => true,
                'answers' => [
                    $question->id => $minus->id,
                    $second->id => null,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Committed together', $property->refresh()->label());

        $stored = PortfolioPropertyAnswer::query()
            ->where('portfolio_property_id', $property->id)
            ->where('valuation_question_id', $question->id)
            ->firstOrFail();
        $this->assertSame($minus->id, $stored->valuation_question_option_id);

        $this->assertSame(
            1,
            PortfolioPropertyAnswer::query()->where('portfolio_property_id', $property->id)->count(),
            'the cleared answer must be gone and the replaced one present — exactly one row remains',
        );
    }

    public function test_a_concurrent_unique_violation_rolls_the_whole_request_back(): void
    {
        [, $question, $plus, $minus] = $this->activeRules();
        $owner = $this->member();
        $property = $this->property([], $owner);
        $propertyId = $property->id;
        $questionId = $question->id;
        $minusId = $minus->id;

        /*
         * The REAL database exception, not a simulated one: between
         * updateOrCreate's miss and its INSERT, a concurrent request's row
         * for the same (property, question) lands — reproduced by
         * inserting it from the query hook — so the INSERT dies on
         * ppa_property_question_unique. Safety invariant only: the OUTER
         * transaction must roll the property changes back completely. The
         * friendly 422 mapping for this rare race stays a separate issue.
         */
        $armed = true;
        DB::listen(static function (QueryExecuted $event) use (&$armed, $propertyId, $questionId, $minusId): void {
            if (! $armed) {
                return;
            }

            $sql = strtolower($event->sql);

            if (! str_starts_with(ltrim($sql), 'select') || ! str_contains($sql, 'portfolio_property_answers')) {
                return;
            }

            $armed = false;

            DB::table('portfolio_property_answers')->insert([
                'portfolio_property_id' => $propertyId,
                'valuation_question_id' => $questionId,
                'valuation_question_option_id' => $minusId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($owner)->put('/account/portfolio/'.$property->id, [
                'label' => 'Raced update',
                'property_type' => 'apartment',
                'area_id' => $this->district->id,
                'currency' => 'USD',
                'consent_valuation' => true,
                'answers' => [$question->id => $plus->id],
            ]);
            $this->fail('the unique violation never fired');
        } catch (QueryException) {
            // The database refused the duplicate — expected.
        }

        $this->assertFalse($armed, 'probe harness: the answer lookup was never observed');

        $this->assertSame(
            'Rules fixture',
            $property->refresh()->label(),
            'a unique-violation during the answer writes left the property half-updated',
        );

        $this->assertSame(
            0,
            PortfolioPropertyAnswer::query()->where('portfolio_property_id', $property->id)->count(),
            'the rolled-back request left answer rows behind',
        );
    }

    /* ---------------------------------------------------------------------
     * consent — the pins the review found missing
     * ------------------------------------------------------------------- */

    public function test_without_consent_a_valuation_request_is_refused_and_persists_nothing(): void
    {
        [, $question, $plus] = $this->activeRules();
        $owner = $this->member();
        $property = $this->property(['consent_valuation' => false], $owner);

        $this->actingAs($owner)
            ->post('/account/portfolio/'.$property->id.'/valuation', [
                'answers' => [$question->id => $plus->id],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('valuation');

        // Refused BEFORE anything: no valuation row, and the answers that
        // rode along with the refused request were never persisted.
        $this->assertSame(0, PortfolioValuation::query()->count());
        $this->assertSame(0, PortfolioPropertyAnswer::query()->count());
    }

    public function test_withdrawing_consent_keeps_answers_and_blocks_future_valuations(): void
    {
        [, $question, $plus] = $this->activeRules();
        $owner = $this->member();
        $property = $this->property([], $owner);
        $this->answer($property, $question, $plus);

        // The owner withdraws consent through an ordinary edit.
        $this->actingAs($owner)
            ->put('/account/portfolio/'.$property->id, [
                'label' => 'Withdrawn consent',
                'property_type' => 'apartment',
                'area_id' => $this->district->id,
                'currency' => 'USD',
                'consent_valuation' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($property->refresh()->consent_valuation);

        // Designed semantics: withdrawal deletes nothing — the answers
        // stay exactly as saved — it only blocks future calculations.
        $stored = PortfolioPropertyAnswer::query()
            ->where('portfolio_property_id', $property->id)
            ->where('valuation_question_id', $question->id)
            ->firstOrFail();
        $this->assertSame($plus->id, $stored->valuation_question_option_id);

        $this->actingAs($owner)
            ->post('/account/portfolio/'.$property->id.'/valuation')
            ->assertRedirect()
            ->assertSessionHasErrors('valuation');

        $this->assertSame(0, PortfolioValuation::query()->count());
    }

    public function test_another_accounts_property_is_404_before_consent_logic(): void
    {
        $ownerA = $this->member();
        $property = $this->property(['consent_valuation' => false], $ownerA);

        $intruder = $this->member();

        // Ownership resolves first: the intruder learns nothing about the
        // property — not even that consent would have refused.
        $this->actingAs($intruder)
            ->post('/account/portfolio/'.$property->id.'/valuation')
            ->assertNotFound();

        $this->assertSame(0, PortfolioValuation::query()->count());
    }

    /* ---------------------------------------------------------------------
     * feature flag OFF — the HTTP pin the review found missing
     * ------------------------------------------------------------------- */

    public function test_flag_off_ignores_a_plausible_answers_payload_and_exposes_no_rule_surface(): void
    {
        [, $question, $plus] = $this->activeRules();

        $this->setFeatures(['portfolio' => true, 'portfolio.valuation_rules' => false]);

        $owner = $this->member();
        $property = $this->property([], $owner);

        // A syntactically plausible payload with REAL ids: the legacy
        // update must behave exactly as before the rule engine existed —
        // the field changes land, the stray payload persists nothing.
        $this->actingAs($owner)
            ->put('/account/portfolio/'.$property->id, [
                'label' => 'Legacy update',
                'property_type' => 'apartment',
                'area_id' => $this->district->id,
                'currency' => 'USD',
                'consent_valuation' => true,
                'answers' => [$question->id => $plus->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Legacy update', $property->refresh()->label());
        $this->assertSame(0, PortfolioPropertyAnswer::query()->count());

        // And the page offers no rule surface to submit against.
        $this->actingAs($owner)
            ->get('/account/portfolio/'.$property->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('valuation_rules', null));
    }
}

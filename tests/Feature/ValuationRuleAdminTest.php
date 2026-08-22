<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Models\AuditLog;
use App\Modules\Portfolio\Models\PortfolioPropertyAnswer;
use App\Modules\Portfolio\Models\PortfolioValuation;
use App\Modules\Portfolio\Models\ValuationQuestion;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The Wave 6 admin builder over HTTP: who may reach it, what a draft
 * accepts, what publishing enforces, what frozen means at the boundary,
 * and that preview computes without persisting a single row.
 */
final class ValuationRuleAdminTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/portfolio/valuation-rules';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->setFeatures(['portfolio.valuation_rules' => true]);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $this->attachRole($user, RoleKey::MarketDataManager);

        return $user;
    }

    private function auditor(): User
    {
        $user = User::factory()->create();
        $this->attachRole($user, RoleKey::SecurityAuditor);

        return $user;
    }

    private function attachRole(User $user, RoleKey $key): void
    {
        $role = Role::query()->firstOrCreate(
            ['key' => $key->value],
            ['name' => $key->value, 'is_system' => true],
        );

        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    /** @param array<model-property<ValuationRuleSet>, mixed> $overrides */
    private function draftSet(array $overrides = []): ValuationRuleSet
    {
        return ValuationRuleSet::query()->create($overrides + [
            'name' => 'Admin draft',
            'scope_transaction' => ValuationRuleSet::SCOPE_TRANSACTION_SALE,
            'version' => (int) ValuationRuleSet::query()->max('version') + 1,
            'status' => ValuationRuleSet::STATUS_DRAFT,
        ]);
    }

    /** A draft with one active question ('renovation') and one option (+5.000). */
    private function publishableDraft(): ValuationRuleSet
    {
        $set = $this->draftSet();

        $question = ValuationQuestion::query()->create([
            'valuation_rule_set_id' => $set->id,
            'key' => 'renovation',
            'label_ckb' => 'نۆژەنکردنەوە',
            'label_ar' => 'التجديد',
            'label_en' => 'Renovation',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        ValuationQuestionOption::query()->create([
            'valuation_question_id' => $question->id,
            'key' => 'renovated',
            'label_ckb' => 'نۆژەنکراوە',
            'label_ar' => 'مجدد',
            'label_en' => 'Renovated',
            'adjustment_percent' => '5.000',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        return $set;
    }

    /* ---------------------------------------------------------------------
     * access control
     * ------------------------------------------------------------------- */

    public function test_the_flag_gates_the_surface_for_ordinary_administrators(): void
    {
        $this->setFeatures(['portfolio.valuation_rules' => false]);

        // The admin surface says what happened (403), and does not pretend
        // the section does not exist to someone holding the permission.
        $this->actingAs($this->manager())->get(self::BASE)->assertForbidden();
    }

    public function test_view_and_configure_are_separate_doors(): void
    {
        $set = $this->publishableDraft();

        // No admin role at all: the permission middleware refuses.
        $this->actingAs(User::factory()->create())->get(self::BASE)->assertForbidden();

        // A Security Auditor reads everything and changes nothing.
        $auditor = $this->auditor();
        $this->actingAs($auditor)->get(self::BASE)->assertOk();
        $this->actingAs($auditor)->get(self::BASE.'/'.$set->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ValuationRules/Edit')
                ->where('can.manage', false));
        $this->actingAs($auditor)
            ->post(self::BASE, ['name' => 'Auditor attempt'])
            ->assertForbidden();
        $this->actingAs($auditor)
            ->post(self::BASE.'/'.$set->id.'/publish')
            ->assertForbidden();

        // The Market Data Manager holds the whole lifecycle.
        $this->actingAs($this->manager())->get(self::BASE.'/'.$set->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.manage', true));
    }

    public function test_the_builder_still_shows_the_exact_percentages(): void
    {
        $set = $this->publishableDraft();

        /*
         * Product decision (Phase 3): the OWNER surface derives a
         * direction and never receives the configured weight; the ADMIN
         * builder is the authoring surface and keeps the exact value.
         * This pin fails if a payload trim ever reaches the wrong side.
         */
        $this->actingAs($this->manager())->get(self::BASE.'/'.$set->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('set.questions.0.options.0.adjustment_percent', '5.000'));
    }

    /* ---------------------------------------------------------------------
     * draft authoring
     * ------------------------------------------------------------------- */

    public function test_a_draft_is_created_with_a_computed_version_and_audited(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(self::BASE, [
                'name' => 'Erbil apartments',
                'property_types' => ['apartment'],
            ])
            ->assertRedirect();

        $set = ValuationRuleSet::query()->firstOrFail();
        $this->assertSame('Erbil apartments', $set->name);
        $this->assertSame(ValuationRuleSet::STATUS_DRAFT, $set->status);
        $this->assertSame(1, $set->version);
        $this->assertSame(['apartment'], $set->property_types);
        $this->assertSame('sale', $set->scope_transaction);
        $this->assertSame($manager->id, $set->created_by);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'portfolio.valuation_rules.set_created')->count());
    }

    public function test_question_and_option_authoring_enforce_keys_and_the_bound(): void
    {
        $manager = $this->manager();
        $set = $this->draftSet();

        $question = [
            'key' => 'renovation',
            'label_ckb' => 'نۆژەنکردنەوە',
            'label_ar' => 'التجديد',
            'label_en' => 'Renovation',
        ];

        $this->actingAs($manager)
            ->post(self::BASE.'/'.$set->id.'/questions', $question)
            ->assertRedirect();

        $stored = ValuationQuestion::query()->firstOrFail();
        $this->assertSame('single_select', $stored->question_type);

        // A duplicate key within the set refuses; a bad key shape refuses.
        $this->actingAs($manager)
            ->postJson(self::BASE.'/'.$set->id.'/questions', $question)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['key']);
        $this->actingAs($manager)
            ->postJson(self::BASE.'/'.$set->id.'/questions', ['key' => 'Bad Key!'] + $question)
            ->assertStatus(422);

        // Trilingual labels are all required.
        $this->actingAs($manager)
            ->postJson(self::BASE.'/'.$set->id.'/questions', [
                'key' => 'view', 'label_ckb' => 'دیمەن', 'label_en' => 'View',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label_ar']);

        $option = [
            'key' => 'renovated',
            'label_ckb' => 'نۆژەنکراوە',
            'label_ar' => 'مجدد',
            'label_en' => 'Renovated',
            'adjustment_percent' => '5.000',
        ];

        $this->actingAs($manager)
            ->post(self::BASE.'/'.$set->id.'/questions/'.$stored->id.'/options', $option)
            ->assertRedirect();

        // The ±25 authoring bound and the stored precision are enforced.
        foreach (['25.001', '-25.001', '1.2345'] as $bad) {
            $this->actingAs($manager)
                ->postJson(self::BASE.'/'.$set->id.'/questions/'.$stored->id.'/options', [
                    'key' => 'bad_'.str_replace(['.', '-'], '_', $bad),
                    'adjustment_percent' => $bad,
                ] + $option)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['adjustment_percent']);
        }

        // The exact bound itself is legal.
        $this->actingAs($manager)
            ->post(self::BASE.'/'.$set->id.'/questions/'.$stored->id.'/options', [
                'key' => 'at_bound', 'adjustment_percent' => '-25.000',
            ] + $option)
            ->assertRedirect();

        $this->assertSame(2, ValuationQuestionOption::query()->count());
    }

    /* ---------------------------------------------------------------------
     * lifecycle over HTTP
     * ------------------------------------------------------------------- */

    public function test_publish_activates_supersedes_and_refuses_empty_drafts(): void
    {
        $manager = $this->manager();

        // An empty draft refuses with a translated lifecycle error.
        $empty = $this->draftSet(['name' => 'Empty']);
        $this->actingAs($manager)
            ->from(self::BASE.'/'.$empty->id)
            ->post(self::BASE.'/'.$empty->id.'/publish')
            ->assertRedirect(self::BASE.'/'.$empty->id)
            ->assertSessionHasErrors(['lifecycle']);
        $this->assertSame(ValuationRuleSet::STATUS_DRAFT, $empty->refresh()->status);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'portfolio.valuation_rules.set_publish_refused')->count());

        // A publishable draft goes active, with published_at stamped.
        $v1 = $this->publishableDraft();
        $this->actingAs($manager)
            ->post(self::BASE.'/'.$v1->id.'/publish')
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(ValuationRuleSet::STATUS_ACTIVE, $v1->refresh()->status);
        $this->assertNotNull($v1->published_at);

        // Duplicate → v+1 draft with copied content and fresh ids.
        $this->actingAs($manager)
            ->post(self::BASE.'/'.$v1->id.'/duplicate')
            ->assertRedirect();

        $v2 = ValuationRuleSet::query()
            ->where('status', ValuationRuleSet::STATUS_DRAFT)
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertSame($v1->version + 1, $v2->version);
        $this->assertSame(1, $v2->questions()->count());
        /** @var ValuationQuestion $copied */
        $copied = $v2->questions()->firstOrFail();
        $this->assertSame('renovation', $copied->key);
        $this->assertNotSame(
            ValuationQuestion::query()->where('valuation_rule_set_id', $v1->id)->firstOrFail()->id,
            $copied->id,
        );

        // Publishing v2 retires v1 in the same breath.
        $this->actingAs($manager)
            ->post(self::BASE.'/'.$v2->id.'/publish')
            ->assertRedirect();
        $this->assertSame(ValuationRuleSet::STATUS_RETIRED, $v1->refresh()->status);
        $this->assertSame(ValuationRuleSet::STATUS_ACTIVE, $v2->refresh()->status);
    }

    public function test_frozen_content_refuses_cleanly_at_the_boundary(): void
    {
        $manager = $this->manager();
        $set = $this->publishableDraft();
        $this->actingAs($manager)->post(self::BASE.'/'.$set->id.'/publish')->assertRedirect();

        /** @var ValuationQuestion $question */
        $question = $set->questions()->firstOrFail();

        // Scope edit, question edit, question delete, option add: all refuse
        // with the lifecycle error, none with a 500.
        $this->actingAs($manager)
            ->put(self::BASE.'/'.$set->id, ['name' => 'Renamed'])
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);

        $this->actingAs($manager)
            ->put(self::BASE.'/'.$set->id.'/questions/'.$question->id, [
                'key' => 'renovation',
                'label_ckb' => 'گۆڕدرا', 'label_ar' => 'معدل', 'label_en' => 'Changed',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);

        $this->actingAs($manager)
            ->delete(self::BASE.'/'.$set->id.'/questions/'.$question->id)
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);

        // Deleting the ACTIVE set refuses; retiring then deleting succeeds,
        // because history holds its own copies.
        $this->actingAs($manager)
            ->delete(self::BASE.'/'.$set->id)
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);
        $this->assertSame(ValuationRuleSet::STATUS_ACTIVE, $set->refresh()->status);

        $this->actingAs($manager)
            ->post(self::BASE.'/'.$set->id.'/retire')
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(ValuationRuleSet::STATUS_RETIRED, $set->refresh()->status);
        $this->assertNotNull($set->retired_at);

        $this->actingAs($manager)
            ->delete(self::BASE.'/'.$set->id)
            ->assertRedirect(self::BASE);
        $this->assertNull(ValuationRuleSet::query()->find($set->id));
    }

    /* ---------------------------------------------------------------------
     * the authoring surface shows percents; preview persists nothing
     * ------------------------------------------------------------------- */

    public function test_the_builder_page_shows_percentages_to_the_authoring_role(): void
    {
        $set = $this->publishableDraft();

        $this->actingAs($this->manager())
            ->get(self::BASE.'/'.$set->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ValuationRules/Edit')
                ->where('set.questions.0.key', 'renovation')
                ->where('set.questions.0.options.0.adjustment_percent', '5.000')
                ->where('max_percent', '25.000')
                ->where('warning_threshold', '30.000'));
    }

    public function test_preview_runs_the_real_arithmetic_and_persists_nothing(): void
    {
        $manager = $this->manager();
        $set = $this->publishableDraft();

        /** @var ValuationQuestion $question */
        $question = $set->questions()->firstOrFail();
        /** @var ValuationQuestionOption $option */
        $option = $question->options()->firstOrFail();

        $this->actingAs($manager)
            ->getJson(self::BASE.'/'.$set->id.'/preview?base=100000&answers['.$question->id.']='.$option->id)
            ->assertOk()
            ->assertJson([
                'base' => '100000.0000',
                'total_percent' => '5.000',
                'factor' => '1.050000',
                'refused' => false,
                'final' => '105000.0000',
                'warned' => false,
            ]);

        // A hostile option id refuses instead of guessing.
        $this->actingAs($manager)
            ->getJson(self::BASE.'/'.$set->id.'/preview?base=100000&answers['.$question->id.']=999999')
            ->assertStatus(422);

        // Preview persisted NOTHING: no valuation, no answers, no audit of a
        // mutation that never happened.
        $this->assertSame(0, PortfolioValuation::query()->count());
        $this->assertSame(0, PortfolioPropertyAnswer::query()->count());
    }

    /* ---------------------------------------------------------------------
     * Phase 4 hardening pins
     * ------------------------------------------------------------------- */

    public function test_child_routes_refuse_cross_set_and_cross_question_ids(): void
    {
        $manager = $this->manager();

        $setA = $this->publishableDraft();
        /** @var ValuationQuestion $qA */
        $qA = $setA->questions()->firstOrFail();
        /** @var ValuationQuestionOption $oA */
        $oA = $qA->options()->firstOrFail();

        // A second question in set A, and a whole second draft set B.
        $qA2 = ValuationQuestion::query()->create([
            'valuation_rule_set_id' => $setA->id,
            'key' => 'second',
            'label_ckb' => 'دووەم', 'label_ar' => 'ثانٍ', 'label_en' => 'Second',
            'sort_order' => 1, 'is_active' => true,
        ]);

        $setB = $this->draftSet(['name' => 'Other draft']);
        $qB = ValuationQuestion::query()->create([
            'valuation_rule_set_id' => $setB->id,
            'key' => 'foreign',
            'label_ckb' => 'بیانی', 'label_ar' => 'أجنبي', 'label_en' => 'Foreign',
            'sort_order' => 0, 'is_active' => true,
        ]);
        $oB = ValuationQuestionOption::query()->create([
            'valuation_question_id' => $qB->id,
            'key' => 'foreign_opt',
            'label_ckb' => 'بژاردە', 'label_ar' => 'خيار', 'label_en' => 'Choice',
            'adjustment_percent' => '9.000',
            'sort_order' => 0, 'is_active' => true,
        ]);

        $questionPayload = [
            'key' => 'forged',
            'label_ckb' => 'دەستکاری', 'label_ar' => 'مزوّر', 'label_en' => 'Forged',
        ];
        $optionPayload = $questionPayload + ['adjustment_percent' => '1.000'];

        // Another set's question id through set A's path: 404 on every verb.
        $this->actingAs($manager)
            ->put(self::BASE.'/'.$setA->id.'/questions/'.$qB->id, $questionPayload)
            ->assertNotFound();
        $this->actingAs($manager)
            ->delete(self::BASE.'/'.$setA->id.'/questions/'.$qB->id)
            ->assertNotFound();
        $this->actingAs($manager)
            ->post(self::BASE.'/'.$setA->id.'/questions/'.$qB->id.'/options', $optionPayload)
            ->assertNotFound();

        // A sibling question's option id: 404, not a reparented write.
        $this->actingAs($manager)
            ->put(self::BASE.'/'.$setA->id.'/questions/'.$qA2->id.'/options/'.$oA->id, $optionPayload)
            ->assertNotFound();
        $this->actingAs($manager)
            ->delete(self::BASE.'/'.$setA->id.'/questions/'.$qA2->id.'/options/'.$oA->id)
            ->assertNotFound();

        // A foreign set's option id under the right-looking question: 404.
        $this->actingAs($manager)
            ->put(self::BASE.'/'.$setA->id.'/questions/'.$qA->id.'/options/'.$oB->id, $optionPayload)
            ->assertNotFound();

        // Nothing moved, nothing changed, nothing was deleted.
        $this->assertSame('foreign', $qB->refresh()->key);
        $this->assertSame($setB->id, $qB->valuation_rule_set_id);
        $this->assertSame('9.000', (string) $oB->refresh()->adjustment_percent);
        $this->assertSame($qB->id, $oB->valuation_question_id);
        $this->assertSame('5.000', (string) $oA->refresh()->adjustment_percent);
        $this->assertSame($qA->id, $oA->valuation_question_id);
        $this->assertSame(3, ValuationQuestion::query()->count());
        $this->assertSame(2, ValuationQuestionOption::query()->count());
    }

    public function test_the_flag_gates_writes_and_the_super_admin_preview_is_audited(): void
    {
        $this->setFeatures(['portfolio.valuation_rules' => false]);

        // A write with the flag off refuses before the controller runs, and
        // the attempt itself is a recorded security event.
        $this->actingAs($this->manager())
            ->post(self::BASE, ['name' => 'Dark write'])
            ->assertForbidden();
        $this->assertSame(0, ValuationRuleSet::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'feature.access_denied')->count());

        // A Super Admin still reaches the admin surface while the flag is
        // off — the documented, audited preview exception.
        $superAdmin = User::factory()->create();
        $this->attachRole($superAdmin, RoleKey::SuperAdmin);

        $this->actingAs($superAdmin)->get(self::BASE)->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'feature.preview_while_disabled')->count());
    }

    public function test_preview_refuses_forged_question_and_option_pairings(): void
    {
        $manager = $this->manager();
        $set = $this->publishableDraft();

        /** @var ValuationQuestion $q1 */
        $q1 = $set->questions()->firstOrFail();
        /** @var ValuationQuestionOption $o1 */
        $o1 = $q1->options()->firstOrFail();

        $q2 = ValuationQuestion::query()->create([
            'valuation_rule_set_id' => $set->id,
            'key' => 'view',
            'label_ckb' => 'دیمەن', 'label_ar' => 'إطلالة', 'label_en' => 'View',
            'sort_order' => 1, 'is_active' => true,
        ]);

        $foreignSet = $this->draftSet(['name' => 'Foreign']);
        $foreignQuestion = ValuationQuestion::query()->create([
            'valuation_rule_set_id' => $foreignSet->id,
            'key' => 'foreign',
            'label_ckb' => 'بیانی', 'label_ar' => 'أجنبي', 'label_en' => 'Foreign',
            'sort_order' => 0, 'is_active' => true,
        ]);
        $foreignOption = ValuationQuestionOption::query()->create([
            'valuation_question_id' => $foreignQuestion->id,
            'key' => 'foreign_opt',
            'label_ckb' => 'بژاردە', 'label_ar' => 'خيار', 'label_en' => 'Choice',
            'adjustment_percent' => '9.000',
            'sort_order' => 0, 'is_active' => true,
        ]);

        // A REAL option id under the wrong question of the same set refuses.
        $this->actingAs($manager)
            ->getJson(self::BASE.'/'.$set->id.'/preview?base=100000&answers['.$q2->id.']='.$o1->id)
            ->assertStatus(422);

        // Another set's genuine question/option pair refuses on this set.
        $this->actingAs($manager)
            ->getJson(self::BASE.'/'.$set->id.'/preview?base=100000&answers['.$foreignQuestion->id.']='.$foreignOption->id)
            ->assertStatus(422);

        // The straight pairing still computes — the refusals above were the
        // forgery, not a broken endpoint.
        $this->actingAs($manager)
            ->getJson(self::BASE.'/'.$set->id.'/preview?base=100000&answers['.$q1->id.']='.$o1->id)
            ->assertOk()
            ->assertJson(['final' => '105000.0000']);

        $this->assertSame(0, PortfolioValuation::query()->count());
        $this->assertSame(0, PortfolioPropertyAnswer::query()->count());
    }

    public function test_every_configure_endpoint_refuses_the_view_only_role(): void
    {
        $auditor = $this->auditor();

        $set = $this->publishableDraft();
        /** @var ValuationQuestion $question */
        $question = $set->questions()->firstOrFail();
        /** @var ValuationQuestionOption $option */
        $option = $question->options()->firstOrFail();

        $endpoints = [
            ['PUT', self::BASE.'/'.$set->id],
            ['DELETE', self::BASE.'/'.$set->id],
            ['POST', self::BASE.'/'.$set->id.'/retire'],
            ['POST', self::BASE.'/'.$set->id.'/duplicate'],
            ['GET', self::BASE.'/'.$set->id.'/preview?base=100000'],
            ['POST', self::BASE.'/'.$set->id.'/questions'],
            ['PUT', self::BASE.'/'.$set->id.'/questions/'.$question->id],
            ['DELETE', self::BASE.'/'.$set->id.'/questions/'.$question->id],
            ['POST', self::BASE.'/'.$set->id.'/questions/'.$question->id.'/options'],
            ['PUT', self::BASE.'/'.$set->id.'/questions/'.$question->id.'/options/'.$option->id],
            ['DELETE', self::BASE.'/'.$set->id.'/questions/'.$question->id.'/options/'.$option->id],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = match ($method) {
                'GET' => $this->actingAs($auditor)->get($url),
                'POST' => $this->actingAs($auditor)->post($url),
                'PUT' => $this->actingAs($auditor)->put($url),
                default => $this->actingAs($auditor)->delete($url),
            };

            $response->assertForbidden();
        }

        // The permission wall left the draft byte-identical.
        $this->assertSame(ValuationRuleSet::STATUS_DRAFT, $set->refresh()->status);
        $this->assertSame(1, ValuationQuestion::query()->count());
        $this->assertSame('5.000', (string) $option->refresh()->adjustment_percent);
    }

    public function test_the_full_lifecycle_leaves_a_complete_audit_trail(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post(self::BASE, ['name' => 'Trail'])->assertRedirect();
        $set = ValuationRuleSet::query()->firstOrFail();
        $base = self::BASE.'/'.$set->id;

        $this->actingAs($manager)->put($base, ['name' => 'Trail renamed'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $labels = ['label_ckb' => 'پرسیار', 'label_ar' => 'سؤال', 'label_en' => 'Question'];
        $this->actingAs($manager)->post($base.'/questions', ['key' => 'first'] + $labels)->assertRedirect();
        $this->actingAs($manager)->post($base.'/questions', ['key' => 'second'] + $labels)->assertRedirect();

        /** @var ValuationQuestion $first */
        $first = $set->questions()->where('key', 'first')->firstOrFail();
        /** @var ValuationQuestion $second */
        $second = $set->questions()->where('key', 'second')->firstOrFail();

        $this->actingAs($manager)
            ->put($base.'/questions/'.$second->id, ['key' => 'second', 'label_en' => 'Renamed'] + $labels)
            ->assertRedirect()->assertSessionHasNoErrors();

        $optionLabels = ['label_ckb' => 'بژاردە', 'label_ar' => 'خيار', 'label_en' => 'Choice'];
        $this->actingAs($manager)
            ->post($base.'/questions/'.$first->id.'/options', ['key' => 'kept', 'adjustment_percent' => '5.000'] + $optionLabels)
            ->assertRedirect();
        $this->actingAs($manager)
            ->post($base.'/questions/'.$first->id.'/options', ['key' => 'doomed', 'adjustment_percent' => '-3.500'] + $optionLabels)
            ->assertRedirect();

        /** @var ValuationQuestionOption $doomed */
        $doomed = $first->options()->where('key', 'doomed')->firstOrFail();

        $this->actingAs($manager)
            ->put($base.'/questions/'.$first->id.'/options/'.$doomed->id, ['key' => 'doomed', 'adjustment_percent' => '-4.000'] + $optionLabels)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($manager)
            ->delete($base.'/questions/'.$first->id.'/options/'.$doomed->id)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($manager)->delete($base.'/questions/'.$second->id)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($manager)->post($base.'/publish')->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($manager)->post($base.'/duplicate')->assertRedirect();
        $this->actingAs($manager)->post($base.'/retire')->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($manager)->delete($base)->assertRedirect(self::BASE);

        $expected = [
            'set_created' => 1,
            'set_updated' => 1,
            'question_created' => 2,
            'question_updated' => 1,
            'question_deleted' => 1,
            'option_created' => 2,
            'option_updated' => 1,
            'option_deleted' => 1,
            'set_published' => 1,
            'set_duplicated' => 1,
            'set_retired' => 1,
            'set_deleted' => 1,
        ];

        foreach ($expected as $event => $count) {
            $this->assertSame($count, AuditLog::query()
                ->where('action', 'portfolio.valuation_rules.'.$event)
                ->where('result', 'success')
                ->count(), $event);
        }
    }

    public function test_duplicate_leaves_the_source_byte_identical(): void
    {
        $manager = $this->manager();

        // Duplicate from a FROZEN source — the strongest case.
        $source = $this->publishableDraft();
        $this->actingAs($manager)->post(self::BASE.'/'.$source->id.'/publish')->assertRedirect();

        /** @var ValuationQuestion $sourceQuestion */
        $sourceQuestion = $source->questions()->firstOrFail();
        /** @var ValuationQuestionOption $sourceOption */
        $sourceOption = $sourceQuestion->options()->firstOrFail();
        $sourceVersion = $source->refresh()->version;

        $this->actingAs($manager)->post(self::BASE.'/'.$source->id.'/duplicate')->assertRedirect();

        // The source kept its status, content, and exactly its own rows.
        $this->assertSame(ValuationRuleSet::STATUS_ACTIVE, $source->refresh()->status);
        $this->assertSame($sourceVersion, $source->version);
        $this->assertSame(1, $source->questions()->count());
        $this->assertSame('renovation', $sourceQuestion->refresh()->key);
        $this->assertSame($source->id, $sourceQuestion->valuation_rule_set_id);
        $this->assertSame('5.000', (string) $sourceOption->refresh()->adjustment_percent);
        $this->assertSame($sourceQuestion->id, $sourceOption->valuation_question_id);

        // The draft is a fresh-row copy in the same family, one version up.
        /** @var ValuationRuleSet $draft */
        $draft = ValuationRuleSet::query()
            ->where('status', ValuationRuleSet::STATUS_DRAFT)
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertSame($sourceVersion + 1, $draft->version);
        /** @var ValuationQuestion $copiedQuestion */
        $copiedQuestion = $draft->questions()->firstOrFail();
        /** @var ValuationQuestionOption $copiedOption */
        $copiedOption = $copiedQuestion->options()->firstOrFail();
        $this->assertNotSame($sourceQuestion->id, $copiedQuestion->id);
        $this->assertNotSame($sourceOption->id, $copiedOption->id);
        $this->assertSame('renovation', $copiedQuestion->key);
        $this->assertSame('5.000', (string) $copiedOption->adjustment_percent);
    }

    public function test_refused_lifecycle_actions_are_audited_as_failures(): void
    {
        $manager = $this->manager();
        $set = $this->publishableDraft();
        /** @var ValuationQuestion $question */
        $question = $set->questions()->firstOrFail();

        $failed = fn (string $event) => AuditLog::query()
            ->where('action', 'portfolio.valuation_rules.'.$event)
            ->where('result', 'failure')
            ->where('severity', 'warning');

        // Retiring a DRAFT refuses exactly as before — and now leaves a row.
        $this->actingAs($manager)
            ->post(self::BASE.'/'.$set->id.'/retire')
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);
        $this->assertSame(ValuationRuleSet::STATUS_DRAFT, $set->refresh()->status);
        $this->assertSame(1, $failed('set_retire_refused')->count());

        $this->actingAs($manager)->post(self::BASE.'/'.$set->id.'/publish')->assertRedirect();

        // Deleting the ACTIVE set refuses and is recorded.
        $this->actingAs($manager)
            ->delete(self::BASE.'/'.$set->id)
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);
        $this->assertSame(ValuationRuleSet::STATUS_ACTIVE, $set->refresh()->status);
        $this->assertSame(1, $failed('set_delete_refused')->count());

        // A frozen structural edit refuses and is recorded — for a child row
        // and for the scope form alike.
        $this->actingAs($manager)
            ->put(self::BASE.'/'.$set->id.'/questions/'.$question->id, [
                'key' => 'renovation',
                'label_ckb' => 'گۆڕدرا', 'label_ar' => 'معدل', 'label_en' => 'Changed',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);
        $this->assertSame(1, $failed('edit_refused')->count());

        $this->actingAs($manager)
            ->put(self::BASE.'/'.$set->id, ['name' => 'Renamed while frozen'])
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);
        $this->assertSame(2, $failed('edit_refused')->count());
        $this->assertSame('renovation', $question->refresh()->key);
    }

    public function test_the_lifecycle_matrix_matches_the_server(): void
    {
        $manager = $this->manager();

        // A draft WITH content deletes cleanly at the server: the page not
        // offering the button is presentation, not policy.
        $fullDraft = $this->publishableDraft();
        $this->actingAs($manager)
            ->delete(self::BASE.'/'.$fullDraft->id)
            ->assertRedirect(self::BASE)
            ->assertSessionHasNoErrors();
        $this->assertNull(ValuationRuleSet::query()->find($fullDraft->id));

        // An ACTIVE set refuses a second publish.
        $active = $this->publishableDraft();
        $this->actingAs($manager)->post(self::BASE.'/'.$active->id.'/publish')->assertRedirect();
        $this->actingAs($manager)
            ->post(self::BASE.'/'.$active->id.'/publish')
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);
        $this->assertSame(ValuationRuleSet::STATUS_ACTIVE, $active->refresh()->status);

        // RETIRED: frozen for edits and both transitions, open to duplicate
        // and delete.
        $this->actingAs($manager)->post(self::BASE.'/'.$active->id.'/retire')->assertRedirect();
        $retired = $active->refresh();

        $this->actingAs($manager)
            ->put(self::BASE.'/'.$retired->id, ['name' => 'Rename retired'])
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);
        $this->actingAs($manager)
            ->post(self::BASE.'/'.$retired->id.'/publish')
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);
        $this->actingAs($manager)
            ->post(self::BASE.'/'.$retired->id.'/retire')
            ->assertRedirect()
            ->assertSessionHasErrors(['lifecycle']);
        $this->assertSame(ValuationRuleSet::STATUS_RETIRED, $retired->refresh()->status);

        $this->actingAs($manager)->post(self::BASE.'/'.$retired->id.'/duplicate')->assertRedirect();
        $this->assertSame(1, ValuationRuleSet::query()
            ->where('status', ValuationRuleSet::STATUS_DRAFT)->count());

        $this->actingAs($manager)
            ->delete(self::BASE.'/'.$retired->id)
            ->assertRedirect(self::BASE)
            ->assertSessionHasNoErrors();
        $this->assertNull(ValuationRuleSet::query()->find($retired->id));
    }
}

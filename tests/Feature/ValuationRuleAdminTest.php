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
}

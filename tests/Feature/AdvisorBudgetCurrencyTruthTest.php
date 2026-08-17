<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Advisor\Services\AdvisorLanguage;
use App\Modules\Advisor\Support\NumericGuard;
use App\Modules\Identity\Models\User;
use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The advisor's budget-currency truthfulness (Phase 18).
 *
 * Three sibling defects said dollars to dinar buyers: the over-budget card
 * difference hardcoded "USD" for comparisons the currency gate had proven
 * to be dinar-against-dinar; the budget caption in the editable summary and
 * the completion sentence rendered every budget as dollars beside a
 * currency row that said dinars; and the summary-edit endpoint stored the
 * posted display label verbatim, so a save-without-change replaced the
 * canonical 'IQD' with a caption string and a retyped budget with its own
 * words — corrupting the matcher's comparisons and the recorded lead.
 *
 * The contracts pinned here: card differences speak the price's own
 * currency and unlike currencies stay non-comparable; the summary and
 * completion speak the person's stated currency in all three locales with
 * the unknown fallback unchanged; amend routes every edit through the
 * interview's own parsers so a no-op save is a true no-op, localized
 * wording and Arabic-Indic digits parse, scale words expand, the
 * currency-inside-budget precedence survives, and the lead receives
 * canonical values; and none of it moves a grounding verdict.
 */
final class AdvisorBudgetCurrencyTruthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures(['advisor.residential' => true]);
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['telegram_verified_at' => now()])->save();

        return $user;
    }

    /** @return TestResponse<Response> */
    private function send(string $message, string $locale = 'ckb'): TestResponse
    {
        return $this->postJson('/advisor/reply', ['message' => $message, 'locale' => $locale]);
    }

    private function pricedProject(string $slug, string $currency, int $price): Project
    {
        $project = Project::query()->create([
            'slug' => $slug,
            'name_ckb' => 'پڕۆژەی '.$slug,
            'name_en' => 'Project '.$slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => 'published',
        ]);
        $project->forceFill(['published_at' => now()])->save();

        PriceRecord::query()->create([
            'record_id' => $slug.'-price',
            'scope_type' => 'project',
            'scope_id' => $project->id,
            'property_type' => 'apartment',
            'transaction_type' => 'sale',
            'price_type' => 'sale_asking',
            'currency' => $currency,
            'price' => $price,
            'effective_date' => '2026-08-01',
            'period' => '2026-08',
            'source' => 'phase 18 fixture',
            'publication_status' => 'published',
        ]);

        return $project;
    }

    /**
     * Complete an interview whose budget answer names its currency.
     *
     * @return TestResponse<Response>
     */
    private function completeIntake(User $member, string $budgetReply): TestResponse
    {
        $this->actingAs($member)->get('/advisor')->assertOk();
        $this->send('بۆ نیشتەجێبوون')->assertOk();
        $this->send($budgetReply)->assertOk();
        $response = $this->send('شوقە')->assertOk();
        if ($response->json('complete') !== true) {
            $response = $this->send('بەسە')->assertOk();
            $response->assertJsonPath('complete', true);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function criteria(): array
    {
        return (array) $this->app['session.store']->get('advisor.criteria', []);
    }

    /* --------------------------------------- A: the project-card wording */

    public function test_an_iqd_over_budget_difference_speaks_dinars_never_dollars(): void
    {
        $this->pricedProject('p18-iqd', 'IQD', 250_000_000);
        $member = $this->member();
        $done = $this->completeIntake($member, '٢٠٠ ملیۆن دینار');

        $this->assertSame('200000000', (string) $this->criteria()['budget']);
        $this->assertSame('IQD', $this->criteria()['currency']);

        $card = collect((array) $done->json('recommendations.items'))->firstWhere('budget_state', 'above');
        $this->assertNotNull($card);
        $differences = implode(' ', (array) $card['differences']);

        $this->assertStringContainsString('50,000,000 IQD', $differences);
        $this->assertStringNotContainsString('USD', $differences);
    }

    public function test_a_usd_over_budget_difference_still_speaks_dollars(): void
    {
        $this->pricedProject('p18-usd', 'USD', 900_000);
        $member = $this->member();
        $done = $this->completeIntake($member, '١٥٠ هەزار دۆلار');

        $this->assertSame('150000', (string) $this->criteria()['budget']);
        $this->assertSame('USD', $this->criteria()['currency']);

        $card = collect((array) $done->json('recommendations.items'))->firstWhere('budget_state', 'above');
        $this->assertNotNull($card);
        $this->assertStringContainsString('750,000 USD', implode(' ', (array) $card['differences']));
    }

    public function test_unlike_currencies_remain_non_comparable(): void
    {
        // An IQD budget against a USD-priced project: the currency gate must
        // keep the comparison OUT — budget_state unknown, no difference text
        // claiming an over-budget figure in either currency.
        $this->pricedProject('p18-cross', 'USD', 140_000);
        $member = $this->member();
        $done = $this->completeIntake($member, '٢٠٠ ملیۆن دینار');

        $card = collect((array) $done->json('recommendations.items'))->first();
        $this->assertNotNull($card);
        $this->assertSame('unknown', $card['budget_state']);
    }

    /* ---------------------------------- B: summary + completion wording */

    public function test_the_summary_row_and_completion_speak_the_stated_currency(): void
    {
        $this->pricedProject('p18-summary', 'IQD', 150_000_000);
        $member = $this->member();
        $done = $this->completeIntake($member, '٢٠٠ ملیۆن دینار');

        $summary = collect((array) $done->json('summary'));
        $budgetRow = $summary->firstWhere('key', 'budget');

        $this->assertStringContainsString('دیناری عێراقی', (string) $budgetRow['value']);
        $this->assertStringNotContainsString('دۆلار', (string) $budgetRow['value']);
        // And the canonical value rides beside the label for the edit box.
        $this->assertSame('200000000', (string) $budgetRow['raw_value']);
    }

    public function test_budget_wording_matrix_across_locales_and_currencies(): void
    {
        $language = app(AdvisorLanguage::class);
        $iqd = ['purpose' => 'residence', 'budget' => '200000000', 'currency' => 'IQD', 'property_type' => 'apartment'];
        $usd = ['purpose' => 'residence', 'budget' => '150000', 'currency' => 'USD', 'property_type' => 'apartment'];
        $unknown = ['purpose' => 'residence', 'budget' => '150000', 'property_type' => 'apartment'];

        // IQD: dinar wording in every locale, dollar wording in none.
        $this->assertStringContainsString('200,000,000 دیناری عێراقی', $language->completeMessage('ckb', $iqd));
        $this->assertStringContainsString('200,000,000 دينار عراقي', $language->completeMessage('ar', $iqd));
        $this->assertStringContainsString('200,000,000 IQD', $language->completeMessage('en', $iqd));
        foreach (['ckb', 'ar', 'en'] as $locale) {
            $message = $language->completeMessage($locale, $iqd);
            $this->assertStringNotContainsString('دۆلار', $message);
            $this->assertStringNotContainsString('دولار', $message);
            $this->assertStringNotContainsString('$', $message);
        }

        // USD: today's dollar wording, unchanged.
        $this->assertStringContainsString('150,000 دۆلار', $language->completeMessage('ckb', $usd));
        $this->assertStringContainsString('150,000 دولار', $language->completeMessage('ar', $usd));
        $this->assertStringContainsString('$150,000', $language->completeMessage('en', $usd));

        // No stated currency: the historical fallback, byte-identical.
        $this->assertStringContainsString('150,000 دۆلار', $language->completeMessage('ckb', $unknown));
        $this->assertStringContainsString('$150,000', $language->completeMessage('en', $unknown));
    }

    /* ------------------------------------------------ C: the amend flow */

    public function test_a_noop_currency_edit_is_a_true_noop(): void
    {
        $member = $this->member();
        $done = $this->completeIntake($member, '٢٠٠ ملیۆن دینار');

        $summary = collect((array) $done->json('summary'));
        $currencyRow = $summary->firstWhere('key', 'currency');
        // The canonical value is what the edit box now prefills…
        $this->assertSame('IQD', (string) $currencyRow['raw_value']);

        // …and even the LOCALIZED label — what an old cached frontend would
        // still post — canonicalizes back to IQD through the parser.
        foreach ([(string) $currencyRow['raw_value'], (string) $currencyRow['value']] as $posted) {
            $this->actingAs($member)
                ->postJson('/advisor/amend', ['slot' => 'currency', 'value' => $posted])
                ->assertStatus(302);
            $this->assertSame('IQD', $this->criteria()['currency'], "posting back '{$posted}' must keep IQD");
        }
    }

    public function test_an_unparseable_currency_edit_keeps_the_known_currency(): void
    {
        $member = $this->member();
        $this->completeIntake($member, '٢٠٠ ملیۆن دینار');
        $this->assertSame('IQD', $this->criteria()['currency']);

        // A typo in the edit box must not demote a known currency to the
        // interview's honest-unknown sentinel: the old value stays.
        $this->actingAs($member)
            ->postJson('/advisor/amend', ['slot' => 'currency', 'value' => 'IQDD'])
            ->assertStatus(302);
        $this->assertSame('IQD', $this->criteria()['currency']);

        // With NO known currency to lose, undetectable text still resolves
        // to the sentinel — the same honesty the interview itself applies.
        $this->actingAs($member)
            ->postJson('/advisor/amend', ['slot' => 'currency', 'value' => null])
            ->assertStatus(302);
        $this->assertNull($this->criteria()['currency']);
        $this->actingAs($member)
            ->postJson('/advisor/amend', ['slot' => 'currency', 'value' => 'IQDD'])
            ->assertStatus(302);
        $this->assertSame('unknown', $this->criteria()['currency']);
    }

    public function test_a_noop_budget_edit_preserves_budget_and_currency(): void
    {
        $member = $this->member();
        $done = $this->completeIntake($member, '٢٠٠ ملیۆن دینار');

        $budgetRow = collect((array) $done->json('summary'))->firstWhere('key', 'budget');
        $this->assertSame('200000000', (string) $budgetRow['raw_value']);

        // Saving the canonical prefill back: nothing may move.
        $this->actingAs($member)
            ->postJson('/advisor/amend', ['slot' => 'budget', 'value' => (string) $budgetRow['raw_value']])
            ->assertStatus(302);
        $this->assertSame('200000000', (string) $this->criteria()['budget']);
        $this->assertSame('IQD', $this->criteria()['currency']);

        // Saving the truthful LOCALIZED label back (old-frontend path): the
        // dinar word it now carries agrees with the stored currency, so the
        // named-currency-wins rule changes nothing either.
        $this->actingAs($member)
            ->postJson('/advisor/amend', ['slot' => 'budget', 'value' => (string) $budgetRow['value']])
            ->assertStatus(302);
        $this->assertSame('200000000', (string) $this->criteria()['budget']);
        $this->assertSame('IQD', $this->criteria()['currency']);
    }

    public function test_budget_edits_parse_wording_digits_and_scale(): void
    {
        $member = $this->member();
        $this->completeIntake($member, '٢٠٠ ملیۆن دینار');

        // A localized retype with a scale word AND an explicit currency:
        // the amount expands and the named currency wins, exactly as the
        // interview's own budget rule documents.
        $this->actingAs($member)
            ->postJson('/advisor/amend', ['slot' => 'budget', 'value' => '200 هەزار دۆلار'])
            ->assertStatus(302);
        $this->assertSame('200000', (string) $this->criteria()['budget']);
        $this->assertSame('USD', $this->criteria()['currency']);

        // Arabic-Indic digits with a scale word parse identically.
        $this->actingAs($member)
            ->postJson('/advisor/amend', ['slot' => 'budget', 'value' => '٣٠٠ ملیۆن دینار'])
            ->assertStatus(302);
        $this->assertSame('300000000', (string) $this->criteria()['budget']);
        $this->assertSame('IQD', $this->criteria()['currency']);
    }

    public function test_clearing_and_unrelated_amends_keep_their_contracts(): void
    {
        $member = $this->member();
        $this->completeIntake($member, '٢٠٠ ملیۆن دینار');

        // An empty value still clears the slot for re-asking.
        $this->actingAs($member)
            ->postJson('/advisor/amend', ['slot' => 'budget', 'value' => null])
            ->assertStatus(302);
        $this->assertNull($this->criteria()['budget']);

        // A free-text slot keeps its existing store-the-text behavior.
        $this->actingAs($member)
            ->postJson('/advisor/amend', ['slot' => 'purpose', 'value' => 'investment'])
            ->assertStatus(302);
        $this->assertSame('investment', $this->criteria()['purpose']);
    }

    /* -------------------------------------------------- the lead's truth */

    public function test_the_submitted_lead_receives_canonical_values_after_noop_edits(): void
    {
        $member = $this->member();
        $done = $this->completeIntake($member, '٢٠٠ ملیۆن دینار');

        // A no-op edit of both rows, via the labels an old frontend posts.
        $summary = collect((array) $done->json('summary'));
        foreach (['currency', 'budget'] as $key) {
            $this->actingAs($member)->postJson('/advisor/amend', [
                'slot' => $key,
                'value' => (string) $summary->firstWhere('key', $key)['value'],
            ])->assertStatus(302);
        }

        $this->actingAs($member)->postJson('/advisor/request', ['consent' => true])->assertStatus(200);

        $lead = DemandProfile::query()->latest('id')->first();
        $this->assertNotNull($lead);
        $this->assertSame('IQD', $lead->currency, 'the lead keeps the stated currency');
        $this->assertNotSame('unknown', $lead->currency_source);
        $this->assertSame(200_000_000.0, (float) $lead->budget_max, 'the lead keeps the stated budget');
    }

    /* ----------------------------------------------------- grounding pin */

    public function test_currency_wording_changes_do_not_move_grounding_verdicts(): void
    {
        $guard = new NumericGuard;

        // The verdict depends on the numeric value, not the currency word:
        // the same claim grounds against the same evidence whether the text
        // says dollars or dinars.
        $this->assertTrue($guard->validate('نزیکەی 244,000 دۆلار', ['243500'])['grounded']);
        $this->assertTrue($guard->validate('نزیکەی 244,000 دینار', ['243500'])['grounded']);
        $this->assertFalse($guard->validate('نزیکەی 245,000 دینار', ['243500'])['grounded']);
    }
}

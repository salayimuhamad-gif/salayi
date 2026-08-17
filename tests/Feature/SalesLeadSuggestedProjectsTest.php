<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Advisor\Services\AdvisorProjectMatcher;
use App\Modules\Identity\Models\User;
use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sales workspace's suggested projects tell the truth (Phase 19).
 *
 * The admin lead page asks AdvisorProjectMatcher::recommend() for up to three
 * published projects matching the lead's STRUCTURED fields — and then, from
 * the controller's first day, read the result under a key the matcher has
 * never produced (`cards`; the matcher exposes `items`). The `?? []` fallback
 * turned that typo into a permanently empty list: every lead, however
 * perfectly matched, showed no suggestions, and the card (with its
 * budget-basis honesty note inside) never rendered at all.
 *
 * The contracts pinned here: matching candidates actually reach the admin
 * payload; the list is the matcher's own first three, in the matcher's own
 * order; empty means genuinely matchless, never a dropped key; unpublished
 * projects stay invisible; an unlike-currency comparison still says
 * `unknown` instead of inventing a budget fit; the payload carries exactly
 * the six keys the page consumes and none of the matcher's internals; and
 * the route stays behind leads.view.
 */
final class SalesLeadSuggestedProjectsTest extends TestCase
{
    use RefreshDatabase;

    private function pricedProject(
        string $slug,
        string $currency = 'IQD',
        int $price = 150_000_000,
        bool $published = true,
    ): Project {
        $project = Project::query()->create([
            'slug' => $slug,
            'name_ckb' => 'پڕۆژەی '.$slug,
            'name_en' => 'Project '.$slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => $published ? 'published' : 'draft',
        ]);

        if ($published) {
            $project->forceFill(['published_at' => now()])->save();
        }

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
            'source' => 'phase 19 fixture',
            'publication_status' => 'published',
        ]);

        return $project;
    }

    /** @param array<model-property<DemandProfile>, mixed> $overrides */
    private function lead(array $overrides = []): DemandProfile
    {
        return DemandProfile::query()->create($overrides + [
            'objective' => 'residence',
            'property_type' => 'apartment',
            'budget_max' => 200_000_000,
            'currency' => 'IQD',
            'currency_source' => 'stated',
            'ai_summary_locale' => 'ckb',
            'stage' => 'new',
            'source' => 'advisor',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function suggestedFor(DemandProfile $lead): array
    {
        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin/leads/'.$lead->id)
            ->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');
        /** @var array<string, mixed> $props */
        $props = $page['props'];

        return array_values((array) ($props['suggested_projects'] ?? []));
    }

    /* ------------------------------------ A: candidates reach the admin */

    public function test_matching_candidates_reach_the_admin_response(): void
    {
        $project = $this->pricedProject('p19-match');
        $lead = $this->lead();

        $suggested = $this->suggestedFor($lead);

        $this->assertNotEmpty($suggested, 'a matching published project must be suggested');
        $this->assertSame($project->slug, $suggested[0]['slug']);
        $this->assertSame('پڕۆژەی p19-match', $suggested[0]['name']);
        // 150M price against a 200M IQD budget: within, honestly compared.
        $this->assertSame('within', $suggested[0]['budget_state']);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin/leads/'.$lead->id)
            ->assertOk();
        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');
        $this->assertSame('compared', $page['props']['suggested_projects_budget_basis']);
    }

    /* --------------------------------------------- B: cap and ordering */

    public function test_the_admin_list_is_the_matchers_first_three_in_order(): void
    {
        foreach (['p19-a', 'p19-b', 'p19-c', 'p19-d'] as $i => $slug) {
            // Distinct prices and publish times keep the ranking well-defined.
            $this->pricedProject($slug, 'IQD', 150_000_000 + $i * 10_000_000);
        }
        $lead = $this->lead();

        // The matcher itself is the ordering oracle — the exact criteria the
        // controller builds, the exact locale it passes. The test must never
        // reimplement the ranking.
        $result = app(AdvisorProjectMatcher::class)->recommend([
            'purpose' => 'residence',
            'budget' => 200_000_000.0,
            'currency' => 'IQD',
            'property_type' => 'apartment',
        ], 'ckb');

        $expected = array_slice(
            array_map(static fn (array $item): ?string => $item['slug'] ?? null, $result['items']),
            0,
            3,
        );
        $this->assertCount(3, $expected, 'four candidates must yield at least three matcher items');

        $suggested = $this->suggestedFor($lead);

        $this->assertCount(3, $suggested, 'the admin payload caps at three suggestions');
        $this->assertSame($expected, array_map(static fn (array $row): ?string => $row['slug'], $suggested));
    }

    /* ------------------------------------------- C: honest empty states */

    public function test_zero_published_matches_yield_an_honest_empty_list(): void
    {
        $lead = $this->lead();

        $this->assertSame([], $this->suggestedFor($lead));
    }

    public function test_a_lead_without_structured_fields_gets_no_suggestions(): void
    {
        $this->pricedProject('p19-unclaimed');
        $lead = $this->lead([
            'objective' => null,
            'property_type' => null,
            'budget_max' => null,
            'currency' => null,
            'currency_source' => 'unknown',
        ]);

        $this->assertSame([], $this->suggestedFor($lead));
    }

    /* --------------------------------------- D: visibility preservation */

    public function test_unpublished_projects_never_reach_the_suggestions(): void
    {
        $published = $this->pricedProject('p19-public');
        $this->pricedProject('p19-hidden', published: false);
        $lead = $this->lead();

        $slugs = array_map(
            static fn (array $row): ?string => $row['slug'],
            $this->suggestedFor($lead),
        );

        $this->assertContains($published->slug, $slugs);
        $this->assertNotContains('p19-hidden', $slugs);
    }

    /* -------------------------- E: Phase 18 currency truthfulness rides */

    public function test_an_unlike_currency_match_stays_budget_unknown(): void
    {
        $this->pricedProject('p19-usd', 'USD', 140_000);
        $lead = $this->lead();

        $suggested = $this->suggestedFor($lead);

        $this->assertNotEmpty($suggested);
        $this->assertSame('unknown', $suggested[0]['budget_state'],
            'an IQD budget must not be compared against a USD price');
    }

    /* -------------------------------------- F: bounded admin payload */

    public function test_each_suggestion_exposes_exactly_the_six_admin_keys(): void
    {
        $this->pricedProject('p19-shape');
        $lead = $this->lead();

        $suggested = $this->suggestedFor($lead);

        $this->assertNotEmpty($suggested);
        foreach ($suggested as $row) {
            $this->assertSame(
                ['slug', 'name', 'area', 'price_label', 'fit_label', 'budget_state'],
                array_keys($row),
                'the admin payload carries the six page keys and no matcher internals',
            );
        }
    }

    /* ------------------------------------------------ G: authorization */

    public function test_the_lead_page_requires_the_leads_view_permission(): void
    {
        $lead = $this->lead();

        $this->actingAs(User::factory()->create())
            ->get('/admin/leads/'.$lead->id)
            ->assertForbidden();
    }
}

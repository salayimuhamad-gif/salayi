<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Knowledge\Enums\KnowledgeStatus;
use App\Modules\Knowledge\Models\KnowledgeEvent;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The public market timeline, on both of its pages (Phase 13).
 *
 * Before this phase the doctrine "Published is the public threshold; Approved
 * is AI evidence, not a public statement" lived only in a controller comment —
 * no test pinned it, the area page had no timeline at all, the project
 * timeline hardcoded Kurdish fields on every locale, the evidence class was
 * invisible, and a lapsed Published row stayed public until the hourly sweep
 * noticed. These tests pin all of it:
 *
 *   - eligibility is exactly Published + not past expires_on, via the shared
 *     scopePubliclyCurrent() both pages use;
 *   - ai_usage_permitted plays NO part in visibility, in either direction;
 *   - text follows the house locale chain [locale, ckb, en, ar];
 *   - the evidence class ships via effectiveEvidenceClass(), so a legacy
 *     null is an observation and can never masquerade as a verified fact;
 *   - events belong to exactly one morph entity — no cross-entity bleed.
 */
final class KnowledgePublicTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // /areas/{slug} sits behind the geography.areas flag.
        $this->setFeatures(['geography.areas' => true]);
    }

    /* ----------------------------------------------------------- fixtures */

    private function area(string $slug = 'ankawa'): Area
    {
        return Area::query()->firstOrCreate(['slug' => $slug], [
            'type' => 'district',
            'name_ckb' => 'ئەنکاوە',
            'name_ar' => 'عنكاوة',
            'name_en' => 'Ankawa',
            'publication_status' => 'published',
        ]);
    }

    private function project(string $slug = 'timeline-home'): Project
    {
        $project = Project::query()->firstOrCreate(['slug' => $slug], [
            'name_ckb' => 'پڕۆژەی '.$slug,
            'name_en' => 'Project '.$slug,
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => 'published',
        ]);
        $project->forceFill(['published_at' => now()])->save();

        return $project;
    }

    /**
     * A Published, current, trilingual area/project event; overrides shape
     * the variants. forceFill so leak-matrix rows can carry any status.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function event(string $entityType, int $entityId, array $overrides = []): KnowledgeEvent
    {
        $event = new KnowledgeEvent;
        $event->forceFill(array_merge([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'title_ckb' => 'بەرزبوونەوەی نرخەکان',
            'title_ar' => 'ارتفاع الأسعار',
            'title_en' => 'Prices rising',
            'summary_ckb' => 'نرخی موڵک بەرزبووەتەوە بەهۆی داواکاری زیاتر.',
            'summary_ar' => 'ارتفعت أسعار العقارات بسبب زيادة الطلب.',
            'summary_en' => 'Property prices rose on higher demand.',
            'event_type' => 'price',
            'direction' => 'positive',
            'strength' => 3,
            'effective_date' => '2026-08-01',
            'source' => 'تیمی چاودێری بازاڕ',
            'confidence' => 'medium',
            'evidence_class' => 'admin_observation',
            'ai_usage_permitted' => false,
            'status' => KnowledgeStatus::Published,
        ], $overrides));
        $event->save();

        return $event;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>>
     */
    private function timelineOf(TestResponse $response): array
    {
        return $response->viewData('page')['props']['timeline'];
    }

    /** @return TestResponse<Response> */
    private function areaPage(string $slug = 'ankawa', string $prefix = ''): TestResponse
    {
        return $this->get($prefix.'/areas/'.$slug)->assertOk();
    }

    /** @return TestResponse<Response> */
    private function projectPage(string $slug = 'timeline-home', string $prefix = ''): TestResponse
    {
        return $this->get($prefix.'/projects/'.$slug)->assertOk();
    }

    /* ------------------------------------------------- A. area happy path */

    public function test_a_published_current_area_event_appears_on_the_area_page(): void
    {
        $area = $this->area();
        $event = $this->event('area', $area->id);

        $timeline = $this->timelineOf($this->areaPage());

        $this->assertCount(1, $timeline);
        $this->assertSame($event->id, $timeline[0]['id']);
        $this->assertSame('بەرزبوونەوەی نرخەکان', $timeline[0]['title']);
        $this->assertSame('نرخی موڵک بەرزبووەتەوە بەهۆی داواکاری زیاتر.', $timeline[0]['summary']);
        $this->assertSame('admin_observation', $timeline[0]['evidence_class']);
        $this->assertSame('تیمی چاودێری بازاڕ', $timeline[0]['source']);
        $this->assertSame('2026-08-01', $timeline[0]['effective_date']);
    }

    public function test_the_public_payload_carries_no_internal_metadata(): void
    {
        $area = $this->area();
        $this->event('area', $area->id);

        $entry = $this->timelineOf($this->areaPage())[0];

        foreach (['ai_usage_permitted', 'author_id', 'reviewed_by', 'reviewed_at', 'details', 'status',
            'related_area_ids', 'related_project_ids', 'related_place_ids'] as $internal) {
            $this->assertArrayNotHasKey($internal, $entry, "{$internal} must never reach a public payload");
        }
    }

    /* ---------------------------------------------- B. project happy path */

    public function test_the_project_timeline_still_appears_now_with_class_and_locale(): void
    {
        $project = $this->project();
        $event = $this->event('project', $project->id, ['evidence_class' => 'verified_fact']);

        $timeline = $this->timelineOf($this->projectPage());

        $this->assertCount(1, $timeline);
        $this->assertSame($event->id, $timeline[0]['id']);
        $this->assertSame('verified_fact', $timeline[0]['evidence_class']);
        // The ckb page renders the Kurdish text, as before the phase.
        $this->assertSame('بەرزبوونەوەی نرخەکان', $timeline[0]['title']);
    }

    /* ------------------------------------------------ C. leakage matrix */

    public function test_only_published_current_rows_reach_either_public_page(): void
    {
        $area = $this->area();
        $project = $this->project();

        $leaks = [
            'draft' => ['status' => KnowledgeStatus::Draft],
            'review' => ['status' => KnowledgeStatus::Review],
            // The doctrine this suite exists to pin: Approved is AI evidence,
            // NOT a public statement.
            'approved-but-unpublished' => ['status' => KnowledgeStatus::Approved],
            'rejected' => ['status' => KnowledgeStatus::Rejected],
            'expired status' => ['status' => KnowledgeStatus::Expired],
            // Published but lapsed: the sweep runs hourly, and the public
            // query must not serve the row during the gap.
            'published-but-lapsed' => [
                'effective_date' => '2026-01-01',
                'expires_on' => '2026-02-01',
            ],
        ];

        foreach ($leaks as $label => $overrides) {
            KnowledgeEvent::query()->forceDelete();
            $this->event('area', $area->id, $overrides + ['title_ckb' => 'نابێت دەربکەوێت']);
            $this->event('project', $project->id, $overrides + ['title_ckb' => 'نابێت دەربکەوێت']);

            $this->assertSame([], $this->timelineOf($this->areaPage()), "a {$label} event must not reach the area page");
            $this->assertSame([], $this->timelineOf($this->projectPage()), "a {$label} event must not reach the project page");
        }
    }

    public function test_ai_permission_grants_no_public_visibility_and_costs_none(): void
    {
        $area = $this->area();

        // Approved + AI-permitted: usable by the Advisor, still NOT public.
        $this->event('area', $area->id, [
            'status' => KnowledgeStatus::Approved,
            'ai_usage_permitted' => true,
        ]);
        $this->assertSame([], $this->timelineOf($this->areaPage()), 'ai_usage_permitted must not open the public page');

        // Published with AI use forbidden: public all the same.
        KnowledgeEvent::query()->forceDelete();
        $this->event('area', $area->id, ['ai_usage_permitted' => false]);
        $this->assertCount(1, $this->timelineOf($this->areaPage()), 'forbidding AI use must not hide a Published event');
    }

    /* ------------------------------------------- D. wrong-entity isolation */

    public function test_events_stay_with_their_own_morph_entity(): void
    {
        $area = $this->area();
        $otherArea = $this->area('kasnazan');
        $project = $this->project();
        $otherProject = $this->project('other-home');

        $areaEvent = $this->event('area', $area->id, ['title_ckb' => 'ڕووداوی ناوچە']);
        $projectEvent = $this->event('project', $project->id, ['title_ckb' => 'ڕووداوی پڕۆژە']);

        $areaTimeline = $this->timelineOf($this->areaPage());
        $this->assertSame([$areaEvent->id], array_column($areaTimeline, 'id'), 'the area page shows only its own events');

        $projectTimeline = $this->timelineOf($this->projectPage());
        $this->assertSame([$projectEvent->id], array_column($projectTimeline, 'id'), 'the project page shows only its own events');

        $this->assertSame([], $this->timelineOf($this->areaPage('kasnazan')), "another area's page stays empty");
        $this->assertSame([], $this->timelineOf($this->projectPage('other-home')), "another project's page stays empty");
    }

    public function test_an_area_event_whose_id_collides_with_a_project_never_crosses_over(): void
    {
        // entity_id values can numerically collide across entity types; the
        // entity_type predicate is what keeps them apart.
        $area = $this->area();
        $project = $this->project();

        $this->event('area', $project->id, ['title_ckb' => 'ناوچەیی بە ژمارەی پڕۆژە']);

        $this->assertSame([], $this->timelineOf($this->projectPage()), 'an area event must never surface on a project page via a shared id');
    }

    /* ----------------------------------------------------- E. locale rules */

    public function test_each_locale_renders_its_own_text_with_the_house_fallback(): void
    {
        $area = $this->area();
        $this->event('area', $area->id);

        $this->assertSame('ارتفاع الأسعار', $this->timelineOf($this->areaPage('ankawa', '/ar'))[0]['title'], 'the /ar page renders the Arabic title');
        $this->assertSame('Prices rising', $this->timelineOf($this->areaPage('ankawa', '/en'))[0]['title'], 'the /en page renders the English title');

        // A ckb-only legacy event still answers every locale via the chain.
        KnowledgeEvent::query()->forceDelete();
        $this->event('area', $area->id, [
            'title_ar' => null, 'title_en' => null,
            'summary_ar' => null, 'summary_en' => null,
        ]);

        foreach (['/ar', '/en'] as $prefix) {
            $entry = $this->timelineOf($this->areaPage('ankawa', $prefix))[0];
            $this->assertSame('بەرزبوونەوەی نرخەکان', $entry['title'], "{$prefix} falls back to the Kurdish title, never blank");
        }
    }

    public function test_the_project_page_is_locale_aware_too(): void
    {
        $project = $this->project();
        $this->event('project', $project->id);

        $this->assertSame('Prices rising', $this->timelineOf($this->projectPage('timeline-home', '/en'))[0]['title']);
        $this->assertSame('ارتفاع الأسعار', $this->timelineOf($this->projectPage('timeline-home', '/ar'))[0]['title']);
    }

    public function test_the_evidence_class_labels_exist_in_all_three_locales(): void
    {
        foreach (['ckb', 'ar', 'en'] as $locale) {
            foreach (['verified_fact', 'admin_observation', 'market_interpretation', 'prediction'] as $class) {
                $key = 'knowledge.evidence_classes.'.$class;
                $this->assertNotSame($key, __($key, [], $locale), "{$key} must be translated in {$locale}");
            }
        }
    }

    /* ------------------------------------------------ F. legacy null class */

    public function test_a_legacy_null_class_ships_as_observation_never_fact(): void
    {
        $area = $this->area();
        $this->event('area', $area->id, ['evidence_class' => null]);

        $entry = $this->timelineOf($this->areaPage())[0];

        $this->assertSame('admin_observation', $entry['evidence_class']);
        $this->assertNotSame('verified_fact', $entry['evidence_class']);
    }

    /* -------------------------------------------------- contract details */

    public function test_the_timeline_is_bounded_and_newest_first(): void
    {
        $area = $this->area();

        foreach (range(1, 27) as $day) {
            $this->event('area', $area->id, [
                'effective_date' => sprintf('2026-07-%02d', min($day, 28)),
                'title_ckb' => 'ڕووداو '.$day,
            ]);
        }

        $timeline = $this->timelineOf($this->areaPage());

        $this->assertCount(25, $timeline, 'the area timeline carries the same bound as the project timeline');
        $this->assertTrue(
            collect($timeline)->pluck('effective_date')->every(
                fn (string $date, int $i): bool => $i === 0 || $date <= $timeline[$i - 1]['effective_date'],
            ),
            'entries are ordered newest first',
        );
    }

    public function test_a_prediction_entry_carries_its_expected_date(): void
    {
        $area = $this->area();
        $this->event('area', $area->id, [
            'evidence_class' => 'prediction',
            'expected_date' => '2026-12-01',
        ]);

        $entry = $this->timelineOf($this->areaPage())[0];

        $this->assertSame('prediction', $entry['evidence_class']);
        $this->assertSame('2026-12-01', $entry['expected_date']);
    }
}

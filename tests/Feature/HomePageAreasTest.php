<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public home page must render on every supported database.
 *
 * The featured-areas query filtered with `having('projects_count', '>', 0)` —
 * a condition on a correlated subquery alias with no GROUP BY. SQLite rejects
 * that outright ("HAVING clause on a non-aggregate query"), so the most public
 * page in the product returned a 500 on any SQLite deployment. Nothing caught
 * it because no test loaded the home page with an area present.
 */
final class HomePageAreasTest extends TestCase
{
    use RefreshDatabase;

    private function area(string $slug, PublicationStatus $status = PublicationStatus::Published): Area
    {
        return Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => $slug,
            'name_ckb' => $slug,
            'publication_status' => $status->value,
        ]);
    }

    private function project(Area $area, PublicationStatus $status): Project
    {
        return Project::query()->create([
            'slug' => 'p-'.uniqid(),
            'name_ckb' => 'پرۆژە',
            'area_id' => $area->id,
            'project_type' => 'residential',
            'construction_status' => ConstructionStatus::Planning->value,
            'delivery_status' => DeliveryStatus::cases()[0]->value,
            'publication_status' => $status->value,
        ]);
    }

    public function test_the_home_page_renders_with_featured_areas(): void
    {
        $area = $this->area('ankawa');
        $this->project($area, PublicationStatus::Published);

        $this->get('/')->assertSuccessful();
    }

    /** An area with no published project is not featured. */
    public function test_an_area_without_published_projects_is_not_featured(): void
    {
        $empty = $this->area('empty-district');
        $this->project($empty, PublicationStatus::Draft);

        $withProjects = $this->area('busy-district');
        $this->project($withProjects, PublicationStatus::Published);

        // `areas` carries has_data/linkable/items; the districts are in items.
        $this->get('/')->assertInertia(
            fn ($page) => $page->where(
                'areas.items',
                function ($items): bool {
                    /** @var list<array<string, mixed>> $items */
                    return collect($items)->pluck('slug')->all() === ['busy-district'];
                },
            ),
        );
    }

    /** The home page still renders when there is nothing to feature. */
    public function test_the_home_page_renders_with_no_areas_at_all(): void
    {
        $this->get('/')->assertSuccessful();
    }

    /** A soft-deleted project neither qualifies an area nor is counted. */
    public function test_soft_deleted_projects_do_not_qualify_or_count(): void
    {
        $area = $this->area('soft-deleted-only');
        $this->project($area, PublicationStatus::Published)->delete();

        $this->get('/')->assertInertia(
            fn ($page) => $page->where('areas.items', function ($items): bool {
                /** @var list<array<string, mixed>> $items */
                return collect($items)->isEmpty();
            }),
        );
    }

    /**
     * The displayed count describes the SAME set that earned the listing.
     *
     * The filter and the count share one constraint precisely so an area cannot
     * be listed on the strength of work a visitor may not see and then shown a
     * count that excludes it.
     */
    public function test_the_displayed_count_matches_the_eligible_projects(): void
    {
        $area = $this->area('counted');

        $this->project($area, PublicationStatus::Published);
        $this->project($area, PublicationStatus::Published);
        $this->project($area, PublicationStatus::Draft);
        $this->project($area, PublicationStatus::Published)->delete();

        $this->get('/')->assertInertia(
            fn ($page) => $page->where('areas.items', function ($items): bool {
                /** @var list<array<string, mixed>> $items */
                return count($items) === 1 && $items[0]['project_count'] === 2;
            }),
        );
    }

    /** Ordering follows the eligible count, highest first. */
    public function test_areas_are_ordered_by_their_eligible_project_count(): void
    {
        $few = $this->area('few');
        $this->project($few, PublicationStatus::Published);

        $many = $this->area('many');

        foreach (range(1, 3) as $ignored) {
            $this->project($many, PublicationStatus::Published);
        }

        $this->get('/')->assertInertia(
            fn ($page) => $page->where('areas.items', function ($items): bool {
                /** @var list<array<string, mixed>> $items */
                return collect($items)->pluck('slug')->all() === ['many', 'few'];
            }),
        );
    }

    /** An unpublished AREA is never featured, whatever it contains. */
    public function test_an_unpublished_area_is_never_featured(): void
    {
        $hidden = $this->area('in-review', PublicationStatus::Draft);
        $this->project($hidden, PublicationStatus::Published);

        $this->get('/')->assertInertia(
            fn ($page) => $page->where('areas.items', function ($items): bool {
                /** @var list<array<string, mixed>> $items */
                return collect($items)->isEmpty();
            }),
        );
    }
}

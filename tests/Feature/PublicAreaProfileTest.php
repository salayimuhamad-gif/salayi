<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public Area profiles (spec 10.2, 12.2).
 *
 * The behaviours worth protecting here are the ones that are invisible when
 * they break: an unpublished parent leaking through a child's breadcrumb, and
 * a project count that does not reconcile with the list beneath it. Both read
 * as working pages.
 */
final class PublicAreaProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures(['geography.areas' => true,
        ]);
    }

    public function test_the_directory_is_unreachable_when_the_flag_is_off(): void
    {
        $this->setFeatures(['geography.areas' => false,
        ]);

        // 404 rather than 403: a disabled public surface must not confirm its
        // own existence to an anonymous visitor.
        $this->get('/areas')->assertNotFound();
    }

    public function test_the_directory_lists_published_areas_grouped_by_type(): void
    {
        $this->makeArea('erbil', AreaType::City);
        $this->makeArea('ankawa', AreaType::District);

        $response = $this->get('/areas');

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page
            ->component('Public/Areas/Index')
            ->where('total', 2)
            ->has('groups', 2));
    }

    public function test_an_unpublished_area_is_absent_from_the_directory_and_404s_directly(): void
    {
        $this->makeArea('draft-area', AreaType::District, PublicationStatus::Draft);

        $this->get('/areas')->assertInertia(fn ($page) => $page->where('total', 0));

        $this->get('/areas/draft-area')->assertNotFound();
    }

    /**
     * The disclosure case. A published neighbourhood under an unpublished
     * district must not be reachable, because its breadcrumb would otherwise
     * name an area that is still in review.
     */
    public function test_a_published_area_under_an_unpublished_parent_is_not_reachable(): void
    {
        $parent = $this->makeArea('hidden-district', AreaType::District, PublicationStatus::Draft);
        $this->makeArea('visible-neighbourhood', AreaType::Neighborhood, PublicationStatus::Published, $parent);

        $this->get('/areas/visible-neighbourhood')->assertNotFound();

        $this->get('/areas')->assertInertia(fn ($page) => $page->where('total', 0));
    }

    public function test_the_breadcrumb_lists_ancestors_outermost_first(): void
    {
        $city = $this->makeArea('erbil', AreaType::City);
        $district = $this->makeArea('ankawa', AreaType::District, PublicationStatus::Published, $city);
        $this->makeArea('ankawa-north', AreaType::Neighborhood, PublicationStatus::Published, $district);

        $this->get('/areas/ankawa-north')->assertInertia(fn ($page) => $page
            ->component('Public/Areas/Show')
            ->has('breadcrumb', 2)
            ->where('breadcrumb.0.slug', 'erbil')
            ->where('breadcrumb.1.slug', 'ankawa'));
    }

    /**
     * The reconciliation guarantee: the listed projects are the direct ones,
     * and the subtree total is a separate figure. A district reporting its
     * descendants' projects as its own is a number no visitor can check.
     */
    public function test_direct_and_subtree_project_counts_are_reported_separately(): void
    {
        $district = $this->makeArea('ankawa', AreaType::District);
        $child = $this->makeArea('ankawa-north', AreaType::Neighborhood, PublicationStatus::Published, $district);

        $this->makeProject($district);
        $this->makeProject($child);
        $this->makeProject($child);

        $this->get('/areas/ankawa')->assertInertia(fn ($page) => $page
            ->where('projects.total', 1)
            ->where('subtree_project_count', 3));
    }

    public function test_unpublished_projects_are_excluded_from_an_area_profile(): void
    {
        $area = $this->makeArea('ankawa', AreaType::District);

        $this->makeProject($area);
        $this->makeProject($area, PublicationStatus::Draft);

        $this->get('/areas/ankawa')->assertInertia(fn ($page) => $page->where('projects.total', 1));
    }

    /**
     * An area with nothing in it must render, and say so. The alternative —
     * a 404 for a real published area — teaches a visitor that a link from
     * the directory cannot be trusted.
     */
    public function test_an_empty_area_renders_with_honest_empty_states(): void
    {
        $this->makeArea('quiet-area', AreaType::Neighborhood);

        $this->get('/areas/quiet-area')->assertInertia(fn ($page) => $page
            ->component('Public/Areas/Show')
            ->where('projects.total', 0)
            ->where('children', [])
            ->where('places', []));
    }

    /** Every locale resolves, and each declares itself canonical. */
    public function test_the_profile_resolves_in_every_enabled_locale(): void
    {
        $this->makeArea('ankawa', AreaType::District);

        $default = (string) config('localization.default', 'ckb');

        foreach (enabled_locales() as $locale) {
            $url = $locale === $default ? '/areas/ankawa' : '/'.$locale.'/areas/ankawa';

            $this->get($url)->assertSuccessful();
        }
    }

    public function test_an_unknown_slug_returns_404(): void
    {
        $this->get('/areas/no-such-area')->assertNotFound();
    }

    private function makeArea(
        string $slug,
        AreaType $type,
        PublicationStatus $status = PublicationStatus::Published,
        ?Area $parent = null,
    ): Area {
        return Area::query()->create([
            'parent_id' => $parent?->id,
            'type' => $type->value,
            'slug' => $slug,
            'name_ckb' => $slug,
            'publication_status' => $status->value,
        ]);
    }

    private function makeProject(Area $area, PublicationStatus $status = PublicationStatus::Published): Project
    {
        // NOT NULL with no default: see create_projects.
        return Project::query()->create([
            'area_id' => $area->id,
            'slug' => 'project-'.uniqid(),
            'name_ckb' => 'Project',
            'project_type' => 'residential',
            'construction_status' => 'under_construction',
            'delivery_status' => 'not_started',
            'publication_status' => $status->value,
        ]);
    }
}

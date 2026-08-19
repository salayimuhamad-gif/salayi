<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Projects\Enums\PublicationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public Place profiles (spec 10.3, 12.2, 32.2).
 *
 * The tests that matter most here are the negative ones. A place page that
 * renders correctly but also emits a surveyed personal mobile number looks
 * perfect in a browser and is a privacy incident; only an assertion that the
 * field is absent catches it.
 */
final class PublicPlaceProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setFeatures([
            'places.database' => true,
            'geography.areas' => true,
        ]);
    }

    public function test_the_directory_is_unreachable_when_the_flag_is_off(): void
    {
        $this->setFeatures(['places.database' => false,
        ]);

        $this->get('/places')->assertNotFound();
    }

    /**
     * Spec 32.2. The phone is encrypted at rest, hidden on the model, and never
     * requested by the controller — this asserts none of that quietly changed.
     */
    public function test_a_place_profile_never_exposes_a_phone_number(): void
    {
        $place = $this->makePlace('erbil-central-hospital');
        $place->setPhone('+9647501234567');
        $place->save();

        $response = $this->get('/places/erbil-central-hospital');

        $response->assertSuccessful();

        // The number itself, in every shape it could leak in.
        $response->assertDontSee('7501234567', false);
        $response->assertDontSee('+9647501234567', false);
        $response->assertDontSee('009647501234567', false);

        /*
         * v6 merge: the page legitimately carries translation VOCABULARY
         * containing the word "phone" (`phone_absent`, `phone_user_provided`
         * and friends are i18n keys shipped with the layout bundle), so a
         * blanket `assertDontSee('phone')` now fails on words rather than
         * on data. The guarantee that matters is that no phone VALUE and no
         * phone-bearing FIELD reaches the client, which is asserted
         * precisely: no digit run that could be a number, and no `phone`
         * key anywhere in the page props.
         */
        $this->assertDoesNotMatchRegularExpression(
            '/(?:\+?964|0)7\d{8,9}/',
            $response->getContent() ?: '',
            'a phone-shaped number reached the rendered page',
        );

        $response->assertInertia(fn ($page) => $page
            ->component('Public/Places/Show')
            ->missing('place.phone')
            ->missing('place.phone_encrypted'));

        $props = json_encode($response->viewData('page')['props'] ?? []);
        $this->assertIsString($props);
        $this->assertStringNotContainsString('7501234567', $props, 'the number reached the page props');
        $this->assertDoesNotMatchRegularExpression(
            '/"phone[a-z_]*"\s*:/i',
            $props,
            'a phone-bearing property was serialised into the page props',
        );
    }

    /**
     * `is_public` is a second gate, not a synonym for published. A place may be
     * reviewed and usable for amenity scoring while still not warranting its
     * own public page.
     */
    public function test_a_published_place_that_is_not_public_has_no_profile(): void
    {
        $this->makePlace('internal-depot', ['is_public' => false]);

        $this->get('/places/internal-depot')->assertNotFound();
        $this->get('/places')->assertInertia(fn ($page) => $page->where('places.total', 0));
    }

    public function test_an_unpublished_place_has_no_profile(): void
    {
        $this->makePlace('draft-place', ['publication_status' => PublicationStatus::Draft->value]);

        $this->get('/places/draft-place')->assertNotFound();
    }

    /** A deduplicated cluster resolves to exactly one public URL. */
    public function test_a_non_primary_duplicate_has_no_profile(): void
    {
        $this->makePlace('duplicate-copy', ['is_duplicate_primary' => false]);

        $this->get('/places/duplicate-copy')->assertNotFound();
    }

    /**
     * The same leak the Area breadcrumb check prevents, from the other side:
     * a published place must not name an area that is still in review.
     */
    public function test_a_place_in_an_unpublished_area_does_not_name_that_area(): void
    {
        $area = Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'hidden-district',
            'name_ckb' => 'Hidden District',
            'publication_status' => PublicationStatus::Draft->value,
        ]);

        $this->makePlace('a-place', ['area_id' => $area->id]);

        $response = $this->get('/places/a-place');

        $response->assertSuccessful();
        $response->assertDontSee('Hidden District', false);
        $response->assertInertia(fn ($page) => $page->where('area', null));
    }

    public function test_a_place_in_a_published_area_links_to_it(): void
    {
        $area = Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'ankawa',
            'name_ckb' => 'Ankawa',
            'publication_status' => PublicationStatus::Published->value,
        ]);

        $this->makePlace('ankawa-school', ['area_id' => $area->id]);

        $this->get('/places/ankawa-school')->assertInertia(fn ($page) => $page
            ->where('area.slug', 'ankawa'));
    }

    /**
     * An unknown category filter must return nothing and say so. Silently
     * ignoring it shows the full list to somebody who believes it is filtered.
     */
    public function test_an_unknown_category_filter_is_reported_not_ignored(): void
    {
        $this->makePlace('a-school');

        $this->get('/places?category=not-a-category')->assertInertia(fn ($page) => $page
            ->where('filters.unknown_category', true)
            ->where('places.total', 0));
    }

    public function test_a_known_category_filters_the_directory(): void
    {
        // place_categories.group is NOT NULL.
        $schools = PlaceCategory::query()->create(['key' => 'school', 'group' => 'education', 'name_ckb' => 'School']);
        $parks = PlaceCategory::query()->create(['key' => 'park', 'group' => 'leisure', 'name_ckb' => 'Park']);

        $this->makePlace('a-school', ['place_category_id' => $schools->id]);
        $this->makePlace('a-park', ['place_category_id' => $parks->id]);

        $this->get('/places?category=school')->assertInertia(fn ($page) => $page
            ->where('filters.unknown_category', false)
            ->where('places.total', 1));
    }

    public function test_the_directory_renders_an_empty_state_when_nothing_is_published(): void
    {
        $this->get('/places')->assertInertia(fn ($page) => $page
            ->component('Public/Places/Index')
            ->where('places.total', 0));
    }

    public function test_nearby_lists_only_other_public_places_in_the_same_area(): void
    {
        $area = Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'ankawa',
            'name_ckb' => 'Ankawa',
            'publication_status' => PublicationStatus::Published->value,
        ]);

        $this->makePlace('subject', ['area_id' => $area->id]);
        $this->makePlace('neighbour', ['area_id' => $area->id]);
        $this->makePlace('private-neighbour', ['area_id' => $area->id, 'is_public' => false]);
        $this->makePlace('elsewhere');

        $this->get('/places/subject')->assertInertia(fn ($page) => $page
            ->has('nearby', 1)
            ->where('nearby.0.slug', 'neighbour'));
    }

    public function test_the_profile_resolves_in_every_enabled_locale(): void
    {
        $this->makePlace('ankawa-school');

        $default = (string) config('localization.default', 'ckb');

        foreach (enabled_locales() as $locale) {
            $url = $locale === $default ? '/places/ankawa-school' : '/'.$locale.'/places/ankawa-school';

            $this->get($url)->assertSuccessful();
        }
    }

    public function test_an_unknown_slug_returns_404(): void
    {
        $this->get('/places/no-such-place')->assertNotFound();
    }

    private function defaultCategory(): PlaceCategory
    {
        return PlaceCategory::query()->firstOrCreate(
            ['key' => 'fixture-category'],
            ['group' => 'other', 'name_ckb' => 'Fixture', 'is_active' => true],
        );
    }

    /** @param  array<model-property<Place>, mixed>  $attributes */
    private function makePlace(string $slug, array $attributes = []): Place
    {
        // places.place_category_id is a NOT NULL foreign key, and places
        // requires latitude/longitude — both omitted before.
        return Place::query()->create(array_merge([
            'slug' => $slug,
            'name_ckb' => $slug,
            'place_category_id' => $this->defaultCategory()->id,
            'latitude' => 36.1901,
            'longitude' => 44.0091,
            'publication_status' => PublicationStatus::Published->value,
            'is_public' => true,
            'is_duplicate_primary' => true,
            'operational_status' => 'operating',
        ], $attributes));
    }
}

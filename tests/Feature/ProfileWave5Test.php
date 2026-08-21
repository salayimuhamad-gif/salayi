<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Geography\Models\Area;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\ProfileLocationOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Wave 5 profile contract (§18): the grouped page exposes exactly the
 * fields the account already holds, edits them under the SAME rules the
 * onboarding screen established, keeps the residence canonical, and states
 * every verification claim independently at its true strength.
 */
final class ProfileWave5Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // setPhone() builds the keyed blind index and refuses, loudly, to
        // run unkeyed — the same test key the other phone-flow suites use.
        if ((string) config('mulkihawler.security.blind_index_key', '') === '') {
            config([
                'mulkihawler.security.blind_index_key' => str_repeat('a', 64),
            ]);
        }
    }

    /** A member who has completed the Telegram link — the profile's gate. */
    private function member(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['telegram_verified_at' => now()])->save();

        return $user;
    }

    /** @param array<model-property<Area>, mixed> $overrides */
    private function area(string $slug, array $overrides = []): Area
    {
        return Area::query()->create($overrides + [
            'type' => 'district',
            'slug' => $slug,
            'name_ckb' => 'ناوچە '.$slug,
            'publication_status' => 'published',
        ]);
    }

    /** @return array<string, mixed> */
    private function validPayload(User $user): array
    {
        return [
            'name' => $user->name,
            'preferred_locale' => 'ckb',
        ];
    }

    /* ------------------------------------------------------------------ */

    public function test_a_member_sees_their_own_profile_and_a_guest_does_not(): void
    {
        $this->get('/account/profile')->assertRedirect();

        $user = $this->member();
        $user->forceFill(['email' => 'me@example.test', 'gender' => 'female'])->save();

        $this->actingAs($user)
            ->get('/account/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Account/Profile')
                ->where('profile.name', $user->name)
                ->where('profile.email', 'me@example.test')
                ->where('profile.gender', 'female'));
    }

    public function test_the_page_carries_only_the_session_owners_data(): void
    {
        $other = $this->member();
        $other->forceFill(['email' => 'other@example.test', 'profile_bio' => 'THE-OTHER-BIO'])->save();

        $me = $this->member();

        // There is no id parameter to tamper with: the route reads the
        // session and nothing else, so the other account's details cannot
        // appear no matter what is requested.
        $this->actingAs($me)
            ->get('/account/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('profile.email', $me->email)
                ->where('profile.profile_bio', null))
            ->assertDontSee('other@example.test')
            ->assertDontSee('THE-OTHER-BIO');
    }

    public function test_the_existing_editable_fields_save_under_the_onboarding_rules(): void
    {
        $user = $this->member();
        $area = $this->area('pw5-ankawa');

        $this->actingAs($user)
            ->put('/account/profile', [
                'name' => 'Shene Karim',
                'display_name' => 'Shene',
                'preferred_locale' => 'en',
                'email' => 'shene@example.test',
                'gender' => 'female',
                'date_of_birth' => '1990-04-15',
                'profile_area_id' => $area->id,
                'profile_bio' => 'Erbil.',
                'contact_preference' => 'telegram',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $user->refresh();
        $this->assertSame('Shene Karim', $fresh->name);
        $this->assertSame('Shene', $fresh->display_name);
        $this->assertSame('en', $fresh->preferred_locale);
        $this->assertSame('shene@example.test', $fresh->email);
        $this->assertSame('female', $fresh->gender);
        $this->assertSame('1990-04-15', $fresh->date_of_birth?->toDateString());
        $this->assertSame($area->id, $fresh->profile_area_id);
        $this->assertSame('telegram', $fresh->contact_preference);
    }

    public function test_refused_input_changes_nothing_and_names_the_field(): void
    {
        $user = $this->member();
        $user->forceFill(['gender' => 'female', 'email' => 'kept@example.test'])->save();

        $taken = $this->member();
        $taken->forceFill(['email' => 'taken@example.test'])->save();

        // A vocabulary the platform does not offer, a birth date implying a
        // ten-year-old, and another account's email — each refused with its
        // own field error, and the row keeps every stored value.
        $this->actingAs($user)
            ->put('/account/profile', $this->validPayload($user) + [
                'gender' => 'dragon',
                'date_of_birth' => now()->subYears(10)->toDateString(),
                'email' => 'taken@example.test',
            ])
            ->assertSessionHasErrors(['gender', 'date_of_birth', 'email']);

        $fresh = $user->refresh();
        $this->assertSame('female', $fresh->gender);
        $this->assertSame('kept@example.test', $fresh->email);
    }

    public function test_the_residence_stays_one_canonical_column(): void
    {
        $user = $this->member();

        // The hierarchy the model itself maintains: the saving hook derives
        // depth and the materialised path from `parent_id`, so the test
        // builds the tree exactly as the Geography admin would.
        $city = $this->area('pw5-erbil', ['type' => 'city']);
        $district = $this->area('pw5-kasnazan', ['parent_id' => $city->id]);

        $this->actingAs($user)
            ->put('/account/profile', $this->validPayload($user) + [
                'profile_area_id' => $district->id,
            ])
            ->assertSessionHasNoErrors();

        // ONE stored fact — the finest choice made — and the city DERIVED
        // from the hierarchy, never stored beside it. The schema itself must
        // hold no second residence representation.
        $this->assertSame($district->id, $user->refresh()->profile_area_id);
        $this->assertFalse(Schema::hasColumn('users', 'profile_city_id'));
        $this->assertSame($city->id, ProfileLocationOptions::cityIdFor($district->id));

        // The page's derived city matches the same rule.
        $this->actingAs($user)
            ->get('/account/profile')
            ->assertInertia(fn ($page) => $page
                ->where('profile_city_id', $city->id)
                ->where('profile.profile_area_id', $district->id));
    }

    public function test_a_draft_area_is_refused_exactly_as_onboarding_refuses_it(): void
    {
        $user = $this->member();
        $draft = $this->area('pw5-draft', ['publication_status' => 'draft']);

        $this->actingAs($user)
            ->put('/account/profile', $this->validPayload($user) + [
                'profile_area_id' => $draft->id,
            ])
            ->assertSessionHasErrors(['profile_area_id']);

        $this->assertNull($user->refresh()->profile_area_id);
    }

    public function test_verification_claims_are_presented_independently(): void
    {
        $user = $this->member(); // Telegram linked, nothing else.

        $this->actingAs($user)
            ->get('/account/profile')
            ->assertInertia(fn ($page) => $page
                ->where('verification.telegram_linked', true)
                ->where('verification.whatsapp_linked', false)
                ->where('verification.phone_verified', false));

        // A WhatsApp-verified account makes the OPPOSITE claim pattern —
        // neither channel ever borrows the other's proof.
        $whatsapp = User::factory()->create();
        $whatsapp->forceFill(['whatsapp_verified_at' => now(), 'phone_verified' => 1])->save();

        $this->actingAs($whatsapp)
            ->get('/account/profile')
            ->assertInertia(fn ($page) => $page
                ->where('verification.telegram_linked', false)
                ->where('verification.whatsapp_linked', true)
                ->where('verification.phone_verified', true));
    }

    public function test_every_new_label_exists_in_all_three_locales(): void
    {
        $keys = [
            'identity.profile.section_identity',
            'identity.profile.section_contact',
            'identity.profile.section_residence',
            'identity.profile.section_verification',
            'identity.profile.verification_intro',
            'identity.profile.status_whatsapp_linked',
            'identity.profile.status_not_linked',
            'portfolio.summary.title',
            'portfolio.summary.coverage',
            'portfolio.summary.no_valuations',
            'portfolio.summary.multi_currency_note',
            'portfolio.history_trend',
            'portfolio.history_trend_hint',
        ];

        foreach (['ckb', 'ar', 'en'] as $locale) {
            foreach ($keys as $key) {
                $this->assertNotSame(
                    $key,
                    __($key, [], $locale),
                    "missing translation: {$key} [{$locale}]",
                );
            }
        }
    }

    public function test_sensitive_material_never_reaches_the_page(): void
    {
        $user = $this->member();
        $user->setPhone('+9647701234567');
        $user->forceFill(['phone_verified' => 0])->save();

        $this->actingAs($user)
            ->get('/account/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Masked: enough to recognise, never the whole number.
                ->where('profile.phone', fn ($value): bool => is_string($value)
                    && str_contains($value, '•••')
                    && ! str_contains($value, '1234567'))
                ->missing('profile.phone_index')
                ->missing('profile.password')
                ->missing('profile.telegram_id'))
            // The raw number and the blind index exist ONLY encrypted /
            // hashed server-side; the HTML must carry neither.
            ->assertDontSee('+9647701234567')
            ->assertDontSee($user->refresh()->phone_index ?? 'never-null-here');
    }
}

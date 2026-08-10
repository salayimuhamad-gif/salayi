<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Leads\Models\PhoneReveal;
use App\Modules\Operations\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Direct phone visibility on Admin -> Users — the DELIBERATE product-policy
 * change, pinned from both sides.
 *
 * The new side: an administrator holding `identity.users.contact` sees the
 * plaintext number in the list and detail payloads with no reveal button,
 * reason, note, or consent gate, and the access is audited per page render
 * with row counts only. The unchanged side: everyone without the permission
 * still receives not one digit; the CSV export still carries availability
 * only; and the Sales/Leads workspace still runs the full PhoneRevealService
 * ceremony — permission, consent, reason, rate, ledger, audit.
 */
final class AdminUsersPhoneVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd#2026';

    private const PHONE = '07501234567';

    /** Every digit form no unauthorized payload may carry. */
    private const PHONE_FORMS = ['07501234567', '+9647501234567', '9647501234567', '1234567'];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        if ((string) config('mulkihawler.security.blind_index_key', '') === '') {
            config([
                'mulkihawler.security.blind_index_key' => str_repeat('a', 64),
                'mulkihawler.security.pii_key' => str_repeat('b', 64),
            ]);
        }

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /** A real member, registered through the real form. */
    private function member(bool $consentContact = false): User
    {
        $this->post('/register', [
            'name' => 'Member Person',
            'phone' => self::PHONE,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'locale' => 'ckb',
            'accept_terms' => true,
            'consent_contact' => $consentContact,
        ])->assertRedirect();
        $this->post('/logout');

        return User::query()->where('name', 'Member Person')->firstOrFail();
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function operator(RoleKey $key): User
    {
        $user = User::factory()->create();

        $user->roles()->attach(Role::query()->firstOrCreate(
            ['key' => $key->value],
            ['name' => $key->value, 'is_system' => true],
        ));

        return $user;
    }

    /* -------------------------------------------- direct display (1, 2, 3) */

    public function test_an_authorized_admin_sees_the_plaintext_phone_directly_with_no_ceremony(): void
    {
        // consent deliberately ABSENT: the account-management surface does
        // not require it — that is the policy change, stated as a test.
        $member = $this->member(consentContact: false);

        $response = $this->actingAs($this->admin())->get('/admin/users')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('users.data.0.id', $member->id)
            ->where('users.data.0.phone', $member->phone())
            ->where('can_view_phone', true));

        // The number is a plain LTR digit string — nothing to mangle in an
        // RTL layout, no direction marks smuggled in; the template adds
        // dir="ltr" on top.
        $phone = $response->inertiaPage()['props']['users']['data'][0]['phone'];
        $this->assertMatchesRegularExpression('/^\+?\d+$/', (string) $phone);
        $this->assertStringContainsString('7501234567', (string) $phone);

        // No reveal reason was submitted, and no reveal ledger row was
        // needed to render the page.
        $this->assertSame(0, PhoneReveal::query()->count());
    }

    public function test_the_detail_page_follows_the_same_authorization_rule(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin())->get("/admin/users/{$member->id}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn ($page) => $page
                ->where('account.phone', $member->phone())
                ->where('can_view_phone', true));

        // Support staff hold identity.users.view but not the contact
        // capability: same page, null phone.
        $response = $this->actingAs($this->operator(RoleKey::SupportAgent))
            ->get("/admin/users/{$member->id}")
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('account.phone', null)
            ->where('can_view_phone', false));

        $serialized = json_encode($response->inertiaPage()['props']['account'], JSON_THROW_ON_ERROR);

        foreach (self::PHONE_FORMS as $form) {
            $this->assertStringNotContainsString($form, $serialized);
        }
    }

    /* --------------------------------------------- refusals (4, 5, 6) */

    public function test_an_admin_without_the_contact_permission_receives_no_digits_anywhere(): void
    {
        $this->member(consentContact: true);

        $response = $this->actingAs($this->operator(RoleKey::SupportAgent))
            ->get('/admin/users')
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('users.data.0.phone', null)
            ->where('can_view_phone', false));

        $serialized = json_encode($response->inertiaPage()['props']['users'], JSON_THROW_ON_ERROR);

        foreach (self::PHONE_FORMS as $form) {
            $this->assertStringNotContainsString($form, $serialized);
        }
    }

    public function test_ordinary_members_and_guests_never_reach_the_surface(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get('/admin/users')->assertForbidden();

        $this->post('/logout');
        $this->get('/admin/users')->assertRedirect();
    }

    /* ------------------------------------------------ audit strategy (9) */

    public function test_the_page_access_is_audited_once_with_safe_metadata_only(): void
    {
        $this->member();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/users')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $entries = AuditLog::query()->where('action', 'identity.users.sensitive_data_viewed')->get();

        // One record per page render — never one per visible row.
        $this->assertCount(1, $entries);
        $this->assertSame($admin->id, $entries[0]->context['actor_id'] ?? null);
        $this->assertSame('index', $entries[0]->context['page'] ?? null);
        $this->assertSame(1, $entries[0]->context['rows'] ?? null);

        // Never a number, an encrypted value, or a blind index.
        $serialized = json_encode([$entries[0]->context, $entries[0]->changes], JSON_THROW_ON_ERROR);
        foreach (self::PHONE_FORMS as $form) {
            $this->assertStringNotContainsString($form, $serialized);
        }
        $this->assertStringNotContainsString('phone_encrypted', $serialized);
        $this->assertStringNotContainsString('phone_index', $serialized);

        // An Inertia partial reload is a refresh, not a second viewing.
        $this->actingAs($admin)->get('/admin/users', [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Admin/Users/Index',
            'X-Inertia-Partial-Data' => 'users',
            'X-Inertia-Version' => '',
        ]);
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'identity.users.sensitive_data_viewed')->count(),
        );

        // A viewer without the capability saw no sensitive data — no record.
        $this->actingAs($this->operator(RoleKey::SupportAgent))->get('/admin/users')->assertOk();
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'identity.users.sensitive_data_viewed')->count(),
        );
    }

    /* ------------------------------------------------------ CSV export (8) */

    public function test_the_export_still_excludes_plaintext_phones(): void
    {
        $this->member(consentContact: true);

        $csv = $this->actingAs($this->admin())
            ->get('/admin/users/export')
            ->assertOk()
            ->streamedContent();

        foreach (self::PHONE_FORMS as $form) {
            $this->assertStringNotContainsString(
                $form,
                $csv,
                'the sheet must stay availability-only: a page-view capability must not become bulk extraction',
            );
        }

        $this->assertStringContainsString('available_reveal_permitted', $csv);
    }

    /* --------------------------------------- the Leads ceremony (10, 11) */

    public function test_the_sales_workspace_reveal_ceremony_is_unchanged(): void
    {
        $member = $this->member(consentContact: true);
        $lead = DemandProfile::query()->create([
            'user_id' => $member->id,
            'preferred_locale' => 'ckb',
            'objective' => 'residence',
            'property_type' => 'apartment',
            'stage' => 'new',
            'source' => 'advisor',
        ]);

        $sales = $this->operator(RoleKey::SalesManager);

        // A reason is still required…
        $this->actingAs($sales)
            ->postJson("/admin/leads/{$lead->id}/phone", [])
            ->assertStatus(422);
        $this->assertSame(0, PhoneReveal::query()->count());

        // …and a reasoned, consented reveal still opens the one door, with
        // its ledger row and audit record.
        $response = $this->actingAs($sales)
            ->postJson("/admin/leads/{$lead->id}/phone", ['reason' => 'callback_requested'])
            ->assertOk()
            ->json();

        $this->assertTrue($response['ok']);
        $this->assertStringContainsString('7501234567', (string) $response['phone']);
        $this->assertSame(1, PhoneReveal::query()->count());
        $this->assertTrue(
            AuditLog::query()->where('action', 'leads.phone_revealed')->exists(),
        );
    }

    public function test_the_sales_reveal_still_refuses_without_consent(): void
    {
        $member = $this->member(consentContact: false);
        $lead = DemandProfile::query()->create([
            'user_id' => $member->id,
            'preferred_locale' => 'ckb',
            'objective' => 'residence',
            'property_type' => 'apartment',
            'stage' => 'new',
            'source' => 'advisor',
        ]);

        $response = $this->actingAs($this->operator(RoleKey::SalesManager))
            ->postJson("/admin/leads/{$lead->id}/phone", ['reason' => 'callback_requested'])
            ->assertStatus(422)
            ->json();

        $this->assertFalse($response['ok']);
        $this->assertArrayNotHasKey('phone', $response);
        $this->assertSame(0, PhoneReveal::query()->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Leads\Models\PhoneReveal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Admin Users surface's privacy contract, pinned — as AMENDED by the
 * direct-visibility product decision.
 *
 * The contract, restated:
 *
 *   - the LIST carries the plaintext number in exactly ONE field, `phone`,
 *     and only for actors holding identity.users.contact; for everyone
 *     else that field is null and no digit appears anywhere in the payload
 *     (AdminUsersPhoneVisibilityTest owns the positive side);
 *   - operator/role accounts are not part of this surface and answer 404
 *     everywhere on it — indistinguishable from ids that do not exist;
 *   - every path is permission-checked on the server.
 */
final class AdminUsersPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd#2026';

    private const PHONE = '07501234567';

    /** Every digit form the payload must never contain, full or masked. */
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
    private function member(bool $consentContact): User
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

    /* -------------------------------------------------------- the list */

    public function test_the_list_payload_is_exactly_the_safe_field_set(): void
    {
        $member = $this->member(consentContact: true);

        $response = $this->actingAs($this->admin())->get('/admin/users')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('users.data.0.id', $member->id)
            ->where('users.data.0.phone_present', true)
            ->where('users.data.0.phone_status', 'user_provided')
            ->where('users.data.0.telegram_linked', false));

        // The exact serialized keys, pinned. A new field must be added HERE,
        // deliberately, where this contract is stated — not slipped into the
        // payload because a mockup showed it.
        $row = $response->inertiaPage()['props']['users']['data'][0];
        $this->assertSame([
            'id', 'name', 'display_name', 'thumb', 'photo', 'initials',
            'preferred_locale', 'is_suspended', 'telegram_linked',
            /*
             * `phone` is the DELIBERATE product-policy addition: plaintext
             * for identity.users.contact holders, null for everyone else,
             * decrypted only after that check. It is the single field
             * allowed to carry a digit, which the conditional digit-scan
             * below pins from the unauthorized side.
             */
            'telegram_linked_at', 'phone_present', 'phone_status', 'phone',
            'registered_at', 'last_login_at', 'last_seen_at', 'online',
            'advisor_request_count', 'portfolio_count',
            /*
             * The workspace additions, stated deliberately: the consent BIT
             * (kept for the workspace's follow-up context) and the latest
             * advisor request's four follow-up fields.
             */
            'contact_consent', 'latest_request',
        ], array_keys($row));
    }

    public function test_no_phone_digits_reach_an_actor_without_the_contact_permission(): void
    {
        $this->member(consentContact: true);

        // Support staff hold identity.users.view and deliberately NOT
        // identity.users.contact — the exact actor the amended policy still
        // owes zero digits to, in any form, full or masked.
        $support = User::factory()->create();
        $support->roles()->attach(Role::query()->firstOrCreate(
            ['key' => RoleKey::SupportAgent->value],
            ['name' => 'support_agent', 'is_system' => true],
        ));

        $response = $this->actingAs($support)->get('/admin/users')->assertOk();

        // The users prop specifically: the rest of the page props carry
        // hashes and version strings where any 7-digit run could occur by
        // chance, and a false alarm teaches people to ignore this test.
        $serialized = json_encode($response->inertiaPage()['props']['users'], JSON_THROW_ON_ERROR);

        foreach (self::PHONE_FORMS as $form) {
            $this->assertStringNotContainsString(
                $form,
                $serialized,
                'Without identity.users.contact the list must not carry phone digits in any form.',
            );
        }
    }

    public function test_the_list_is_permission_gated_server_side(): void
    {
        // A real operator role that simply does not hold identity.users.view.
        $this->actingAs(User::factory()->projectEditor()->create())
            ->get('/admin/users')
            ->assertForbidden();
    }

    /* ------------------------------------------------- non-enumeration */

    public function test_role_holders_are_absent_from_the_list_and_404_everywhere(): void
    {
        $operator = User::factory()->projectEditor()->create();
        $this->member(consentContact: true);
        $admin = $this->admin();

        // Absent from the listing…
        $this->actingAs($admin)->get('/admin/users')->assertInertia(
            fn ($page) => $page->missing('users.data.1'),
        );

        // …and indistinguishable from a nonexistent id on every route.
        $this->actingAs($admin)->get("/admin/users/{$operator->id}")->assertNotFound();
        $this->actingAs($admin)
            ->post("/admin/users/{$operator->id}/suspend", ['reason' => 'because'])
            ->assertNotFound();
        $this->actingAs($admin)
            ->post("/admin/users/{$operator->id}/logout")
            ->assertNotFound();
    }

    /* ------------------------------------------------- no reveal endpoint */

    public function test_the_users_reveal_endpoint_is_gone_the_leads_ceremony_is_not(): void
    {
        // The accounts surface shows the number directly now; its old reveal
        // endpoint must not linger as a second, ceremonial door.
        $member = $this->member(consentContact: true);

        $this->actingAs($this->admin())
            ->postJson("/admin/users/{$member->id}/phone", ['reason' => 'callback_requested'])
            ->assertNotFound();

        $this->assertSame(0, PhoneReveal::query()->count());
    }

    /* ---------------------------------------------- suspend / reactivate */

    public function test_suspension_requires_a_reason_is_audited_and_reversible(): void
    {
        $member = $this->member(consentContact: true);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post("/admin/users/{$member->id}/suspend", [])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->post("/admin/users/{$member->id}/suspend", ['reason' => 'abuse of the offer form'])
            ->assertRedirect();

        $this->assertNotNull($member->refresh()->suspended_at);
        $this->assertTrue(
            DB::table('audit_logs')->where('action', 'identity.user_suspended')->exists(),
        );

        $this->actingAs($admin)
            ->post("/admin/users/{$member->id}/reactivate")
            ->assertRedirect();

        $this->assertNull($member->refresh()->suspended_at);
    }

    public function test_an_admin_cannot_suspend_their_own_account(): void
    {
        $admin = $this->admin();

        // A super admin holds a role, so the role-holder 404 answers first —
        // which is itself the property worth pinning: the self-target 422 can
        // only ever be seen for a role-less actor, and no role-less actor
        // reaches this route. The refusal ordering is deliberate.
        $this->actingAs($admin)
            ->post("/admin/users/{$admin->id}/suspend", ['reason' => 'testing self-target'])
            ->assertNotFound();

        $this->assertNull($admin->refresh()->suspended_at);
    }
}

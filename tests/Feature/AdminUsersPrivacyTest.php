<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Models\User;
use App\Modules\Leads\Models\PhoneReveal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Admin Users surface's privacy contract, pinned.
 *
 * These behaviours all existed and none were tested — which is the state in
 * which a mockup asking for "masked phone digits in the list" gets
 * implemented without anyone noticing what was lost. The contract, stated:
 *
 *   - the LIST carries phone presence as a boolean, never digits in any
 *     form, full or masked;
 *   - contact access goes through the audited, consent-gated reveal
 *     ceremony, one number at a time, with a reason;
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
            'telegram_linked_at', 'phone_present', 'phone_status',
            'registered_at', 'last_login_at', 'last_seen_at', 'online',
            'advisor_request_count', 'portfolio_count',
            /*
             * The workspace additions, stated deliberately: the consent BIT
             * (whether the reveal ceremony may even be offered — never the
             * consent record), and the latest advisor request's four
             * follow-up fields. Neither carries a digit of phone material,
             * which the digit-scan test below verifies against the whole
             * serialized payload.
             */
            'contact_consent', 'latest_request',
        ], array_keys($row));
    }

    public function test_no_phone_digits_appear_anywhere_in_the_list_response(): void
    {
        $this->member(consentContact: true);

        $response = $this->actingAs($this->admin())->get('/admin/users')->assertOk();

        // The users prop specifically: the rest of the page props carry
        // hashes and version strings where any 7-digit run could occur by
        // chance, and a false alarm teaches people to ignore this test.
        $serialized = json_encode($response->inertiaPage()['props']['users'], JSON_THROW_ON_ERROR);

        foreach (self::PHONE_FORMS as $form) {
            $this->assertStringNotContainsString(
                $form,
                $serialized,
                'The users list must not carry phone digits in any form — full or masked.',
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
            ->post("/admin/users/{$operator->id}/phone", ['reason' => 'callback_requested'])
            ->assertNotFound();
    }

    /* ------------------------------------------------------- the reveal */

    public function test_the_reveal_requires_a_reason(): void
    {
        $member = $this->member(consentContact: true);

        $this->actingAs($this->admin())
            ->postJson("/admin/users/{$member->id}/phone", [])
            ->assertStatus(422);

        $this->assertSame(0, PhoneReveal::query()->count());
    }

    public function test_the_reveal_is_refused_without_contact_consent(): void
    {
        $member = $this->member(consentContact: false);

        $response = $this->actingAs($this->admin())
            ->postJson("/admin/users/{$member->id}/phone", ['reason' => 'callback_requested'])
            ->assertStatus(422)
            ->json();

        $this->assertFalse($response['ok']);
        $this->assertArrayNotHasKey('phone', $response);
        $this->assertSame(0, PhoneReveal::query()->count());
    }

    public function test_a_consented_reveal_returns_the_number_with_ledger_audit_and_no_store(): void
    {
        $member = $this->member(consentContact: true);
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->postJson("/admin/users/{$member->id}/phone", ['reason' => 'callback_requested'])
            ->assertOk()
            // Symfony normalizes the directives and appends `private`; what
            // matters is that no-store survives into the wire header.
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $json = $response->json();
        $this->assertTrue($json['ok']);
        $this->assertStringContainsString('7501234567', (string) $json['phone']);

        // The ledger row names the actor and the subject; the number itself
        // is never stored.
        $reveal = PhoneReveal::query()->firstOrFail();
        $this->assertSame($admin->id, $reveal->user_id);
        $this->assertSame($member->id, (int) $reveal->subject_id);

        $this->assertTrue(
            DB::table('audit_logs')->where('action', 'leads.phone_revealed')->exists(),
            'A reveal must leave an audit record.',
        );
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

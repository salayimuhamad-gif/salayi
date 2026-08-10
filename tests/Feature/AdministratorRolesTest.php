<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The operators surface — the roles machinery the accounts surface defers to.
 *
 * Before this surface existed, identity.roles.view and identity.roles.assign
 * sat in the registry authorising nothing: no page listed operators, and no
 * action could change an assignment. These tests pin the machinery AND its
 * boundaries — who may grant what, what may never be removed, and which
 * population each surface answers for.
 */
final class AdministratorRolesTest extends TestCase
{
    use RefreshDatabase;

    private function role(RoleKey $key): Role
    {
        return Role::query()->firstOrCreate(
            ['key' => $key->value],
            ['name' => $key->value, 'is_system' => true],
        );
    }

    private function operator(RoleKey $key): User
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->role($key));

        return $user;
    }

    // ------------------------------------------------------------ the list

    public function test_the_index_lists_operators_and_never_members(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $operator = $this->operator(RoleKey::ContentEditor);
        $member = User::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/administrators')->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Administrators/Index')
            ->where('administrators.data', fn (Collection $rows): bool => $rows->pluck('id')->contains($operator->id)
                && ! $rows->pluck('id')->contains($member->id)));
    }

    public function test_the_row_payload_is_pinned_and_phone_free(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $rows = $this->actingAs($admin)->get('/admin/administrators')
            ->assertOk()
            ->viewData('page')['props']['administrators']['data'];

        // The exact serialized keys, pinned. This surface answers "who can do
        // what" — a phone or contact field appearing here must fail the build.
        $this->assertSame([
            'id', 'name', 'initials', 'roles', 'is_super_admin', 'is_suspended',
            'suspended_reason', 'mfa_enabled', 'telegram_linked',
            'registered_at', 'last_login_at', 'last_seen_at', 'online',
        ], array_keys($rows[0]));
    }

    public function test_the_index_requires_the_roles_view_permission(): void
    {
        $this->actingAs(User::factory()->projectEditor()->create())
            ->get('/admin/administrators')
            ->assertForbidden();
    }

    // ------------------------------------------------------- assigning roles

    public function test_a_super_admin_promotes_a_member_to_operator(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)
            ->put("/admin/administrators/{$member->id}/roles", ['roles' => [RoleKey::ContentEditor->value]])
            ->assertRedirect(route('admin.administrators.index'));

        $this->assertSame([RoleKey::ContentEditor->value], $member->fresh()->roles()->pluck('key')->all());
        $this->assertTrue(
            AuditLog::query()->where('action', 'identity.roles.updated')->exists(),
        );
    }

    public function test_a_system_admin_may_grant_ordinary_roles(): void
    {
        $actor = $this->operator(RoleKey::SystemAdmin);
        $target = $this->operator(RoleKey::ContentEditor);

        $this->actingAs($actor)
            ->put("/admin/administrators/{$target->id}/roles", [
                'roles' => [RoleKey::ContentEditor->value, RoleKey::TranslationReviewer->value],
            ])
            ->assertRedirect(route('admin.administrators.index'));

        $this->assertEqualsCanonicalizing(
            [RoleKey::ContentEditor->value, RoleKey::TranslationReviewer->value],
            $target->fresh()->roles()->pluck('key')->all(),
        );
    }

    public function test_a_system_admin_cannot_grant_super_admin(): void
    {
        $actor = $this->operator(RoleKey::SystemAdmin);
        $target = $this->operator(RoleKey::ContentEditor);

        $this->actingAs($actor)
            ->put("/admin/administrators/{$target->id}/roles", ['roles' => [RoleKey::SuperAdmin->value]])
            ->assertForbidden();

        $this->assertSame([RoleKey::ContentEditor->value], $target->fresh()->roles()->pluck('key')->all());
        $this->assertTrue(
            AuditLog::query()->where('action', 'identity.roles.escalation_denied')->exists(),
            'the refused escalation must leave a security audit record',
        );
    }

    public function test_a_system_admin_cannot_edit_a_super_admin_at_all(): void
    {
        $actor = $this->operator(RoleKey::SystemAdmin);
        $target = User::factory()->superAdmin()->create();

        $this->actingAs($actor)
            ->put("/admin/administrators/{$target->id}/roles", ['roles' => [RoleKey::ContentEditor->value]])
            ->assertForbidden();

        $this->assertTrue($target->fresh()->isSuperAdmin());
    }

    public function test_removing_the_last_active_super_admin_role_is_refused(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->put("/admin/administrators/{$admin->id}/roles", ['roles' => [RoleKey::SystemAdmin->value]])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($admin->fresh()->isSuperAdmin());
    }

    public function test_removing_super_admin_is_allowed_when_another_active_one_exists(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $second = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->put("/admin/administrators/{$second->id}/roles", ['roles' => [RoleKey::SystemAdmin->value]])
            ->assertRedirect(route('admin.administrators.index'));

        $this->assertFalse($second->fresh()->isSuperAdmin());
    }

    public function test_a_suspended_super_admin_does_not_count_towards_the_lockout_guard(): void
    {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create(['suspended_at' => now()]);

        // The only OTHER holder is suspended, so the actor is the last usable
        // one and must keep the role.
        $this->actingAs($admin)
            ->put("/admin/administrators/{$admin->id}/roles", ['roles' => []])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($admin->fresh()->isSuperAdmin());
    }

    public function test_public_account_roles_survive_the_administrative_sync(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();
        $target->roles()->attach($this->role(RoleKey::CompanyOwner));
        $target->roles()->attach($this->role(RoleKey::ContentEditor));

        $this->actingAs($admin)
            ->put("/admin/administrators/{$target->id}/roles", ['roles' => []])
            ->assertRedirect(route('admin.administrators.index'));

        // The administrative role went; the company role is not this
        // surface's to remove.
        $this->assertSame([RoleKey::CompanyOwner->value], $target->fresh()->roles()->pluck('key')->all());
    }

    public function test_unknown_and_public_roles_are_rejected_by_validation(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->put("/admin/administrators/{$target->id}/roles", ['roles' => [RoleKey::Member->value]])
            ->assertSessionHasErrors('roles.0');

        $this->actingAs($admin)
            ->put("/admin/administrators/{$target->id}/roles", ['roles' => ['made.up.role']])
            ->assertSessionHasErrors('roles.0');

        $this->assertSame(0, $target->fresh()->roles()->count());
    }

    // ------------------------------------------- suspension and sessions

    public function test_suspending_an_operator_works_and_is_audited(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = $this->operator(RoleKey::ContentEditor);

        $this->actingAs($admin)
            ->post("/admin/administrators/{$target->id}/suspend", ['reason' => 'offboarded'])
            ->assertRedirect();

        $this->assertNotNull($target->fresh()->suspended_at);
        $this->assertTrue(
            AuditLog::query()->where('action', 'identity.administrator_suspended')->exists(),
        );

        $this->actingAs($admin)
            ->post("/admin/administrators/{$target->id}/reactivate")
            ->assertRedirect();

        $this->assertNull($target->fresh()->suspended_at);
    }

    public function test_only_a_super_admin_may_suspend_a_super_admin(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $actor = $this->operator(RoleKey::SystemAdmin);

        $this->actingAs($actor)
            ->post("/admin/administrators/{$admin->id}/suspend", ['reason' => 'takeover attempt'])
            ->assertForbidden();

        $this->assertNull($admin->fresh()->suspended_at);
        $this->assertTrue(
            AuditLog::query()->where('action', 'identity.administrators.escalation_denied')->exists(),
        );
    }

    public function test_a_super_admin_may_suspend_another_super_admin(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $second = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post("/admin/administrators/{$second->id}/suspend", ['reason' => 'compromised'])
            ->assertRedirect();

        $this->assertNotNull($second->fresh()->suspended_at);
    }

    public function test_operators_cannot_suspend_themselves(): void
    {
        $actor = $this->operator(RoleKey::SystemAdmin);

        $this->actingAs($actor)
            ->post("/admin/administrators/{$actor->id}/suspend", ['reason' => 'oops'])
            ->assertStatus(422);
    }

    public function test_members_are_not_this_surfaces_population(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)
            ->post("/admin/administrators/{$member->id}/suspend", ['reason' => 'wrong door'])
            ->assertNotFound();

        $this->actingAs($admin)
            ->post("/admin/administrators/{$member->id}/logout")
            ->assertNotFound();
    }

    public function test_force_logout_ends_sessions_and_rotates_the_remember_token(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = $this->operator(RoleKey::ContentEditor);
        $before = $target->remember_token;

        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $target->id,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/administrators/{$target->id}/logout")
            ->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertNotSame($before, $target->fresh()->remember_token);
    }

    // ------------------------------------------------------- the promotion door

    public function test_the_member_page_offers_promotion_only_with_the_assign_permission(): void
    {
        $member = User::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get("/admin/users/{$member->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can_assign_roles', true)
                ->where('assignable_roles', fn (Collection $roles): bool => $roles->contains(RoleKey::SuperAdmin->value)));

        $support = $this->operator(RoleKey::SupportAgent);

        $this->actingAs($support)
            ->get("/admin/users/{$member->id}")
            ->assertInertia(fn ($page) => $page->where('can_assign_roles', false));
    }

    public function test_the_promotion_list_offers_super_admin_only_to_a_super_admin(): void
    {
        $member = User::factory()->create();
        $actor = $this->operator(RoleKey::SystemAdmin);

        $this->actingAs($actor)
            ->get("/admin/users/{$member->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can_assign_roles', true)
                ->where('assignable_roles', fn (Collection $roles): bool => ! $roles->contains(RoleKey::SuperAdmin->value)));
    }
}

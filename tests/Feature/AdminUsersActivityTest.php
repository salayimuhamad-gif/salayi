<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Models\PasswordRecoveryChallenge;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Member activity: presence, the filters and sorts built on it, the two new
 * administrative actions, and the dashboard metrics — each behind its own
 * server-side permission, each audited where it changes somebody's state.
 */
final class AdminUsersActivityTest extends TestCase
{
    use RefreshDatabase;

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

        config(['services.telegram.bot_token' => 'test-bot-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function member(array $overrides = []): User
    {
        return User::factory()->withoutRoles()->create($overrides + ['is_active' => true]);
    }

    /* ----------------------------------------------------- last seen */

    public function test_an_authenticated_request_stamps_last_seen_once_per_interval(): void
    {
        $member = $this->member();
        $this->assertNull($member->last_seen_at);

        $this->actingAs($member)->get('/');
        $first = $member->refresh()->last_seen_at;
        $this->assertNotNull($first);

        // A second request inside the interval writes nothing: the cache
        // gate absorbs it. The timestamp must not move.
        $this->travel(1)->minutes();
        $this->actingAs($member)->get('/');
        $this->assertTrue($member->refresh()->last_seen_at->equalTo($first));
    }

    public function test_guests_do_not_error_or_write(): void
    {
        $this->get('/')->assertSuccessful();
        $this->assertSame(0, User::query()->whereNotNull('last_seen_at')->count());
    }

    /* ------------------------------------------------- filters + sorts */

    public function test_the_recently_active_filter_and_sort_use_last_seen(): void
    {
        $fresh = $this->member(['last_seen_at' => now()->subMinute()]);
        $stale = $this->member(['last_seen_at' => now()->subDays(10)]);
        $never = $this->member();

        $admin = User::factory()->superAdmin()->create();

        $rows = $this->actingAs($admin)
            ->get('/admin/users?active=week')
            ->assertOk()
            ->inertiaPage()['props']['users']['data'];

        $this->assertSame([$fresh->id], array_column($rows, 'id'));
        $this->assertTrue($rows[0]['online']);

        $sorted = $this->actingAs($admin)
            ->get('/admin/users?sort=recent_activity')
            ->assertOk()
            ->inertiaPage()['props']['users']['data'];

        $this->assertSame([$fresh->id, $stale->id], array_slice(array_column($sorted, 'id'), 0, 2));
        $this->assertContains($never->id, array_column($sorted, 'id'));
    }

    public function test_registration_date_filters_narrow_the_list(): void
    {
        $old = $this->member(['created_at' => '2026-01-10 10:00:00']);
        $new = $this->member(['created_at' => '2026-08-01 10:00:00']);

        $admin = User::factory()->superAdmin()->create();

        $rows = $this->actingAs($admin)
            ->get('/admin/users?registered_from=2026-06-01&registered_to=2026-08-05')
            ->assertOk()
            ->inertiaPage()['props']['users']['data'];

        $this->assertSame([$new->id], array_column($rows, 'id'));
        $this->assertNotContains($old->id, array_column($rows, 'id'));
    }

    /* ------------------------------------------------------ force logout */

    public function test_force_logout_ends_sessions_rotates_remember_and_audits(): void
    {
        $member = $this->member(['remember_token' => 'before-rotation-value']);

        DB::table('sessions')->insert([
            'id' => 'member-live-session',
            'user_id' => $member->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phone',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post("/admin/users/{$member->id}/logout")
            ->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $member->id)->count());
        $this->assertNotSame('before-rotation-value', $member->refresh()->remember_token);
        $this->assertTrue(
            DB::table('audit_logs')->where('action', 'identity.user_sessions_revoked')->exists(),
        );
    }

    public function test_force_logout_needs_its_own_permission_and_404s_operators(): void
    {
        $member = $this->member();
        $operator = User::factory()->projectEditor()->create();

        // A role WITHOUT identity.sessions.revoke is refused server-side.
        $this->actingAs(User::factory()->projectEditor()->create())
            ->post("/admin/users/{$member->id}/logout")
            ->assertForbidden();

        // An operator target is indistinguishable from a missing id.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post("/admin/users/{$operator->id}/logout")
            ->assertNotFound();
    }

    /* -------------------------------------------------- trigger recovery */

    public function test_an_admin_can_send_a_recovery_link_to_a_linked_account(): void
    {
        $member = $this->member([
            'telegram_id' => '555000111',
            'telegram_verified_at' => now(),
            'preferred_locale' => 'ckb',
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post("/admin/users/{$member->id}/recovery")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, PasswordRecoveryChallenge::query()->where('user_id', $member->id)->count());
        $this->assertTrue(
            DB::table('audit_logs')
                ->where('action', 'identity.password_recovery_triggered_by_admin')->exists(),
        );
    }

    public function test_an_unlinked_account_gets_an_honest_refusal_and_no_challenge(): void
    {
        $member = $this->member();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post("/admin/users/{$member->id}/recovery")
            ->assertRedirect()
            ->assertSessionHasErrors('recovery');

        $this->assertSame(0, PasswordRecoveryChallenge::query()->count());
    }

    /* --------------------------------------------------------- dashboard */

    public function test_activity_metrics_appear_only_for_the_users_permission(): void
    {
        $this->member(['last_seen_at' => now()->subMinute()]);
        $this->member(['last_seen_at' => now()->subDays(2)]);
        $this->member();

        $withPermission = $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin')
            ->assertOk()
            ->inertiaPage()['props']['activity'];

        $this->assertSame(1, $withPermission['online_now']);
        $this->assertSame(2, $withPermission['active_week']);
        $this->assertSame(3, $withPermission['total']);
        $this->assertSame(3, $withPermission['new_today']);

        $withoutPermission = $this->actingAs(User::factory()->projectEditor()->create())
            ->get('/admin')
            ->assertOk()
            ->inertiaPage()['props']['activity'];

        $this->assertNull($withoutPermission);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Core\Support\AdminNavigation;
use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionRegistry;
use App\Modules\Operations\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The Super Admin coverage matrix.
 *
 * The defect class this file exists to prevent: admin capabilities that exist
 * in the backend quietly becoming unreachable — a permission declared and
 * never routed, a route reachable and never linked, a navigation entry whose
 * gates are stricter than its destination's. Each of those happened, nothing
 * noticed, and the accumulated result was a Super Admin panel missing whole
 * sections while every individual authorisation check was "correct".
 *
 * The rule enforced here is the product's own: a valid, active, MFA-complete
 * super_admin reaches every implemented admin surface, and the navigation
 * offers every one of them. Nothing here weakens anybody else — the same file
 * proves a System Admin does NOT silently hold the whole catalogue and a
 * suspended Super Admin stays out.
 */
final class SuperAdminCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Every feature flag on, so flag gating never masks a missing capability. */
    private function enableEveryFlag(): void
    {
        $this->setEveryFlag(true);
    }

    private function setEveryFlag(bool $enabled): void
    {
        $flags = [];

        foreach (array_keys((array) config('features.defaults')) as $flag) {
            $flags[$flag] = $enabled;
        }

        $this->setFeatures($flags);
    }

    public function test_super_admin_holds_every_catalogue_permission(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $all = PermissionRegistry::all();
        sort($all);

        $effective = $admin->allPermissions();
        sort($effective);

        $this->assertSame($all, $effective);
        $this->assertCount(108, $effective);

        foreach ($all as $permission) {
            $this->assertTrue($admin->hasPermission($permission), $permission);
        }
    }

    /**
     * Every permission an admin route demands must exist in the catalogue.
     *
     * A route gated by a permission the registry does not know is a surface
     * NOBODY can enter except Super Admin's bypass — an action with no
     * assignable operator. This is the drift guard for future routes.
     */
    public function test_every_admin_route_permission_exists_in_the_catalogue(): void
    {
        $known = PermissionRegistry::all();
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'admin.')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                foreach (['permission:', 'permission_any:'] as $prefix) {
                    if (! str_starts_with($middleware, $prefix)) {
                        continue;
                    }

                    foreach (explode(',', substr($middleware, strlen($prefix))) as $permission) {
                        $checked++;
                        $this->assertContains($permission, $known, sprintf(
                            'route %s demands "%s", which the PermissionRegistry does not declare',
                            $route->getName(), $permission,
                        ));
                    }
                }
            }
        }

        $this->assertGreaterThan(40, $checked, 'the admin surface stopped declaring permissions — the matrix is measuring nothing');
    }

    /**
     * THE MATRIX: every parameterless admin GET answers a Super Admin.
     *
     * With every flag on and a fully authenticated, MFA-complete session, no
     * implemented admin page may 403 or 500. Redirects are legitimate (wizard
     * entries redirect into their flow); refusals are not.
     */
    public function test_every_parameterless_admin_get_route_answers_a_super_admin(): void
    {
        $this->enableEveryFlag();

        $admin = User::factory()->superAdmin()->create();

        $visited = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'admin.')
                || ! in_array('GET', $route->methods(), true)
                || str_contains($route->uri(), '{')) {
                continue;
            }

            $response = $this->actingAs($admin)->get('/'.ltrim($route->uri(), '/'));

            $this->assertLessThan(400, $response->getStatusCode(), sprintf(
                'GET %s (%s) answered %d to a Super Admin',
                $route->uri(), $name, $response->getStatusCode(),
            ));

            $visited++;
        }

        $this->assertGreaterThan(20, $visited, 'the admin surface lost its GET routes — the matrix is measuring nothing');
    }

    /**
     * The navigation offers a Super Admin every implemented section, pinned.
     *
     * A new top-level section must be added HERE as well as to the tree, so
     * "implemented but never linked" fails loudly instead of shipping.
     */
    public function test_navigation_for_super_admin_contains_every_implemented_section(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $nav = AdminNavigation::for($admin, static fn (): bool => true);

        $this->assertSame([
            'overview', 'notifications', 'projects', 'geography', 'market',
            'imports', 'marketplace', 'companies', 'company_developers',
            'project_drafts', 'leads', 'content', 'knowledge', 'branding',
            'features', 'system',
        ], array_column($nav, 'key'));

        $children = [];

        foreach ($nav as $item) {
            $children[$item['key']] = array_column($item['children'], 'key');
        }

        $this->assertSame(['projects.all', 'projects.developers'], $children['projects']);
        $this->assertSame(['geography.areas', 'geography.places', 'geography.place_categories'], $children['geography']);
        $this->assertSame(['market.prices', 'market.indices'], $children['market']);
        $this->assertSame(['marketplace.offers', 'marketplace.moderation', 'marketplace.media_queue'], $children['marketplace']);
        $this->assertSame(
            ['system.settings', 'system.users', 'system.roles', 'system.audit', 'system.health'],
            $children['system'],
        );
    }

    /**
     * The admin twin of NavigationDestinationsTest: if the navigation can
     * render a link, the server must answer it — for the navigation's most
     * privileged reader.
     */
    public function test_every_navigation_destination_resolves_and_answers_a_super_admin(): void
    {
        $this->enableEveryFlag();

        $admin = User::factory()->superAdmin()->create();

        $walk = function (array $items) use (&$walk, $admin): void {
            foreach ($items as $item) {
                $this->assertTrue(Route::has($item['route']), sprintf(
                    'navigation item %s points at unregistered route %s',
                    $item['key'], $item['route'],
                ));

                $response = $this->actingAs($admin)->get(route($item['route']));

                $this->assertLessThan(400, $response->getStatusCode(), sprintf(
                    'navigation item %s (%s) answered %d to a Super Admin',
                    $item['key'], $item['route'], $response->getStatusCode(),
                ));

                $walk($item['children']);
            }
        };

        $walk(AdminNavigation::for($admin, static fn (): bool => true));
    }

    /**
     * The requirement, verbatim: a Super Admin sees every implemented
     * section — including while its launch flag is OFF. The flag darkens the
     * public product, not the administration of it.
     */
    public function test_navigation_shows_super_admin_every_section_even_with_flags_off(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->assertSame(
            array_column(AdminNavigation::for($admin, static fn (): bool => true), 'key'),
            array_column(AdminNavigation::for($admin, static fn (): bool => false), 'key'),
        );
    }

    /**
     * The matrix again, with every flag OFF: the same admin surfaces answer
     * the same Super Admin, each pass through a disabled flag an audited
     * security event rather than a silent hole.
     */
    public function test_super_admin_reaches_every_admin_surface_while_flags_are_off(): void
    {
        $this->setEveryFlag(false);

        $admin = User::factory()->superAdmin()->create();

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'admin.')
                || ! in_array('GET', $route->methods(), true)
                || str_contains($route->uri(), '{')) {
                continue;
            }

            $response = $this->actingAs($admin)->get('/'.ltrim($route->uri(), '/'));

            $this->assertLessThan(400, $response->getStatusCode(), sprintf(
                'GET %s (%s) answered %d to a Super Admin with flags off',
                $route->uri(), $name, $response->getStatusCode(),
            ));
        }

        $this->assertTrue(
            AuditLog::query()->where('action', 'feature.preview_while_disabled')->exists(),
            'previewing a disabled feature must leave a security audit record',
        );
    }

    public function test_ordinary_administrators_remain_flag_gated(): void
    {
        $this->setEveryFlag(false);

        // Product Owner holds market.prices.view and marketplace.offers.view,
        // so the refusal below is the FLAG's, not a permission miss.
        $admin = User::factory()->productOwner()->create();

        $this->actingAs($admin)->get('/admin/market/prices')->assertForbidden();
        $this->actingAs($admin)->get('/admin/offers')->assertForbidden();
    }

    public function test_public_surfaces_stay_dark_while_disabled_even_for_super_admin(): void
    {
        $this->setEveryFlag(false);

        $admin = User::factory()->superAdmin()->create();

        // The PUBLIC market and offers pages: a disabled launch flag keeps
        // them dark for everyone — the admin preview never leaks forward.
        $this->actingAs($admin)->get('/market')->assertNotFound();
        $this->actingAs($admin)->get('/offers')->assertNotFound();
    }

    /**
     * Imports is reachable from the navigation on exactly the terms of its
     * routes: the imports permission, no market permission, no feature flag.
     * This is the regression pin for the entry that used to sit under a
     * parent gated by both.
     */
    public function test_imports_navigation_matches_its_routes_not_the_market_gates(): void
    {
        $user = User::factory()->create();
        $this->attachRole($user, RoleKey::GisPlacesManager);

        // Every flag OFF — exactly the configuration that used to hide it.
        $nav = AdminNavigation::for($user, static fn (): bool => false);

        $this->assertContains('imports', array_column($nav, 'key'));
        $this->assertNotContains('market', array_column($nav, 'key'));

        $this->assertLessThan(
            400,
            $this->actingAs($user)->get('/admin/imports/prices')->getStatusCode(),
        );
    }

    public function test_a_system_admin_does_not_silently_become_super_admin(): void
    {
        $admin = User::factory()->create();
        $this->attachRole($admin, RoleKey::SystemAdmin);

        $this->assertFalse($admin->isSuperAdmin());
        $this->assertNotSame(count(PermissionRegistry::all()), count($admin->allPermissions()));
        $this->assertFalse($admin->hasPermission('projects.publish'));

        // A concrete refusal, not just arithmetic: a surface outside the
        // System Admin grant answers 403 exactly as before this change.
        $this->actingAs($admin)->get('/admin/projects/create')->assertForbidden();
    }

    public function test_a_suspended_super_admin_is_refused_and_logged_out(): void
    {
        $admin = User::factory()->superAdmin()->create([
            'suspended_at' => now(),
            'suspended_reason' => 'audit',
        ]);

        $this->assertFalse($admin->hasPermission('system.settings.view'));

        $this->actingAs($admin)->get('/admin/users')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_unenrolled_super_admin_is_still_sent_to_mfa_setup(): void
    {
        $admin = User::factory()->superAdmin()->withoutMfa()->create();

        $this->actingAs($admin)->get('/admin/users')
            ->assertRedirect(route('admin.mfa.setup'));
    }

    /**
     * The settings page carries configuration STATE, never credentials: for
     * every secret the payload says whether one is configured and nothing
     * else. Spec 37.5.
     */
    public function test_the_settings_page_never_ships_secret_values(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemSettings')
                ->has('integrations.mail.password_configured')
                ->has('integrations.telegram.bot_token_configured')
                ->has('integrations.ai.api_key_configured')
                ->missing('integrations.mail.password')
                ->missing('integrations.telegram.bot_token')
                ->missing('integrations.telegram.webhook_secret')
                ->missing('integrations.maps.google_maps_api_key')
                ->missing('integrations.ai.api_key'));
    }

    private function attachRole(User $user, RoleKey $key): void
    {
        $role = Role::query()->firstOrCreate(
            ['key' => $key->value],
            ['name' => $key->value, 'is_system' => true],
        );

        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}

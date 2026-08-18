<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * The tests referenced `User::factory()` and no factory existed, so every
 * wizard test would have fataled on its first line. Worth stating plainly:
 * a suite that cannot construct its subject is not a suite.
 *
 * Roles are attached through named states rather than by passing a role id,
 * because permission checks go through PermissionRegistry::forRole() — a user
 * with no role has no permissions at all, so a test asserting a 403 and a test
 * asserting a 200 would otherwise both pass for the same middleware reason and
 * neither would be testing the wizard.
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Hydrate the persisted row onto the returned model.
     *
     * `preventAccessingMissingAttributes()` is on outside production, and a
     * factory model carries ONLY the attributes the factory set. Anything else
     * on the row — `suspended_at`, `mfa_secret`, and every other column the
     * middleware stack reads — threw MissingAttributeException, so an
     * `actingAs()` request died with a 500 before reaching its controller.
     *
     * In production the session guard loads the full row from the database, so
     * refreshing here is not a convenience: it is what makes the fixture model
     * the same shape as the model production actually works with. Declaring a
     * fixed list of columns instead would silently rot the next time a
     * migration adds one.
     */
    public function configure(): static
    {
        return $this->afterCreating(static fn (User $user): User => $user->refresh());
    }

    public function definition(): array
    {
        return [
            'name' => 'Test User '.Str::random(6),
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
            'preferred_locale' => 'ckb',
            'is_active' => true,
            'timezone' => 'Asia/Baghdad',
            'email_verified_at' => now(),

            /*
             * THE MFA COLUMNS ARE DECLARED, EVEN THOUGH THEY ARE NULL.
             *
             * `preventAccessingMissingAttributes()` is on outside production,
             * and a model built by a factory carries only the attributes the
             * factory set — so `EnsureMfaConfirmed` calling `hasMfaEnabled()`
             * on an `actingAs()` user threw MissingAttributeException and every
             * authenticated request in the suite returned 500. A real row has
             * these columns; the fixture must too, or it is not modelling a
             * user at all.
             */
            /*
             * MFA IS CONFIRMED BY DEFAULT, because the guard is real.
             *
             * `EnsureMfaConfirmed` sends any administrative user without
             * confirmed MFA to /admin/mfa/setup, so a factory admin with no MFA
             * never reached a single admin route — the entire wizard, moderation
             * and company suite was asserting against a redirect it had caused
             * itself. The guard is correct and untouched; the fixture now models
             * an administrator who has actually completed enrolment, which is
             * the only state in which the rest of the admin surface is
             * reachable. `withoutMfa()` covers the opposite case so the
             * redirect itself stays under test.
             */
            'mfa_secret' => encrypt('TESTSECRETTESTSECRET'),
            'mfa_recovery_codes' => null,
            'mfa_confirmed_at' => now(),
        ];
    }

    /** An administrator who has NOT completed MFA enrolment. */
    public function withoutMfa(): self
    {
        return $this->state(fn (): array => [
            'mfa_secret' => null,
            'mfa_confirmed_at' => null,
        ]);
    }

    /** Super Admin: hasPermission() short-circuits to true for every check. */
    public function superAdmin(): self
    {
        return $this->afterCreating(fn (User $user) => $this->attach($user, RoleKey::SuperAdmin));
    }

    /**
     * Project data editor — holds projects.create but NOT projects.publish.
     *
     * The realistic wizard user, and the one that makes the publish-permission
     * assertions meaningful.
     */
    public function projectEditor(): self
    {
        return $this->afterCreating(fn (User $user) => $this->attach($user, RoleKey::ProjectDataEditor));
    }

    /**
     * Unscoped creation only — no company scope, no Super Admin bypass.
     *
     * Using Super Admin as "platform-only" proves nothing: it short-circuits
     * every check, so the test passes whether or not the boundary exists.
     */
    public function platformProjectOperator(): self
    {
        return $this->afterCreating(fn (User $user) => $this->attach($user, RoleKey::PlatformProjectOperator));
    }

    /**
     * Platform marketplace moderation, without the Super Admin bypass.
     *
     * Needed to test the moderation boundary at all: a Super Admin passes
     * every permission check, so it cannot demonstrate that a boundary exists.
     */
    public function productOwner(): self
    {
        return $this->afterCreating(fn (User $user) => $this->attach($user, RoleKey::ProductOwner));
    }

    /** A company portal user. */
    public function companyAccountManager(): self
    {
        return $this->afterCreating(fn (User $user) => $this->attach($user, RoleKey::CompanyAccountManager));
    }

    /** No role at all: every permission check fails. */
    public function withoutRoles(): self
    {
        return $this->state(fn (): array => []);
    }

    private function attach(User $user, RoleKey $key): void
    {
        $role = Role::query()->firstOrCreate(
            ['key' => $key->value],
            ['name' => $key->value, 'is_system' => true],
        );

        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}

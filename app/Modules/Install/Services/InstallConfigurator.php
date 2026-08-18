<?php

declare(strict_types=1);

namespace App\Modules\Install\Services;

use App\Modules\Core\Contracts\SettingsRepository;
use App\Modules\Identity\Enums\RoleKey;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Turns validated installer answers into actual configuration
 * (repair prompt §2.4, §2.5, §2.6, §2.7, §2.8, §2.9).
 *
 * This is the piece that was missing. The wizard collected fourteen screens of
 * answers into a JSON file; EnvWriter existed and was bound in the container;
 * nothing connected them. An operator could complete every screen and end up
 * with an unconfigured application, because `.env` was never written and no
 * administrator account was ever created.
 *
 * ORDERING IS THE WHOLE DESIGN HERE.
 *
 * InstallState deliberately refuses to persist secrets (§2.5) — a resumable
 * JSON file holding a database password on shared hosting is a liability, and
 * `storage/` is not always outside the document root on a misconfigured
 * account. The consequence is that a secret exists only for the duration of
 * the request that carried it. So `.env` must be written *during* that
 * request, before the answers are handed to InstallState and stripped. Get
 * this backwards and the password is silently lost, which is precisely what
 * "wire EnvWriter into the installer" has to mean in practice.
 */
final class InstallConfigurator
{
    public function __construct(
        private readonly InstallState $state,
        private readonly EnvWriter $env,
        private readonly StepValidator $validator,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Commit one step's validated answers.
     *
     * @param  array<string, mixed>  $validated
     */
    public function commit(string $step, array $validated): void
    {
        $normalised = $this->validator->normalise($step, $validated);

        // 1. Secrets reach .env now, on this request, or never.
        $envValues = $this->validator->envFor($step, $normalised);

        if ($envValues !== []) {
            $this->env->write($envValues);
        }

        // 2. Runtime config is updated so later steps in THIS request-chain see
        //    the new values without a rebuild — §2.6 for the database.
        $this->applyRuntime($step, $normalised);

        // 3. Only now does the state file see the answers, secrets removed.
        $this->state->remember($step, $this->validator->withoutSecrets($normalised));
        $this->state->markComplete($step);
    }

    /**
     * Apply the installer's database credentials to the live connection
     * (repair prompt §2.6).
     *
     * Without this, `php artisan migrate` at the migrate step runs against
     * whatever DB_* values were in .env when the process booted — usually the
     * placeholders from .env.example — and fails with an access-denied error
     * that points at the wrong database entirely.
     *
     * @param  array<string, mixed>  $answers
     */
    public function applyDatabaseConnection(array $answers): void
    {
        $connection = [
            'driver' => 'mysql',
            'host' => (string) ($answers['db_host'] ?? '127.0.0.1'),
            'port' => (int) ($answers['db_port'] ?? 3306),
            'database' => (string) ($answers['db_database'] ?? ''),
            'username' => (string) ($answers['db_username'] ?? ''),
            'password' => (string) ($answers['db_password'] ?? ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ];

        Config::set('database.connections.mysql', $connection);
        Config::set('database.default', 'mysql');

        // Purge so the next resolve picks up the new credentials rather than a
        // PDO handle opened against the old ones.
        DB::purge('mysql');
    }

    /**
     * Create the first Super Admin from the submitted values
     * (repair prompt §2.7).
     *
     * Runs after `seed`, because the roles table must exist and be populated
     * first. Idempotent: re-entering a resumed install must not fail on a
     * duplicate email, and must not silently create a second administrator.
     *
     * MFA is deliberately NOT pre-confirmed. The account is created without a
     * TOTP secret so the operator is forced through enrolment at first sign-in;
     * seeding a confirmed MFA state the operator never saw would leave the
     * strongest account in the system protected by nothing.
     *
     * @param  array<string, mixed>  $answers
     * @param  string  $password  held only in memory; never in the state file
     * @return array{created: bool, user_id: int}
     */
    public function createSuperAdmin(array $answers, string $password): array
    {
        $email = (string) ($answers['admin_email'] ?? '');

        if ($email === '' || $password === '') {
            throw new RuntimeException('super_admin_answers_missing');
        }

        $role = Role::query()->firstOrCreate(
            ['key' => RoleKey::SuperAdmin->value],
            ['name' => 'Super Admin', 'is_system' => true],
        );

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            // A resumed install re-submitting the same operator. Do not create
            // a second account and do not silently reset the password of an
            // account that may already be in use.
            if (! $existing->roles()->where('key', RoleKey::SuperAdmin->value)->exists()) {
                $existing->roles()->attach($role->id);
            }

            $this->state->log('super admin already existed; role ensured');

            return ['created' => false, 'user_id' => (int) $existing->id];
        }

        $user = User::query()->create([
            'name' => (string) ($answers['admin_name'] ?? 'Administrator'),
            'email' => $email,
            'password' => Hash::make($password),
            'preferred_locale' => (string) ($answers['admin_locale'] ?? 'ckb'),
            'is_active' => true,
            'timezone' => (string) config('app.timezone', 'Asia/Baghdad'),
        ]);

        $user->roles()->attach($role->id);

        // The email is recorded; the password never is.
        $this->state->log('super admin created', ['email' => $email]);

        return ['created' => true, 'user_id' => (int) $user->id];
    }

    /**
     * Persist branding answers as settings rather than .env, so an
     * administrator can change them later without filesystem access.
     *
     * @param  array<string, mixed>  $answers
     */
    public function applyBranding(array $answers): void
    {
        $map = [
            'branding.site_name' => $answers['site_name'] ?? null,
            'branding.support_email' => $answers['support_email'] ?? null,
            'branding.support_phone' => $answers['support_phone'] ?? null,
        ];

        foreach ($map as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // Through the repository, not the model: it infers the type,
            // derives the group from the key and flushes the settings cache.
            // Writing the row directly would leave a stale cache behind.
            $this->settings->set($key, (string) $value);
        }

        $this->state->log('branding settings applied');
    }

    /**
     * Mark the deployment installed in .env (repair prompt §2.10, step 25).
     *
     * Separate from writing the lock file. The lock file is the authority —
     * it survives an .env edit, which is what makes "the installer disables
     * itself" actually hold — but MULKIHAWLER_INSTALLED lets EnsureInstalled
     * short-circuit without a filesystem stat on every request.
     */
    public function markInstalled(): void
    {
        $this->env->write([
            'MULKIHAWLER_INSTALLED' => 'true',
            'APP_DEBUG' => 'false',
        ]);
    }

    /**
     * Apply answers to the live config so subsequent steps in the same run
     * behave as the finished installation will.
     *
     * @param  array<string, mixed>  $answers
     */
    private function applyRuntime(string $step, array $answers): void
    {
        match ($step) {
            'database' => $this->applyDatabaseConnection($answers),

            'app_url' => Config::set([
                'app.name' => (string) ($answers['app_name'] ?? config('app.name')),
                'app.url' => (string) ($answers['app_url'] ?? config('app.url')),
                'app.timezone' => (string) ($answers['timezone'] ?? config('app.timezone')),
            ]),

            'default_language' => Config::set([
                'app.locale' => (string) ($answers['default_locale'] ?? 'ckb'),
                'app.fallback_locale' => (string) ($answers['default_locale'] ?? 'ckb'),
            ]),

            'enabled_languages' => Config::set([
                'localization.enabled' => (array) ($answers['enabled_locales'] ?? ['ckb']),
            ]),

            default => null,
        };
    }
}

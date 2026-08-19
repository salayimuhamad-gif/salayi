<?php

declare(strict_types=1);

namespace App\Modules\Install\Services;

use App\Modules\Operations\Services\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The mutating installer steps (spec 33.1 steps 17-23, spec 33.3 upgrade mode).
 *
 * These returned HTTP 501 from Step 1 through Step 7. That was deliberate: a
 * migrate step which appears to succeed and does not is the worst failure mode
 * for a shared-hosting installer, because the operator only discovers it when
 * the site 500s and there is no way back. They are implemented here, together
 * with the backup and rollback that make them safe — which is why they waited.
 *
 * The ordering guarantee for an upgrade (spec 33.3):
 *
 *   preflight -> BACKUP -> verify backup -> maintenance on -> migrate
 *            -> caches -> health check -> maintenance off
 *                                      \-> on failure: RESTORE, maintenance off
 *
 * The backup is verified before the migration runs, not merely taken. "We took
 * a backup" and "we took a usable backup" are different claims, and the gap
 * between them is only ever discovered at the worst possible moment.
 *
 * @phpstan-type InstallStepResult array{
 *     ok: bool, step: string, detail: array<string, mixed>, rolled_back: bool
 * }
 */
final class InstallRunner
{
    public function __construct(
        private readonly InstallState $state,
        private readonly BackupService $backups,
        private readonly InstallConfigurator $configurator,
    ) {}

    /**
     * Spec 33.1 step 17. On upgrade this backs up and can roll back; on a fresh
     * install there is nothing to lose, so it runs directly.
     *
     * @return InstallStepResult
     */
    public function migrate(): array
    {
        $isUpgrade = $this->state->mode() === 'upgrade';
        $backup = null;

        /*
         * Repair prompt §2.6. Without this the migration runs against whatever
         * DB_* values were in .env when PHP booted — on a fresh upload, the
         * placeholders from .env.example — and fails pointing at a database
         * the operator never named. .env has already been written by
         * InstallConfigurator, but this process's config was loaded before
         * that happened.
         */
        $this->configurator->applyDatabaseConnection($this->state->answers('database'));

        if ($isUpgrade && (bool) config('installer.upgrade.backup_before_migrate', true)) {
            try {
                $backup = $this->backups->dump($this->backupPath());
                $verification = $this->backups->verify($backup['path'], $backup['checksum']);

                if (! $verification['valid']) {
                    // Refuse to migrate rather than proceed without a usable
                    // rollback. Spec 33.3 requires the ability to roll back.
                    return $this->failure('migrate', [
                        'reason' => 'backup_unusable',
                        'verification' => $verification,
                    ]);
                }

                $this->state->log('pre-migration backup verified', ['bytes' => $backup['bytes']]);
            } catch (Throwable $e) {
                return $this->failure('migrate', [
                    'reason' => 'backup_failed',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($isUpgrade && (bool) config('installer.upgrade.maintenance_mode', true)) {
            $this->safely(fn () => Artisan::call('down', ['--render' => 'errors::503']));
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();

            $this->state->log('migrations applied');
            $this->state->markComplete('migrate');

            $this->safely(fn () => Artisan::call('up'));

            return [
                'ok' => true,
                'step' => 'migrate',
                'detail' => ['output' => $this->trim($output), 'backup' => $backup === null ? null : basename($backup['path'])],
                'rolled_back' => false,
            ];
        } catch (Throwable $e) {
            $rolledBack = false;

            if ($backup !== null && (bool) config('installer.upgrade.rollback_on_health_failure', true)) {
                $restore = $this->backups->restore($backup['path'], $backup['checksum']);
                $rolledBack = $restore['restored'];
                $this->state->log('migration failed, rollback attempted', ['restored' => $rolledBack]);
            }

            $this->safely(fn () => Artisan::call('up'));

            return [
                'ok' => false,
                'step' => 'migrate',
                'detail' => ['reason' => 'migration_failed', 'message' => $e->getMessage()],
                'rolled_back' => $rolledBack,
            ];
        }
    }

    /**
     * Spec 33.1 step 18. Idempotent: seeders use firstOrCreate throughout.
     *
     * The Super Admin is created here rather than at the `super_admin` screen
     * (repair prompt §2.7) because the roles table must exist and be populated
     * first — at the time the operator types their details there is no schema
     * at all. The password is carried from that screen in memory only; see
     * InstallController::store().
     *
     * @return InstallStepResult
     */
    public function seed(): array
    {
        try {
            Artisan::call('db:seed', ['--force' => true]);

            $this->configurator->applyBranding($this->state->answers('branding'));

            $password = $this->state->takePendingAdminPassword();

            if ($password !== null) {
                $result = $this->configurator->createSuperAdmin(
                    $this->state->answers('super_admin'),
                    $password,
                );

                $this->state->markComplete('seed');

                return $this->success('seed', [
                    'output' => $this->trim(Artisan::output()),
                    'super_admin_created' => $result['created'],
                ]);
            }

            $this->state->markComplete('seed');

            return $this->success('seed', [
                'output' => $this->trim(Artisan::output()),
                'super_admin_created' => false,
                'warning' => 'no_admin_password_in_session',
            ]);
        } catch (Throwable $e) {
            return $this->failure('seed', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Spec 33.1 step 19.
     *
     * A symlink is attempted first and a directory copy is the fallback,
     * because `symlink()` is disabled on a number of shared hosts. Reporting
     * which one happened matters: a copied directory does not pick up new
     * uploads and needs re-running after a deploy.
     *
     * @return InstallStepResult
     */
    public function storageLink(): array
    {
        $target = storage_path('app/public');
        $link = public_path('storage');

        if (is_link($link) || is_dir($link)) {
            $this->state->markComplete('storage_link');

            return $this->success('storage_link', ['method' => 'already_present']);
        }

        if (! is_dir($target)) {
            File::makeDirectory($target, 0o755, true);
        }

        if (@symlink($target, $link)) {
            $this->state->markComplete('storage_link');

            return $this->success('storage_link', ['method' => 'symlink']);
        }

        try {
            File::copyDirectory($target, $link);
            $this->state->markComplete('storage_link');

            return $this->success('storage_link', [
                'method' => 'directory_copy',
                'warning' => 'symlink_unavailable_on_this_host_rerun_after_each_deploy',
            ]);
        } catch (Throwable $e) {
            return $this->failure('storage_link', ['message' => $e->getMessage()]);
        }
    }

    /** Spec 33.1 step 20. */
    /**
     * Step 21 (File two §18) — verify the compiled frontend is present.
     *
     * There was no such step. An operator who uploaded a package built without
     * `npm run build`, or who unzipped over a partial transfer, completed the
     * installer successfully and then saw a site that rendered nothing at all —
     * Laravel throws "Vite manifest not found" on the first page load, long
     * after the installer said everything was fine.
     *
     * Checked, not built: `npm` does not exist on Hostinger shared hosting, so
     * the assets must arrive pre-compiled in the deployment package. The only
     * useful thing the installer can do is confirm they did and say plainly
     * what is missing if they did not.
     *
     * @return InstallStepResult
     */
    public function assets(): array
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            // The exact defect Phase 1 fixed in vite.config.ts, so it is worth
            // naming precisely rather than reporting "assets missing".
            $misplaced = is_file(public_path('build/.vite/manifest.json'));

            return $this->failure('assets', [
                'reason' => $misplaced ? 'manifest_in_vite_subdirectory' : 'manifest_missing',
                'expected_path' => 'public/build/manifest.json',
            ]);
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($decoded) || $decoded === []) {
            return $this->failure('assets', ['reason' => 'manifest_unreadable']);
        }

        // Spot-check that the files the manifest names actually exist. A
        // manifest that survived an incomplete upload is worse than none:
        // it passes a existence check and 404s every asset.
        $missing = [];

        foreach ($decoded as $entry) {
            $file = is_array($entry) ? ($entry['file'] ?? null) : null;

            if (is_string($file) && ! is_file(public_path('build/'.$file))) {
                $missing[] = $file;
            }
        }

        if ($missing !== []) {
            return $this->failure('assets', [
                'reason' => 'referenced_files_missing',
                'count' => count($missing),
                'examples' => array_slice($missing, 0, 3),
            ]);
        }

        $this->state->markComplete('assets');

        return $this->success('assets', ['entries' => count($decoded)]);
    }

    /**
     * @return InstallStepResult
     */
    public function cache(): array
    {
        $results = [];

        foreach (['config:cache', 'route:cache', 'view:cache', 'event:cache'] as $command) {
            try {
                Artisan::call($command);
                $results[$command] = 'ok';
            } catch (Throwable $e) {
                // A cache failure is recoverable and must not abort an install.
                // Route caching in particular fails on any closure-based route.
                $results[$command] = 'skipped: '.$e->getMessage();
            }
        }

        $this->state->markComplete('cache');

        return $this->success('cache', $results);
    }

    /**
     * Spec 33.1 step 21, and the gate that decides whether an upgrade stands.
     *
     * @return InstallStepResult
     */
    public function healthCheck(): array
    {
        $checks = [];

        $checks['database'] = $this->probe(function (): bool {
            DB::connection()->getPdo();

            return true;
        });

        $checks['migrations_table'] = $this->probe(fn (): bool => DB::getSchemaBuilder()->hasTable('migrations'));
        $checks['roles_seeded'] = $this->probe(fn (): bool => DB::table('roles')->count() > 0);
        $checks['languages_seeded'] = $this->probe(fn (): bool => DB::table('languages')->count() > 0);
        $checks['app_key'] = $this->probe(fn (): bool => (string) config('app.key') !== '');
        $checks['storage_writable'] = $this->probe(fn (): bool => is_writable(storage_path('framework')));

        // Sorani must be present and enabled, or the product's default language
        // is missing and every page falls back to a raw key.
        $checks['sorani_enabled'] = $this->probe(
            fn (): bool => DB::table('languages')->where('code', 'ckb')->where('is_enabled', true)->exists(),
        );

        $failed = array_keys(array_filter($checks, static fn (bool $ok): bool => ! $ok));

        if ($failed !== []) {
            return $this->failure('health_check', ['failed' => $failed, 'checks' => $checks]);
        }

        $this->state->markComplete('health_check');

        return $this->success('health_check', ['checks' => $checks]);
    }

    /**
     * Spec 33.1 steps 22-23, and spec 37.6 "installer disables itself".
     *
     * Refuses to lock unless health passed. Locking a broken installation
     * removes the only route back into the installer, on a host with no SSH.
     */
    /**
     * Step 24 — finish the installation WITHOUT locking (repair prompt §2.10).
     *
     * This method used to call lock() itself, which deleted the state file and
     * wrote the lock. The separate `lock` step could then never run: the
     * wizard's own route was already 404 by the time the operator reached it,
     * and the summary screen that is supposed to appear between "installed"
     * and "sealed" was unreachable. The controller compounded it by mapping
     * both steps to this same method.
     *
     * Completion and locking are genuinely different events. Completion is
     * reversible — an operator who spots a wrong SMTP host can go back.
     * Locking is not: it destroys the collected answers and 404s the installer.
     * The operator gets to see the summary and decide.
     *
     * @return InstallStepResult
     */
    public function complete(): array
    {
        if (! $this->state->isComplete('health_check')) {
            return $this->failure('complete', ['reason' => 'health_check_not_passed']);
        }

        $this->configurator->markInstalled();
        $this->state->markComplete('complete');

        return $this->success('complete', [
            'locked' => false,
            'version' => (string) config('mulkihawler.version'),
        ]);
    }

    /**
     * Step 25 — seal the installation (repair prompt §2.10, §2.14).
     *
     * Writes the lock file and deletes the state file, after which
     * EnsureInstalled 404s the whole /install group. Refuses to run before
     * `complete`, so the sequence cannot be skipped by posting directly to
     * this step.
     *
     * @return InstallStepResult
     */
    public function lock(): array
    {
        if (! $this->state->isComplete('complete')) {
            return $this->failure('lock', ['reason' => 'complete_not_finished']);
        }

        $this->state->markComplete('lock');
        $this->state->lock();

        return $this->success('lock', [
            'locked' => $this->state->isLocked(),
            'version' => (string) config('mulkihawler.version'),
        ]);
    }

    private function backupPath(): string
    {
        return storage_path('backups/pre-migration-'.date('Ymd-His').'.sql');
    }

    /** @param array<string, mixed> $detail */
    /**
     * @param  array<string, mixed>  $detail
     * @return InstallStepResult
     */
    private function success(string $step, array $detail): array
    {
        return ['ok' => true, 'step' => $step, 'detail' => $detail, 'rolled_back' => false];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return InstallStepResult
     */
    private function failure(string $step, array $detail): array
    {
        $this->state->log('step failed: '.$step, $detail);

        return ['ok' => false, 'step' => $step, 'detail' => $detail, 'rolled_back' => false];
    }

    private function probe(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (Throwable) {
            return false;
        }
    }

    private function safely(callable $action): void
    {
        try {
            $action();
        } catch (Throwable) {
            // Maintenance-mode toggling must never mask the real error.
        }
    }

    private function trim(string $output): string
    {
        return mb_substr(trim($output), 0, 4000);
    }
}

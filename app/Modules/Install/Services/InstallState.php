<?php

declare(strict_types=1);

namespace App\Modules\Install\Services;

use App\Modules\Operations\Support\Redactor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resumable installer state (spec 33.2 "resumable installation", "safe retry").
 *
 * State is a JSON file, not a session and not a database row:
 *   - a session is lost when the wizard writes a new APP_KEY;
 *   - a database row does not exist until step 17.
 *
 * Collected answers are persisted so a failed migration can be retried without
 * re-typing fourteen screens. Secrets are held only long enough to write .env
 * and are scrubbed from the state file and the install log.
 */
final class InstallState
{
    /** Keys never written to the state file or the log. */
    private const TRANSIENT = [
        'db_password', 'mail_password', 'admin_password', 'admin_password_confirmation',
        'ai_api_key', 'google_maps_api_key', 'telegram_bot_token', 'telegram_webhook_secret',
    ];

    /** @var array<string, mixed>|null */
    private ?array $state = null;

    public function mode(): string
    {
        // An existing APP_KEY plus a populated schema means upgrade, not a new
        // install (spec 33.3). Getting this wrong regenerates APP_KEY and
        // renders every encrypted phone number unreadable.
        return $this->get('mode', $this->looksLikeExistingInstall() ? 'upgrade' : 'install');
    }

    public function looksLikeExistingInstall(): bool
    {
        return is_file(base_path('.env')) && (string) config('app.key') !== '';
    }

    /** @return list<string> */
    public function stepKeys(): array
    {
        /** @var list<array{key: string}> $steps */
        $steps = (array) config('installer.steps', []);

        return array_map(static fn (array $s): string => $s['key'], $steps);
    }

    public function currentStep(): string
    {
        $completed = $this->completedSteps();
        $keys = $this->stepKeys();

        foreach ($keys as $key) {
            if (! in_array($key, $completed, true)) {
                return $key;
            }
        }

        return end($keys) ?: 'welcome';
    }

    /** @return list<string> */
    public function completedSteps(): array
    {
        /** @var list<string> $completed */
        $completed = $this->get('completed', []);

        return $completed;
    }

    public function markComplete(string $step): void
    {
        $completed = $this->completedSteps();

        if (! in_array($step, $completed, true)) {
            $completed[] = $step;
        }

        $this->put('completed', $completed);
        $this->log("step complete: {$step}");
    }

    public function isComplete(string $step): bool
    {
        return in_array($step, $this->completedSteps(), true);
    }

    public function progress(): float
    {
        $total = count($this->stepKeys());

        return $total === 0 ? 0.0 : round(count($this->completedSteps()) / $total * 100, 1);
    }

    /**
     * Persist step answers. Transient secrets are dropped, not stored.
     *
     * @param  array<string, mixed>  $answers
     */
    public function remember(string $step, array $answers): void
    {
        $safe = array_diff_key($answers, array_flip(self::TRANSIENT));

        $data = $this->get('answers', []);
        $data[$step] = $safe;

        $this->put('answers', $data);
    }

    /** @return array<string, mixed> */
    public function answers(?string $step = null): array
    {
        /** @var array<string, mixed> $all */
        $all = $this->get('answers', []);

        if ($step === null) {
            return $all;
        }

        /** @var array<string, mixed> $stepAnswers */
        $stepAnswers = $all[$step] ?? [];

        return $stepAnswers;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $state = $this->load();

        return $state[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $state = $this->load();
        $state[$key] = $value;
        $this->save($state);
    }

    /**
     * Session key for the pending Super Admin password (repair prompt §2.5,
     * §2.7).
     *
     * The password is needed at `seed` (step 18) but typed at `super_admin`
     * (step 17), so it has to survive one hop. It must not survive it in the
     * state file: that file is JSON on disk under storage/, it is written on
     * shared hosting where storage/ is not reliably outside the document root,
     * and it persists across a resumed install that might be abandoned
     * half-finished for days.
     *
     * The session is the right place — file-backed during install (see
     * InstallServiceProvider), tied to one browser, and discarded when the
     * session expires. It is read exactly once and removed on read, so it does
     * not linger after the account exists.
     *
     * SECRET-SCAN: key-name — this is the session key under which the value is
     * stored, not the value itself. No credential appears in this file.
     */
    private const PENDING_ADMIN_PASSWORD = 'install.pending_admin_password';

    public function rememberAdminPassword(string $password): void
    {
        session()->put(self::PENDING_ADMIN_PASSWORD, $password);
    }

    /** Read once and forget. Returns null if the session no longer holds one. */
    public function takePendingAdminPassword(): ?string
    {
        $password = session()->pull(self::PENDING_ADMIN_PASSWORD);

        return is_string($password) && $password !== '' ? $password : null;
    }

    public function hasPendingAdminPassword(): bool
    {
        return is_string(session()->get(self::PENDING_ADMIN_PASSWORD));
    }

    /**
     * Write the lock file (spec 33.1 step 23, 37.6 "installer disables
     * itself"). The state file is deleted at the same moment so no collected
     * answer survives on disk.
     */
    public function lock(): void
    {
        $lockPath = (string) config('installer.lock_file');
        $this->ensureDirectory($lockPath);

        file_put_contents($lockPath, json_encode([
            'installed_at' => now()->toIso8601String(),
            'version' => config('mulkihawler.version'),
            'schema_version' => config('mulkihawler.schema_version'),
            'php' => PHP_VERSION,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        @chmod($lockPath, 0o440);

        $statePath = (string) config('installer.state_file');

        if (is_file($statePath)) {
            @unlink($statePath);
        }

        $this->state = null;
        $this->log('installation locked');
    }

    public function isLocked(): bool
    {
        return is_file((string) config('installer.lock_file'));
    }

    /**
     * Everything the installer writes goes through here, scrubbed.
     *
     * @param  array<string, mixed>  $context  arbitrary diagnostic detail
     */
    public function log(string $message, array $context = []): void
    {
        try {
            Log::channel('installer')->info($message, Redactor::scrub($context));
        } catch (Throwable) {
            // A log failure must never abort an installation.
        }
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        $path = (string) config('installer.state_file');

        if (! is_file($path)) {
            return $this->state = [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return $this->state = $decoded;
        } catch (Throwable) {
            // A corrupt state file must not brick the installer; start over.
            return $this->state = [];
        }
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        $path = (string) config('installer.state_file');
        $this->ensureDirectory($path);

        file_put_contents(
            $path,
            json_encode(Redactor::scrub($state), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            LOCK_EX,
        );

        @chmod($path, 0o600);

        $this->state = $state;
    }

    private function ensureDirectory(string $path): void
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0o750, true);
        }
    }
}

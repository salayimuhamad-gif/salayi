<?php

declare(strict_types=1);

namespace App\Modules\Operations\Services;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

/**
 * Narrow, audited writer for the small set of runtime values that a Super
 * Admin is allowed to change from the control panel.
 *
 * The writer never accepts arbitrary keys. In particular APP_KEY, database
 * credentials, PII encryption keys and session material are deliberately
 * outside the allow-list. The existing .env layout and comments are retained;
 * only named assignments are replaced or appended.
 */
final class EnvironmentSettings
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'APP_NAME', 'APP_URL', 'APP_TIMEZONE', 'APP_LOCALE', 'APP_FALLBACK_LOCALE',
        'APP_ENABLED_LOCALES', 'QUEUE_CONNECTION',
        'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
        'MAIL_SCHEME', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
        'MAP_PROVIDER', 'MAPLIBRE_STYLE_URL', 'GOOGLE_MAPS_API_KEY',
        'TELEGRAM_BOT_TOKEN', 'TELEGRAM_BOT_USERNAME', 'TELEGRAM_WEBHOOK_SECRET',
        'AI_PROVIDER', 'AI_BASE_URL', 'AI_API_KEY', 'AI_MODEL', 'AI_FALLBACK_MODEL',
        'AI_TIMEOUT', 'AI_MONTHLY_COST_LIMIT_USD',
    ];

    /** @var list<string> */
    private const SECRET_KEYS = [
        'MAIL_PASSWORD', 'GOOGLE_MAPS_API_KEY', 'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_WEBHOOK_SECRET', 'AI_API_KEY',
    ];

    public function path(): string
    {
        return base_path('.env');
    }

    public function isWritable(): bool
    {
        $path = $this->path();

        return is_file($path) && is_readable($path) && is_writable($path);
    }

    public function value(string $key, ?string $default = null): ?string
    {
        $values = $this->read();

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    public function configured(string $key): bool
    {
        return trim((string) $this->value($key, '')) !== '';
    }

    public function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    /** @return array<string, string> */
    public function read(): array
    {
        $path = $this->path();

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        return $this->parse($contents);
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $values
     * @return list<string> keys that actually changed
     */
    public function update(array $values): array
    {
        $values = $this->normaliseAndAuthorise($values);

        if ($values === []) {
            return [];
        }

        $path = $this->path();

        if (! $this->isWritable()) {
            throw new RuntimeException('environment_file_not_writable');
        }

        $lockPath = $path.'.system-settings.lock';
        $lock = fopen($lockPath, 'c+');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('environment_file_lock_failed');
        }

        try {
            $original = file_get_contents($path);

            if ($original === false) {
                throw new RuntimeException('environment_file_read_failed');
            }

            $before = $this->parse($original);
            $changed = [];

            foreach ($values as $key => $value) {
                if (($before[$key] ?? null) !== $value) {
                    $changed[] = $key;
                }
            }

            if ($changed === []) {
                return [];
            }

            $this->backup($original);
            $updated = $this->replaceAssignments($original, $values);
            $this->atomicPut($path, $updated);

            try {
                $this->rebuildConfigCache();
            } catch (Throwable $e) {
                // Roll back the environment file if Laravel cannot rebuild its
                // configuration. A half-applied provider change is harder to
                // diagnose than a cleanly rejected one.
                $this->atomicPut($path, $original);

                try {
                    $this->rebuildConfigCache();
                } catch (Throwable) {
                    // The original exception is the actionable one; the backup
                    // remains available under storage/app/private.
                }

                throw new RuntimeException('environment_config_rebuild_failed', previous: $e);
            }

            return $changed;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }

    /** @return array<string, string> */
    private function parse(string $contents): array
    {
        $out = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);

            if ($key === '' || preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $value = trim($value);

            if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
                $value = stripcslashes(substr($value, 1, -1));
            } elseif (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
                $value = substr($value, 1, -1);
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $values
     * @return array<string, string>
     */
    private function normaliseAndAuthorise(array $values): array
    {
        $allowed = array_fill_keys(self::ALLOWED_KEYS, true);
        $out = [];

        foreach ($values as $key => $value) {
            if (! isset($allowed[$key])) {
                throw new RuntimeException('environment_key_not_allowed');
            }

            $out[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => '',
                default => (string) $value,
            };
        }

        return $out;
    }

    /** @param array<string, string> $values */
    private function replaceAssignments(string $contents, array $values): string
    {
        $remaining = $values;
        $lines = preg_split('/\R/', $contents) ?: [];
        $rendered = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $match) === 1 && array_key_exists($match[1], $remaining)) {
                $key = $match[1];
                $rendered[] = $key.'='.$this->quote($remaining[$key]);
                unset($remaining[$key]);

                continue;
            }

            $rendered[] = $line;
        }

        if ($remaining !== []) {
            while ($rendered !== [] && end($rendered) === '') {
                array_pop($rendered);
            }

            $rendered[] = '';
            $rendered[] = '# Updated from Admin > System Settings';

            foreach ($remaining as $key => $value) {
                $rendered[] = $key.'='.$this->quote($value);
            }
        }

        return rtrim(implode("\n", $rendered), "\n")."\n";
    }

    private function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_.,:\/@+\-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.addcslashes($value, "\\\"\n\r\t").'"';
    }

    private function atomicPut(string $path, string $contents): void
    {
        $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($temp, $contents, LOCK_EX) === false) {
            throw new RuntimeException('environment_file_write_failed');
        }

        @chmod($temp, 0o640);

        if (! rename($temp, $path)) {
            @unlink($temp);

            throw new RuntimeException('environment_file_replace_failed');
        }
    }

    private function backup(string $contents): void
    {
        $directory = storage_path('app/private/system-settings/env-backups');

        if (! is_dir($directory) && ! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException('environment_backup_directory_failed');
        }

        $path = $directory.'/.env-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(2)).'.bak';

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('environment_backup_failed');
        }

        @chmod($path, 0o600);

        $backups = glob($directory.'/.env-*.bak') ?: [];
        rsort($backups, SORT_STRING);

        foreach (array_slice($backups, 5) as $old) {
            @unlink($old);
        }
    }

    private function rebuildConfigCache(): void
    {
        // Unit/feature tests must never rewrite the repository's bootstrap
        // cache. Production and local admin requests do need the new .env to
        // become effective on the very next request.
        if (app()->environment('testing')) {
            return;
        }

        if (Artisan::call('config:clear') !== 0 || Artisan::call('config:cache') !== 0) {
            throw new RuntimeException('config_cache_command_failed');
        }
    }
}

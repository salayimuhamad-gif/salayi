<?php

declare(strict_types=1);

namespace App\Modules\Install\Services;

/**
 * Server pre-flight for the installer (spec 33.1 steps 3-5).
 *
 * Every check returns a structured result rather than a boolean so the wizard
 * can show a Hostinger user exactly what to change in hPanel — "enable the
 * intl extension" is actionable, "requirements not met" is not.
 */
final class RequirementChecker
{
    /**
     * @return array{passed: bool, checks: list<array{key: string, label: string, required: bool, passed: bool, actual: string, expected: string}>}
     */
    public function php(): array
    {
        $min = (string) config('installer.minimum_php', '8.3.0');

        $checks = [[
            'key' => 'php.version',
            'label' => 'PHP version',
            'required' => true,
            'passed' => version_compare(PHP_VERSION, $min, '>='),
            'actual' => PHP_VERSION,
            'expected' => '>= '.$min,
        ]];

        // 64-bit matters: dates past 2038 appear in project delivery data.
        $checks[] = [
            'key' => 'php.64bit',
            'label' => '64-bit integers',
            'required' => true,
            'passed' => PHP_INT_SIZE >= 8,
            'actual' => (PHP_INT_SIZE * 8).'-bit',
            'expected' => '64-bit',
        ];

        return $this->wrap($checks);
    }

    /** @return array{passed: bool, checks: list<array<string, mixed>>} */
    public function extensions(): array
    {
        $checks = [];

        /** @var list<string> $required */
        $required = (array) config('installer.required_extensions', []);
        /** @var list<string> $recommended */
        $recommended = (array) config('installer.recommended_extensions', []);

        foreach ($required as $extension) {
            $checks[] = [
                'key' => 'ext.'.$extension,
                'label' => $extension,
                'required' => true,
                'passed' => extension_loaded($extension),
                'actual' => extension_loaded($extension) ? 'loaded' : 'missing',
                'expected' => 'loaded',
            ];
        }

        foreach ($recommended as $extension) {
            $checks[] = [
                'key' => 'ext.'.$extension,
                'label' => $extension.' (recommended)',
                'required' => false,
                'passed' => extension_loaded($extension),
                'actual' => extension_loaded($extension) ? 'loaded' : 'missing',
                'expected' => 'loaded',
            ];
        }

        return $this->wrap($checks);
    }

    /** @return array{passed: bool, checks: list<array<string, mixed>>} */
    public function permissions(): array
    {
        $checks = [];

        /** @var list<string> $paths */
        $paths = (array) config('installer.writable_paths', []);

        foreach ($paths as $relative) {
            $absolute = base_path($relative);

            if (! is_dir($absolute)) {
                @mkdir($absolute, 0o755, true);
            }

            $writable = is_dir($absolute) && is_writable($absolute);

            $checks[] = [
                'key' => 'path.'.$relative,
                'label' => $relative,
                'required' => true,
                'passed' => $writable,
                'actual' => $writable ? 'writable' : (is_dir($absolute) ? 'not writable' : 'missing'),
                'expected' => 'writable',
            ];
        }

        // .env must be writable at install time so the wizard can generate it,
        // and should be tightened afterwards. Called out explicitly because on
        // Hostinger it is the single most common installation failure.
        $envPath = base_path('.env');
        $envWritable = is_file($envPath) ? is_writable($envPath) : is_writable(base_path());

        $checks[] = [
            'key' => 'path.env',
            'label' => '.env (or project root, to create it)',
            'required' => true,
            'passed' => $envWritable,
            'actual' => $envWritable ? 'writable' : 'not writable',
            'expected' => 'writable',
        ];

        return $this->wrap($checks);
    }

    /**
     * Advisory checks. None of these block installation, but every one of them
     * causes a support ticket later if ignored.
     *
     * @return array{passed: bool, checks: list<array<string, mixed>>}
     */
    public function advisory(): array
    {
        $memory = $this->bytes((string) ini_get('memory_limit'));
        $maxExecution = (int) ini_get('max_execution_time');
        $upload = $this->bytes((string) ini_get('upload_max_filesize'));

        return $this->wrap([
            [
                'key' => 'ini.memory_limit',
                'label' => 'memory_limit',
                'required' => false,
                'passed' => $memory === -1 || $memory >= 256 * 1024 * 1024,
                'actual' => (string) ini_get('memory_limit'),
                'expected' => '>= 256M (imports and image processing)',
            ],
            [
                'key' => 'ini.max_execution_time',
                'label' => 'max_execution_time',
                'required' => false,
                'passed' => $maxExecution === 0 || $maxExecution >= 60,
                'actual' => (string) $maxExecution,
                'expected' => '>= 60 (migration step)',
            ],
            [
                'key' => 'ini.upload_max_filesize',
                'label' => 'upload_max_filesize',
                'required' => false,
                'passed' => $upload >= 20 * 1024 * 1024,
                'actual' => (string) ini_get('upload_max_filesize'),
                'expected' => '>= 20M (project media and Excel imports)',
            ],
            [
                'key' => 'https',
                'label' => 'HTTPS',
                'required' => false,
                'passed' => request()->isSecure(),
                'actual' => request()->isSecure() ? 'yes' : 'no',
                'expected' => 'yes (secure cookies and Telegram callbacks require it)',
            ],
        ]);
    }

    /** @return array{passed: bool, groups: array<string, array<string, mixed>>} */
    public function all(): array
    {
        $groups = [
            'php' => $this->php(),
            'extensions' => $this->extensions(),
            'permissions' => $this->permissions(),
            'advisory' => $this->advisory(),
        ];

        $passed = $groups['php']['passed']
            && $groups['extensions']['passed']
            && $groups['permissions']['passed'];

        return ['passed' => $passed, 'groups' => $groups];
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @return array{passed: bool, checks: list<array<string, mixed>>}
     */
    private function wrap(array $checks): array
    {
        $passed = true;

        foreach ($checks as $check) {
            if (($check['required'] ?? false) === true && ($check['passed'] ?? false) === false) {
                $passed = false;
                break;
            }
        }

        return ['passed' => $passed, 'checks' => $checks];
    }

    private function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}

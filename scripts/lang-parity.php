<?php

declare(strict_types=1);

/*
 * mbstring is a PRODUCTION requirement (composer.json declares ext-mbstring)
 * and this script does not need it to be present.
 *
 * A local audit environment without the extension previously fataled with
 * "undefined function mb_strlen" — so the check that verifies trilingual
 * parity could not run in exactly the sparse environments where somebody would
 * reach for it.
 */
function safe_length(string $value): int
{
    // Character count where possible; byte count is a safe over-estimate for
    // the "is this string long enough to be a real translation" test below.
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

/**
 * Translation parity guard (spec 37.1: "No missing key Sorani translations in
 * production", spec 7.3 "Missing translation detection").
 *
 * Sorani (ckb) is the reference. Every key authored there must exist in every
 * other enabled locale, and no other locale may carry a key ckb does not have
 * — an orphan usually means a key was renamed in ckb and the translation was
 * left behind, which produces a silently dead string.
 *
 * Because config/app.php sets fallback_locale to ckb rather than en, a missing
 * ckb key does NOT silently render English. It renders the raw key, which is
 * ugly and obvious. This script is what stops that reaching production.
 *
 * Usage:
 *   php scripts/lang-parity.php            report only, exit 0
 *   php scripts/lang-parity.php --strict   exit 1 on any discrepancy (CI)
 *   php scripts/lang-parity.php --json     machine-readable output
 */
$root = dirname(__DIR__);
$langPath = $root.'/lang';

$strict = in_array('--strict', $argv, true);
$json = in_array('--json', $argv, true);

$reference = 'ckb';
$compare = ['ar', 'en'];

/** Flatten a nested translation array to dot keys. */
function flattenKeys(array $array, string $prefix = ''): array
{
    $out = [];

    foreach ($array as $key => $value) {
        $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $out = array_merge($out, flattenKeys($value, $full));

            continue;
        }

        $out[$full] = $value;
    }

    return $out;
}

function loadLocale(string $langPath, string $locale): array
{
    $dir = $langPath.'/'.$locale;

    if (! is_dir($dir)) {
        return [];
    }

    $all = [];

    foreach (glob($dir.'/*.php') ?: [] as $file) {
        $group = basename($file, '.php');
        /** @var array<string, mixed> $contents */
        $contents = require $file;

        if (! is_array($contents)) {
            continue;
        }

        foreach (flattenKeys($contents) as $key => $value) {
            $all[$group.'.'.$key] = $value;
        }
    }

    return $all;
}

$referenceKeys = loadLocale($langPath, $reference);

if ($referenceKeys === []) {
    fwrite(STDERR, "lang-parity: no reference ({$reference}) translations found in {$langPath}\n");
    exit(1);
}

$report = [
    'reference' => $reference,
    'reference_key_count' => count($referenceKeys),
    'locales' => [],
    'ok' => true,
];

foreach ($compare as $locale) {
    $keys = loadLocale($langPath, $locale);

    $missing = array_values(array_diff(array_keys($referenceKeys), array_keys($keys)));
    $orphaned = array_values(array_diff(array_keys($keys), array_keys($referenceKeys)));

    // An empty string is a missing translation wearing a disguise.
    $blank = [];

    foreach ($keys as $key => $value) {
        if (is_string($value) && trim($value) === '') {
            $blank[] = $key;
        }
    }

    // A value identical to the ckb source is almost always an untranslated
    // copy-paste. Reported as a warning, not a failure — some strings (a brand
    // name, a currency code) legitimately match.
    $untranslated = [];

    foreach ($keys as $key => $value) {
        if (is_string($value) && isset($referenceKeys[$key]) && $value === $referenceKeys[$key] && safe_length($value) > 3) {
            $untranslated[] = $key;
        }
    }

    $localeOk = $missing === [] && $orphaned === [] && $blank === [];

    $report['locales'][$locale] = [
        'key_count' => count($keys),
        'missing' => $missing,
        'orphaned' => $orphaned,
        'blank' => $blank,
        'possibly_untranslated' => $untranslated,
        'ok' => $localeOk,
    ];

    if (! $localeOk) {
        $report['ok'] = false;
    }
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($strict && ! $report['ok'] ? 1 : 0);
}

echo "\nTranslation parity — reference locale: {$reference} ({$report['reference_key_count']} keys)\n";
echo str_repeat('─', 68), "\n";

foreach ($report['locales'] as $locale => $result) {
    $status = $result['ok'] ? "\033[32mOK\033[0m" : "\033[31mFAIL\033[0m";
    printf("  %-4s %-6s  %d keys\n", $locale, $status, $result['key_count']);

    foreach (['missing' => 'missing in '.$locale, 'orphaned' => 'orphaned (not in ckb)', 'blank' => 'blank value'] as $bucket => $label) {
        if ($result[$bucket] !== []) {
            echo "         \033[31m{$label}:\033[0m\n";

            foreach (array_slice($result[$bucket], 0, 20) as $key) {
                echo "           - {$key}\n";
            }

            if (count($result[$bucket]) > 20) {
                echo '           … and '.(count($result[$bucket]) - 20)." more\n";
            }
        }
    }

    if ($result['possibly_untranslated'] !== []) {
        echo "         \033[33mwarning — identical to ckb source:\033[0m\n";

        foreach (array_slice($result['possibly_untranslated'], 0, 10) as $key) {
            echo "           ~ {$key}\n";
        }
    }
}

echo str_repeat('─', 68), "\n";

if ($report['ok']) {
    echo "  \033[42;30m PASS \033[0m  all locales in parity with {$reference}\n\n";
    exit(0);
}

echo "  \033[41;37m FAIL \033[0m  parity violations found\n\n";
exit($strict ? 1 : 0);

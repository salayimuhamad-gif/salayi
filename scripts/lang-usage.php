<?php

declare(strict_types=1);

/**
 * Translation usage guard.
 *
 * scripts/lang-parity.php compares the locales against each other: if ckb has a
 * key, ar and en must too. That is necessary and it is blind to the failure
 * this script catches — a key that is used by the interface and defined in NO
 * locale at all. Parity is perfectly happy, because the key is equally absent
 * everywhere.
 *
 * The consequence is not a crash. `t()` deliberately falls through to the key
 * itself rather than to English, so the page renders the literal string
 * `geography.nearby.straight_line_notice` to the visitor. Eight such keys were
 * live when this script was written, two of them on the public project page.
 *
 * Only statically analysable calls are checked: t('a.b') and t("a.b").
 * A computed key — t(`market.warnings.${row.warning}`) — cannot be resolved
 * without executing the app, so those are counted and reported separately
 * rather than guessed at.
 *
 * Runs with no Composer, like every other script here.
 *
 * Usage: php scripts/lang-usage.php [--json]
 */
$root = dirname(__DIR__);
$reference = 'ckb';
$asJson = in_array('--json', $argv, true);

// ------------------------------------------------------------ defined keys

$defined = [];

foreach (glob($root.'/lang/'.$reference.'/*.php') ?: [] as $file) {
    $group = basename($file, '.php');

    $flatten = static function (array $items, string $prefix) use (&$flatten, &$defined): void {
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $flatten($value, $prefix.$key.'.');

                continue;
            }

            $defined[$prefix.$key] = true;
        }
    };

    $flatten((array) include $file, $group.'.');
}

// --------------------------------------------------------------- used keys

/** @var array<string, list<string>> $used */
$used = [];
$dynamic = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root.'/resources/js', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (! in_array($file->getExtension(), ['vue', 'ts'], true)) {
        continue;
    }

    $contents = (string) file_get_contents($file->getPathname());
    $short = str_replace($root.'/', '', $file->getPathname());

    // Static: t('group.key') or t("group.key")
    if (preg_match_all('/\bt\(\s*[\'"]([a-z0-9_]+(?:\.[a-z0-9_]+)+)[\'"]/i', $contents, $matches)) {
        foreach ($matches[1] as $key) {
            $used[$key][] = $short;
        }
    }

    // Dynamic: t(`group.${expr}`) — counted, not resolved.
    $dynamic += preg_match_all('/\bt\(\s*`[^`]*\$\{/', $contents);
}

ksort($used);

$missing = [];

foreach ($used as $key => $files) {
    if (! isset($defined[$key])) {
        $missing[$key] = array_values(array_unique($files));
    }
}

// ------------------------------------------------- duplicate key detection

/*
 * A repeated array key in a translation file is silently legal PHP: the last
 * definition wins and the earlier one vanishes without a warning. `geography.php`
 * carried two `'nearby' => [...]` blocks in all three locales, and the smaller,
 * later one was shadowing eight keys the interface actually used — which is how
 * a public page ended up rendering `geography.nearby.straight_line_notice` as
 * literal text.
 *
 * Neither `php -l` nor parity can see this: the file is valid, and every locale
 * is equally wrong, so they agree with each other perfectly.
 */
$duplicates = [];

foreach (glob($root.'/lang/*/*.php') ?: [] as $file) {
    $contents = (string) file_get_contents($file);
    $short = str_replace($root.'/', '', $file);
    $seen = [];

    // Top-level groups are indented exactly four spaces by house style.
    if (preg_match_all("/^    '([a-z0-9_]+)' => \[/m", $contents, $matches)) {
        foreach ($matches[1] as $key) {
            if (isset($seen[$key])) {
                $duplicates[] = [$short, $key];
            }

            $seen[$key] = true;
        }
    }
}

// ----------------------------------------------------------------- report

if ($asJson) {
    echo json_encode([
        'ok' => $missing === [] && $duplicates === [],
        'defined' => count($defined),
        'used_static' => count($used),
        'dynamic_calls' => $dynamic,
        'missing' => $missing,
        'duplicate_groups' => $duplicates,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";

    exit($missing === [] && $duplicates === [] ? 0 : 1);
}

echo "\nTranslation usage — keys referenced by the interface\n";
echo str_repeat('─', 68), "\n";
printf("  \033[36mNOTE\033[0m  %d defined in %s, %d referenced statically\n", count($defined), $reference, count($used));
printf("  \033[36mNOTE\033[0m  %d dynamic call(s) not statically checkable\n", $dynamic);

foreach ($duplicates as [$file, $key]) {
    printf("  \033[31mFAIL\033[0m  duplicate group '%s' in %s — the later block silently wins\n", $key, $file);
}

foreach ($missing as $key => $files) {
    printf("  \033[31mFAIL\033[0m  %s\n", $key);

    foreach (array_slice($files, 0, 3) as $file) {
        printf("          used in %s\n", $file);
    }
}

echo str_repeat('─', 68), "\n";

if ($missing === [] && $duplicates === []) {
    echo "  \033[42;30m PASS \033[0m  every referenced key is defined, no group is shadowed\n\n";
    exit(0);
}

printf(
    "  \033[41;37m FAIL \033[0m  %d undefined key(s), %d shadowed group(s)\n\n",
    count($missing),
    count($duplicates)
);
exit(1);

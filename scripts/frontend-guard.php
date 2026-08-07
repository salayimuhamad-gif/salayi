<?php

declare(strict_types=1);

/**
 * Frontend build guard (repair prompt §1.8, §1.10, §1.11).
 *
 * Three assertions, each closing a defect that reached the shipped archive
 * without any check noticing:
 *
 *   1. PAGES — every Inertia::render('X') target must exist as a .vue file.
 *      IndexValueController rendered 'Admin/Market/IndexValues' and the file
 *      did not exist. The route resolved, the controller ran, the query ran,
 *      and the failure surfaced only in the browser as a blank component.
 *
 *   2. MANIFEST — public/build/manifest.json must exist. Vite's
 *      `build.manifest: true` writes .vite/manifest.json, while Laravel's Vite
 *      helper reads public/build/manifest.json. The build succeeded and
 *      production would still have thrown "Vite manifest not found".
 *
 *   3. FRESHNESS — no resources/js source may be newer than the newest build
 *      artifact. The shipped bundle predated the notification pages by five
 *      hours because `npm run build` is gated on vue-tsc, vue-tsc was failing,
 *      and nobody was told. A stale bundle is invisible: the app runs, serving
 *      last week's code.
 *
 * Runs without Composer, like every other script here, so it works in a
 * sandbox with Packagist blocked.
 *
 * Usage: php scripts/frontend-guard.php [--skip-freshness]
 *
 * --skip-freshness is for CI jobs that lint/typecheck without building.
 */
$root = dirname(__DIR__);
$skipFreshness = in_array('--skip-freshness', $argv, true);

/** @var list<array{0:string,1:string}> $failures */
$failures = [];
/** @var list<string> $notes */
$notes = [];

// ---------------------------------------------------------------- 1. PAGES

$pagesDir = $root.'/resources/js/Pages';

/** Recursively collect every .vue under Pages/, as Inertia dot-free paths. */
$existing = [];
if (is_dir($pagesDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pagesDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'vue') {
            $relative = substr($file->getPathname(), strlen($pagesDir) + 1);
            $existing[substr($relative, 0, -4)] = true;
        }
    }
}

/** Scan PHP sources for Inertia::render('Page/Name') and ->render('Page/Name'). */
$rendered = [];
$phpRoots = [$root.'/app', $root.'/routes'];

foreach ($phpRoots as $phpRoot) {
    if (! is_dir($phpRoot)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($phpRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (! preg_match_all(
            "/Inertia::render\(\s*'([^']+)'|->render\(\s*'([^']+)'/",
            $contents,
            $matches,
            PREG_SET_ORDER
        )) {
            continue;
        }

        foreach ($matches as $match) {
            $page = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

            // A variable or concatenated page name cannot be checked statically.
            if ($page === '' || str_contains($page, '$')) {
                continue;
            }

            $short = str_replace($root.'/', '', $file->getPathname());
            $rendered[$page][] = $short;
        }
    }
}

ksort($rendered);

foreach ($rendered as $page => $sources) {
    if (! isset($existing[$page])) {
        $failures[] = [
            'PAGES',
            sprintf(
                "Inertia::render('%s') has no resources/js/Pages/%s.vue — rendered from %s",
                $page,
                $page,
                implode(', ', array_unique($sources))
            ),
        ];
    }
}

$notes[] = sprintf(
    '%d rendered page reference(s) checked against %d .vue file(s)',
    count($rendered),
    count($existing)
);

// ------------------------------------------------------------- 2. MANIFEST

$buildDir = $root.'/public/build';
$manifest = $buildDir.'/manifest.json';

if (! is_dir($buildDir)) {
    $failures[] = ['MANIFEST', 'public/build does not exist — the frontend has never been built'];
} elseif (! is_file($manifest)) {
    $hint = is_file($buildDir.'/.vite/manifest.json')
        ? " (found .vite/manifest.json instead — set build.manifest: 'manifest.json' in vite.config.ts)"
        : '';

    $failures[] = ['MANIFEST', 'public/build/manifest.json is missing — Laravel cannot resolve production assets'.$hint];
} else {
    $decoded = json_decode((string) file_get_contents($manifest), true);

    if (! is_array($decoded) || $decoded === []) {
        $failures[] = ['MANIFEST', 'public/build/manifest.json is not valid, non-empty JSON'];
    } else {
        $notes[] = sprintf('manifest at public/build/manifest.json with %d entries', count($decoded));
    }
}

// ------------------------------------------------------------ 3. FRESHNESS

if (! $skipFreshness && is_dir($buildDir)) {
    $newestBuild = 0;
    $buildIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($buildDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($buildIterator as $file) {
        $newestBuild = max($newestBuild, $file->getMTime());
    }

    $newestSource = 0;
    $newestSourceFile = '';
    $sourceDirs = [$root.'/resources/js', $root.'/resources/css'];

    foreach ($sourceDirs as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        $sourceIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($sourceIterator as $file) {
            if (! in_array($file->getExtension(), ['vue', 'ts', 'js', 'css'], true)) {
                continue;
            }

            if ($file->getMTime() > $newestSource) {
                $newestSource = $file->getMTime();
                $newestSourceFile = str_replace($root.'/', '', $file->getPathname());
            }
        }
    }

    if ($newestBuild === 0) {
        $failures[] = ['FRESHNESS', 'public/build contains no files'];
    } elseif ($newestSource > $newestBuild) {
        $failures[] = [
            'FRESHNESS',
            sprintf(
                'stale build: %s modified %s, newest build artifact is %s — run `npm run build`',
                $newestSourceFile,
                date('Y-m-d H:i:s', $newestSource),
                date('Y-m-d H:i:s', $newestBuild)
            ),
        ];
    } else {
        $notes[] = sprintf('build artifact (%s) is at least as new as every source file', date('Y-m-d H:i:s', $newestBuild));
    }
}

// ---------------------------------------------------------------- REPORT

echo "\nFrontend guard — Inertia pages, Vite manifest, build freshness\n";
echo str_repeat('─', 68), "\n";

foreach ($notes as $note) {
    printf("  \033[36mNOTE\033[0m  %s\n", $note);
}

foreach ($failures as [$kind, $message]) {
    printf("  \033[31mFAIL\033[0m  [%s] %s\n", $kind, $message);
}

echo str_repeat('─', 68), "\n";

if ($failures === []) {
    echo "  \033[42;30m PASS \033[0m  every rendered page exists, the manifest is resolvable, the build is current\n\n";
    exit(0);
}

printf("  \033[41;37m FAIL \033[0m  %d issue(s)\n\n", count($failures));
exit(1);

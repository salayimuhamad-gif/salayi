<?php

declare(strict_types=1);

/*
 * The packaging script must not ship its own working files.
 *
 * `.bundle-list` was written into the output directory before the file list was
 * taken, so it listed itself and was delivered inside the Release Bundle. The
 * script's own audit did not notice: it was looking for secrets, not for its
 * own litter. This gate reads the script and requires that any internal list is
 * created outside the directory being archived, and that the archive audit
 * rejects such files by name.
 */

declare(ticks=1);

require_once __DIR__.'/../../scripts/support/TestTally.php';

use Mulkihawler\Tooling\TestTally;

$script = (string) file_get_contents(__DIR__.'/../../scripts/package-release.sh');

$ok = static fn (string $name, bool $condition, string $detail = ''): bool => TestTally::check($name, $condition, $detail);

/* 1. No internal list is created inside the output directory. */
$ok(
    'the bundle file list is written outside the output directory',
    ! preg_match('/>\s*\.bundle-list/', $script)
    && str_contains($script, '$STAGE/bundle-list.txt'),
    'a list written into $OUT ends up listing itself',
);

/* 2. The archive audit rejects packaging temp files by name. */
$ok(
    'the content audit rejects packaging internals by name',
    str_contains($script, 'PACKAGING_INTERNALS')
    && str_contains($script, 'bundle-list')
    && str_contains($script, 'file-list')
    && str_contains($script, 'archive-list')
    && str_contains($script, 'manifest')
    && str_contains($script, '.tmp'),
    'the audit must reject bundle-list, file-list, archive-list, manifest.tmp and *.tmp',
);

$ok(
    'the audit runs over both delivered archives',
    (bool) preg_match('/for z in "\$CLEAN_ZIP" "\$DEPLOY_ZIP"/', $script),
    'a pattern applied to only one archive protects only one archive',
);

/* 3. Staging is cleaned on both success and failure. */
$ok(
    'the staging directory is removed on exit, including failure',
    str_contains($script, "trap 'rm -rf \"\$STAGE\"' EXIT"),
    'without an EXIT trap a failed run leaves the staging tree behind',
);

/* 4. Nested release bundles are never packaged. */
$ok(
    'a Release Bundle is never nested inside another archive',
    str_contains($script, 'Release_Bundle'),
    'the audit must reject a bundle inside a bundle',
);

echo TestTally::failures() === 0
    ? "\nALL PACKAGING HYGIENE ASSERTIONS PASSED\n"
    : "\n".TestTally::failures()." PACKAGING HYGIENE FAILURES\n";

exit(TestTally::exitCode());

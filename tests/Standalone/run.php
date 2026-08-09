<?php

declare(strict_types=1);

/*
 * Standalone suite — no Laravel, no database, no Composer.
 *
 * These cover the pure geometry helpers the Map Explorer depends on. They
 * exist because the PHPUnit suite cannot run wherever Packagist is
 * unreachable, and the two defects that shipped in the boundary layer (a ring
 * list treated as a ring, and a degree-based simplifier) were both provable
 * without a framework. Anything testable without Laravel belongs here, so a
 * blocked dependency install never means zero executed tests.
 *
 * Usage: php tests/Standalone/run.php
 */

$root = dirname(__DIR__, 2);
$failures = 0;

foreach (['SimplifierTest.php', 'GeometryTest.php', 'StructureTest.php', 'SignatureGuardTest.php', 'RelationGenericsTest.php', 'PhpstanMeasureTest.php', 'PackagingHygieneTest.php', 'ArtifactEvidenceTest.php', 'SourceProvenanceTest.php', 'DocConsistencyFixturesTest.php', 'WktValidationTest.php', 'TelegramVerificationContractTest.php'] as $file) {
    echo "\n=== {$file} ===\n";

    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$file);
    passthru('cd '.escapeshellarg($root).' && '.$command, $status);

    if ($status !== 0) {
        $failures++;
    }
}

echo $failures === 0
    ? "\nSTANDALONE SUITE PASSED\n"
    : "\n{$failures} STANDALONE FILE(S) FAILED\n";

exit($failures === 0 ? 0 : 1);

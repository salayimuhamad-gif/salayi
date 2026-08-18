<?php

declare(strict_types=1);

/**
 * Prove the rollback inventory, the documentation and the files agree.
 *
 * Three places have carried three different counts at once. This reads the
 * command's constant as the single source of truth and checks the other two
 * against it, so they cannot drift again without failing.
 */
$root = dirname(__DIR__);

$source = (string) file_get_contents($root.'/app/Modules/Projects/Console/RollbackWizardSchema.php');

preg_match('/const MIGRATIONS = \[(.*?)\];/s', $source, $block);
preg_match_all("/'(2026_[a-z0-9_]+)'/", $block[1] ?? '', $names);

$inventory = $names[1] ?? [];
$failures = 0;

echo "\nRollback inventory — one source of truth\n";
echo str_repeat('─', 68)."\n";
echo '  NOTE  '.count($inventory)." migration(s) in RollbackWizardSchema::MIGRATIONS\n";

// 1. Every named migration must exist on disk.
foreach ($inventory as $migration) {
    $found = glob($root.'/app/Modules/*/Database/Migrations/'.$migration.'.php');

    if ($found === [] || $found === false) {
        echo "  FAIL  no file for {$migration}\n";
        $failures++;
    }
}

// 2. The documentation must state the same count.
$doc = (string) file_get_contents($root.'/docs/PROJECT_WIZARD_SLICE.md');

$words = [
    10 => 'TEN', 11 => 'ELEVEN', 12 => 'TWELVE', 13 => 'THIRTEEN',
    14 => 'FOURTEEN', 15 => 'FIFTEEN', 16 => 'SIXTEEN',
];

$expected = $words[count($inventory)] ?? null;

if ($expected === null || ! str_contains($doc, $expected)) {
    echo '  FAIL  documentation does not state '.($expected ?? 'the correct count')."\n";
    $failures++;
}

// 3. No OTHER count word may appear, or the document contradicts itself.
foreach ($words as $count => $word) {
    if ($count !== count($inventory) && preg_match('/\b'.$word.' migrations\b/', $doc)) {
        echo "  FAIL  documentation also claims {$word} migrations\n";
        $failures++;
    }
}

echo str_repeat('─', 68)."\n";
echo $failures === 0
    ? "  PASS  inventory, files and documentation agree\n\n"
    : "  FAIL  {$failures} inconsistenc(y/ies)\n\n";

exit($failures === 0 ? 0 : 1);

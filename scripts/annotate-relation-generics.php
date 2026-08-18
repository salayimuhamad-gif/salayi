<?php

declare(strict_types=1);

/*
 * Add or correct the generic type parameters on Eloquent relations and local
 * scopes, using PHP's tokeniser rather than text matching.
 *
 * PHPStan is right that a bare `HasMany` says nothing about what the relation
 * returns. The parameters are derived from the relation call itself, so an
 * annotation cannot drift from the code it describes.
 *
 * TRANSACTIONAL: every file is rewritten in memory, syntax checked and
 * semantically validated; the tree is written only when ALL of them pass. Two
 * earlier text-based versions left a model with a parse error, which is a
 * worse outcome than any missing annotation.
 *
 * Usage: php scripts/annotate-relation-generics.php [--check]
 */

require __DIR__.'/support/RelationGenerics.php';

use Mulkihawler\Tooling\RelationGenerics;

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$engine = new RelationGenerics;

$files = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && str_contains($file->getPathname(), '/Models/')) {
        $files[] = $file->getPathname();
    }
}

sort($files);

if ($check) {
    $problems = [];

    foreach ($files as $path) {
        $problems = array_merge(
            $problems,
            $engine->problems((string) file_get_contents($path), str_replace($root.'/', '', $path)),
        );
    }

    if ($problems !== []) {
        fwrite(STDERR, "Relation generics are wrong or missing:\n  ".implode("\n  ", $problems)."\n");
        exit(1);
    }

    echo "relation generics are current\n";
    exit(0);
}

$proposals = [];

foreach ($files as $path) {
    $proposed = $engine->propose((string) file_get_contents($path));

    if ($proposed !== null) {
        $proposals[$path] = $proposed;
    }
}

try {
    $written = $engine->commit($proposals);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage()."\n\nNo application files were modified.\n");
    exit(1);
}

echo 'annotated '.count($written)." model file(s)\n";

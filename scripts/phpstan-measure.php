<?php

declare(strict_types=1);

/*
 * Measure PHPStan safely: a run that did not complete is reported as
 * MEASUREMENT INVALID, never as zero findings.
 *
 * Usage: php scripts/phpstan-measure.php [paths...]
 */

require __DIR__.'/support/PhpstanResult.php';

use Mulkihawler\Tooling\PhpstanResult;

$root = dirname(__DIR__);
$paths = array_slice($argv, 1);

$out = tempnam(sys_get_temp_dir(), 'phpstan-').'.json';
$err = tempnam(sys_get_temp_dir(), 'phpstan-').'.err';

$command = escapeshellcmd($root.'/vendor/bin/phpstan')
    .' analyse --no-progress --memory-limit=1G --error-format=json'
    .($paths === [] ? '' : ' '.implode(' ', array_map('escapeshellarg', $paths)))
    .' > '.escapeshellarg($out).' 2> '.escapeshellarg($err);

$exit = 0;
passthru($command, $exit);

$result = PhpstanResult::parse(
    $exit,
    is_file($out) ? (string) file_get_contents($out) : null,
    is_file($err) ? (string) file_get_contents($err) : '',
    'vendor/bin/phpstan analyse --error-format=json'.($paths === [] ? '' : ' '.implode(' ', $paths)),
);

if ($result->valid) {
    file_put_contents($root.'/docs/phpstan-latest.json', (string) file_get_contents($out));
}

@unlink($out);
@unlink($err);

echo $result->describe()."\n";

exit($result->valid ? 0 : 1);

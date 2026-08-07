<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
 * Hostinger split layout (spec 34.1):
 *
 *   /home/ACCOUNT/application   <- Laravel private application root
 *   /home/ACCOUNT/public_html   <- this file + compiled assets only
 *
 * MULKIHAWLER_APP_BASE lets the deployment artifact stay identical between a
 * standard single-root install and the Hostinger split, without editing code.
 * It is resolved before the autoloader so a wrong path fails loudly here
 * rather than as an opaque class-not-found later.
 */
$base = getenv('MULKIHAWLER_APP_BASE') ?: dirname(__DIR__).'/application';
$base = rtrim($base, '/');

/*
 * FAIL WITHOUT TALKING.
 *
 * This block used to print `Looked in: {$base}` straight to the browser. On the
 * Hostinger split layout $base is `/home/<ACCOUNT>/application`, so a single
 * misconfigured deployment served the hosting account name and the absolute
 * home directory to anybody who loaded the site — including a scanner. The
 * operator needs that string; a visitor must never see it.
 *
 * So: the detail goes to the server error log, and the browser gets a sentence
 * that says something is wrong and nothing about where.
 *
 * MULKIHAWLER_APP_BASE is operator-supplied configuration, which makes it
 * untrusted input to this file. It is validated before it is used to build a
 * require() path — absolute, no null byte, no traversal segment, resolvable,
 * and actually containing the two files that must be there. A require() built
 * from an unvalidated environment string is a local file include waiting for
 * the day something else can set that variable.
 */
$fail = static function (string $detail): never {
    error_log('Mulkihawler deployment configuration error: '.$detail);

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    exit("Deployment configuration error.\n");
};

if ($base === '' || str_contains($base, "\0")) {
    $fail('MULKIHAWLER_APP_BASE is empty or contains a null byte.');
}

if (! str_starts_with($base, '/')) {
    $fail('MULKIHAWLER_APP_BASE must be an absolute path; got a relative value.');
}

if (in_array('..', explode('/', $base), true)) {
    $fail('MULKIHAWLER_APP_BASE must not contain a traversal segment: '.$base);
}

$resolved = realpath($base);

if ($resolved === false || ! is_dir($resolved)) {
    $fail('Application root does not exist or is not a directory: '.$base);
}

$base = rtrim($resolved, '/');

foreach (['/vendor/autoload.php', '/bootstrap/app.php'] as $required) {
    if (! is_file($base.$required)) {
        $fail('Missing required file '.$required.' under application root '.$base
            .'. Set MULKIHAWLER_APP_BASE in public_html/.user.ini or the host panel'
            .' to the absolute path of the /application directory.');
    }
}

if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $base.'/bootstrap/app.php';

/*
 * TELL LARAVEL WHERE `public` ACTUALLY IS.
 *
 * On a split deployment the web root is `public_html/` beside the application,
 * not `application/public/` — the packaging step moves it and removes the
 * original. Laravel still resolved `public_path()` to the vanished directory,
 * so `Vite::manifest()` looked for the build manifest at
 * `application/public/build/manifest.json` and every page threw
 * "Vite manifest not found". The assets were present the whole time, one
 * directory across.
 *
 * `__DIR__` is the real web root in both layouts: beside the application in
 * development, and `public_html/` after deployment.
 */
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());

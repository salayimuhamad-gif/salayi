<?php

declare(strict_types=1);

namespace Mulkihawler\Tooling;

/**
 * Where mutable run evidence lives — deliberately OUTSIDE the source tree.
 *
 * `docs/release-evidence.json` used to sit inside the tree while recording that
 * tree's own manifest hash. That is a self-referential identity: updating the
 * file to name the current tree changes the file, which changes the manifest,
 * which produces a different hash, which the file no longer names. Every
 * "freeze, collect, refreeze" pass therefore moved the tree, and no number of
 * additional passes could converge. It was a design defect, not a scheduling
 * problem.
 *
 * The boundary is now explicit:
 *
 *   IMMUTABLE SOURCE   product code, tests, portable tooling, and static
 *                      documentation that embeds no artifact identity.
 *   EXTERNAL EVIDENCE  release-evidence.json, the command ledger, raw logs,
 *                      indexes, reports, and every final hash.
 *
 * Nothing written after the freeze may land inside the source tree.
 */
final class EvidencePath
{
    public const OPTION = '--evidence-dir=';

    public const ENV = 'MYHAWLER_EVIDENCE_DIR';

    public const FILENAME = 'release-evidence.json';

    /**
     * Resolve the external evidence directory.
     *
     * Defaults to a sibling of the project root rather than anything beneath
     * it, so the default is correct rather than merely convenient.
     *
     * @param  list<string>  $argv
     */
    public static function directory(string $root, array $argv = []): string
    {
        foreach ($argv as $argument) {
            if (str_starts_with($argument, self::OPTION)) {
                $value = trim(substr($argument, strlen(self::OPTION)));

                if ($value !== '') {
                    return rtrim($value, '/');
                }
            }
        }

        $environment = getenv(self::ENV);

        if (is_string($environment) && trim($environment) !== '') {
            return rtrim(trim($environment), '/');
        }

        return dirname(rtrim($root, '/')).'/myhawler-evidence';
    }

    /**
     * @param  list<string>  $argv
     */
    public static function evidenceFile(string $root, array $argv = []): string
    {
        return self::directory($root, $argv).'/'.self::FILENAME;
    }

    /**
     * True when $path would be written inside the source tree.
     *
     * realpath() alone is not enough: it returns false for a directory that
     * does not exist yet, which made a not-yet-created directory INSIDE the
     * source read as "outside", and the collector then created it there. The
     * candidate is therefore normalised textually against the nearest existing
     * ancestor, so a path is judged on where it would land rather than on
     * whether it happens to exist.
     */
    public static function isInsideSource(string $root, string $path): bool
    {
        $realRoot = realpath($root);

        if ($realRoot === false) {
            // An unresolvable root cannot be proved safe, so refuse.
            return true;
        }

        $realRoot = rtrim($realRoot, '/');
        $candidate = self::normalise($path);

        return $candidate === $realRoot || str_starts_with($candidate, $realRoot.'/');
    }

    /**
     * Absolute, normalised path for a location that need not exist.
     *
     * The nearest existing ancestor is resolved with realpath() so symlinked
     * parents cannot be used to escape and re-enter; the remaining components
     * are then applied textually, with `.` dropped and `..` popped.
     */
    public static function normalise(string $path): string
    {
        if (! str_starts_with($path, '/')) {
            $path = rtrim((string) getcwd(), '/').'/'.$path;
        }

        $segments = explode('/', trim($path, '/'));
        $pending = [];
        $existing = null;

        // Walk from the full path upwards until something exists.
        for ($i = count($segments); $i >= 0; $i--) {
            $prefix = '/'.implode('/', array_slice($segments, 0, $i));
            $resolved = realpath($prefix);

            if ($resolved !== false) {
                $existing = rtrim($resolved, '/');
                $pending = array_slice($segments, $i);
                break;
            }
        }

        if ($existing === null) {
            $existing = '';
            $pending = $segments;
        }

        $parts = $existing === '' ? [] : explode('/', trim($existing, '/'));

        foreach ($pending as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return '/'.implode('/', $parts);
    }
}

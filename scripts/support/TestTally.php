<?php

declare(strict_types=1);

namespace Mulkihawler\Tooling;

/**
 * A failure counter the analyser can actually reason about.
 *
 * The standalone suites counted failures in a `global $failures` incremented
 * inside a helper function. PHP is happy with that, but static analysis cannot
 * follow a global mutated from another scope, so every suite's final
 * `exit($failures === 0 ? 0 : 1)` was reported as an always-true comparison —
 * fourteen findings all saying the same thing: "this counter looks like a
 * constant zero to me".
 *
 * That is a fair complaint about the harness rather than about the checks. A
 * counter held here is visible to the analyser and to a reader, and it removes
 * the last globals from the suite.
 */
final class TestTally
{
    private static int $failures = 0;

    private static int $passes = 0;

    public static function reset(): void
    {
        self::$failures = 0;
        self::$passes = 0;
    }

    public static function pass(): void
    {
        self::$passes++;
    }

    public static function fail(): void
    {
        self::$failures++;
    }

    public static function failures(): int
    {
        return self::$failures;
    }

    public static function passes(): int
    {
        return self::$passes;
    }

    /**
     * Record one assertion and report it, returning whether it held.
     *
     * Centralised so every suite prints the same way and no suite can count a
     * failure without printing it, or print one without counting it — which is
     * how a red suite quietly exits zero.
     */
    public static function check(string $name, bool $condition, string $detail = ''): bool
    {
        if ($condition) {
            self::pass();
            echo "  pass {$name}\n";

            return true;
        }

        self::fail();
        echo "  FAIL {$name}\n";

        if ($detail !== '') {
            echo '        '.str_replace("\n", "\n        ", $detail)."\n";
        }

        return false;
    }

    /** The process exit code this suite should end with. */
    public static function exitCode(): int
    {
        return self::$failures === 0 ? 0 : 1;
    }
}

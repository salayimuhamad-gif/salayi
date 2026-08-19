<?php

declare(strict_types=1);

namespace Fixtures\SignatureGuard;

/** A command whose signature is the option contract under test. */
final class FixtureCommand
{
    protected string $signature = 'mulkihawler:fixture {--dry-run} {--limit=} {--tag=*}';
}

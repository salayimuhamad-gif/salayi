<?php

declare(strict_types=1);

namespace Fixtures\SignatureGuard;

/** A known contract the guard's self-tests are measured against. */
final class Subject
{
    public const KNOWN = 'known';

    /** Four parameters, all required — the shape of finaliseAbsentSource(). */
    public function finalise(int $mediaId, string $disk, string $path, int $outboxId): bool
    {
        return $mediaId > 0 && $disk !== '' && $path !== '' && $outboxId > 0;
    }

    public static function build(string $name): self
    {
        return new self();
    }

    public function onlyTwo(int $a, int $b = 2): int
    {
        return $a + $b;
    }

    public function anyNumber(int $first, int ...$rest): int
    {
        return $first + array_sum($rest);
    }

    protected function hidden(): void {}
}

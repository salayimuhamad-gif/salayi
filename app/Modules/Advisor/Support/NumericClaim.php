<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Support;

use App\Modules\Core\ValueObjects\Decimal;

/**
 * A single numeric assertion extracted from AI output.
 *
 * Kind matters because the grounding rules differ. "3 bedrooms" is a count and
 * needs no evidence row; "240,000 USD" is a price claim and needs one.
 */
final class NumericClaim
{
    public function __construct(
        public readonly string $raw,
        public readonly Decimal $value,
        public readonly string $kind,
        public readonly int $offset,
        /** Trailing-zero precision, e.g. 244000 -> 1000. */
        public readonly int $precision,
    ) {}

    /** Kinds that may never appear without a matching evidence value. */
    public function requiresGrounding(): bool
    {
        return in_array($this->kind, ['money', 'percentage', 'distance', 'area'], true);
    }

    public function describe(): string
    {
        return sprintf('%s (%s)', $this->raw, $this->kind);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Portfolio\Exceptions;

use RuntimeException;

/**
 * A rule-set lifecycle transition refused (Wave 6).
 *
 * Carries a stable `errorKey` so the admin boundary can translate the
 * refusal for the operator without parsing prose, plus enough context to
 * name WHICH question, option or conflicting set caused it.
 */
final class ValuationRulePublishException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $errorKey,
        public readonly array $context = [],
    ) {
        parent::__construct(sprintf('Valuation rule set transition refused: %s', $errorKey));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Support;

/**
 * Token usage priced in USD, as a decimal string.
 *
 * A string, not a float: this figure accumulates into a monthly budget that
 * gates whether the feature runs at all, and float addition over thousands of
 * small values drifts. The rest of this codebase uses Decimal for money for
 * the same reason.
 *
 * Rates are configuration (USD per one million tokens, prompt and completion
 * separately) because most endpoints do not return a price and a wrong
 * hard-coded rate silently corrupts the budget that is supposed to stop
 * runaway spend. Unset rates price everything at zero — which the diagnostics
 * surface reports, so "the ceiling never trips" is a visible configuration
 * state rather than a silent accounting hole.
 */
final class AiCost
{
    /** @param array{prompt: float, completion: float} $rates USD per 1M tokens */
    public static function of(int $promptTokens, int $completionTokens, array $rates): string
    {
        $total = ($promptTokens * $rates['prompt'] + $completionTokens * $rates['completion']) / 1_000_000;

        return number_format($total, 6, '.', '');
    }
}

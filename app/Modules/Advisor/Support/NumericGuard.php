<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Support;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Localization\Support\SoraniText;

/**
 * Numeric grounding for AI output (spec 17.5, Step 4 exit criterion
 * "No invented prices").
 *
 * The problem this solves is specific. A language model asked about Erbil
 * property will happily produce "apartments in Ankawa are around $185,000"
 * whether or not that figure exists in the retrieved evidence, and the answer
 * will read exactly as confidently as a grounded one. Prompt instructions
 * reduce the rate; they do not make it zero, and a market intelligence platform
 * cannot ship a system whose failure mode is a plausible invented price.
 *
 * So the check is deterministic and post-hoc: extract every numeric claim from
 * the generated text, and refuse any money, percentage, distance or area figure
 * that cannot be traced to a retrieved evidence value.
 *
 * ROUNDING IS ALLOWED, INVENTION IS NOT. This is the subtle part. "About
 * 244,000" for an evidence value of 243,500 is good communication, not
 * fabrication. "245,000" for the same value is a small lie. The rule that
 * separates them: a claim is grounded when rounding some evidence value to the
 * claim's own apparent precision reproduces the claim exactly. 243,500 rounded
 * to the nearest thousand IS 244,000, so that passes; nothing rounds to
 * 245,000, so that fails.
 *
 * Counts, years and small bare integers are exempt — "3 bedrooms" and "in 2026"
 * need no evidence row, and demanding one would make every answer unshippable.
 */
final class NumericGuard
{
    /** Bare integers at or below this are treated as counts, not claims. */
    private const COUNT_CEILING = 100;

    /**
     * Extract every numeric claim from a block of generated text.
     *
     * Handles Latin and Arabic-Indic digits, thousands separators in both
     * conventions, and currency or unit markers on either side of the number —
     * Sorani and Arabic put the unit where English would not.
     *
     * @return list<NumericClaim>
     */
    public function extract(string $text): array
    {
        $normalised = SoraniText::digitsToLatin($text);

        $claims = [];

        // A number, optionally with separators and decimals. Captured with the
        // surrounding 12 characters so the unit marker can be inspected.
        $pattern = '/(?<![\d.,])(\d{1,3}(?:[,،٬]\d{3})+(?:[.٫]\d+)?|\d+(?:[.٫]\d+)?)(?![\d])/u';

        if (preg_match_all($pattern, $normalised, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        foreach ($matches[1] as $match) {
            [$raw, $offset] = $match;

            $clean = str_replace([',', '،', '٬'], '', (string) $raw);
            $clean = str_replace('٫', '.', $clean);

            $value = Decimal::tryParse($clean, 4);

            if ($value === null) {
                continue;
            }

            /*
             * PREG_OFFSET_CAPTURE reports BYTE offsets; the windows below are
             * carved with mb_substr, which counts CHARACTERS. In ASCII text
             * the two agree, which is why English always classified
             * correctly — but every Arabic-script letter is two bytes, so in
             * Kurdish/Arabic prose the raw byte offset overshot the true
             * character position and the windows landed on the wrong text.
             * Whether a unit marker (٪, دۆلار, مەتر…) was seen then depended
             * on how long the text BEFORE the number happened to be, and a
             * small figure whose marker was missed fell through to the
             * exempt 'count' kind — shipping ungrounded (Phase 14). One
             * conversion makes the windows land by construction.
             */
            $charOffset = mb_strlen(substr($normalised, 0, (int) $offset));

            $before = mb_substr($normalised, max(0, $charOffset - 14), min(14, $charOffset));
            $after = mb_substr($normalised, $charOffset + mb_strlen((string) $raw), 14);

            $kind = $this->classify($value, (string) $raw, $before, $after);

            $claims[] = new NumericClaim(
                raw: (string) $raw,
                value: $value,
                kind: $kind,
                offset: $charOffset,
                precision: $this->precisionOf($clean),
            );
        }

        return $claims;
    }

    /**
     * Validate generated text against the evidence actually retrieved.
     *
     * @param  list<string|int|float>  $evidenceValues  every numeric value present in the evidence set
     * @return array{
     *     grounded: bool,
     *     claims: list<array{raw: string, kind: string, grounded: bool, matched_evidence: string|null}>,
     *     ungrounded: list<string>,
     *     checked: int
     * }
     */
    public function validate(string $text, array $evidenceValues): array
    {
        $evidence = [];

        foreach ($evidenceValues as $value) {
            $decimal = Decimal::tryParse((string) $value, 4);

            if ($decimal !== null) {
                $evidence[] = $decimal;
            }
        }

        $claims = $this->extract($text);
        $report = [];
        $ungrounded = [];
        $checked = 0;

        foreach ($claims as $claim) {
            if (! $claim->requiresGrounding()) {
                $report[] = [
                    'raw' => $claim->raw,
                    'kind' => $claim->kind,
                    'grounded' => true,
                    'matched_evidence' => null,
                ];

                continue;
            }

            $checked++;
            $matched = $this->findSupport($claim, $evidence);

            $report[] = [
                'raw' => $claim->raw,
                'kind' => $claim->kind,
                'grounded' => $matched !== null,
                'matched_evidence' => $matched?->toString(),
            ];

            if ($matched === null) {
                $ungrounded[] = $claim->describe();
            }
        }

        return [
            'grounded' => $ungrounded === [],
            'claims' => $report,
            'ungrounded' => $ungrounded,
            'checked' => $checked,
        ];
    }

    /**
     * Find an evidence value that supports this claim.
     *
     * Exact match, or the claim is a correctly-rounded form of the evidence at
     * the claim's own precision.
     *
     * @param  list<Decimal>  $evidence
     */
    private function findSupport(NumericClaim $claim, array $evidence): ?Decimal
    {
        foreach ($evidence as $value) {
            if ($value->equals($claim->value)) {
                return $value;
            }
        }

        if ($claim->precision <= 1) {
            return null;
        }

        foreach ($evidence as $value) {
            if ($this->roundTo($value, $claim->precision)->equals($claim->value)) {
                return $value;
            }
        }

        return null;
    }

    /** Half-up rounding to a multiple of $step, done in Decimal. */
    private function roundTo(Decimal $value, int $step): Decimal
    {
        if ($step <= 1) {
            return $value;
        }

        return $value->divide($step)->toScale(0)->multiply($step);
    }

    /**
     * The claim's apparent precision, from its trailing zeros.
     *
     * 244000 -> 1000, 243500 -> 100, 243512 -> 1. A figure written with more
     * significant digits is asserting more precision, and is held to it.
     */
    private function precisionOf(string $clean): int
    {
        if (str_contains($clean, '.')) {
            return 1;
        }

        $digits = ltrim($clean, '-');
        $trailing = strlen($digits) - strlen(rtrim($digits, '0'));

        return $trailing === 0 ? 1 : (int) (10 ** min($trailing, 9));
    }

    /**
     * Classify a number by the units around it.
     *
     * Unit markers are checked on both sides because Sorani and Arabic place
     * them differently from English, and a currency symbol before the number is
     * as common as a currency word after it.
     */
    private function classify(Decimal $value, string $raw, string $before, string $after): string
    {
        $context = mb_strtolower($before.' '.$after);

        $has = static fn (array $needles): bool => array_reduce(
            $needles,
            static fn (bool $carry, string $n): bool => $carry || str_contains($context, $n),
            false,
        );

        if ($has(['%', 'percent', 'لە سەدا', 'بالمئة', 'سەدا', '٪'])) {
            return 'percentage';
        }

        if ($has(['$', 'usd', 'iqd', 'dinar', 'dollar', 'دۆلار', 'دینار', 'دولار'])) {
            return 'money';
        }

        if ($has([' km', 'km ', ' m ', 'metre', 'meter', 'کیلۆمەتر', 'مەتر', 'كيلومتر', 'متر'])) {
            // Square metres are an area claim, not a distance.
            return $has(['sqm', 'm²', 'square', 'چوارگۆشە', 'مربع']) ? 'area' : 'distance';
        }

        if ($has(['sqm', 'm²', 'square metre', 'square meter', 'مەتری چوارگۆشە', 'متر مربع'])) {
            return 'area';
        }

        // A four-digit number in a plausible calendar range with no unit is a
        // year. Treating 2026 as an ungrounded price would flag every answer.
        if ($value->compareTo('1900') >= 0 && $value->compareTo('2100') <= 0 && ! str_contains($raw, '.')) {
            return 'year';
        }

        // Bare small integers are counts: bedrooms, floors, list positions.
        if ($value->compareTo((string) self::COUNT_CEILING) <= 0 && ! str_contains($raw, '.')) {
            return 'count';
        }

        // Anything large and unmarked is treated as money. In a real estate
        // answer an unlabelled 185000 is a price, and the safe default for an
        // ambiguous large number is the one that demands evidence.
        return 'money';
    }
}

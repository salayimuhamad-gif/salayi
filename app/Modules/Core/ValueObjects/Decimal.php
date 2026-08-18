<?php

declare(strict_types=1);

namespace App\Modules\Core\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Arbitrary-precision decimal for money and market values.
 *
 * Spec 26.1: "Financial values use Decimal." Float arithmetic is banned
 * anywhere a price, index value, yield or valuation is computed — 0.1 + 0.2
 * is not 0.3 in IEEE-754, and a market index that drifts by a cent per
 * revision is a credibility problem, not a rounding curiosity.
 *
 * Immutable. Every operation returns a new instance.
 *
 * Rounding is HALF-UP, the commercial convention, applied on every operation
 * that can produce more digits than the working scale. Banker's rounding is
 * deliberately not used: Erbil price tables are read by humans who expect
 * 0.5 to go up.
 */
final class Decimal implements JsonSerializable, Stringable
{
    /** Working scale for intermediate arithmetic. */
    public const WORKING_SCALE = 8;

    private function __construct(
        private readonly string $value,
        private readonly int $scale,
    ) {}

    public static function of(string|int|float|self $value, ?int $scale = null): self
    {
        if ($value instanceof self) {
            return $scale === null ? $value : $value->toScale($scale);
        }

        $scale ??= (int) config('mulkihawler.money.scale', 4);

        if ($scale < 0) {
            throw new InvalidArgumentException('Decimal scale cannot be negative.');
        }

        $normalized = self::normalizeInput($value);

        return new self(self::roundHalfUp($normalized, $scale), $scale);
    }

    public static function zero(?int $scale = null): self
    {
        return self::of('0', $scale);
    }

    /**
     * Parse user or import input that may contain Arabic-Indic digits,
     * thousands separators, currency symbols or a trailing minus.
     *
     * Returns null rather than throwing, because import rows are reviewed by
     * a human: a bad cell must become a row-level error, not a 500.
     */
    public static function tryParse(?string $input, ?int $scale = null): ?self
    {
        if ($input === null) {
            return null;
        }

        $candidate = self::digitsToLatin($input);
        $candidate = str_replace(["\u{00A0}", "\u{202F}", ' ', ',', '٬', '_'], '', $candidate);
        $candidate = str_replace('٫', '.', $candidate);

        /*
         * Currency symbols and separators are stripped; LETTERS are not.
         *
         * This method previously removed every non-numeric character, which
         * made "240k" parse as 240 and "about 240k" parse as 240 — silently
         * turning a 240,000 price into 240 in an import, with a plausible-
         * looking result rather than an error. Every index and valuation built
         * on that row would then be quietly wrong.
         *
         * A cell containing letters is not a number the importer should guess
         * at. It is a cell a human needs to look at, so it returns null and
         * becomes a reviewable row error.
         */
        if (preg_match('/\p{L}/u', $candidate) === 1) {
            return null;
        }

        $candidate = preg_replace('/[^\d.\-+]/u', '', $candidate) ?? '';

        // Trailing sign, e.g. "1200-" from some spreadsheet exports.
        if (str_ends_with($candidate, '-')) {
            $candidate = '-'.rtrim($candidate, '-');
        }

        $candidate = ltrim($candidate, '+');

        if ($candidate === '' || $candidate === '-' || ! preg_match('/^-?\d*(\.\d*)?$/', $candidate)) {
            return null;
        }

        if (substr_count($candidate, '.') > 1) {
            return null;
        }

        return self::of($candidate, $scale);
    }

    public function add(string|int|float|self $other): self
    {
        return new self(
            self::roundHalfUp(bcadd($this->value, self::operand($other), self::WORKING_SCALE), $this->scale),
            $this->scale,
        );
    }

    public function subtract(string|int|float|self $other): self
    {
        return new self(
            self::roundHalfUp(bcsub($this->value, self::operand($other), self::WORKING_SCALE), $this->scale),
            $this->scale,
        );
    }

    public function multiply(string|int|float|self $other): self
    {
        return new self(
            self::roundHalfUp(bcmul($this->value, self::operand($other), self::WORKING_SCALE), $this->scale),
            $this->scale,
        );
    }

    public function divide(string|int|float|self $other): self
    {
        $divisor = self::operand($other);

        if (bccomp($divisor, '0', self::WORKING_SCALE) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return new self(
            self::roundHalfUp(bcdiv($this->value, $divisor, self::WORKING_SCALE), $this->scale),
            $this->scale,
        );
    }

    /** Percentage change from $this to $to, e.g. 100 -> 125 gives 25.00. */
    public function percentageChangeTo(string|int|float|self $to, int $scale = 2): ?self
    {
        if ($this->isZero()) {
            return null; // Undefined, not zero. A price index cannot rebase off nothing.
        }

        $delta = bcsub(self::operand($to), $this->value, self::WORKING_SCALE);
        $ratio = bcdiv($delta, $this->value, self::WORKING_SCALE);

        return new self(self::roundHalfUp(bcmul($ratio, '100', self::WORKING_SCALE), $scale), $scale);
    }

    public function percentOf(string|int|float|self $percent): self
    {
        $factor = bcdiv(self::operand($percent), '100', self::WORKING_SCALE);

        return new self(
            self::roundHalfUp(bcmul($this->value, $factor, self::WORKING_SCALE), $this->scale),
            $this->scale,
        );
    }

    public function abs(): self
    {
        return new self(ltrim($this->value, '-'), $this->scale);
    }

    public function negate(): self
    {
        if ($this->isZero()) {
            return $this;
        }

        return new self(
            str_starts_with($this->value, '-') ? ltrim($this->value, '-') : '-'.$this->value,
            $this->scale,
        );
    }

    public function toScale(int $scale): self
    {
        return new self(self::roundHalfUp($this->value, $scale), $scale);
    }

    public function compareTo(string|int|float|self $other): int
    {
        return bccomp($this->value, self::operand($other), self::WORKING_SCALE);
    }

    public function equals(string|int|float|self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function greaterThan(string|int|float|self $other): bool
    {
        return $this->compareTo($other) === 1;
    }

    public function lessThan(string|int|float|self $other): bool
    {
        return $this->compareTo($other) === -1;
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::WORKING_SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->value, '0', self::WORKING_SCALE) === -1;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', self::WORKING_SCALE) === 1;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    /** Exact string form. This is what goes into a DECIMAL column. */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Lossy on purpose and named so. Only for charting and sorting, never
     * for storage or for a figure shown next to a currency symbol.
     */
    public function toFloat(): float
    {
        return (float) $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    // ---------------------------------------------------------------

    private static function operand(string|int|float|self $value): string
    {
        return $value instanceof self ? $value->value : self::normalizeInput($value);
    }

    private static function normalizeInput(string|int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Cannot build a Decimal from INF or NAN.');
            }

            // Route through a fixed-notation string so 1.0E-7 never reaches bcmath.
            return rtrim(rtrim(number_format($value, self::WORKING_SCALE, '.', ''), '0'), '.') ?: '0';
        }

        $trimmed = trim($value);

        if ($trimmed === '' || ! preg_match('/^[+-]?\d*(\.\d+)?$/', $trimmed) || $trimmed === '.') {
            throw new InvalidArgumentException(sprintf('Value "%s" is not a valid decimal.', $value));
        }

        $trimmed = ltrim($trimmed, '+');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /** Half-up rounding of a bc-safe numeric string to $scale decimals. */
    private static function roundHalfUp(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');

        $addend = $scale > 0
            ? '0.'.str_repeat('0', $scale).'5'
            : '0.5';

        $bumped = bcadd($abs, $addend, $scale + 1);
        $truncated = bcadd($bumped, '0', $scale);

        if ($negative && bccomp($truncated, '0', $scale) !== 0) {
            return '-'.$truncated;
        }

        return $truncated;
    }

    /** Arabic-Indic (٠-٩) and Extended Arabic-Indic (۰-۹) to ASCII. */
    private static function digitsToLatin(string $input): string
    {
        return strtr($input, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use App\Modules\Core\ValueObjects\Decimal;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Market\Enums\PropertyType;
use App\Modules\Market\Enums\ScopeType;
use DateTimeImmutable;

/**
 * Row-level validation for the historical price import (spec 14.3, Appendix B).
 *
 * Storage-free, so all twelve of spec 14.3's requirements can be tested
 * exhaustively without a database and cannot drift between the preview screen
 * and the acceptance step — a preview that validates differently from the
 * commit is how an operator ends up accepting rows that then fail.
 *
 * The design commitment: a row is either VALID, or it carries a specific
 * reason. There is no "probably fine" state. Spec 14.3 requires errors shown
 * per row and partial acceptance, and both are impossible if a failure is a
 * boolean.
 *
 * Warnings are separate from errors and deliberately do not block. An
 * unrealistic jump is worth an operator's attention and is not proof of a
 * mistake — Erbil genuinely moves that fast sometimes — so it is surfaced and
 * the human decides.
 */
final class PriceRowValidator
{
    /** Currencies this product transacts in. */
    private const CURRENCIES = ['USD', 'IQD', 'EUR'];

    /**
     * Validate one row.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context  known scope ids, seen period slots
     * @return array{
     *     valid: bool,
     *     normalised: array<string, mixed>|null,
     *     errors: list<array{field: string, code: string}>,
     *     warnings: list<array{field: string, code: string}>
     * }
     */
    public function validate(array $row, array $context = []): array
    {
        $errors = [];
        $warnings = [];
        $out = [];

        // --- required identity ---
        foreach (['scope_type', 'property_type', 'price_type', 'currency', 'effective_date'] as $field) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                $errors[] = ['field' => $field, 'code' => 'missing_required'];
            }
        }

        $scopeType = ScopeType::tryFrom(strtolower(trim((string) ($row['scope_type'] ?? ''))));
        if ($scopeType === null && ($row['scope_type'] ?? '') !== '') {
            $errors[] = ['field' => 'scope_type', 'code' => 'unknown_scope_type'];
        }

        $propertyType = PropertyType::tryFrom(strtolower(trim((string) ($row['property_type'] ?? ''))));
        if ($propertyType === null && ($row['property_type'] ?? '') !== '') {
            $errors[] = ['field' => 'property_type', 'code' => 'unknown_property_type'];
        }

        $priceType = PriceType::tryFrom(strtolower(trim((string) ($row['price_type'] ?? ''))));
        if ($priceType === null && ($row['price_type'] ?? '') !== '') {
            $errors[] = ['field' => 'price_type', 'code' => 'unknown_price_type'];
        }

        // --- currency (spec 14.3 "validate currencies") ---
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        if ($currency !== '' && ! in_array($currency, self::CURRENCIES, true)) {
            $errors[] = ['field' => 'currency', 'code' => 'unknown_currency'];
        }

        // --- dates (spec 14.3 "validate dates") ---
        $effective = $this->date($row['effective_date'] ?? null);
        if ($effective === null && trim((string) ($row['effective_date'] ?? '')) !== '') {
            $errors[] = ['field' => 'effective_date', 'code' => 'invalid_date'];
        } elseif ($effective !== null && strtotime($effective) > time()) {
            // A future effective date is a data-entry error, not a forecast.
            $errors[] = ['field' => 'effective_date', 'code' => 'date_in_future'];
        }

        $published = $this->date($row['published_date'] ?? null);
        if ($published !== null && $effective !== null && strtotime($published) < strtotime($effective)) {
            $warnings[] = ['field' => 'published_date', 'code' => 'published_before_effective'];
        }

        // --- numeric values (spec 14.3 "validate decimal values") ---
        $numeric = [];
        foreach (['price', 'price_per_sqm', 'minimum', 'maximum', 'median'] as $field) {
            $raw = $row[$field] ?? null;

            if ($raw === null || trim((string) $raw) === '') {
                $numeric[$field] = null;

                continue;
            }

            // Decimal::tryParse handles Arabic-Indic digits and separators, so
            // a spreadsheet typed in Sorani imports without pre-cleaning.
            $value = Decimal::tryParse((string) $raw, 4);

            if ($value === null) {
                $errors[] = ['field' => $field, 'code' => 'invalid_decimal'];
                $numeric[$field] = null;

                continue;
            }

            if ($value->isNegative()) {
                $errors[] = ['field' => $field, 'code' => 'negative_value'];
            }

            $numeric[$field] = $value;
        }

        if ($numeric['price'] === null && $numeric['price_per_sqm'] === null && $numeric['median'] === null) {
            $errors[] = ['field' => 'price', 'code' => 'no_value_supplied'];
        }

        // A min above a max is an inverted pair, which is a transposition
        // rather than an out-of-range value and reads as such.
        if ($numeric['minimum'] !== null && $numeric['maximum'] !== null
            && $numeric['minimum']->greaterThan($numeric['maximum'])) {
            $errors[] = ['field' => 'minimum', 'code' => 'minimum_exceeds_maximum'];
        }

        foreach (['median' => 'median_outside_range'] as $field => $code) {
            if ($numeric[$field] === null || $numeric['minimum'] === null || $numeric['maximum'] === null) {
                continue;
            }

            if ($numeric[$field]->lessThan($numeric['minimum']) || $numeric[$field]->greaterThan($numeric['maximum'])) {
                $errors[] = ['field' => $field, 'code' => $code];
            }
        }

        // --- sample size vs source count ---
        $sample = $this->positiveInt($row['sample_size'] ?? null);
        $sources = $this->positiveInt($row['source_count'] ?? null);

        if ($sample !== null && $sources !== null && $sources > $sample) {
            $warnings[] = ['field' => 'source_count', 'code' => 'more_sources_than_observations'];
        }

        // --- scope resolution (spec 14.3 "identify missing project or area") ---
        $externalId = trim((string) ($row['scope_external_id'] ?? ''));
        /*
         * EXPLICIT, because `tryFrom()` returns null for anything a spreadsheet
         * happens to contain. `$scopeType?->value ?? ''` looked up the empty
         * key for an unknown scope, and writing it as `->` instead would emit
         * "attempt to read property on null" on every bad row. Saying "no scope
         * means no known list" states the intent and does neither.
         */
        $known = $scopeType === null
            ? null
            : ($context['known_scopes'][$scopeType->value] ?? null);

        if ($scopeType !== null && $scopeType !== ScopeType::City) {
            if ($externalId === '') {
                $errors[] = ['field' => 'scope_external_id', 'code' => 'missing_required'];
            } elseif (is_array($known) && ! in_array($externalId, $known, true)) {
                $errors[] = ['field' => 'scope_external_id', 'code' => 'scope_not_found'];
            }
        }

        // --- duplicate period (spec 14.3 "identify duplicate periods") ---
        $period = $this->period($effective);
        // An unparsed enum contributes an empty segment, exactly as before,
        // but without reading a property off null to get there.
        $slot = implode('|', [
            $scopeType instanceof ScopeType ? $scopeType->value : '', $externalId,
            $propertyType instanceof PropertyType ? $propertyType->value : '',
            trim((string) ($row['unit_type'] ?? '')),
            $priceType instanceof PriceType ? $priceType->value : '', $period ?? '',
        ]);

        if ($period !== null && in_array($slot, (array) ($context['seen_slots'] ?? []), true)) {
            $errors[] = ['field' => 'effective_date', 'code' => 'duplicate_period'];
        }

        if ($errors !== []) {
            return ['valid' => false, 'normalised' => null, 'errors' => $errors, 'warnings' => $warnings];
        }

        $out = [
            'record_id' => trim((string) ($row['record_id'] ?? '')) ?: null,
            'scope_type' => $scopeType?->value,
            'scope_external_id' => $externalId ?: null,
            'property_type' => $propertyType?->value,
            'unit_type' => trim((string) ($row['unit_type'] ?? '')) ?: null,
            'transaction_type' => $priceType?->transaction(),
            'price_type' => $priceType?->value,
            'currency' => $currency,
            'price' => $numeric['price']?->toString(),
            'price_per_sqm' => $numeric['price_per_sqm']?->toString(),
            'minimum' => $numeric['minimum']?->toString(),
            'maximum' => $numeric['maximum']?->toString(),
            'median' => $numeric['median']?->toString(),
            'sample_size' => $sample,
            'source_count' => $sources,
            'effective_date' => $effective,
            'published_date' => $published,
            'period' => $period,
            'source' => trim((string) ($row['source'] ?? '')) ?: null,
            'confidence' => $this->confidence($row['confidence'] ?? null),
            'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
            'slot' => $slot,
        ];

        return ['valid' => true, 'normalised' => $out, 'errors' => [], 'warnings' => $warnings];
    }

    /**
     * Period-over-period jump detection across an accepted batch
     * (spec 14.3 "detect unrealistic jumps").
     *
     * A WARNING, never an error. Erbil genuinely moves fast, and rejecting a
     * real 45% move because it looks implausible would corrupt the series just
     * as surely as accepting a typo. The human decides.
     *
     * @param  list<array<string, mixed>>  $rows  normalised, valid rows
     * @return array<int, list<array{field: string, code: string, detail: string}>> keyed by row index
     */
    public function detectJumps(array $rows, float $thresholdPercent = 40.0): array
    {
        $series = [];

        foreach ($rows as $index => $row) {
            if ($row['price'] === null || $row['period'] === null) {
                continue;
            }

            // Grouped by everything except period, so a jump is compared
            // against the same scope, property type and price type — never
            // against a different series that happens to sit nearby.
            $key = implode('|', [
                $row['scope_type'], $row['scope_external_id'],
                $row['property_type'], $row['unit_type'], $row['price_type'],
            ]);

            $series[$key][] = ['index' => $index, 'period' => $row['period'], 'value' => $row['price']];
        }

        $flags = [];
        $threshold = Decimal::of((string) $thresholdPercent, 4);

        foreach ($series as $points) {
            usort($points, static fn (array $a, array $b): int => strcmp($a['period'], $b['period']));

            for ($i = 1, $n = count($points); $i < $n; $i++) {
                $previous = Decimal::of((string) $points[$i - 1]['value'], 4);
                $change = $previous->percentageChangeTo((string) $points[$i]['value'], 2);

                if ($change === null || ! $change->abs()->greaterThan($threshold)) {
                    continue;
                }

                $flags[$points[$i]['index']][] = [
                    'field' => 'price',
                    'code' => 'unrealistic_jump',
                    'detail' => $change->toString().'% from '.$points[$i - 1]['period'],
                ];
            }
        }

        return $flags;
    }

    private function date(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '') {
            return null;
        }

        // Arabic-Indic digits reach this from a Sorani spreadsheet.
        $raw = strtr($raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $raw);

            if ($parsed !== false && $parsed->format($format) === $raw) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    private function period(?string $date): ?string
    {
        return $date === null ? null : substr($date, 0, 7);
    }

    private function positiveInt(mixed $value): ?int
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '' || ! ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function confidence(mixed $value): string
    {
        $raw = strtolower(trim((string) ($value ?? '')));

        return in_array($raw, ['low', 'medium', 'high'], true) ? $raw : 'medium';
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Market\Enums;

/**
 * The provenance of a price figure (spec 14.1).
 *
 * Spec 14.1 states the rule this enum exists to enforce:
 *
 *     "Official Market Snapshots and Asking Prices must never be mixed."
 *
 * and spec 36 Step 3 repeats it as an exit criterion: "Asking prices remain
 * separate."
 *
 * The reason is not pedantry. An asking price is what a seller hopes for; a
 * verified transaction price is what someone actually paid. In Erbil the gap
 * between the two is routinely 10-20%, and it widens in a slow market — which
 * is exactly when a buyer most needs the distinction. An index that quietly
 * averaged the two would overstate the market precisely when overstating it
 * does the most damage, and would do so with the platform's credibility
 * attached.
 *
 * `family()` is the mechanism: two price types may only be aggregated together
 * when they share a family. There is no code path that blends families, and
 * the invariant is asserted in the standalone suite.
 */
enum PriceType: string
{
    // --- Asking family: what sellers and landlords are advertising ---
    case SaleAsking = 'sale_asking';
    case RentAsking = 'rent_asking';

    // --- Verified family: what was actually transacted ---
    case SaleVerified = 'sale_verified';
    case RentVerified = 'rent_verified';

    // --- Official family: published third-party or in-house snapshots ---
    case OfficialSnapshot = 'official_snapshot';

    /**
     * Aggregation family. Two figures may be combined only within one family.
     */
    public function family(): string
    {
        return match ($this) {
            self::SaleAsking, self::RentAsking => 'asking',
            self::SaleVerified, self::RentVerified => 'verified',
            self::OfficialSnapshot => 'official',
        };
    }

    /**
     * The hard guard. Called before any aggregation.
     *
     * BOTH conditions must hold:
     *
     *   1. Same family — spec 14.1, asking may never mix with verified or
     *      official.
     *   2. Same transaction basis — a sale price and a rent price are not
     *      summable even when they share a family. A $240,000 asking sale
     *      price and a $500 asking monthly rent are both "asking", and
     *      averaging them produces a number that means nothing.
     *
     * Condition 2 was missed on the first pass: family alone reported that
     * sale_asking could aggregate with rent_asking. Guarding provenance is not
     * the same as guarding units.
     *
     * For OfficialSnapshot the basis is 'either', because a published snapshot
     * may cover sale or rent and declares which in its own `transaction_type`
     * column. Two snapshots therefore pass this enum-level check and are
     * separated at the record level by that column.
     */
    public function mayAggregateWith(self $other): bool
    {
        return $this->family() === $other->family()
            && $this->transaction() === $other->transaction();
    }

    /** Sale or rent. Orthogonal to family. */
    public function transaction(): string
    {
        return match ($this) {
            self::SaleAsking, self::SaleVerified => 'sale',
            self::RentAsking, self::RentVerified => 'rent',
            // A snapshot declares its own transaction basis in its own column,
            // because a published snapshot may cover either.
            self::OfficialSnapshot => 'either',
        };
    }

    /**
     * Evidence strength, used for index weighting and confidence.
     *
     * Verified transactions outrank everything: they are the only category
     * where money actually changed hands.
     */
    public function evidenceWeight(): int
    {
        return match ($this) {
            self::SaleVerified, self::RentVerified => 100,
            self::OfficialSnapshot => 70,
            self::SaleAsking, self::RentAsking => 40,
        };
    }

    /**
     * Whether a figure of this type may be presented without a qualifier.
     *
     * Only verified prices may. Spec 15.3 requires every public index value to
     * carry its source summary, and an asking-price figure shown bare reads as
     * a market rate to anyone who does not inspect the label.
     */
    public function requiresPublicQualifier(): bool
    {
        return $this !== self::SaleVerified && $this !== self::RentVerified;
    }

    /** Types that may feed an index labelled as a transaction-price index. */
    public function isTransactionEvidence(): bool
    {
        return $this->family() === 'verified';
    }

    /** @return list<self> */
    public static function inFamily(string $family): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $t): bool => $t->family() === $family,
        ));
    }

    /** @return list<string> */
    public static function families(): array
    {
        return array_values(array_unique(array_map(
            static fn (self $t): string => $t->family(),
            self::cases(),
        )));
    }

    public function label(): string
    {
        return __('market.price_types.'.$this->value);
    }
}

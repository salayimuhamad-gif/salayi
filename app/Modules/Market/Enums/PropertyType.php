<?php

declare(strict_types=1);

namespace App\Modules\Market\Enums;

/** Property category for index segmentation (spec 15.1). */
enum PropertyType: string
{
    case Apartment = 'apartment';
    case House = 'house';
    case Villa = 'villa';
    case Land = 'land';
    case Commercial = 'commercial';
    case Office = 'office';
    case Retail = 'retail';
    case Warehouse = 'warehouse';

    /**
     * Whether price-per-square-metre is a meaningful comparison for this type.
     *
     * For land it is the primary metric. For a villa it is misleading on its
     * own, because plot size dominates and two villas at the same rate per
     * square metre can be very different propositions.
     */
    public function pricePerSqmIsPrimary(): bool
    {
        return match ($this) {
            self::Land, self::Apartment, self::Office, self::Retail, self::Warehouse => true,
            self::House, self::Villa, self::Commercial => false,
        };
    }

    public function label(): string
    {
        return __('market.property_types.'.$this->value);
    }
}

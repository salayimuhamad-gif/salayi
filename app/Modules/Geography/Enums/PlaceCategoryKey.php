<?php

declare(strict_types=1);

namespace App\Modules\Geography\Enums;

/**
 * The 31 seeded place categories (spec 10.4).
 *
 * These are SEEDS, not a closed set. Spec 10.4 requires that "admin can add
 * categories and icons without code", so the authoritative list lives in the
 * `place_categories` table and this enum only guarantees a stable key for the
 * ones the product ships with and references by name (lifestyle matching in
 * Step 4 needs to find "school" without depending on a row id).
 */
enum PlaceCategoryKey: string
{
    case School = 'school';
    case Kindergarten = 'kindergarten';
    case University = 'university';
    case Institute = 'institute';
    case Hospital = 'hospital';
    case Clinic = 'clinic';
    case Pharmacy = 'pharmacy';
    case Mall = 'mall';
    case Supermarket = 'supermarket';
    case Market = 'market';
    case Restaurant = 'restaurant';
    case Cafe = 'cafe';
    case Park = 'park';
    case SportsFacility = 'sports_facility';
    case Mosque = 'mosque';
    case Church = 'church';
    case GovernmentOffice = 'government_office';
    case Police = 'police';
    case FireStation = 'fire_station';
    case Bank = 'bank';
    case Atm = 'atm';
    case Airport = 'airport';
    case BusStation = 'bus_station';
    case FuelStation = 'fuel_station';
    case Workplace = 'workplace';
    case IndustrialZone = 'industrial_zone';
    case Landmark = 'landmark';
    case Hotel = 'hotel';
    case RoadEntrance = 'road_entrance';
    case HighwayAccess = 'highway_access';
    case PlannedInfrastructure = 'planned_infrastructure';

    /**
     * Grouping used by the nearby-places panel and, later, lifestyle matching.
     */
    public function group(): string
    {
        return match ($this) {
            self::School, self::Kindergarten, self::University, self::Institute => 'education',
            self::Hospital, self::Clinic, self::Pharmacy => 'health',
            self::Mall, self::Supermarket, self::Market => 'shopping',
            self::Restaurant, self::Cafe, self::Hotel => 'hospitality',
            self::Park, self::SportsFacility => 'recreation',
            self::Mosque, self::Church => 'worship',
            self::GovernmentOffice, self::Police, self::FireStation => 'civic',
            self::Bank, self::Atm => 'finance',
            self::Airport, self::BusStation, self::FuelStation,
            self::RoadEntrance, self::HighwayAccess => 'transport',
            self::Workplace, self::IndustrialZone => 'employment',
            self::Landmark, self::PlannedInfrastructure => 'other',
        };
    }

    /**
     * Default search radius in metres for the nearby calculation.
     *
     * Category-specific because relevance is not uniform: a pharmacy two
     * kilometres away is not a selling point, while an airport twenty
     * kilometres away genuinely is. A single global radius would either flood
     * the panel with distant cafés or hide the airport entirely.
     */
    public function defaultRadiusMetres(): int
    {
        return match ($this) {
            self::Pharmacy, self::Atm, self::Cafe, self::Supermarket => 1_500,
            self::School, self::Kindergarten, self::Clinic,
            self::Restaurant, self::Park, self::Market, self::Bank => 3_000,
            self::Mosque, self::Church, self::SportsFacility,
            self::Police, self::FireStation, self::FuelStation => 5_000,
            self::Mall, self::Hospital, self::University, self::Institute,
            self::GovernmentOffice, self::Hotel, self::Landmark => 10_000,
            self::Airport, self::BusStation, self::IndustrialZone,
            self::Workplace, self::RoadEntrance, self::HighwayAccess,
            self::PlannedInfrastructure => 25_000,
        };
    }

    /**
     * Weight in the "nearby services" quality signal, 0..1.
     *
     * A hospital near a residential project matters more than a café. These
     * are product judgements, deliberately visible in one place so they can be
     * argued about rather than buried in a query.
     */
    public function amenityWeight(): float
    {
        return match ($this->group()) {
            'education', 'health' => 1.0,
            'shopping', 'transport' => 0.8,
            'recreation', 'civic' => 0.6,
            'worship', 'finance' => 0.5,
            'hospitality', 'employment' => 0.4,
            default => 0.2,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function label(): string
    {
        return __('geography.place_categories.'.$this->value);
    }
}

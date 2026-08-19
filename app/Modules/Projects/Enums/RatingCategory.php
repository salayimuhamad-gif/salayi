<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/**
 * The 31 evaluable dimensions (spec 13.1), counted from the specification
 * source rather than from memory.
 *
 * Grouped so the admin rating screen and the public profile can present them
 * in a sensible order, and so a project can be scored on construction quality
 * without an investment analyst's dimensions cluttering the form.
 */
enum RatingCategory: string
{
    // Construction
    case BuildQuality = 'build_quality';
    case FinishingQuality = 'finishing_quality';
    case DesignQuality = 'design_quality';
    case Maintenance = 'maintenance';

    // Infrastructure
    case Infrastructure = 'infrastructure';
    case ElectricityReliability = 'electricity_reliability';
    case WaterReliability = 'water_reliability';
    case InternetAvailability = 'internet_availability';
    case RoadAccess = 'road_access';
    case Parking = 'parking';
    case GreenSpace = 'green_space';

    // Community
    case FamilySuitability = 'family_suitability';
    case ResidentProfile = 'resident_profile';
    case Occupancy = 'occupancy';

    // Market
    case RentalDemand = 'rental_demand';
    case SaleDemand = 'sale_demand';
    case Liquidity = 'liquidity';
    case ResaleSpeed = 'resale_speed';
    case RentalYield = 'rental_yield';
    case PriceStability = 'price_stability';
    case CapitalGrowthPotential = 'capital_growth_potential';

    // Developer
    case DeveloperReputation = 'developer_reputation';
    case DeliveryReliability = 'delivery_reliability';
    case ManagementQuality = 'management_quality';
    case ServiceQuality = 'service_quality';

    // Environment
    case Noise = 'noise';
    case Traffic = 'traffic';
    case SafetyPerception = 'safety_perception';

    // Risk
    case InvestmentRisk = 'investment_risk';
    case LegalClarity = 'legal_clarity';
    case DataConfidence = 'data_confidence';

    public function group(): string
    {
        return match ($this) {
            self::BuildQuality, self::FinishingQuality, self::DesignQuality, self::Maintenance => 'construction',
            self::Infrastructure, self::ElectricityReliability, self::WaterReliability,
            self::InternetAvailability, self::RoadAccess, self::Parking, self::GreenSpace => 'infrastructure',
            self::FamilySuitability, self::ResidentProfile, self::Occupancy => 'community',
            self::RentalDemand, self::SaleDemand, self::Liquidity, self::ResaleSpeed,
            self::RentalYield, self::PriceStability, self::CapitalGrowthPotential => 'market',
            self::DeveloperReputation, self::DeliveryReliability,
            self::ManagementQuality, self::ServiceQuality => 'developer',
            self::Noise, self::Traffic, self::SafetyPerception => 'environment',
            self::InvestmentRisk, self::LegalClarity, self::DataConfidence => 'risk',
        };
    }

    /**
     * Categories where a HIGH score is a bad thing.
     *
     * Noise, traffic and investment risk all read "more is worse". Without
     * this the public profile would present a high-traffic, high-risk project
     * as excellent, which is the sort of error that destroys trust in every
     * other number on the page.
     */
    public function isInverted(): bool
    {
        return match ($this) {
            self::Noise, self::Traffic, self::InvestmentRisk => true,
            default => false,
        };
    }

    /** Which public audience this dimension is primarily for. */
    public function audience(): string
    {
        return match ($this->group()) {
            'market', 'risk' => 'investor',
            'community', 'environment' => 'resident',
            default => 'both',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function label(): string
    {
        return __('projects.rating_categories.'.$this->value);
    }
}

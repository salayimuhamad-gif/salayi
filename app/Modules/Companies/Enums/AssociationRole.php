<?php

declare(strict_types=1);

namespace App\Modules\Companies\Enums;

/**
 * How a company relates to a project (spec 18.3).
 *
 * Spec 37.4: "Project association is admin-controlled." A company cannot claim
 * to be a project's official developer; an administrator grants it. The
 * distinction is load-bearing — "official sales partner" on a project page is a
 * claim buyers act on.
 */
enum AssociationRole: string
{
    case OfficialDeveloper = 'official_developer';
    case OfficialSalesPartner = 'official_sales_partner';
    case VerifiedReseller = 'verified_reseller';
    case PropertyManagementPartner = 'property_management_partner';
    case RentalSpecialist = 'rental_specialist';
    case AdvertisingPartner = 'advertising_partner';
    case IndependentBrokerage = 'independent_brokerage';

    /**
     * Roles asserting an official relationship with the developer.
     *
     * These require documented evidence before approval, because a false claim
     * here is a consumer-protection problem, not a data-quality one.
     */
    public function assertsOfficialStatus(): bool
    {
        return in_array($this, [
            self::OfficialDeveloper,
            self::OfficialSalesPartner,
            self::VerifiedReseller,
        ], true);
    }

    /**
     * Roles that are inherently commercial and must carry a disclosure label
     * wherever they appear (spec 18.3 "disclosure label").
     */
    public function requiresDisclosure(): bool
    {
        return $this === self::AdvertisingPartner;
    }

    /** Default display priority. Higher sorts first among equals. */
    public function defaultPriority(): int
    {
        return match ($this) {
            self::OfficialDeveloper => 100,
            self::OfficialSalesPartner => 80,
            self::VerifiedReseller => 60,
            self::PropertyManagementPartner => 50,
            self::RentalSpecialist => 40,
            self::IndependentBrokerage => 20,
            // Deliberately lowest. A paid relationship must not outrank a
            // verified official one on organic display priority.
            self::AdvertisingPartner => 10,
        };
    }

    public function label(): string
    {
        return __('companies.association_roles.'.$this->value);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * The seventeen administrative roles from spec 3.2, plus the public-facing
 * account types.
 *
 * Roles are an enum rather than free text so a typo cannot silently create a
 * role with no permissions that appears to work until someone needs it.
 */
enum RoleKey: string
{
    // --- Administrative (spec 3.2) ---
    case SuperAdmin = 'super_admin';
    case SystemAdmin = 'system_admin';
    case ProductOwner = 'product_owner';
    case MarketDataManager = 'market_data_manager';
    case ProjectDataEditor = 'project_data_editor';

    /**
     * Enters projects platform-wide, with no company scope.
     *
     * A real shape, not a test artefact: the platform's own data team creates
     * projects that belong to no company. Separating it from Super Admin also
     * makes the scoped/unscoped boundary testable — Super Admin bypasses every
     * check, so a test using one proves nothing about the boundary.
     */
    case PlatformProjectOperator = 'platform_project_operator';
    case GisPlacesManager = 'gis_places_manager';
    case ContentEditor = 'content_editor';
    case TranslationReviewer = 'translation_reviewer';
    case AiQualityReviewer = 'ai_quality_reviewer';
    case SalesManager = 'sales_manager';
    case SalesAgent = 'sales_agent';
    case CompanyAccountManager = 'company_account_manager';
    case AdvertisingManager = 'advertising_manager';
    case FinanceManager = 'finance_manager';
    case SecurityAuditor = 'security_auditor';
    case ReadOnlyAnalyst = 'read_only_analyst';
    case SupportAgent = 'support_agent';

    // --- Public (spec 3.1) ---
    case Member = 'member';
    case CompanyOwner = 'company_owner';
    case CompanyStaff = 'company_staff';
    case Broker = 'broker';

    /** Roles that may reach /admin at all. */
    public function isAdministrative(): bool
    {
        return ! in_array($this, [
            self::Member,
            self::CompanyOwner,
            self::CompanyStaff,
            self::Broker,
        ], true);
    }

    /**
     * Roles that must complete an MFA challenge (spec 30.1 "MFA for
     * administrators"). Every administrative role qualifies — a Read-Only
     * Analyst still sees lead data and market figures before publication.
     */
    public function requiresMfa(): bool
    {
        return $this->isAdministrative();
    }

    /** Spec 24.1: only relevant admin sections appear, by permission. */
    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }

    public function label(): string
    {
        return __('identity.roles.'.$this->value);
    }
}

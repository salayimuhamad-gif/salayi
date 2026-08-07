<?php

declare(strict_types=1);
use App\Modules\Advertising\Providers\AdvertisingServiceProvider;
use App\Modules\Advisor\Providers\AdvisorServiceProvider;
use App\Modules\Analytics\Providers\AnalyticsServiceProvider;
use App\Modules\Branding\Providers\BrandingServiceProvider;
use App\Modules\Companies\Providers\CompaniesServiceProvider;
use App\Modules\Content\Providers\ContentServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Modules\Geography\Providers\GeographyServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Imports\Providers\ImportsServiceProvider;
use App\Modules\Install\Providers\InstallServiceProvider;
use App\Modules\Knowledge\Providers\KnowledgeServiceProvider;
use App\Modules\Leads\Providers\LeadsServiceProvider;
use App\Modules\Localization\Providers\LocalizationServiceProvider;
use App\Modules\Market\Providers\MarketServiceProvider;
use App\Modules\Marketplace\Providers\MarketplaceServiceProvider;
use App\Modules\Notifications\Providers\NotificationsServiceProvider;
use App\Modules\Operations\Providers\OperationsServiceProvider;
use App\Modules\Portfolio\Providers\PortfolioServiceProvider;
use App\Modules\Projects\Providers\ProjectsServiceProvider;
use App\Providers\AppServiceProvider;

/*
 * Module providers are listed explicitly rather than auto-discovered so the
 * boot order is deterministic and reviewable. Core must boot first: it binds
 * the settings repository and feature-flag store that later modules read
 * during their own registration.
 *
 * Every domain below is registered and carries an implementation. The comment
 * this replaces described a Step 1 tree in which fourteen of these were empty
 * placeholders; that stopped being true many steps ago and the stale note made
 * the file actively misleading about what boots.
 */

return [
    AppServiceProvider::class,

    // --- Core platform: registered first, others depend on them ---
    CoreServiceProvider::class,
    OperationsServiceProvider::class,
    LocalizationServiceProvider::class,
    BrandingServiceProvider::class,
    IdentityServiceProvider::class,
    InstallServiceProvider::class,

    // --- Domain modules ---
    GeographyServiceProvider::class,
    ProjectsServiceProvider::class,
    MarketServiceProvider::class,
    AdvisorServiceProvider::class,
    CompaniesServiceProvider::class,
    MarketplaceServiceProvider::class,
    AdvertisingServiceProvider::class,
    PortfolioServiceProvider::class,
    LeadsServiceProvider::class,
    NotificationsServiceProvider::class,
    ContentServiceProvider::class,
    KnowledgeServiceProvider::class,
    ImportsServiceProvider::class,
    AnalyticsServiceProvider::class,
];

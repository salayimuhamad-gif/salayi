<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Modules\Advisor\Models\AdvisorConversation;
use App\Modules\Advisor\Models\AdvisorMatch;
use App\Modules\Advisor\Models\AdvisorMessage;
use App\Modules\Branding\Models\BrandingAsset;
use App\Modules\Branding\Models\FeatureFlag;
use App\Modules\Branding\Models\SiteSetting;
use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyBranch;
use App\Modules\Companies\Models\CompanyProjectAssociation;
use App\Modules\Companies\Models\CompanyStaff;
use App\Modules\Core\Support\FeatureFlagResolver;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Models\Place;
use App\Modules\Geography\Models\PlaceCategory;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Knowledge\Models\KnowledgeEvent;
use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Localization\Models\GlossaryTerm;
use App\Modules\Localization\Models\Language;
use App\Modules\Localization\Models\Translation;
use App\Modules\Market\Models\MarketIndex;
use App\Modules\Market\Models\MarketIndexValue;
use App\Modules\Market\Models\PriceRecord;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\OfferMedia;
use App\Modules\Portfolio\Models\PortfolioProperty;
use App\Modules\Portfolio\Models\PortfolioValuation;
use App\Modules\Projects\Models\Developer;
use App\Modules\Projects\Models\OrphanedFile;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectDraft;
use App\Modules\Projects\Models\ProjectDraftMedia;
use App\Modules\Projects\Models\ProjectMedia;
use App\Modules\Projects\Models\ProjectNearbyPlace;
use App\Modules\Projects\Models\ProjectRating;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;

final class CoreServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Core';
    }

    protected function registerModule(): void
    {
        // Resolver is storage-free, so it is a singleton with no DB touch.
        $this->app->singleton(FeatureFlagResolver::class, fn (): FeatureFlagResolver => new FeatureFlagResolver(
            /** @var array<string, bool> */
            defaults: (array) config('features.defaults', []),
            /** @var list<string> */
            requiresSuperAdmin: (array) config('features.requires_super_admin', []),
        ));
    }

    protected function bootModule(): void
    {
        /*
         * Spec 26.1: "Important entities use Soft Delete or archiving" and
         * "No destructive shortcuts". Preventing lazy loading turns an N+1
         * into a loud failure in non-production instead of a slow page in
         * production. Strict mode also blocks silent attribute discards.
         */
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());
        Model::unguard(false);

        /*
         * Spec 26.1 + morph safety: an explicit morph map means a class rename
         * never orphans a polymorphic row, and a database dump never leaks
         * internal namespaces. Entries are appended, never renamed, once a
         * release has shipped.
         */
        Relation::enforceMorphMap([
            'user' => User::class,
            'role' => Role::class,
            'site_setting' => SiteSetting::class,
            'branding_asset' => BrandingAsset::class,
            'feature_flag' => FeatureFlag::class,
            'language' => Language::class,
            'translation' => Translation::class,
            'glossary_term' => GlossaryTerm::class,

            // --- Step 2: geography and projects ---
            'area' => Area::class,
            'place' => Place::class,
            'place_category' => PlaceCategory::class,
            'developer' => Developer::class,
            'project' => Project::class,
            'project_rating' => ProjectRating::class,
            'project_nearby_place' => ProjectNearbyPlace::class,

            // --- Step 3: market data ---
            'price_record' => PriceRecord::class,
            'market_index' => MarketIndex::class,
            'market_index_value' => MarketIndexValue::class,

            // --- Step 4: advisor and lifestyle matching ---
            'advisor_conversation' => AdvisorConversation::class,
            'advisor_message' => AdvisorMessage::class,
            'advisor_match' => AdvisorMatch::class,

            // --- Step 5: companies, marketplace, advertising ---
            'company' => Company::class,
            'company_branch' => CompanyBranch::class,
            'company_project_association' => CompanyProjectAssociation::class,
            'offer' => Offer::class,

            /*
             * MEDIA AND LIFECYCLE MODELS, which were absent.
             *
             * `enforceMorphMap()` makes `getMorphClass()` THROW for any model
             * without an alias, and `AuditLogger::record()` catches every
             * Throwable and merely logs it. The two combined meant audit writes
             * for media uploads, draft lifecycle, staff changes, leads and
             * outbox jobs failed silently: spec 26.1 requires every
             * administrator mutation to be auditable, and none of these were.
             * The failure was invisible because the audit is deliberately
             * non-fatal — the right call for availability, and the reason this
             * gap survived so long.
             */
            'project_media' => ProjectMedia::class,
            'project_draft' => ProjectDraft::class,
            'project_draft_media' => ProjectDraftMedia::class,
            'offer_media' => OfferMedia::class,
            'company_staff' => CompanyStaff::class,
            'demand_profile' => DemandProfile::class,
            'orphaned_file' => OrphanedFile::class,

            // --- Step 6: portfolio, alerts, leads ---
            'portfolio_property' => PortfolioProperty::class,
            'portfolio_valuation' => PortfolioValuation::class,

            // --- Step 7: knowledge, content, analytics ---
            'knowledge_event' => KnowledgeEvent::class,
        ]);

        Date::use(Carbon::class);

        if ((bool) config('mulkihawler.security.force_https') && $this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}

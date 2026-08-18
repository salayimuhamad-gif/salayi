<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Enums\RoleKey;

/**
 * The permission catalogue and the role -> permission map (spec 3.2:
 * "Every role must use fine-grained permissions and scoped access").
 *
 * Kept as data in one class so that:
 *   - the seeder, the admin UI and the tests read one source of truth;
 *   - a new permission is a one-line change reviewable in a diff;
 *   - `php artisan mulkihawler:permissions` can print exactly what a role can
 *     do, which is what a Security Auditor actually asks for.
 *
 * Naming is `domain.subject.verb`. Wildcards are NOT supported at check time:
 * a permission either exists on a role or it does not. `super_admin` is the
 * single exception, resolved before any lookup.
 */
final class PermissionRegistry
{
    /**
     * @return array<string, list<string>> group => permissions
     */
    public static function catalogue(): array
    {
        return [
            'system' => [
                'system.settings.view', 'system.settings.update',
                'system.features.view', 'system.features.update',
                'system.integrations.view', 'system.integrations.update',
                'system.backups.view', 'system.backups.run', 'system.backups.restore',
                'system.maintenance.toggle', 'system.releases.view',
                'system.queue.view', 'system.queue.retry',
            ],
            'identity' => [
                'identity.users.view', 'identity.users.create', 'identity.users.update',
                'identity.users.suspend', 'identity.users.delete',
                // Releasing a person's user-provided number from the accounts
                // surface (spec §9). Separate from viewing for the same reason
                // leads.contact is: "can see the account" must never quietly
                // become "can read the number".
                'identity.users.contact',
                /*
                 * Bulk export of the accounts workspace. Its own permission
                 * because a sheet of every member is a privacy event the way
                 * leads.export is (spec 23.3) — viewing a paginated list and
                 * walking away with the whole population are different powers.
                 * The export NEVER contains a phone number, only availability
                 * status; the reveal ceremony remains the single door to a
                 * number, one account at a time.
                 */
                'identity.users.export',
                'identity.roles.view', 'identity.roles.assign',
                'identity.consents.view', 'identity.sessions.revoke',
            ],
            'audit' => [
                'audit.logs.view', 'audit.logs.export', 'audit.security.view',
            ],
            'branding' => [
                'branding.settings.view', 'branding.settings.update',
                'branding.assets.view', 'branding.assets.upload', 'branding.assets.revert',
                'branding.pwa.update',
            ],
            'localization' => [
                'localization.translations.view', 'localization.translations.update',
                'localization.translations.review', 'localization.translations.publish',
                'localization.glossary.view', 'localization.glossary.update',
                'localization.languages.toggle',
            ],
            // Declared now so Step 2+ code can reference a stable name and the
            // Security Auditor can review the full surface before it ships.
            'geography' => [
                'geography.areas.view', 'geography.areas.create', 'geography.areas.update', 'geography.areas.publish',
                'geography.places.view', 'geography.places.create', 'geography.places.update',
                'geography.places.verify', 'geography.places.import',
            ],
            'projects' => [
                'projects.view', 'projects.create', 'projects.update', 'projects.publish',
                // Platform roles may create with no company scope.
                'projects.create_scoped', 'projects.create_unscoped',
                // Developers are platform reference data shared across
                // companies; only platform roles may write them.
                'developers.manage',
                'projects.archive', 'projects.ratings.update', 'projects.media.manage',
            ],
            'market' => [
                'market.prices.view', 'market.prices.import', 'market.prices.approve',
                'market.indices.view', 'market.indices.configure', 'market.indices.publish',
                'market.methodology.update',
            ],
            'knowledge' => [
                'knowledge.events.view', 'knowledge.events.create',
                'knowledge.events.review', 'knowledge.events.publish',
            ],
            'content' => [
                'content.view', 'content.create', 'content.update',
                'content.publish', 'content.schedule',
            ],
            'companies' => [
                'companies.view', 'companies.create', 'companies.update',
                'companies.verify', 'companies.associations.manage', 'companies.subscriptions.manage',
                /*
                 * Scoped counterparts, so a company portal role can act on ITS
                 * OWN company without receiving platform-wide administration.
                 */
                /*
                 * `companies.associations.request` is NOT listed: there is no
                 * route or controller implementing that workflow yet, and a
                 * permission nothing enforces is a claim the product does not
                 * keep. It returns when the workflow does.
                 */
                'companies.update_own', 'companies.subscriptions.view',
            ],
            'marketplace' => [
                'marketplace.offers.view', 'marketplace.offers.moderate',
                'marketplace.offers.publish', 'marketplace.offers.archive',
                /*
                 * A seller's own listings, scoped to the acting company.
                 *
                 * Distinct from `moderate`, which decides what appears
                 * publicly across the whole marketplace — that is the
                 * platform's judgement, not a seller's about their own goods.
                 * The permission was granted to a role while absent from this
                 * catalogue, so it existed nowhere and authorised nothing.
                 */
                'marketplace.offers.manage_own',
            ],
            'advertising' => [
                'advertising.campaigns.view', 'advertising.campaigns.create',
                'advertising.campaigns.approve', 'advertising.invoices.view',
            ],
            'leads' => [
                'leads.view', 'leads.assign', 'leads.contact', 'leads.export', 'leads.score.override',
            ],
            'advisor' => [
                'advisor.conversations.view', 'advisor.prompts.view',
                'advisor.prompts.update', 'advisor.evaluations.review',
            ],
            'analytics' => [
                'analytics.dashboard.view', 'analytics.events.view', 'analytics.export',
            ],
            'imports' => [
                'imports.view', 'imports.run', 'imports.approve', 'imports.rollback',
            ],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        // `array_merge` already returns a list; the INNER array_values is the
        // one that matters, turning a role-keyed catalogue into positional
        // arguments for the spread.
        return array_merge(...array_values(self::catalogue()));
    }

    /**
     * Role -> permissions. `super_admin` is intentionally absent: it is
     * resolved as "everything" before any map lookup, so it can never drift
     * out of date as new permissions are added.
     *
     * @return array<string, list<string>>
     */
    public static function map(): array
    {
        $c = self::catalogue();

        $readOnlyEverything = array_values(array_filter(
            self::all(),
            static fn (string $p): bool => str_ends_with($p, '.view'),
        ));

        return [
            RoleKey::SystemAdmin->value => array_merge(
                $c['system'], $c['identity'], $c['audit'], $c['branding'], $c['localization'],
            ),

            RoleKey::ProductOwner->value => array_merge(
                $readOnlyEverything,
                [
                    'projects.publish', 'market.indices.publish', 'content.publish',
                    'system.features.update',
                    /*
                     * Marketplace moderation. The queue and the media
                     * moderation routes required this and NO role held it, so
                     * they were reachable only by Super Admin — a moderation
                     * workflow with no moderator is not a workflow.
                     */
                    'marketplace.offers.moderate', 'marketplace.offers.publish',
                    'marketplace.offers.archive',
                ],
            ),

            RoleKey::MarketDataManager->value => array_merge(
                $c['market'], $c['imports'],
                ['projects.view', 'geography.areas.view', 'analytics.dashboard.view'],
            ),

            /*
             * EXPLICIT, not the whole projects group.
             *
             * Merging `$c['projects']` handed a data editor projects.publish,
             * projects.create_unscoped and developers.manage — publishing
             * rights, platform-wide creation and administration of shared
             * reference data, none of which the role is meant to have. It also
             * made every permission test using this role meaningless, because
             * the role passed everything.
             *
             * A data editor enters and corrects project records for a company.
             * Publication is a reviewed transition someone else performs.
             */
            /*
             * UNSCOPED creation only, and deliberately without the scoped
             * permission: this operator works for the platform, not for a
             * company, so company mode is closed to them.
             */
            RoleKey::PlatformProjectOperator->value => [
                'projects.view', 'projects.create_unscoped', 'projects.update',
                'projects.media.manage',
                'geography.areas.view', 'geography.places.view',
            ],

            RoleKey::ProjectDataEditor->value => [
                'projects.view', 'projects.create', 'projects.create_scoped',
                'projects.update', 'projects.media.manage', 'projects.ratings.update',
                'geography.areas.view', 'geography.places.view',
                'market.prices.view', 'localization.translations.view',
            ],

            RoleKey::GisPlacesManager->value => array_merge(
                $c['geography'],
                ['projects.view', 'imports.view', 'imports.run'],
            ),

            RoleKey::ContentEditor->value => array_merge(
                $c['content'],
                [
                    'knowledge.events.view', 'knowledge.events.create',
                    /*
                     * Review and publish were defined in the catalogue and
                     * granted to nobody, so the routes requiring them were
                     * reachable only by Super Admin — a knowledge workflow with
                     * no editor is not a workflow.
                     */
                    'knowledge.events.review', 'knowledge.events.publish',
                    'projects.view', 'geography.areas.view', 'localization.translations.view',
                ],
            ),

            RoleKey::TranslationReviewer->value => array_merge(
                $c['localization'],
                ['content.view', 'projects.view', 'geography.areas.view'],
            ),

            RoleKey::AiQualityReviewer->value => array_merge(
                $c['advisor'],
                ['market.indices.view', 'projects.view', 'knowledge.events.view', 'localization.glossary.view'],
            ),

            // Must be a strict superset of SalesAgent. A manager who cannot
            // see what their own agents are working on cannot supervise them,
            // and the subset invariant is asserted in the test suite.
            RoleKey::SalesManager->value => array_merge(
                $c['leads'],
                [
                    'companies.view', 'marketplace.offers.view',
                    'analytics.dashboard.view', 'identity.users.view',
                    'projects.view', 'geography.areas.view',
                ],
            ),

            // Deliberately narrower than SalesManager: no export, no override.
            // Spec 23.3 + 30.2 — bulk lead export is a privacy event.
            RoleKey::SalesAgent->value => [
                'leads.view', 'leads.contact',
                'companies.view', 'marketplace.offers.view', 'projects.view',
            ],

            /*
             * A company account manager enters their own company's projects —
             * that is most of what the portal is for. Without projects.create
             * the Wizard route rejected every company user, so the whole
             * company-scoping path was unreachable and its tests could only
             * ever assert a 403.
             *
             * projects.publish is deliberately NOT granted: a company may
             * submit a project, and the platform decides whether it goes
             * public.
             */
            /*
             * EXPLICIT, and deliberately NOT the whole companies group.
             *
             * Merging `$c['companies']` handed a company portal user
             * companies.create, companies.verify and
             * companies.subscriptions.manage — the power to create companies,
             * verify their own, and administer anybody's subscription. A
             * portal role administers ONE company, its own, and asks the
             * platform for everything else.
             */
            RoleKey::CompanyAccountManager->value => [
                'companies.view', 'companies.update_own',
                'companies.subscriptions.view',
                // SCOPED creation only — never projects.create_unscoped, so an
                // inactive membership leaves no path to platform mode.
                'projects.view', 'projects.create', 'projects.create_scoped', 'projects.update',
                /*
                 * Company-owned offers, NOT platform moderation.
                 * `marketplace.offers.moderate` decides what appears publicly
                 * across the marketplace — that is the platform's judgement,
                 * not a seller's about their own listings.
                 */
                'marketplace.offers.view', 'marketplace.offers.manage_own',
                'leads.view', 'advertising.campaigns.view',
            ],

            RoleKey::AdvertisingManager->value => array_merge(
                $c['advertising'],
                ['companies.view', 'analytics.dashboard.view', 'marketplace.offers.view'],
            ),

            RoleKey::FinanceManager->value => [
                'companies.view', 'companies.subscriptions.manage',
                'advertising.invoices.view', 'advertising.campaigns.view',
                'analytics.dashboard.view',
            ],

            // Reads everything, writes nothing. Spec 3.2 Security Auditor.
            RoleKey::SecurityAuditor->value => array_merge(
                $readOnlyEverything,
                ['audit.logs.export', 'audit.security.view', 'identity.sessions.revoke'],
            ),

            RoleKey::ReadOnlyAnalyst->value => array_values(array_diff(
                $readOnlyEverything,
                // An analyst does not need to see identifiable lead or consent
                // records to do analysis (spec 32.2 privacy rules).
                ['leads.view', 'identity.consents.view', 'audit.logs.view', 'audit.security.view', 'system.settings.view'],
            )),

            RoleKey::SupportAgent->value => [
                'identity.users.view', 'identity.sessions.revoke',
                'projects.view', 'geography.areas.view', 'marketplace.offers.view',
                'companies.view', 'content.view',
            ],

            // Public roles hold no administrative permission at all.
            RoleKey::Member->value => [],
            RoleKey::CompanyOwner->value => [],
            RoleKey::CompanyStaff->value => [],
            RoleKey::Broker->value => [],
        ];
    }

    /** @return list<string> */
    public static function forRole(RoleKey|string $role): array
    {
        $key = $role instanceof RoleKey ? $role->value : $role;

        if ($key === RoleKey::SuperAdmin->value) {
            return self::all();
        }

        $permissions = self::map()[$key] ?? [];

        sort($permissions);

        return array_values(array_unique($permissions));
    }

    /**
     * Every permission named in the map must exist in the catalogue.
     *
     * @return list<string>
     */
    public static function orphanedPermissions(): array
    {
        $known = self::all();
        $orphans = [];

        foreach (self::map() as $role => $permissions) {
            foreach ($permissions as $permission) {
                if (! in_array($permission, $known, true)) {
                    $orphans[] = $role.' => '.$permission;
                }
            }
        }

        return $orphans;
    }
}

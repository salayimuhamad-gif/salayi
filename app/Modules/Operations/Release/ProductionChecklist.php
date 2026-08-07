<?php

declare(strict_types=1);

namespace App\Modules\Operations\Release;

/**
 * The production gate set (spec 36 Step 8 "production checklist passes",
 * spec 37, spec 38 Definition of Done).
 *
 * Every gate is a question with a verifiable answer, fed into a ReleaseDecision
 * so a failure automatically becomes a classified blocker. Gates are stated as
 * evidence the caller must supply rather than as things this class goes and
 * measures, because most of them can only be answered by actually running the
 * application — and a checklist that guesses is worse than no checklist.
 */
final class ProductionChecklist
{
    /**
     * @param  array{
     *     dependencies_installed?: bool, static_analysis_passed?: bool,
     *     code_style_passed?: bool, unit_tests_passed?: bool, feature_tests_passed?: bool,
     *     migrations_run?: bool, migrations_reversible?: bool, seed_completed?: bool,
     *     app_key_set?: bool, debug_disabled?: bool, https_enforced?: bool,
     *     secrets_absent_from_package?: bool, admin_mfa_enforced?: bool,
     *     backup_taken?: bool, rollback_tested?: bool,
     *     installer_locked?: bool, scheduler_alive?: bool,
     *     language_parity?: bool, sorani_reviewed?: bool,
     *     staging_accepted?: bool, business_approved?: bool,
     *     legal_pages_published?: bool, frontend_built?: bool
     * }  $evidence
     */
    public function evaluate(array $evidence): ReleaseDecision
    {
        $decision = new ReleaseDecision;

        $gate = static fn (string $key): bool => ($evidence[$key] ?? false) === true;

        // --- Code ---
        $decision->check('Dependencies installed (composer install)', $gate('dependencies_installed'), 'code');
        $decision->check('Static analysis passes', $gate('static_analysis_passed'), 'code');
        $decision->check('Code style passes', $gate('code_style_passed'), 'code');
        $decision->check('Unit tests pass', $gate('unit_tests_passed'), 'code');
        $decision->check('Feature tests pass', $gate('feature_tests_passed'), 'code');
        $decision->check('Frontend assets built', $gate('frontend_built'), 'code');

        // --- Database ---
        $decision->check('Migrations run successfully', $gate('migrations_run'), 'database');
        $decision->check('Every migration is reversible', $gate('migrations_reversible'), 'database');
        $decision->check('Baseline seed completed', $gate('seed_completed'), 'database');

        // --- Security ---
        $decision->check('APP_KEY is set', $gate('app_key_set'), 'security');
        $decision->check('Debug mode disabled', $gate('debug_disabled'), 'security');
        $decision->check('HTTPS enforced', $gate('https_enforced'), 'security');
        $decision->check('No secrets in the release package', $gate('secrets_absent_from_package'), 'security');
        $decision->check('Administrator MFA enforced', $gate('admin_mfa_enforced'), 'security');
        $decision->check('Installer locked after installation', $gate('installer_locked'), 'security');

        // --- Infrastructure ---
        $decision->check('Database backup taken', $gate('backup_taken'), 'infrastructure');
        $decision->check('Rollback tested', $gate('rollback_tested'), 'infrastructure');
        $decision->check('Scheduler heartbeat is alive', $gate('scheduler_alive'), 'infrastructure');

        // --- Language ---
        $decision->check('Translation parity across ckb/ar/en', $gate('language_parity'), 'language');
        $decision->check('Sorani copy reviewed by a native reviewer', $gate('sorani_reviewed'), 'language');

        // --- Legal and business ---
        $decision->check('Privacy and terms pages published', $gate('legal_pages_published'), 'legal');
        $decision->check('Staging accepted', $gate('staging_accepted'), 'business_approval');
        $decision->check('Business sign-off recorded', $gate('business_approved'), 'business_approval');

        return $decision;
    }

    /** @return list<string> */
    public static function gateKeys(): array
    {
        return [
            'dependencies_installed', 'static_analysis_passed', 'code_style_passed',
            'unit_tests_passed', 'feature_tests_passed', 'frontend_built',
            'migrations_run', 'migrations_reversible', 'seed_completed',
            'app_key_set', 'debug_disabled', 'https_enforced',
            'secrets_absent_from_package', 'admin_mfa_enforced', 'installer_locked',
            'backup_taken', 'rollback_tested', 'scheduler_alive',
            'language_parity', 'sorani_reviewed',
            'legal_pages_published', 'staging_accepted', 'business_approved',
        ];
    }
}

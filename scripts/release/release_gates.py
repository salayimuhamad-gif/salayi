#!/usr/bin/env python3
"""
The canonical gate registry.

One list of gate identities, consumed by the recorder, the ledger validator, the
release-index builder, the report generator and the final verifier. Previously
each of those carried its own spelling — the ledger said `full-sqlite` while the
verifier required `phpunit_sqlite_full` — so a complete, correct ledger still
read as "all required gates missing". A name that means the same thing in five
places must be defined in one.

Each gate carries:

    ledger_label   the id used in command-ledger.json and in log paths
    index_key      the snake_case key used in release-index.json and by the verifier
    category       grouping for reports and log directories
    required       whether a release may complete without it

`required=False` exists only for genuinely optional diagnostics. Nothing that
the audit chain treats as mandatory is marked optional here.
"""
from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class Gate:
    ledger_label: str
    index_key: str
    category: str
    required: bool = True


GATES: tuple[Gate, ...] = (
    # ---------------------------------------------------------------- backend
    Gate('focused-sqlite', 'phpunit_sqlite_focused', 'php'),
    Gate('full-sqlite', 'phpunit_sqlite_full', 'php'),
    Gate('focused-mariadb', 'phpunit_mariadb_focused', 'php'),
    Gate('full-mariadb', 'phpunit_mariadb_full', 'php'),
    Gate('handoff-concurrency-mariadb', 'handoff_concurrency_mariadb', 'php'),
    Gate('phpstan', 'phpstan', 'php'),
    Gate('pint', 'pint', 'php'),
    Gate('standalone-suite', 'standalone_suite', 'php'),
    # --------------------------------------------------------------- frontend
    Gate('npm-ci', 'npm_ci', 'frontend'),
    Gate('typecheck', 'typecheck', 'frontend'),
    Gate('lint', 'lint', 'frontend'),
    Gate('build', 'build', 'frontend'),
    # The frontend build runs in a disposable workspace, so this gate proves the
    # assets it produces match the ones frozen into the source.
    Gate('build-reproducibility', 'build_reproducibility', 'frontend'),
    # ----------------------------------------------------------------- guards
    Gate('security-audit', 'security_audit', 'guards'),
    Gate('secret-scan', 'secret_scan', 'guards'),
    Gate('language-parity', 'language_parity', 'guards'),
    Gate('language-usage', 'language_usage', 'guards'),
    Gate('migration-guard', 'migration_guard', 'guards'),
    Gate('doc-consistency', 'doc_consistency', 'guards'),
    Gate('doc-portability', 'doc_portability', 'guards'),
    Gate('frontend-manifest-audit', 'frontend_manifest_audit', 'guards'),
    # -------------------------------------------------------- runtime checks
    Gate('route-check', 'route_check', 'runtime-checks'),
    Gate('middleware-check', 'middleware_check', 'runtime-checks'),
    Gate('scheduler-check', 'scheduler_check', 'runtime-checks'),
    Gate('queue-check', 'queue_check', 'runtime-checks'),
    # The production dependency tree: installed --no-dev from the frozen
    # lock, shipped inside the runtime artifact, and proven to match that
    # lock exactly. Discovered live: the runtime used to ship no vendor at
    # all, so production kept an old tree (league/commonmark 2.8.3) that no
    # rehearsal had ever tested against the new code.
    Gate('production-vendor-install', 'production_vendor_install', 'runtime-checks'),
    Gate('dependency-parity', 'dependency_parity', 'runtime-checks'),
    Gate('platform-requirements', 'platform_requirements', 'runtime-checks'),
    # ---------------------------------------------------------------- browser
    # The browser suite needs a migrated, seeded, dedicated database and an
    # application that believes it is installed. Preparing that is release
    # evidence in its own right, not a side effect of another gate.
    # The on-disk spec inventory must equal the canonical registry BEFORE the
    # suites run: the remaining suite executes exactly the registry, so a spec
    # file the registry does not name would silently never run.
    Gate('browser-spec-closure', 'browser_spec_closure', 'browser'),
    Gate('browser-database-prepare', 'browser_database_prepare', 'browser'),
    Gate('browser-fixtures-seed', 'browser_fixtures_seed', 'browser'),
    Gate('playwright-mobile-360x800', 'playwright_mobile_360x800', 'browser'),
    Gate('playwright-mobile-390x844', 'playwright_mobile_390x844', 'browser'),
    Gate('playwright-tablet-768x1024', 'playwright_tablet_768x1024', 'browser'),
    Gate('playwright-laptop-1366x768', 'playwright_laptop_1366x768', 'browser'),
    Gate('playwright-desktop-1440x900', 'playwright_desktop_1440x900', 'browser'),
    Gate('playwright-merge-junit', 'playwright_merge_junit', 'browser'),
    # The remaining browser suite, under its own policy: zero failures, zero
    # flakes, skips permitted because they are viewport-driven by design.
    Gate('playwright-remaining-suite', 'playwright_remaining_suite', 'browser'),
    # Validates the remaining suite's generated JSON report against the
    # canonical PLAYWRIGHT_REMAINING_SPECS inventory: every intended spec file
    # represented, no account-first scenario smuggled in, no failure, no flake,
    # and skips only in the specs reviewed as intentionally viewport-driven.
    Gate('playwright-remaining-merge', 'playwright_remaining_merge', 'browser'),
    # ------------------------------------------------------------- rehearsals
    Gate('deployment-rehearsal', 'deployment_rehearsal', 'rehearsal'),
    Gate('rollback-rehearsal', 'rollback_rehearsal', 'rehearsal'),
    Gate('deletion-apply-restore', 'deletion_apply_restore', 'rehearsal'),
    # -------------------------------------------------------------- packaging
    Gate('full-source-builder', 'full_source_builder', 'packaging'),
    Gate('runtime-builder', 'runtime_builder', 'packaging'),
    Gate('runtime-manifest-audit', 'runtime_manifest_audit', 'packaging'),
    Gate('source-patch-builder', 'source_patch_builder', 'packaging'),
    Gate('evidence-builder', 'evidence_builder', 'packaging'),
    # ----------------------------------------------------------- verification
    Gate('source-archive-audit', 'source_archive_audit', 'verification'),
    Gate('baseline-archive-audit', 'baseline_archive_audit', 'verification'),
    Gate('source-runtime-parity', 'source_runtime_parity', 'verification'),
    Gate('runtime-asset-parity', 'runtime_asset_parity', 'verification'),
    # Audits the component artifacts that exist BEFORE indexing. Named for what
    # it actually covers: the previous `archive-audit` label implied it had seen
    # the final evidence ZIP and the master, which did not exist when it ran.
    Gate('component-archive-audit', 'component_archive_audit', 'verification'),

    # The gates below cover bytes that only exist after the release evidence is
    # sealed, so they belong to the external attestation phase, not to the
    # ledger inside the package they judge.
    #
    # `evidence-finalizer` is the command that actually creates the sealed
    # evidence ZIP and its internal index. Its own raw log cannot live inside
    # the archive it creates without self-reference, so it is recorded in the
    # attestation ledger and bound by the external attestation instead.
    Gate('evidence-finalizer', 'evidence_finalizer', 'packaging'),
    Gate('final-archive-audit', 'final_archive_audit', 'verification'),
    Gate('sha256sums-verification', 'sha256sums_verification', 'verification'),
    Gate('final-clean-verifier', 'final_clean_verifier', 'verification'),
)

BY_LABEL = {gate.ledger_label: gate for gate in GATES}
BY_INDEX_KEY = {gate.index_key: gate for gate in GATES}

REQUIRED_LABELS = tuple(gate.ledger_label for gate in GATES if gate.required)
REQUIRED_INDEX_KEYS = tuple(gate.index_key for gate in GATES if gate.required)

# The five viewport projects.
#
# The exact 20-per-project / 100-total contract belongs to the ACCOUNT-FIRST
# spec alone. The complete browser directory registers far more tests per
# project than that and deliberately skips by viewport — admin and MFA outside
# desktop, touch-only accessibility checks, desktop/mobile navigation
# alternatives — so "20 expected, 0 skipped" could never describe a
# whole-suite run. A correct product would have failed that gate.
#
# The remaining suite therefore runs as its own recorded gate with its own
# policy, rather than being forced into a contract written for one spec.
PLAYWRIGHT_PROJECTS = (
    'mobile-360x800', 'mobile-390x844', 'tablet-768x1024',
    'laptop-1366x768', 'desktop-1440x900',
)
PLAYWRIGHT_TESTS_PER_PROJECT = 20
PLAYWRIGHT_TESTS_TOTAL = PLAYWRIGHT_TESTS_PER_PROJECT * len(PLAYWRIGHT_PROJECTS)

# The spec that contract describes.
PLAYWRIGHT_ACCOUNT_FIRST_SPEC = 'tests/Browser/account-first-registration.spec.ts'

# The rest of the browser suite. Skips here are expected and viewport-driven, so
# the gate requires that every test either passed or was skipped — never failed,
# never flaky — instead of an exact count that would need editing whenever a
# scenario is added.
PLAYWRIGHT_REMAINING_SPECS = (
    'tests/Browser/accessibility.spec.ts',
    'tests/Browser/admin.spec.ts',
    # Project creation truthfulness (Phase 11): a real project is created
    # through the legacy admin form and persists across a reload; a URL
    # pasted into the slug field fails in words, never as a raw
    # validation.* key; the wizard's project-type options are translated.
    'tests/Browser/admin-project-creation.spec.ts',
    'tests/Browser/advisor.spec.ts',
    # Market grounding (Phase 12): an admin records an Ankawa insight through
    # the real Knowledge form, approves it through the real workflow, and the
    # advisor's market answer is built FROM it with its evidence-class stance
    # — deterministically, with no AI provider configured.
    'tests/Browser/advisor-market-grounding.spec.ts',
    # Advisor overlay containment (Phase 10): the live-chat header decoration
    # is gated to the advisor page's own marker — the admin chrome stays one
    # viewport wide on both phone widths, and neither the admin nor a public
    # detail header carries the ring or the "AI live" badge.
    'tests/Browser/advisor-widget-containment.spec.ts',
    'tests/Browser/auth.spec.ts',
    # The Investment Map suite: every locale, every viewport, against the
    # persisted fixture rows — including the degraded no-tiles contract.
    'tests/Browser/invest.spec.ts',
    'tests/Browser/locales.spec.ts',
    # Wave 3 location intelligence: geolocation strictly user-initiated
    # (measured, not assumed), the seeded polygon resolves with its real
    # published index value, denial/outside-coverage stay honest and
    # localized, the manual published-area fallback answers identically,
    # and the valuation CTA follows the real portfolio auth gate. Runs on
    # every viewport with NO skips, so it is deliberately absent from
    # PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS — a skip here is a defect.
    'tests/Browser/location-intelligence.spec.ts',
    # The working-map suite: a deterministic style served from inside the
    # tests (never demotiles), map readiness on the homepage//map//invest,
    # all four trend semantics from persisted rows, marker-click selection,
    # and the admin picker's hidden-tab recovery.
    'tests/Browser/map-production.spec.ts',
    # The Arabic-script RTL text plugin suite (PR #14): single registration
    # across an SPA navigation, the plugin served as a same-origin production
    # asset, and the lazy load driven to 'loaded' by أربيل/هەولێر labels.
    # Runs on every viewport with NO skips, so it is deliberately absent
    # from PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS — a skip here is a defect.
    'tests/Browser/map-rtl.spec.ts',
    # Wave 4 market movement: the panel drives the REAL /market/movement
    # endpoint against seeded monthly series — sale/rent isolation, exact
    # category composition, the honestly disabled 7D and honestly available
    # 30D dated windows, genuine gainer/loser percentages, the four-point
    # fixture sparkline, RTL/LTR and the 120% text scale. Runs on every
    # viewport with NO skips, so it is deliberately absent from
    # PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS — a skip here is a defect.
    'tests/Browser/market-movement.spec.ts',
    'tests/Browser/mfa.spec.ts',
    'tests/Browser/navigation.spec.ts',
    # Valuation rules (redesign Wave 6): the active set's question renders
    # trilingually from persisted rows with NO percentage anywhere in the
    # owner DOM; the answer travels as an option id, rehydrates by id after
    # a reload of the edit fold, and the requested valuation lands at the
    # exact adjusted Decimal (110000 -> 115500.0000) with the snapshot-backed
    # breakdown, total and history row. No skips on any viewport.
    'tests/Browser/portfolio-valuation.spec.ts',
    'tests/Browser/production-assets.spec.ts',
    # Profile + portfolio (redesign Wave 5): the grouped profile edits real
    # fields under the onboarding rules and refuses dishonest input; the
    # portfolio dashboard derives counts, per-currency totals, coverage and
    # the two-point history trend from persisted rows; a stranger's direct
    # property URL answers 404. No skips on any viewport.
    'tests/Browser/profile-portfolio.spec.ts',
    'tests/Browser/public-home.spec.ts',
    # Public market insights (Phase 13): the real editorial lifecycle drives
    # the public area timeline — approved-but-unpublished, draft and lapsed
    # insights stay invisible; a published one appears with its evidence
    # class, source and dates; predictions wear caution; 360px holds.
    'tests/Browser/public-market-insights.spec.ts',
)

# The specs REVIEWED as intentionally skipping by design. Each entry names the
# spec file and the reviewed reason its skips exist. A skip reported from any
# spec not listed here is an unreviewed skip and fails the remaining-suite
# merge gate — "skips permitted" must never quietly widen into "skips ignored".
PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS = {
    'tests/Browser/accessibility.spec.ts':
        'touch-target and drawer checks are touch/small-viewport requirements',
    'tests/Browser/admin.spec.ts':
        'admin flow runs once, on desktop-1440x900 only',
    'tests/Browser/admin-project-creation.spec.ts':
        'admin creation flow runs once, on desktop-1440x900 only',
    'tests/Browser/advisor.spec.ts':
        'advisor flow is desktop-only and feature-flag dependent',
    'tests/Browser/advisor-market-grounding.spec.ts':
        'the market-grounding flow runs once, on desktop-1440x900, '
        'and is feature-flag dependent',
    'tests/Browser/advisor-widget-containment.spec.ts':
        'the admin overflow pins are phone-width scenarios; '
        'the public-detail negative pin runs once, on desktop',
    'tests/Browser/invest.spec.ts':
        "the list-tab/bottom-sheet selection scenario exists only below lg "
        "(the page renders tabs and a sheet on phones, a side panel on "
        "desktop), so laptop-1366x768 and desktop-1440x900 skip it by design "
        "— reviewed for final release run #30, where this Phase 4 skip "
        "surfaced as the registry's one unrecorded entry",
    'tests/Browser/mfa.spec.ts':
        'MFA flow runs once, on desktop-1440x900 only',
    'tests/Browser/map-production.spec.ts':
        'marker interaction and the admin picker are desktop scenarios; '
        'the map/list switch exists only below md',
    'tests/Browser/navigation.spec.ts':
        'rail and drawer are each intentionally absent on one side of lg',
    'tests/Browser/public-market-insights.spec.ts':
        'the publish flow runs once on desktop-1440x900; '
        'the overflow pin runs once at 360x800; both are flag-dependent',
}


# Files that cannot appear in their own artifact map, because each would have to
# contain a hash of itself. Producer and verifier read this ONE set — duplicating
# it meant the index excluded three files and the verifier only excluded one, so
# the verifier reported the other two as unclassified.
SELF_REFERENCE_EXCLUSIONS = (
    'evidence-index.json',
    'release-index.json',
    'myhawler-account-first-registration-FINAL-DELIVERY.zip',
)

# What the production runtime deliberately omits. The builder applies this and
# the parity checker skips the same set — two lists meant parity reported files
# "absent from runtime" that the builder was correct to leave out.
RUNTIME_EXCLUDED_DIRS = (
    'tests', '.github', 'scripts/release', 'resources/js', 'resources/css',
    'node_modules', 'vendor', '.phpunit.cache', 'public/build',
)
RUNTIME_EXCLUDED_FILES = (
    'playwright.config.ts', 'vite.config.ts', 'tsconfig.json', 'eslint.config.js',
    'phpunit.xml', 'phpunit.mariadb.xml', 'phpstan.neon',
    'package.json', 'package-lock.json',
    'TREE_MANIFEST.txt', 'TREE_MANIFEST.sha256', 'SHA256SUMS.txt',
)


def runtime_excluded(rel: str) -> bool:
    if rel in RUNTIME_EXCLUDED_FILES:
        return True
    return any(rel == d or rel.startswith(d + '/') for d in RUNTIME_EXCLUDED_DIRS)


EVIDENCE_INDEX_SCHEMA = 'myhawler-evidence-index/v3'
RELEASE_INDEX_SCHEMA = 'myhawler-release-index/v3'
LEDGER_SCHEMA = 'myhawler-command-ledger/v3'
# v2: gates are derived from the measured attestation ledger (single measured
# exit_code cross-checked against the runner's observed exit), the raw logs are
# re-hashed, and the sealed evidence archive is bound alongside the master.
ATTESTATION_SCHEMA = 'myhawler-final-attestation/v2'

# These gates seal or judge the finished package: the command that creates the
# evidence ZIP, the master archive audit, the complete detached checksum set,
# and the delivered verifier's own verdict. None of their results can live
# inside the package they seal or judge without a circular dependency, so they
# are recorded in a SEPARATE external attestation ledger produced around the
# sealing — a formally defined second phase, not an ordering accident.
ATTESTATION_ONLY_LABELS = (
    'evidence-finalizer',
    'final-archive-audit',
    'sha256sums-verification',
    'final-clean-verifier',
)

RELEASE_EVIDENCE_LABELS = tuple(
    gate.ledger_label for gate in GATES
    if gate.required and gate.ledger_label not in ATTESTATION_ONLY_LABELS
)


def log_path(label: str) -> str:
    """
    Raw-log path for a gate, RELATIVE TO THE EVIDENCE ROOT.

    Relative to the evidence root specifically, because that is what gets zipped.
    A recorder that wrote into `<evidence>/ledger/` while recording
    `php/foo.log` produced entries that could not resolve inside the archive.
    """
    gate = BY_LABEL.get(label)
    category = gate.category if gate else 'other'

    return f'{category}/{label}.log'


def index_key(label: str) -> str:
    gate = BY_LABEL.get(label)

    return gate.index_key if gate else label.replace('-', '_')


def verify_spec_closure(directory) -> list[str]:
    """
    Prove the on-disk browser spec inventory equals the canonical registry.

    The remaining suite runs from PLAYWRIGHT_REMAINING_SPECS, so a spec file
    the registry does not name would silently never run — the exact class of
    silent coverage loss the registry replaced `--grep-invert` to close. This
    closes the loop in the other direction: every *.spec.ts under the browser
    test directory must be either the account-first spec or a canonical
    remaining spec, nothing may be missing, and basenames must be unique
    (report matching is by basename).
    """
    from pathlib import Path

    root = Path(directory)
    problems: list[str] = []

    if not root.is_dir():
        return [f'no browser spec directory at {root}']

    canonical = {PLAYWRIGHT_ACCOUNT_FIRST_SPEC, *PLAYWRIGHT_REMAINING_SPECS}
    prefix = 'tests/Browser/'
    expected = {spec[len(prefix):] if spec.startswith(prefix) else spec
                for spec in canonical}

    on_disk = {str(path.relative_to(root)).replace('\\', '/')
               for path in root.rglob('*.spec.ts')}

    for missing in sorted(expected - on_disk):
        problems.append(f'canonical spec absent from disk: {prefix}{missing}')

    for extra in sorted(on_disk - expected):
        problems.append(f'spec on disk but not in the canonical registry '
                        f'(it would silently never run): {prefix}{extra}')

    basenames: dict[str, str] = {}

    for rel in sorted(on_disk):
        base = rel.rsplit('/', 1)[-1]
        if base in basenames:
            problems.append(f'duplicate spec basename: {rel} vs '
                            f'{basenames[base]}; report matching is by basename')
        basenames.setdefault(base, rel)

    return problems


if __name__ == '__main__':
    import json
    import sys

    # `--remaining-specs` prints the canonical remaining-suite inventory one
    # path per line, so the runner builds its Playwright argv from THIS list
    # instead of a `--grep-invert` title match that proves nothing about which
    # files were discovered.
    if '--remaining-specs' in sys.argv[1:]:
        for spec in PLAYWRIGHT_REMAINING_SPECS:
            print(spec)
        sys.exit(0)

    # `--verify-spec-closure DIR` fails when the on-disk spec inventory and
    # the canonical registry disagree in either direction.
    if '--verify-spec-closure' in sys.argv[1:]:
        position = sys.argv.index('--verify-spec-closure')
        if position + 1 >= len(sys.argv):
            print('FAIL: --verify-spec-closure requires a directory', file=sys.stderr)
            sys.exit(2)
        closure_problems = verify_spec_closure(sys.argv[position + 1])
        for problem in closure_problems:
            print(f'  FAIL  {problem}', file=sys.stderr)
        print(f'spec closure: {len(closure_problems)} problem(s)')
        sys.exit(1 if closure_problems else 0)

    print(json.dumps({
        'gates': len(GATES),
        'required': len(REQUIRED_LABELS),
        'categories': sorted({g.category for g in GATES}),
        'playwright_total': PLAYWRIGHT_TESTS_TOTAL,
    }, indent=2))
    sys.exit(0)

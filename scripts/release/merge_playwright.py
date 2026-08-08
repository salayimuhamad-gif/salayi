#!/usr/bin/env python3
"""
Merge the Playwright project reports and reject anything short of a clean run.

"At least one test passed" would let a partially-collected run look green, which
is how a missing scenario ships. A skip verifies nothing, and a flake that
passes on retry is an unresolved defect, so both fail here.

Two modes, two contracts:

`account-first` merges the five per-project reports of the exact-count matrix:
exactly 20 expected tests per project, five projects, no skip anywhere.

`remaining` validates the remaining-suite report against the CANONICAL file
inventory in release_gates.PLAYWRIGHT_REMAINING_SPECS, not against test-title
matching. Every intended spec file must be represented by at least one executed
test, every project must have executed something, no account-first scenario may
appear, nothing outside the inventory may appear, no failure or flake is
tolerated, and a skip is only acceptable from a spec reviewed as intentionally
viewport- or feature-driven (PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS). An empty
report directory fails outright unless `--allow-empty` says this is the offline
stub — a gate that can pass on zero reports is not a gate.
"""
from __future__ import annotations

import argparse
import glob
import json
import os
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from normalize_browser_report import vcs_keys_in  # noqa: E402
from release_gates import (  # noqa: E402
    PLAYWRIGHT_ACCOUNT_FIRST_SPEC, PLAYWRIGHT_PROJECTS,
    PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS, PLAYWRIGHT_REMAINING_SPECS,
    PLAYWRIGHT_TESTS_PER_PROJECT, PLAYWRIGHT_TESTS_TOTAL,
)


def walk_tests(suite: dict, inherited_file: str | None):
    """Yield (spec_file, spec_title, test) for every test under a suite."""
    file = suite.get('file', inherited_file)

    for spec in suite.get('specs', []):
        spec_file = spec.get('file') or file or ''
        for test in spec.get('tests', []):
            yield spec_file, spec.get('title', ''), test

    for child in suite.get('suites', []):
        yield from walk_tests(child, file)


def validate_remaining_reports(report_paths: list[str]) -> list[str]:
    """
    Prove the remaining-suite report covers the canonical spec inventory.

    File identity is matched by basename: the canonical registry names
    repository-relative paths while the Playwright JSON reporter names paths
    relative to its testDir, and every canonical basename is unique.
    """
    problems: list[str] = []
    canonical = {spec.rsplit('/', 1)[-1]: spec for spec in PLAYWRIGHT_REMAINING_SPECS}
    account_first_base = PLAYWRIGHT_ACCOUNT_FIRST_SPEC.rsplit('/', 1)[-1]
    reviewed_skips = {spec.rsplit('/', 1)[-1]
                     for spec in PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS}

    executed_by_spec = {base: 0 for base in canonical}
    executed_by_project = {name: 0 for name in PLAYWRIGHT_PROJECTS}

    for path in report_paths:
        report = json.loads(Path(path).read_text())

        for suite in report.get('suites', []):
            for spec_file, title, test in walk_tests(suite, suite.get('file')):
                base = spec_file.rsplit('/', 1)[-1]
                project = test.get('projectName', '<unknown>')
                status = test.get('status', '<none>')

                if base == account_first_base:
                    problems.append(
                        f'account-first scenario inside the remaining suite: {title!r}')
                    continue

                if base not in canonical:
                    problems.append(
                        f'spec outside the canonical remaining inventory: {base}')
                    continue

                if status in ('unexpected', 'flaky'):
                    problems.append(f'{base}: {title!r} is {status} on {project}')
                elif status == 'skipped':
                    if base not in reviewed_skips:
                        problems.append(
                            f'{base}: unreviewed skip {title!r} on {project}; only '
                            'specs in PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS may skip')
                elif status == 'expected':
                    executed_by_spec[base] += 1
                    if project in executed_by_project:
                        executed_by_project[project] += 1
                    else:
                        problems.append(
                            f'{base}: executed under unknown project {project!r}')

    for base, spec in sorted(canonical.items()):
        if executed_by_spec[base] == 0:
            problems.append(f'{spec}: no test executed; the canonical remaining '
                            'inventory is not covered')

    for name in PLAYWRIGHT_PROJECTS:
        if executed_by_project[name] == 0:
            problems.append(f'project {name}: no remaining-suite test executed')

    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description='Merge Playwright reports.')
    parser.add_argument('--browser-dir', required=True)
    parser.add_argument('--tree-manifest-sha256', required=True)
    parser.add_argument('--allow-empty', action='store_true',
                        help='offline stub mode: no browser ran, so require nothing')
    parser.add_argument('--mode', choices=('account-first', 'remaining'),
                        default='account-first',
                        help='account-first requires exactly 20 per project and no '
                             'skips; remaining validates the canonical spec-file '
                             'inventory and permits only reviewed intentional skips')
    args = parser.parse_args()

    browser = Path(args.browser_dir)
    browser.mkdir(parents=True, exist_ok=True)
    merged = {'tree_manifest_sha256': args.tree_manifest_sha256,
              'projects': {}, 'totals': {'expected': 0, 'unexpected': 0,
                                         'skipped': 0, 'flaky': 0}}
    problems: list[str] = []
    report_paths: list[str] = []

    for path in sorted(glob.glob(os.path.join(browser, '*.json'))):
        if 'playwright-merged' in Path(path).name:
            continue
        report_paths.append(path)
        name = Path(path).stem
        with open(path) as fh:
            report = json.load(fh)
        stats = report.get('stats', {})

        # VCS metadata must already be gone. normalize_browser_report.py strips
        # it and Playwright is configured not to capture it, but this is the
        # gate that is RECORDED — so if either of those is skipped or silently
        # stops working, the release stops here instead of sealing a commit
        # message into the evidence. Final release #5 shipped one all the way to
        # the clean-directory scanner.
        leftover = vcs_keys_in(report)
        if leftover:
            problems.append(f'{name}: VCS metadata in the report '
                            f'({", ".join(leftover)}); it is not release '
                            f'evidence and must be normalized away first')
        merged['projects'][name] = stats
        for key in merged['totals']:
            merged['totals'][key] += stats.get(key, 0)
        # A failure or a flake is never acceptable in either mode: a flake that
        # passes on retry is an unresolved defect, not a pass.
        for bad in ('unexpected', 'flaky'):
            if stats.get(bad, 0):
                problems.append(f'{name}: {stats[bad]} {bad}')

        if args.mode == 'account-first':
            # A skip here would mean a required registration scenario did not
            # run, which tells us nothing about the behaviour it covers.
            if stats.get('skipped', 0):
                problems.append(f'{name}: {stats["skipped"]} skipped')
            if stats.get('expected', 0) != PLAYWRIGHT_TESTS_PER_PROJECT:
                problems.append(f'{name}: {stats.get("expected", 0)} expected tests, '
                                f'required exactly {PLAYWRIGHT_TESTS_PER_PROJECT}')
        else:
            # Skips are viewport-driven by design here, but something must have
            # actually run.
            if stats.get('expected', 0) == 0:
                problems.append(f'{name}: no test executed')

    if args.mode == 'account-first':
        if not args.allow_empty:
            if len(merged['projects']) != len(PLAYWRIGHT_PROJECTS):
                problems.append(f'expected {len(PLAYWRIGHT_PROJECTS)} projects, '
                                f'found {len(merged["projects"])}')
            if merged['totals']['expected'] != PLAYWRIGHT_TESTS_TOTAL:
                problems.append(f'{merged["totals"]["expected"]} expected tests in '
                                f'total, required exactly {PLAYWRIGHT_TESTS_TOTAL}')
        elif not merged['projects']:
            problems = []
            print('offline stub: no browser reports to merge')
    elif not report_paths:
        # Remaining mode with nothing to read. Only the declared offline stub
        # may pass here — an absent report must never validate a real run.
        if args.allow_empty:
            problems = []
            print('offline stub: no remaining-suite report to merge')
        else:
            problems.append('no remaining-suite JSON report found; the canonical '
                            'inventory cannot be validated from nothing')
    else:
        problems += validate_remaining_reports(report_paths)

    merged['mode'] = args.mode
    suffix = '' if args.mode == 'account-first' else f'-{args.mode}'
    (browser / f'playwright-merged{suffix}.json').write_text(json.dumps(merged, indent=2))

    suites = ET.Element('testsuites', name='myhawler-playwright')
    for path in sorted(glob.glob(os.path.join(browser, '*.xml'))):
        if 'playwright-merged' in Path(path).name:
            continue
        for suite in ET.parse(path).getroot():
            suites.append(suite)
    ET.ElementTree(suites).write(browser / f'playwright-merged{suffix}.xml',
                                 encoding='utf-8', xml_declaration=True)

    for problem in problems:
        print(f'  FAIL  {problem}', file=sys.stderr)
    print('playwright totals: ' + json.dumps(merged['totals']))

    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())

#!/usr/bin/env python3
"""
Strip non-contract VCS metadata from Playwright reports BEFORE they are evidence.

Playwright captures git information on CI and stores it in
`config.metadata.gitCommit` — hash, author, timestamps and the FULL commit
message body. The release runner points Playwright's JSON output directly at
the evidence directory, so whatever a commit message happened to say became
sealed release evidence and shipped inside FINAL-DELIVERY.zip.

Final release #5 failed on exactly that. A commit message explaining a
regression quoted an environment assignment as prose; the reporter copied the
message into all five project reports; the clean-directory secret scanner found
secret-shaped text in the delivery and refused it. The scanner was correct. The
defect was that arbitrary prose reached the evidence at all.

WHAT THIS REMOVES, AND WHY THAT IS SAFE.

MyHawler identifies its source by TREE_MANIFEST.sha256 — the frozen tree hash
bound into every ledger entry, the evidence index and the attestation. A commit
message is not part of that contract and proves nothing about the tree: two
different trees can share one, and the authenticated tree is not even a git
checkout by the time the browsers run. Removing it costs the evidence nothing.

Everything the release contract does rely on is untouched: suites, specs, test
results, stats, project identity, timings, attachments, errors, and every other
`config` field. Only VCS keys inside `config.metadata` are dropped.

Playwright's config is set to `captureGitInfo: { commit: false, diff: false }`,
which should mean there is nothing here to remove. This runs anyway, because a
future version may capture something new by default, a config may be overridden
on the command line, and the evidence has to be safe either way. Removing
nothing is the expected outcome, not a reason to skip the step.

Usage:
    normalize_browser_report.py --browser-dir DIR [--check]

--check reports without rewriting, for use as an assertion.
Exits nonzero if a report cannot be read, or — under --check — if any VCS
metadata is still present.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

# Keys inside `config.metadata` that carry version-control provenance rather
# than test results. `gitCommit` and `gitDiff` are what Playwright writes today;
# the prefix rule covers whatever it names the next one, because the failure
# mode is a field nobody knew to look for.
VCS_METADATA_KEYS = ('gitCommit', 'gitDiff')
VCS_METADATA_PREFIXES = ('git',)


def is_vcs_key(key: str) -> bool:
    return key in VCS_METADATA_KEYS or key.lower().startswith(VCS_METADATA_PREFIXES)


def vcs_keys_in(report: dict) -> list[str]:
    """The VCS metadata keys a report currently carries."""
    metadata = (report.get('config') or {}).get('metadata')

    if not isinstance(metadata, dict):
        return []

    return sorted(key for key in metadata if is_vcs_key(key))


def normalize(path: Path, *, check: bool) -> tuple[list[str], str | None]:
    """
    Returns (removed keys, error).

    The file is rewritten only when something was actually removed, so a clean
    report keeps its exact bytes and re-running changes nothing.
    """
    try:
        report = json.loads(path.read_text())
    except (OSError, json.JSONDecodeError, UnicodeDecodeError) as exc:
        return [], f'{path}: unreadable Playwright report ({exc})'

    if not isinstance(report, dict):
        return [], f'{path}: not a Playwright report object'

    found = vcs_keys_in(report)

    if not found or check:
        return found, None

    metadata = report['config']['metadata']
    for key in found:
        del metadata[key]

    path.write_text(json.dumps(report, indent=2) + '\n')

    return found, None


def main() -> int:
    parser = argparse.ArgumentParser(
        description='Remove VCS metadata from Playwright reports.')
    parser.add_argument('--browser-dir', required=True)
    parser.add_argument('--check', action='store_true',
                        help='report without rewriting; fail if any is present')
    args = parser.parse_args()

    browser = Path(args.browser_dir)

    if not browser.is_dir():
        raise SystemExit(f'FAIL: no browser evidence directory at {browser}')

    # Recursive: the account-first projects sit at the top and the remaining
    # suite one level down, and both are sealed.
    reports = sorted(p for p in browser.rglob('*.json')
                     if 'playwright-merged' not in p.name)

    problems: list[str] = []
    stripped = 0

    for report in reports:
        removed, error = normalize(report, check=args.check)

        if error:
            problems.append(error)
            continue

        if removed:
            stripped += 1
            verb = 'still carries' if args.check else 'removed from'
            print(f'  {verb} {report.name}: {", ".join(removed)}')

            if args.check:
                problems.append(f'{report}: VCS metadata present: '
                                f'{", ".join(removed)}')

    for problem in problems[:20]:
        print(f'  FAIL  {problem}', file=sys.stderr)

    print(f'browser reports normalized: {len(reports)} scanned, '
          f'{stripped} carried VCS metadata, problems: {len(problems)}')

    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())

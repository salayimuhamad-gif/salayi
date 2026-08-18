#!/usr/bin/env python3
"""
Build evidence-index.json and release-index.json from the FINAL artifact set.

Both read the shared registry and the shared self-reference exclusion set, so a
producer and a verifier cannot disagree about what a gate is called or which
files cannot classify themselves.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from release_gates import (  # noqa: E402
    ATTESTATION_ONLY_LABELS, EVIDENCE_INDEX_SCHEMA, RELEASE_EVIDENCE_LABELS,
    RELEASE_INDEX_SCHEMA, SELF_REFERENCE_EXCLUSIONS, index_key,
)


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser(description='Build the release indexes.')
    parser.add_argument('--delivery', required=True)
    parser.add_argument('--evidence', required=True)
    parser.add_argument('--tree-manifest-sha256', required=True)
    parser.add_argument('--baseline-commit', required=True)
    parser.add_argument('--browser-spec', required=True)
    args = parser.parse_args()

    delivery, evidence = Path(args.delivery), Path(args.evidence)
    tree = args.tree_manifest_sha256

    ledger = json.loads((delivery / 'command-ledger.json').read_text())
    entries = ledger['entries'] if isinstance(ledger, dict) else ledger
    inventory = json.loads((evidence / 'CHANGED_FILES_INVENTORY.json').read_text())

    artifacts = {
        path.name: {'sha256': digest(path), 'bytes': path.stat().st_size}
        for path in sorted(delivery.iterdir())
        if path.is_file() and not path.name.endswith('.sha256')
        and path.name not in SELF_REFERENCE_EXCLUSIONS
    }

    (delivery / 'evidence-index.json').write_text(json.dumps({
        'schema': EVIDENCE_INDEX_SCHEMA,
        'tree_manifest_sha256': tree,
        'baseline_commit': args.baseline_commit,
        'ledger_entries': len(entries),
        'bound_artifacts': artifacts,
        'browser_spec_sha256': digest(Path(args.browser_spec)),
        'runner_script_sha256': (evidence / 'RUNNER_SCRIPT_SHA256').read_text().strip(),
        'baseline_archive_sha256': (evidence / 'BASELINE_ARCHIVE_SHA256').read_text().strip(),
    }, indent=2))

    gates = {index_key(e['label']): f'exit {e["exit_code"]}' for e in entries}
    recorded = {e['label'] for e in entries}
    missing = [label for label in RELEASE_EVIDENCE_LABELS if label not in recorded]
    failed = sorted(e['label'] for e in entries if e['exit_code'] != 0)

    (delivery / 'release-index.json').write_text(json.dumps({
        'schema': RELEASE_INDEX_SCHEMA,
        'identity': {
            'baseline_commit': args.baseline_commit,
            'baseline_commit_note': 'ANCESTOR ONLY; not the identity of this tree.',
            'final_tree_manifest_sha256': tree,
        },
        'artifacts': artifacts,
        'gates': gates,
        'attestation_only_gates': list(ATTESTATION_ONLY_LABELS),
        'changed_files': {
            'total': inventory['total'],
            'files': [{'path': p, 'change': 'added'} for p in inventory['added']]
                     + [{'path': p, 'change': 'modified'} for p in inventory['modified']],
            'removed': inventory['removed'],
        },
        'verdict': ('PRODUCTION CANDIDATE COMPLETE' if not missing and not failed
                    else 'INCOMPLETE'),
    }, indent=2))

    for label in missing[:10]:
        print(f'  FAIL  required gate never recorded: {label}', file=sys.stderr)
    for label in failed[:10]:
        print(f'  FAIL  gate exited nonzero: {label}', file=sys.stderr)

    print(f'indexed {len(artifacts)} artifacts, {len(entries)} ledger entries, '
          f'{len(missing)} missing, {len(failed)} failed')

    return 1 if missing or failed else 0


if __name__ == '__main__':
    sys.exit(main())

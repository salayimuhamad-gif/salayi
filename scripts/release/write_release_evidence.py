#!/usr/bin/env python3
"""
Write the schema-valid release-evidence.json into the evidence directory.

The cleanroom verifier requires this document inside the authoritative evidence
archive, and nothing produced it: the runner never invoked
collect-release-evidence.php, and build_v7_evidence.py does not generate it.

Everything here is derived from artifacts that already exist — the frozen tree,
the final ledger, the two verified input archives and the component hashes — so
the document cannot describe a state that was never reached.

SCOPE: this document is written BEFORE the evidence is sealed, because the
cleanroom verifier requires it INSIDE the authoritative evidence archive. At
that moment the evidence ZIP, evidence-index.json, release-index.json, the
detached checksum set and the FINAL-DELIVERY master do not exist yet, so its
artifact map is named `pre_seal_component_artifacts` and covers exactly the
component artifacts present pre-seal — nothing more. The sealed artifacts are
bound by the EXTERNAL final attestation (write_attestation.py), which runs
after sealing and hashes the evidence ZIP and the master directly.

Offline stub runs produce the SAME schema with `offline_stub: true` and a
non-production verdict, rather than omitting the file. Omitting it would make
the stub path structurally different from the real one, which is exactly what
the stub exists to rule out.

Usage:
    write_release_evidence.py --evidence DIR --delivery DIR
                              --tree-manifest-sha256 SHA --baseline-commit SHA
                              [--offline-stub]
"""
from __future__ import annotations

import argparse
import datetime
import hashlib
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from release_gates import (  # noqa: E402
    ATTESTATION_ONLY_LABELS, RELEASE_EVIDENCE_LABELS, index_key,
)

SCHEMA_VERSION = 3


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def read_hash(path: Path) -> str | None:
    return path.read_text().strip() if path.is_file() else None


def main() -> int:
    parser = argparse.ArgumentParser(description='Write release-evidence.json.')
    parser.add_argument('--evidence', required=True)
    parser.add_argument('--delivery', required=True)
    parser.add_argument('--tree-manifest-sha256', required=True)
    parser.add_argument('--baseline-commit', required=True)
    parser.add_argument('--offline-stub', action='store_true')
    args = parser.parse_args()

    evidence, delivery = Path(args.evidence), Path(args.delivery)
    tree = args.tree_manifest_sha256

    if len(tree) != 64 or any(c not in '0123456789abcdef' for c in tree):
        raise SystemExit('FAIL: --tree-manifest-sha256 must be a 64-character digest')

    ledger_path = evidence / 'command-ledger.json'

    if not ledger_path.is_file():
        raise SystemExit(f'FAIL: no ledger at {ledger_path}; evidence cannot describe '
                         'gates that were never recorded')

    ledger = json.loads(ledger_path.read_text())
    entries = ledger['entries'] if isinstance(ledger, dict) else ledger

    wrong = [e['label'] for e in entries if e.get('final_tree_manifest_sha256') != tree]

    if wrong:
        raise SystemExit(f'FAIL: {len(wrong)} ledger entries bind to another tree')

    recorded = {e['label'] for e in entries}
    missing = [label for label in RELEASE_EVIDENCE_LABELS if label not in recorded]
    failed = sorted(e['label'] for e in entries if e['exit_code'] != 0)

    if missing:
        for label in missing[:10]:
            print(f'  FAIL  required gate never recorded: {label}', file=sys.stderr)
        raise SystemExit(f'FAIL: {len(missing)} required gate(s) absent from the ledger')

    # Component artifact hashes, taken from the bytes present PRE-SEAL. The
    # evidence ZIP, the indexes, the detached checksum set and the master do
    # not exist yet; see the module docstring for where those are bound.
    artifacts = {
        path.name: {'sha256': digest(path), 'bytes': path.stat().st_size}
        for path in sorted(delivery.iterdir())
        if path.is_file() and not path.name.endswith('.sha256')
        and not path.name.endswith('FINAL-DELIVERY.zip')
    }

    gates = {
        index_key(e['label']): {
            'exit_code': e['exit_code'],
            'duration_seconds': e['duration_seconds'],
            'raw_output_sha256': e['raw_output_sha256'],
        }
        for e in entries
    }

    document = {
        'schema_version': SCHEMA_VERSION,
        'generated_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'offline_stub': bool(args.offline_stub),

        # Three identities, never conflated.
        'baseline_commit': args.baseline_commit,
        'baseline_commit_note': 'ANCESTOR ONLY; not the identity of this tree.',
        'final_tree_manifest_sha256': tree,
        'source_archive_sha256': read_hash(evidence / 'SOURCE_ARCHIVE_SHA256'),
        'baseline_archive_sha256': read_hash(evidence / 'BASELINE_ARCHIVE_SHA256'),
        'runner_script_sha256': read_hash(evidence / 'RUNNER_SCRIPT_SHA256'),

        'ledger_entries': len(entries),
        'gates': gates,
        'gates_failed': failed,
        'attestation_only_gates': list(ATTESTATION_ONLY_LABELS),

        # Named for its exact scope. This document is generated before the
        # authoritative evidence ZIP, evidence-index.json, release-index.json,
        # the detached checksum set and the FINAL-DELIVERY master exist, so it
        # can only bind the component artifacts present at that moment. The
        # sealed artifacts are bound by the external final attestation.
        'pre_seal_component_artifacts': artifacts,
        'pre_seal_component_artifacts_scope': (
            'Generated before sealing. Excludes the authoritative evidence ZIP, '
            'evidence-index.json, release-index.json, all detached .sha256 files '
            'and the FINAL-DELIVERY master; those hashes are bound in the '
            'external final-attestation.json written after sealing.'
        ),

        # A stub run has not verified anything about the product, and says so in
        # the document itself rather than leaving a reader to infer it.
        'verdict': (
            'OFFLINE STUB — NOT A PRODUCTION RELEASE' if args.offline_stub
            else ('PRODUCTION CANDIDATE COMPLETE' if not failed else 'INCOMPLETE')
        ),
    }

    target = evidence / 'release-evidence.json'
    target.write_text(json.dumps(document, indent=2) + '\n')

    print(f'release-evidence.json: tree={tree[:12]} gates={len(gates)} '
          f'artifacts={len(artifacts)} verdict={document["verdict"]}')

    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())

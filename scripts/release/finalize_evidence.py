#!/usr/bin/env python3
"""
Finalize the evidence package: index it once, zip it once, prove they agree.

Two components used to write evidence archives in one cycle. The first ran
mid-run and produced an index describing the directory as it was then; the
runner later re-zipped the same directory after adding reports, the final ledger
and release-evidence.json. The authoritative archive therefore carried an index
that did not describe its own contents.

This is the only component that packages evidence, and it runs last. The index
is generated from the completed directory, the archive is built from that exact
set, and the two are compared entry by entry — an archive whose index disagrees
with it is worse than no index at all.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import sys
import zipfile
from pathlib import Path

# The index cannot contain its own hash, so it is excluded from the entry set it
# describes; the detached .sha256 beside it carries that value.
SELF_DESCRIBING = ('evidence-index.json', 'evidence-index.json.sha256')


def digest_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser(description='Finalize the evidence package.')
    parser.add_argument('--evidence', required=True)
    parser.add_argument('--output', required=True, help='the evidence ZIP to create')
    parser.add_argument('--tree-manifest-sha256', required=True)
    args = parser.parse_args()

    evidence = Path(args.evidence).resolve()
    output = Path(args.output).resolve()
    tree = args.tree_manifest_sha256

    if not evidence.is_dir():
        raise SystemExit(f'FAIL: no evidence directory at {evidence}')

    files = sorted(
        path for path in evidence.rglob('*')
        if path.is_file() and path.name not in SELF_DESCRIBING
    )

    if not files:
        raise SystemExit('FAIL: the evidence directory is empty')

    index = {
        'schema': 'myhawler-internal-evidence-index/v1',
        'tree_manifest_sha256': tree,
        'file_count': len(files),
        'files': {
            str(path.relative_to(evidence)): {
                'sha256': digest_bytes(path.read_bytes()),
                'bytes': path.stat().st_size,
            }
            for path in files
        },
    }

    index_path = evidence / 'evidence-index.json'
    index_path.write_text(json.dumps(index, indent=2) + '\n')
    (evidence / 'evidence-index.json.sha256').write_text(
        f'{digest_bytes(index_path.read_bytes())}  evidence-index.json\n')

    output.unlink(missing_ok=True)

    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as zf:
        for path in files:
            zf.write(path, str(path.relative_to(evidence)))
        for name in SELF_DESCRIBING:
            member = evidence / name
            if member.is_file():
                zf.write(member, name)

    # Exact parity, both directions, against the archive that was just written.
    with zipfile.ZipFile(output) as zf:
        shipped = {n for n in zf.namelist() if n not in SELF_DESCRIBING}
        listed = set(index['files'])
        problems: list[str] = []

        for missing in sorted(listed - shipped):
            problems.append(f'indexed but not shipped: {missing}')
        for extra in sorted(shipped - listed):
            problems.append(f'shipped but not indexed: {extra}')
        for name in sorted(listed & shipped):
            payload = zf.read(name)
            if digest_bytes(payload) != index['files'][name]['sha256']:
                problems.append(f'hash mismatch: {name}')
            elif len(payload) != index['files'][name]['bytes']:
                problems.append(f'size mismatch: {name}')

    for problem in problems[:20]:
        print(f'  FAIL  {problem}', file=sys.stderr)

    print(f'evidence finalized: {len(files)} files, {len(shipped)} archive entries, '
          f'problems: {len(problems)}')

    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())

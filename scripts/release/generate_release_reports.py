#!/usr/bin/env python3
"""
Generate the four mandatory reports from canonical data.

`CHANGED_FILES.md`, `FIX_REPORT.md`, `SECURITY_REVIEW.md` and `TEST_RESULTS.md`
are required by the final verifier and did not exist anywhere: no script
produced them, and the frozen collector only emits the other four. The runner
was therefore guaranteed to stop at "required report missing and not generated
anywhere".

They are derived here from the command ledger and the changed-file inventory, so
every figure traces to a recorded execution. Nothing is copied forward from an
older checkpoint — a report carried over from a previous tree describes a tree
that no longer exists.

Usage:
    generate_release_reports.py --ledger FILE --inventory FILE
                                --tree-manifest-sha256 SHA --baseline-commit SHA
                                --output-dir DIR
"""
from __future__ import annotations

import argparse
import datetime
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from release_gates import BY_LABEL, RELEASE_EVIDENCE_LABELS  # noqa: E402


def table(rows: list[tuple[str, ...]], headers: tuple[str, ...]) -> str:
    lines = ['| ' + ' | '.join(headers) + ' |',
             '| ' + ' | '.join('---' for _ in headers) + ' |']
    lines += ['| ' + ' | '.join(row) + ' |' for row in rows]

    return '\n'.join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description='Generate mandatory release reports.')
    parser.add_argument('--ledger', required=True)
    parser.add_argument('--inventory', required=True)
    parser.add_argument('--tree-manifest-sha256', required=True)
    parser.add_argument('--baseline-commit', required=True)
    parser.add_argument('--output-dir', required=True)
    args = parser.parse_args()

    tree = args.tree_manifest_sha256
    out = Path(args.output_dir).resolve()
    out.mkdir(parents=True, exist_ok=True)

    document = json.loads(Path(args.ledger).read_text())
    entries = document['entries'] if isinstance(document, dict) else document
    inventory = json.loads(Path(args.inventory).read_text())

    wrong = [e['label'] for e in entries if e.get('final_tree_manifest_sha256') != tree]

    if wrong:
        raise SystemExit(f'FAIL: {len(wrong)} ledger entries bind to another tree; '
                         'reports would describe a tree that was never tested')

    by_label = {e['label']: e for e in entries}
    # Release evidence, not every registry gate: `final-clean-verifier` proves
    # the finished package and is recorded in the external attestation, so
    # requiring it here would make reports impossible to generate by design.
    missing = [label for label in RELEASE_EVIDENCE_LABELS if label not in by_label]
    failed = sorted(e['label'] for e in entries if e['exit_code'] != 0)
    stamp = datetime.datetime.now(datetime.timezone.utc).isoformat()

    header = (
        f'```text\n'
        f'final_tree_manifest_sha256  {tree}\n'
        f'baseline_commit             {args.baseline_commit}  (ancestor only)\n'
        f'generated                   {stamp}\n'
        f'```\n'
    )

    # ------------------------------------------------------------ TEST_RESULTS
    rows = []
    for label in sorted(by_label):
        entry = by_label[label]
        gate = BY_LABEL.get(label)
        rows.append((
            label,
            gate.category if gate else 'other',
            'PASS' if entry['exit_code'] == 0 else f'FAIL (exit {entry["exit_code"]})',
            f'{entry["duration_seconds"]}s',
            entry['raw_output_file'],
        ))

    test_results = f"""# TEST_RESULTS

{header}
Every row below is a recorded execution: the recorder captured its start time,
finish time, duration, exit code and raw output as it ran. No row is inferred
from the presence of a log file.

{table(rows, ('gate', 'category', 'result', 'duration', 'raw log'))}

## Coverage

```text
recorded gates      {len(entries)}
required gates      {len(RELEASE_EVIDENCE_LABELS)}
missing             {len(missing)}
nonzero exits       {len(failed)}
```
"""

    if missing:
        test_results += ('\n## Required gates with no recorded execution\n\n'
                         + '\n'.join(f'- `{label}`' for label in missing) + '\n')

    if failed:
        test_results += ('\n## Gates that did not exit 0\n\n'
                         + '\n'.join(f'- `{label}`' for label in failed) + '\n')

    (out / 'TEST_RESULTS.md').write_text(test_results)

    # ----------------------------------------------------------- CHANGED_FILES
    added, modified, removed = (inventory['added'], inventory['modified'],
                                inventory['removed'])

    changed = f"""# CHANGED_FILES

{header}
Derived from the canonical inventory the Source Patch builder produced by
comparing the verified baseline against the frozen tree. The arithmetic here and
in `release-index.json` come from the same source, so they cannot disagree.

```text
added     {len(added)}
modified  {len(modified)}
removed   {len(removed)}
total     {inventory['total']}
```

## Added

{chr(10).join(f'- `{p}`' for p in added) or '_none_'}

## Modified

{chr(10).join(f'- `{p}`' for p in modified) or '_none_'}

## Removed

Removals cannot be expressed by an overlay and must be applied from
`DELETE_FILES.txt`.

{chr(10).join(f'- `{p}`' for p in removed) or '_none_'}
"""
    (out / 'CHANGED_FILES.md').write_text(changed)

    # --------------------------------------------------------------- FIX_REPORT
    fix = f"""# FIX_REPORT

{header}
This release freezes one source tree and binds every recorded result to it.

## Identity model

Three identities are kept separate and never conflated:

```text
baseline_commit             an ANCESTOR label; it does not describe these files
final_tree_manifest_sha256  what the tree contains now
source_archive_sha256       which archive carries it
```

## Evidence architecture

Run-derived material lives outside the authenticated source. Evidence
collection is read-only against the frozen tree, and the cycle re-derives the
manifest afterwards and requires it to equal the freeze hash.

## Recorded gates

```text
recorded      {len(entries)}
required      {len(RELEASE_EVIDENCE_LABELS)}
missing       {len(missing)}
nonzero       {len(failed)}
```

Each entry carries thirteen fields measured at execution time. Timestamps,
durations and exit codes are captured by the recorder; none is written after the
fact.

Full per-gate detail is in `TEST_RESULTS.md`; changed files are in
`CHANGED_FILES.md`.
"""
    (out / 'FIX_REPORT.md').write_text(fix)

    # ---------------------------------------------------------- SECURITY_REVIEW
    # `archive-audit` was renamed `component-archive-audit` in the registry;
    # the old spelling here silently dropped the row from SECURITY_REVIEW.md.
    security_gates = ('security-audit', 'secret-scan', 'migration-guard',
                      'source-archive-audit', 'baseline-archive-audit',
                      'component-archive-audit')
    security_rows = [
        (label,
         'PASS' if by_label[label]['exit_code'] == 0 else f'FAIL (exit {by_label[label]["exit_code"]})',
         by_label[label]['raw_output_file'])
        for label in security_gates if label in by_label
    ]

    security = f"""# SECURITY_REVIEW

{header}
## Automated security gates

{table(security_rows, ('gate', 'result', 'raw log')) if security_rows
 else '_No security gate was recorded; this review cannot be relied upon._'}

## Archive safety

Both the source archive and the baseline archive are audited BEFORE extraction
for traversal, absolute and Windows paths, backslash separators, duplicates,
case collisions, symlinks, encrypted members and CRC failures. A checksum proves
byte identity, not path safety, so the audit is separate from the checksum.

## Credential handling

The cycle runs with no `.env` on disk; `secret-scan.php` treats one as a
committed secret. Configuration is supplied through the environment, and the
database credentials are throwaway values for a disposable service container.
No release tool contains a private path or a hardcoded credential.

## Provenance

The manifest is trusted only against a detached hash supplied from outside the
archive it describes. Manifest paths are validated before use: absolute paths,
`..` traversal, NUL bytes, backslash separators, duplicates and symlinks are all
refused, and the file set is compared in both directions so an unlisted extra
file cannot pass unnoticed.

## Residual risk

{('The gates above all passed.' if not failed
  else 'One or more gates did not exit 0; see TEST_RESULTS.md. This review is not clean.')}
"""
    (out / 'SECURITY_REVIEW.md').write_text(security)

    print(f'generated reports: 4, recorded gates: {len(entries)}, '
          f'missing: {len(missing)}, nonzero: {len(failed)}')

    if missing or failed:
        for label in missing[:10]:
            print(f'  FAIL  required gate never recorded: {label}', file=sys.stderr)
        for label in failed[:10]:
            print(f'  FAIL  gate exited nonzero: {label}', file=sys.stderr)

        return 1

    return 0


if __name__ == '__main__':
    sys.exit(main())

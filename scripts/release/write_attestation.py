#!/usr/bin/env python3
"""
Write the external final attestation.

The clean verifier proves the delivered package, so its own result cannot live
inside that package without a circular dependency. This is the formally defined
second phase: a small document, outside the delivery, binding every post-seal
gate's real measured record to the master archive and the frozen tree.

THE MEASURED ATTESTATION LEDGER IS THE ONLY SOURCE OF TRUTH. An earlier
version accepted a separately supplied `--*-exit` value for each gate and
recorded it NEXT TO the ledger's measured exit code without requiring the two
to match — so a ledger full of exit-7 failures plus a CLI claiming exit 0
produced a VERIFIED attestation. Those duplicate exit arguments are gone.
Every gate's exit code, timings, argv and raw-log identity are read from the
ledger record_command.py wrote while the gate actually ran, and each raw log is
re-hashed from disk and required to match the measured hash and byte count.

The runner still passes the exit codes it captured, as `--observed-exit
LABEL=CODE` — not as data, but as a cross-check. A captured code that differs
from the ledger's measured code means a wrapper failure was about to be
mistaken for a child result, and the attestation refuses to exist rather than
describe it.

Any contradiction — exit-code disagreement, raw-log hash or size mismatch, an
unresolvable or escaping log path, a gate bound to a different tree — is a
controlled nonzero exit with no attestation written.
"""
from __future__ import annotations

import argparse
import datetime
import hashlib
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from release_gates import ATTESTATION_ONLY_LABELS, ATTESTATION_SCHEMA  # noqa: E402

# Every field the recorder measures while the gate runs. The attestation copies
# the measured record wholesale, so a gate missing any of these was not really
# recorded and cannot be attested.
MEASURED_FIELDS = (
    'label', 'argv', 'command', 'working_directory', 'started_at',
    'finished_at', 'duration_seconds', 'exit_code', 'environment',
    'child_environment', 'clean_env', 'clean_env_allow_list',
    'raw_output_file', 'raw_output_sha256', 'raw_output_bytes',
    'final_tree_manifest_sha256',
)


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def bind_artifact(path: Path) -> dict:
    return {'sha256': digest(path), 'bytes': path.stat().st_size}


def main() -> int:
    parser = argparse.ArgumentParser(description='Write the final attestation.')
    parser.add_argument('--output', required=True)
    parser.add_argument('--tree-manifest-sha256', required=True)
    parser.add_argument('--master', required=True,
                        help='the sealed FINAL-DELIVERY master archive')
    parser.add_argument('--evidence-zip',
                        help='the sealed authoritative evidence archive; bound '
                             'into the attestation when supplied')
    parser.add_argument('--attestation-ledger', required=True,
                        help='ledger written by record_command.py for the post-seal gates')
    parser.add_argument('--attestation-evidence', required=True,
                        help='evidence root the post-seal raw logs were recorded under')
    parser.add_argument('--observed-exit', action='append', default=[],
                        metavar='LABEL=CODE',
                        help='exit code the runner captured for a gate; must be '
                             'supplied once per attestation gate and must equal '
                             'the measured ledger exit code')
    args = parser.parse_args()

    problems: list[str] = []

    master = Path(args.master)

    if not master.is_file():
        raise SystemExit(f'FAIL: missing master archive {master}')

    ledger_path = Path(args.attestation_ledger)

    if not ledger_path.is_file():
        raise SystemExit(f'FAIL: no attestation ledger at {ledger_path}')

    evidence_root = Path(args.attestation_evidence).resolve()

    if not evidence_root.is_dir():
        raise SystemExit(f'FAIL: no attestation evidence directory at {evidence_root}')

    # The runner must consume the propagated child exit for every gate and hand
    # it over here. Missing, duplicate or unknown labels are refused: a gate
    # whose captured exit nobody read is a gate whose failure nobody could act on.
    observed: dict[str, int] = {}

    for raw in args.observed_exit:
        label, _, value = raw.partition('=')
        if not value or not value.lstrip('-').isdigit():
            raise SystemExit(f'FAIL: --observed-exit expects LABEL=CODE, got {raw!r}')
        if label not in ATTESTATION_ONLY_LABELS:
            raise SystemExit(f'FAIL: {label!r} is not an attestation gate')
        if label in observed:
            raise SystemExit(f'FAIL: duplicate --observed-exit for {label}')
        observed[label] = int(value)

    for label in ATTESTATION_ONLY_LABELS:
        if label not in observed:
            raise SystemExit(f'FAIL: no --observed-exit supplied for {label}; the '
                             'runner must capture and hand over every gate exit')

    ledger = json.loads(ledger_path.read_text())
    measured_by_label = {entry.get('label'): entry
                         for entry in ledger.get('entries', [])}

    gates: dict[str, dict] = {}

    for label in ATTESTATION_ONLY_LABELS:
        measured = measured_by_label.get(label)

        if measured is None:
            problems.append(f'{label}: no measured ledger entry; the attestation '
                            'cannot describe a gate that did not run')
            continue

        absent = [field for field in MEASURED_FIELDS if field not in measured]

        if absent:
            problems.append(f'{label} is missing measured field(s): ' + ', '.join(absent))
            continue

        if measured['final_tree_manifest_sha256'] != args.tree_manifest_sha256:
            problems.append(f'{label}: measured entry is bound to tree '
                            f'{str(measured["final_tree_manifest_sha256"])[:12]}, '
                            f'not {args.tree_manifest_sha256[:12]}')
            continue

        # The raw log must resolve INSIDE the attestation evidence root, exist,
        # and hash to exactly what the recorder measured as the gate finished.
        resolved = (evidence_root / measured['raw_output_file']).resolve()

        if not resolved.is_relative_to(evidence_root):
            problems.append(f'{label}: raw_output_file escapes the evidence root: '
                            f'{measured["raw_output_file"]}')
            continue

        if not resolved.is_file():
            problems.append(f'{label}: raw log does not resolve: {resolved}')
            continue

        actual_sha256 = digest(resolved)
        actual_bytes = resolved.stat().st_size

        if actual_sha256 != measured['raw_output_sha256']:
            problems.append(f'{label}: raw log hash mismatch: measured '
                            f'{measured["raw_output_sha256"][:12]}, actual '
                            f'{actual_sha256[:12]}')

        if actual_bytes != measured['raw_output_bytes']:
            problems.append(f'{label}: raw log is {actual_bytes} bytes, ledger '
                            f'measured {measured["raw_output_bytes"]}')

        if observed[label] != measured['exit_code']:
            problems.append(f'{label}: runner captured exit {observed[label]} but '
                            f'the ledger measured {measured["exit_code"]}; a '
                            'wrapper result was about to impersonate the child')

        gate = dict(measured)
        gate['observed_exit_code'] = observed[label]
        gate['resolved_log_path'] = str(resolved.relative_to(evidence_root))
        gates[label] = gate

    if problems:
        for problem in problems:
            print(f'  FAIL  {problem}', file=sys.stderr)
        print(f'ATTESTATION REFUSED: {len(problems)} contradiction(s) between the '
              'measured ledger, the raw logs and the observed results',
              file=sys.stderr)
        return 2

    # The sealed artifacts this attestation binds. Each detached .sha256 beside
    # a sealed artifact must agree with the bytes, or the delivery contradicts
    # itself before it ever leaves the machine.
    sealed_artifacts = {master.name: bind_artifact(master)}

    if args.evidence_zip:
        evidence_zip = Path(args.evidence_zip)
        if not evidence_zip.is_file():
            raise SystemExit(f'FAIL: missing evidence archive {evidence_zip}')
        sealed_artifacts[evidence_zip.name] = bind_artifact(evidence_zip)

    for name, binding in sorted(sealed_artifacts.items()):
        detached = master.parent / f'{name}.sha256'
        if detached.is_file():
            declared = detached.read_text().split()[0]
            if declared != binding['sha256']:
                print(f'  FAIL  {name}.sha256 declares {declared[:12]}, the sealed '
                      f'bytes are {binding["sha256"][:12]}', file=sys.stderr)
                return 2

    verifier = gates['final-clean-verifier']

    document = {
        'schema': ATTESTATION_SCHEMA,
        'generated_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'final_tree_manifest_sha256': args.tree_manifest_sha256,
        'master_sha256': sealed_artifacts[master.name]['sha256'],
        'master_bytes': sealed_artifacts[master.name]['bytes'],
        'sealed_artifacts': sealed_artifacts,
        'verifier_argv': verifier['argv'],
        'verifier_command': verifier['command'],
        'verifier_exit_code': verifier['exit_code'],
        'verifier_log_sha256': verifier['raw_output_sha256'],
        'verifier_log_bytes': verifier['raw_output_bytes'],
        'attestation_gates': gates,
        'verdict': (
            'VERIFIED'
            if all(gate['exit_code'] == 0 for gate in gates.values())
            else 'NOT VERIFIED'
        ),
    }

    output = Path(args.output)
    output.write_text(json.dumps(document, indent=2) + '\n')
    Path(str(output) + '.sha256').write_text(f'{digest(output)}  {output.name}\n')

    print(f'attestation: {document["verdict"]} tree={args.tree_manifest_sha256[:12]} '
          f'master={document["master_sha256"][:12]}')

    return 0 if document['verdict'] == 'VERIFIED' else 1


if __name__ == '__main__':
    sys.exit(main())

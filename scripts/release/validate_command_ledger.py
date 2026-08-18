#!/usr/bin/env python3
"""
Validate the command ledger against the delivered evidence archive.

The ledger claimed 27 complete entries. Independent inspection found otherwise:
`duration_seconds`, `environment` and `final_tree_manifest_sha256` were present
on 20 of 27, `server_configuration` on 5, and every one of the five Playwright
entries pointed at `run-<viewport>.log` while the delivered files live under
`browser/`. A reference that does not resolve is not evidence, and a schema that
varies per entry cannot be checked mechanically — which is how five broken
references survived to delivery.

This enforces one schema for every entry, resolves each `raw_output_file` INSIDE
the evidence ZIP, and checks the declared byte count and SHA-256 against what is
actually there.

Usage:
    validate_command_ledger.py --ledger FILE --evidence-zip FILE
                               [--tree-manifest-sha256 SHA] [--require-labels ...]
"""
import argparse
import hashlib
import json
import sys
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from release_gates import RELEASE_EVIDENCE_LABELS  # noqa: E402

# The clean verifier proves the package containing this ledger, so its own entry
# belongs to the external attestation, not here. Requiring it made a complete
# and correct ledger read as invalid.
DEFAULT_REQUIRED_LABELS = RELEASE_EVIDENCE_LABELS

REQUIRED_FIELDS = (
    'label', 'argv', 'command', 'working_directory', 'started_at',
    'finished_at', 'duration_seconds', 'exit_code', 'termination_signal',
    'environment', 'child_environment', 'clean_env', 'clean_env_allow_list',
    'server_configuration', 'raw_output_file', 'raw_output_sha256',
    'raw_output_bytes', 'final_tree_manifest_sha256',
)

# `server_configuration` is allowed to be null because most commands do not run
# against a server; `clean_env_allow_list` is null unless --clean-env applied
# one; `termination_signal` is null unless the child died to a signal. Each
# must still be PRESENT — a uniform schema is the whole point.
NULLABLE = ('server_configuration', 'clean_env_allow_list', 'termination_signal')

# The audit named these as commands that must appear as complete recorded
# entries. Absence is a failure, not an omission.


def main() -> int:
    parser = argparse.ArgumentParser(description='Validate the command ledger.')
    parser.add_argument('--ledger', required=True)
    parser.add_argument('--evidence-zip', required=True)
    parser.add_argument('--tree-manifest-sha256')
    parser.add_argument('--require-labels', nargs='*', default=list(DEFAULT_REQUIRED_LABELS))
    args = parser.parse_args()

    problems = []

    with open(args.ledger) as fh:
        document = json.load(fh)

    entries = document['entries'] if isinstance(document, dict) and 'entries' in document else document

    if not isinstance(entries, list) or not entries:
        print('FAIL: the ledger contains no entries', file=sys.stderr)
        return 1

    with zipfile.ZipFile(args.evidence_zip) as zf:
        members = {name: zf.getinfo(name) for name in zf.namelist()}

        # Tolerate ONE wrapping directory, but only when it is a genuine archive
        # root — every member beneath it. Accepting any subdirectory as a prefix
        # would silently repair exactly the defect this exists to catch: the
        # Playwright entries declared `run-<viewport>.log` when the files are at
        # `browser/run-<viewport>.log`, and a lenient search "resolves" that.
        names = [name for name in members if not name.endswith('/')]
        roots = {name.split('/', 1)[0] for name in names if '/' in name}
        single_root = (
            len(roots) == 1
            and all(name.startswith(f'{next(iter(roots))}/') for name in names)
        )
        prefixes = ['', f'{next(iter(roots))}/'] if single_root else ['']

        seen_labels = set()

        for index, entry in enumerate(entries):
            label = entry.get('label', f'<entry {index}>')
            seen_labels.add(entry.get('label'))

            for field in REQUIRED_FIELDS:
                if field not in entry:
                    problems.append(f'{label}: missing field {field}')
                elif entry[field] in (None, '') and field not in NULLABLE:
                    problems.append(f'{label}: field {field} is empty')

            # argv is the authoritative execution identity: a real, non-empty
            # array of strings. The joined `command` string is display-only and
            # cannot round-trip argument boundaries, so it is never enough.
            argv = entry.get('argv')

            if argv is not None and not (
                isinstance(argv, list) and argv
                and all(isinstance(item, str) for item in argv)
            ):
                problems.append(f'{label}: argv is not a non-empty array of strings')

            if 'raw_output_file' not in entry:
                continue

            declared = entry['raw_output_file']
            resolved = next(
                (f'{prefix}{declared}' for prefix in prefixes if f'{prefix}{declared}' in members),
                None,
            )

            if resolved is None:
                problems.append(
                    f'{label}: raw_output_file does not resolve inside the evidence archive: {declared}'
                )
                continue

            payload = zf.read(resolved)

            if 'raw_output_bytes' in entry and len(payload) != entry['raw_output_bytes']:
                problems.append(
                    f'{label}: {declared} is {len(payload)} bytes, ledger declares '
                    f'{entry["raw_output_bytes"]}'
                )

            digest = hashlib.sha256(payload).hexdigest()

            if 'raw_output_sha256' in entry and digest != entry['raw_output_sha256']:
                problems.append(f'{label}: {declared} hash mismatch')

            if args.tree_manifest_sha256 and entry.get('final_tree_manifest_sha256') \
                    != args.tree_manifest_sha256:
                problems.append(
                    f'{label}: bound to tree '
                    f'{str(entry.get("final_tree_manifest_sha256"))[:12]}, expected '
                    f'{args.tree_manifest_sha256[:12]}'
                )

    for label in args.require_labels:
        if label not in seen_labels:
            problems.append(f'no recorded entry for required command: {label}')

    print(f'ledger entries: {len(entries)}')
    print(f'required labels: {len(args.require_labels)}')

    if problems:
        for problem in problems:
            print(f'  FAIL  {problem}', file=sys.stderr)
        print(f'\nCOMMAND LEDGER INVALID: {len(problems)} problem(s)', file=sys.stderr)
        return 1

    print('COMMAND LEDGER VALID: uniform schema, every raw log resolves and matches')
    return 0


if __name__ == '__main__':
    sys.exit(main())

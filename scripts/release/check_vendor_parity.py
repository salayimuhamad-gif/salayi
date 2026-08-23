#!/usr/bin/env python3
"""
Prove the runtime ships the EXACT production dependency state of the frozen
lock.

The defect this exists to prevent: the release cycle installed and tested one
vendor tree, the runtime artifact shipped none, and production kept whatever
old vendor it already had — so the rehearsal proved NEW CODE + NEW DEPENDENCIES
while the real deployment produced NEW CODE + OLD DEPENDENCIES. Discovered
live: production still held league/commonmark 2.8.3 after the lock had moved
off it for a security update.

Checks, all against the runtime application directory that actually ships:

  1. composer.json is present and byte-equal to the frozen source's.
  2. composer.lock is present and byte-equal to the frozen source's.
  3. vendor/ carries Composer's own install record (installed.json) and the
     autoloader entrypoint.
  4. The install record says --no-dev: dev flag false, no dev package names.
  5. Every production package in the lock is installed at the exact locked
     version AND the exact locked source/dist reference.
  6. No package from the lock's packages-dev section is present.
  7. Explicit regressions: any --forbid name=version pair must NOT be the
     installed version (league/commonmark=2.8.3 is the founding case).

Exit nonzero on any finding; print a computed summary so a recorded gate can
be told apart from one that silently did nothing.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path


def reference(entry: dict) -> str:
    for key in ('source', 'dist'):
        value = entry.get(key)
        if isinstance(value, dict) and value.get('reference'):
            return str(value['reference'])
    return ''


def main() -> int:
    parser = argparse.ArgumentParser(description='Runtime dependency parity.')
    parser.add_argument('--lock', required=True,
                        help='the frozen source composer.lock')
    parser.add_argument('--composer', required=True,
                        help='the frozen source composer.json')
    parser.add_argument('--runtime-app', required=True,
                        help='the runtime application directory that ships')
    parser.add_argument('--forbid', action='append', default=[],
                        metavar='NAME=VERSION',
                        help='a package version that must NOT be installed')
    args = parser.parse_args()

    runtime = Path(args.runtime_app)
    problems: list[str] = []

    # 1 + 2: the manifest pair ships and is byte-identical to the source.
    for label, source_path, runtime_rel in (
        ('composer.json', Path(args.composer), 'composer.json'),
        ('composer.lock', Path(args.lock), 'composer.lock'),
    ):
        shipped = runtime / runtime_rel
        if not shipped.is_file():
            problems.append(f'runtime ships no {label}')
            continue
        if shipped.read_bytes() != source_path.read_bytes():
            problems.append(f'runtime {label} differs from the frozen source')

    # 3: the vendor tree and Composer's own record of what it installed.
    installed_path = runtime / 'vendor' / 'composer' / 'installed.json'
    autoload = runtime / 'vendor' / 'autoload.php'
    if not autoload.is_file():
        problems.append('runtime vendor carries no autoload.php')
    if not installed_path.is_file():
        problems.append('runtime vendor carries no composer/installed.json')
        for problem in problems:
            print(f'  FAIL  {problem}', file=sys.stderr)
        print(f'dependency parity: problems={len(problems)}')
        return 1

    with open(installed_path) as fh:
        installed_doc = json.load(fh)
    installed_list = (installed_doc['packages']
                      if isinstance(installed_doc, dict) else installed_doc)
    installed = {p['name']: p for p in installed_list}

    # 4: a production install, by Composer's own testimony.
    if isinstance(installed_doc, dict):
        if installed_doc.get('dev') is not False:
            problems.append('installed.json does not record a --no-dev install')
        if installed_doc.get('dev-package-names'):
            problems.append('installed.json lists dev package names: '
                            + ', '.join(installed_doc['dev-package-names'][:5]))

    with open(args.lock) as fh:
        lock = json.load(fh)

    # 5: every locked production package, exact version and reference.
    matched = 0
    for pkg in lock['packages']:
        name, want_version = pkg['name'], pkg['version']
        got = installed.get(name)
        if got is None:
            problems.append(f'locked package missing from runtime vendor: '
                            f'{name} {want_version}')
            continue
        if got.get('version') != want_version:
            problems.append(f'version drift: {name} locked {want_version}, '
                            f'installed {got.get("version")}')
            continue
        want_ref, got_ref = reference(pkg), reference(got)
        if want_ref and got_ref and want_ref != got_ref:
            problems.append(f'reference drift: {name} locked {want_ref[:12]}, '
                            f'installed {got_ref[:12]}')
            continue
        matched += 1

    # 6: nothing from packages-dev leaked into the shipped tree.
    dev_names = {p['name'] for p in lock.get('packages-dev', [])}
    leaked = sorted(dev_names & set(installed))
    for name in leaked:
        problems.append(f'dev-only package shipped in the runtime vendor: {name}')

    # 7: the explicit regression pins.
    for pin in args.forbid:
        name, _, version = pin.partition('=')
        got = installed.get(name)
        if got is None:
            problems.append(f'forbidden-pin target not installed at all: {name}')
        elif got.get('version') == version:
            problems.append(f'FORBIDDEN version shipped: {name} {version}')

    for problem in problems:
        print(f'  FAIL  {problem}', file=sys.stderr)

    print(f'dependency parity: locked={len(lock["packages"])} '
          f'matched={matched} installed={len(installed)} '
          f'dev-leaks={len(leaked)} problems={len(problems)}')
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())

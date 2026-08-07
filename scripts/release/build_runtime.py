#!/usr/bin/env python3
"""
Build the corrected runtime in the real Hostinger layout.

    application/        the app, minus development-only material
    public_html/build/  the built frontend assets
    DELETE_FILES.txt    deletions an overlay cannot apply
    RUNTIME_MANIFEST.txt

Frontend SOURCE is excluded: it is already compiled into public_html/build, and
the archive audit forbids shipping it.
"""
from __future__ import annotations

import argparse
import hashlib
import shutil
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from release_gates import runtime_excluded as excluded  # noqa: E402


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser(description='Build the corrected runtime.')
    parser.add_argument('--stage', required=True)
    parser.add_argument('--source', required=True)
    parser.add_argument('--output', required=True, help='basename, without .zip')
    parser.add_argument('--runtime-dir', required=True)
    args = parser.parse_args()

    stage, source = Path(args.stage), Path(args.source)
    runtime = Path(args.runtime_dir)
    shutil.rmtree(runtime, ignore_errors=True)
    (runtime / 'application').mkdir(parents=True)
    (runtime / 'public_html' / 'build').mkdir(parents=True)

    copied = 0
    for path in sorted(p for p in stage.rglob('*') if p.is_file()):
        rel = str(path.relative_to(stage))
        if excluded(rel):
            continue
        target = runtime / 'application' / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, target)
        copied += 1

    build = source / 'public' / 'build'
    assets = 0
    if build.is_dir():
        for path in sorted(p for p in build.rglob('*') if p.is_file()):
            target = runtime / 'public_html' / 'build' / path.relative_to(build)
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, target)
            assets += 1

    deletions = source / 'DELETE_FILES.txt'
    if deletions.is_file():
        shutil.copy2(deletions, runtime / 'DELETE_FILES.txt')

    lines = ''
    for path in sorted(p for p in runtime.rglob('*') if p.is_file()):
        rel = str(path.relative_to(runtime))
        if rel == 'RUNTIME_MANIFEST.txt':
            continue
        lines += f'{digest(path)}  {rel}\n'
    (runtime / 'RUNTIME_MANIFEST.txt').write_text(lines)

    for forbidden in ('resources/js', 'tests', '.github', 'scripts/release'):
        if (runtime / 'application' / forbidden).exists():
            raise SystemExit(f'FAIL: the runtime contains development-only material: {forbidden}')

    if copied == 0:
        raise SystemExit('FAIL: the runtime contains no application files')

    output = Path(args.output)
    archive = output.with_suffix('.zip')
    archive.unlink(missing_ok=True)
    subprocess.run(['zip', '-qrX', str(archive), 'application', 'public_html',
                    'DELETE_FILES.txt', 'RUNTIME_MANIFEST.txt'],
                   cwd=str(runtime), check=True)

    # Written from the containing directory so the file contains hash AND name.
    subprocess.run(f'sha256sum {archive.name} > {archive.name}.sha256',
                   shell=True, cwd=str(archive.parent), check=True)
    subprocess.run(f'sha256sum -c {archive.name}.sha256', shell=True,
                   cwd=str(archive.parent), check=True, stdout=subprocess.DEVNULL)

    print(f'runtime: application={copied} assets={assets} archive={archive.name}')
    return 0


if __name__ == '__main__':
    sys.exit(main())

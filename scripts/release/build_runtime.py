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
import datetime
import hashlib
import os
import shutil
import stat
import subprocess
import sys
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from release_gates import runtime_excluded as excluded  # noqa: E402


def zip_date_time(path: Path | None = None):
    """
    SOURCE_DATE_EPOCH when the environment provides it (the release cycle
    derives it from the verified input archive), else the file's own mtime.
    The previous `zip -qrX` subprocess stamped each entry with its on-disk
    mtime — and every runtime file is copied fresh during the run, so two
    runs from identical inputs produced 890 metadata-differing entries.
    """
    epoch = os.environ.get('SOURCE_DATE_EPOCH')
    if epoch and epoch.isdigit():
        moment = datetime.datetime.fromtimestamp(int(epoch), datetime.timezone.utc)
    elif path is not None:
        moment = datetime.datetime.fromtimestamp(path.stat().st_mtime, datetime.timezone.utc)
    else:
        moment = datetime.datetime(1980, 1, 1, tzinfo=datetime.timezone.utc)
    if moment.year < 1980:
        moment = datetime.datetime(1980, 1, 1, tzinfo=datetime.timezone.utc)
    return (moment.year, moment.month, moment.day,
            moment.hour, moment.minute, moment.second)


def zip_tree(archive: Path, root: Path, members: list[str]) -> None:
    """
    Deterministic replacement for `zip -qrX <archive> <members...>`:
    sorted traversal (readdir order is filesystem accident), directory
    entries kept (the historical archives carried them), pinned timestamps,
    permissions from disk, no extra fields (zipfile writes none — the -X
    the subprocess needed).
    """
    entries: list[tuple[str, Path, bool]] = []
    for member in members:
        path = root / member
        if path.is_file():
            entries.append((member, path, False))
            continue
        if not path.is_dir():
            raise SystemExit(f'FAIL: archive member missing: {member}')
        entries.append((member + '/', path, True))
        for sub in sorted(path.rglob('*')):
            rel = sub.relative_to(root).as_posix()
            entries.append((rel + '/', sub, True) if sub.is_dir() else (rel, sub, False))
    entries.sort(key=lambda e: e[0])

    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as zf:
        for arcname, path, is_dir in entries:
            info = zipfile.ZipInfo(arcname, date_time=zip_date_time(path))
            mode = stat.S_IMODE(path.stat().st_mode)
            if is_dir:
                info.external_attr = ((mode | stat.S_IFDIR) << 16) | 0x10
                zf.writestr(info, b'')
            else:
                info.external_attr = (mode | stat.S_IFREG) << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                zf.writestr(info, path.read_bytes())


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
    parser.add_argument('--vendor', default=None,
                        help='a PRODUCTION (--no-dev) Composer vendor tree to '
                             'ship inside application/. The staged eligible '
                             'set never carries vendor, and production must '
                             'not install packages over the network during a '
                             'maintenance window, so the tested dependency '
                             'bytes travel inside the runtime artifact.')
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

    vendor_files = 0
    if args.vendor:
        vendor_src = Path(args.vendor)
        if not (vendor_src / 'autoload.php').is_file():
            raise SystemExit('FAIL: --vendor does not point at a Composer '
                             f'vendor tree (no autoload.php): {vendor_src}')
        # symlinks dereferenced: the archive audit refuses symlink entries,
        # and Hostinger receives plain files either way.
        shutil.copytree(vendor_src, runtime / 'application' / 'vendor',
                        symlinks=False)
        vendor_files = sum(1 for p in (runtime / 'application' / 'vendor')
                           .rglob('*') if p.is_file())
        for required in ('vendor/autoload.php', 'vendor/composer/installed.json',
                         'composer.json', 'composer.lock'):
            if not (runtime / 'application' / required).is_file():
                raise SystemExit('FAIL: the dependency-bearing runtime is '
                                 f'missing {required}')

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
    zip_tree(archive, runtime,
             ['application', 'public_html', 'DELETE_FILES.txt', 'RUNTIME_MANIFEST.txt'])

    # Written from the containing directory so the file contains hash AND name.
    subprocess.run(f'sha256sum {archive.name} > {archive.name}.sha256',
                   shell=True, cwd=str(archive.parent), check=True)
    subprocess.run(f'sha256sum -c {archive.name}.sha256', shell=True,
                   cwd=str(archive.parent), check=True, stdout=subprocess.DEVNULL)

    print(f'runtime: application={copied} assets={assets} '
          f'vendor={vendor_files} archive={archive.name}')
    return 0


if __name__ == '__main__':
    sys.exit(main())

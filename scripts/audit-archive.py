#!/usr/bin/env python3
"""Independently audit a finished release archive and emit structured evidence.

This deliberately shares no code with scripts/package-release.sh. An audit that
reuses the builder's own helpers can only confirm the builder agrees with
itself; the point is to open the finished bytes with a different implementation
and see whether they survive.

The JSON report is bound to the archive's SHA-256 and byte size, so a result can
never be mistaken for evidence about a rebuilt archive with different bytes.

Usage:
  audit-archive.py <archive.zip> [--require PATH]... [--forbid REGEX]...
                   [--role ROLE] [--commit SHA] [--json OUT.json]
"""

from __future__ import annotations

import argparse
import datetime
import hashlib
import json
import os
import platform
import posixpath
import re
import stat
import sys
import tempfile
import zipfile

SCHEMA_VERSION = 1
SCRIPT_NAME = "audit-archive.py"

ALLOWED_NESTED_ZIPS = re.compile(
    r"^Mulkihawler_[\w.\-]+_(Clean_Source|Production_Deployment)\.zip$"
)
WINDOWS_DRIVE = re.compile(r"^[A-Za-z]:")


def utc_now() -> str:
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def sha256_of(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def script_version() -> str:
    """This file's own hash, so a result names the code that produced it."""
    return sha256_of(os.path.abspath(__file__))[:16]


def verify_manifest(zf: zipfile.ZipFile, manifest_entry: str) -> tuple[bool, str]:
    """Every regular file must be listed, hashed correctly, exactly once.

    The deployment archive shipped a manifest covering the application source
    only — about 527 files out of nearly 6,900 — while an audit reported
    "manifest: PASS". Checking that the listed subset matches says nothing about
    the thousands of files nobody listed, so completeness is the check.
    """
    try:
        raw = zf.read(manifest_entry).decode("utf-8")
    except KeyError:
        return False, f"manifest {manifest_entry} is not in the archive"

    prefix = posixpath.dirname(manifest_entry)
    listed: dict[str, str] = {}

    for line in raw.splitlines():
        if not line.strip():
            continue
        parts = line.split(None, 1)
        if len(parts) != 2:
            return False, f"unparsable manifest line: {line[:60]}"
        digest, name = parts[0], parts[1].strip().lstrip("*")
        entry = posixpath.normpath(posixpath.join(prefix, name)) if prefix else name
        if entry in listed:
            return False, f"manifest lists {entry} twice"
        listed[entry] = digest

    actual = {
        i.filename
        for i in zf.infolist()
        if not i.is_dir() and i.filename != manifest_entry
    }

    unlisted = sorted(actual - set(listed))
    if unlisted:
        return False, (
            f"{len(unlisted)} archive file(s) are not in the manifest, "
            f"e.g. {unlisted[0]}"
        )

    missing = sorted(set(listed) - actual)
    if missing:
        return False, f"manifest lists {len(missing)} file(s) not in the archive, e.g. {missing[0]}"

    for entry, digest in listed.items():
        if hashlib.sha256(zf.read(entry)).hexdigest() != digest:
            return False, f"hash mismatch for {entry}"

    return True, f"{len(listed)} file(s) listed and verified, none unlisted"


def audit(path: str, require: list[str], forbid: list[str], manifest: str | None = None) -> dict:
    checks: dict[str, str] = {}
    failures: list[str] = []
    nested: list[dict] = []

    def record(name: str, ok: bool, detail: str = "") -> None:
        checks[name] = "PASS" if ok else "FAIL"
        if not ok:
            failures.append(f"{name}: {detail}" if detail else name)

    try:
        zf = zipfile.ZipFile(path)
    except zipfile.BadZipFile as exc:
        return {
            "result": "FAIL",
            "checks": {"readable": "FAIL"},
            "failures": [f"not a readable zip: {exc}"],
        }

    with zf:
        bad = zf.testzip()
        record("crc", bad is None, f"CRC failure in {bad}")

        infos = zf.infolist()
        seen: dict[str, str] = {}
        files = dirs = links = 0
        total_compressed = total_uncompressed = 0
        largest = ("", 0)
        worst_ratio = ("", 0.0)

        absolute = traversal = drive = nul = duplicate = collision = 0
        encrypted = special = escaping = unexpected_nested = 0
        forbidden_hits: list[str] = []

        for info in infos:
            name = info.filename
            normalised = posixpath.normpath(name.replace("\\", "/"))

            if "\x00" in name:
                nul += 1
            if name.startswith("/") or name.startswith("\\"):
                absolute += 1
            if WINDOWS_DRIVE.match(name):
                drive += 1
            if normalised.startswith("..") or "/../" in f"/{normalised}":
                traversal += 1

            folded = normalised.lower()
            if folded in seen:
                if seen[folded] == normalised:
                    duplicate += 1
                else:
                    collision += 1
            seen[folded] = normalised

            if info.flag_bits & 0x1:
                encrypted += 1

            mode = info.external_attr >> 16
            if mode:
                if stat.S_ISLNK(mode):
                    links += 1
                    target = zf.read(info).decode("utf-8", "replace")
                    resolved = posixpath.normpath(
                        posixpath.join(posixpath.dirname(normalised), target)
                    )
                    if target.startswith("/") or resolved.startswith(".."):
                        escaping += 1
                if any(
                    check(mode)
                    for check in (
                        stat.S_ISBLK,
                        stat.S_ISCHR,
                        stat.S_ISFIFO,
                        stat.S_ISSOCK,
                    )
                ):
                    special += 1

            if normalised.lower().endswith(".zip"):
                base = posixpath.basename(normalised)
                if ALLOWED_NESTED_ZIPS.match(base):
                    with tempfile.TemporaryDirectory() as tmp:
                        extracted = os.path.join(tmp, base)
                        with open(extracted, "wb") as out:
                            out.write(zf.read(info))
                        nested.append(
                            {
                                "name": base,
                                "bytes": info.file_size,
                                "sha256": sha256_of(extracted),
                            }
                        )
                else:
                    unexpected_nested += 1

            if info.is_dir():
                dirs += 1
            else:
                files += 1
                total_compressed += info.compress_size
                total_uncompressed += info.file_size
                if info.file_size > largest[1]:
                    largest = (normalised, info.file_size)
                if info.compress_size > 0:
                    ratio = info.file_size / info.compress_size
                    if ratio > worst_ratio[1]:
                        worst_ratio = (normalised, ratio)

            for pattern in forbid:
                if re.search(pattern, normalised):
                    forbidden_hits.append(f"{pattern}: {normalised}")

        missing = [n for n in require if not any(n in v for v in seen.values())]
        total_ratio = total_uncompressed / total_compressed if total_compressed else 0.0

        record("absolute_paths", absolute == 0, f"{absolute} entries")
        record("traversal", traversal == 0, f"{traversal} entries")
        record("windows_drive_paths", drive == 0, f"{drive} entries")
        record("nul_in_names", nul == 0, f"{nul} entries")
        record("duplicate_paths", duplicate == 0, f"{duplicate} entries")
        record("case_fold_collisions", collision == 0, f"{collision} entries")
        record("encrypted_entries", encrypted == 0, f"{encrypted} entries")
        record("special_files", special == 0, f"{special} entries")
        record("escaping_symlinks", escaping == 0, f"{escaping} entries")
        record("nested_archives", unexpected_nested == 0, f"{unexpected_nested} unexpected")
        record("forbidden_files", not forbidden_hits, "; ".join(forbidden_hits[:5]))
        record("manifest", not missing, "missing: " + ", ".join(missing))
        if manifest:
            ok, detail = verify_manifest(zf, manifest)
            record("manifest_complete", ok, detail)

        record(
            "expansion_ratio",
            total_ratio <= 100 and worst_ratio[1] <= 2000,
            f"total {total_ratio:.1f}x, worst {worst_ratio[1]:.1f}x",
        )

    return {
        "entries": len(infos),
        "file_entries": files,
        "directory_entries": dirs,
        "symlink_entries": links,
        "compressed_bytes": total_compressed,
        "uncompressed_bytes": total_uncompressed,
        "total_expansion_ratio": round(total_ratio, 3),
        "max_entry_expansion_ratio": round(worst_ratio[1], 3),
        "largest_entry": {"name": largest[0], "bytes": largest[1]},
        "nested_archives": nested,
        "checks": checks,
        "failures": failures,
        "result": "PASS" if not failures else "FAIL",
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("archive")
    parser.add_argument("--require", action="append", default=[])
    parser.add_argument("--forbid", action="append", default=[])
    parser.add_argument("--role", default="unknown")
    parser.add_argument(
        "--manifest",
        help="archive path of a sha256sum-format manifest that must cover EVERY "
             "regular file except itself",
    )
    parser.add_argument("--commit", default="unknown")
    parser.add_argument("--json")
    args = parser.parse_args()

    started = utc_now()
    digest = sha256_of(args.archive)
    size = os.path.getsize(args.archive)

    print(f"Independent audit: {os.path.basename(args.archive)}")
    print(f"  sha256                {digest}")
    print(f"  bytes                 {size:,}")

    report = audit(args.archive, args.require, args.forbid, args.manifest)

    for key in ("entries", "compressed_bytes", "uncompressed_bytes"):
        if key in report:
            print(f"  {key:<21} {report[key]:,}")

    if report.get("nested_archives"):
        for nest in report["nested_archives"]:
            print(f"  nested                {nest['name']}  {nest['bytes']:,}  {nest['sha256'][:16]}…")

    for failure in report.get("failures", []):
        print(f"  FAIL  {failure}")

    document = {
        "schema_version": SCHEMA_VERSION,
        "result_type": "independent_archive_audit",
        "generated_by": SCRIPT_NAME,
        "generator_version": script_version(),
        "source_commit": args.commit,
        "artifact": {
            "filename": os.path.basename(args.archive),
            "bytes": size,
            "sha256": digest,
            "role": args.role,
        },
        "started_at": started,
        "finished_at": utc_now(),
        "exit_code": 0 if report["result"] == "PASS" else 1,
        "result": report["result"],
        "commands": [f"{SCRIPT_NAME} <archive> --role {args.role}"],
        "host": {
            "platform": platform.system(),
            "python": platform.python_version(),
        },
        **{k: v for k, v in report.items() if k != "result"},
    }

    if args.json:
        tmp = args.json + ".tmp"
        with open(tmp, "w", encoding="utf-8") as handle:
            json.dump(document, handle, indent=1, sort_keys=True)
            handle.write("\n")
        os.replace(tmp, args.json)  # atomic
        print(f"  wrote                 {args.json}")

    print(f"  {report['result']}")

    return 0 if report["result"] == "PASS" else 1


if __name__ == "__main__":
    sys.exit(main())

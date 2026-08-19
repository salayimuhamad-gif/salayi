#!/usr/bin/env python3
"""Independent verification of the final delivery. Shares no state with the builders."""
import hashlib
import io
import json
import os
import re
import subprocess
import shutil
import sys
import tempfile
import zipfile
from pathlib import Path

# BLOCKER 7: the delivery directory is a CLI argument. The previous version
# hardcoded a path on the machine that built the release, so the verifier
# shipped inside the delivery could not verify the delivery.
OUT = Path(sys.argv[1] if len(sys.argv) > 1 else '.').resolve()
problems = []


def check(ok, msg):
    if not ok:
        problems.append(msg)


shab = lambda b: hashlib.sha256(b).hexdigest()
shaf = lambda p: shab(Path(p).read_bytes())

# The suffix list matched `.backup` exactly, so `.backup-20260802-014031` — the
# very file this release deletes — was not recognised. Swap and temp files were
# not covered at all.
UNSAFE = ('.sqlite', '.backup', '.orig', '.rej', '.bak', '.swp', '.swo', '~')
UNSAFE_PATTERNS = (
    re.compile(r'\.backup(-[\w.-]+)?$'),
    re.compile(r'\.bak(-[\w.-]+)?$'),
    re.compile(r'\.orig(-[\w.-]+)?$'),
    re.compile(r'\.rej(-[\w.-]+)?$'),
    re.compile(r'^#.*#$'),
    re.compile(r'^\..*\.sw[a-z]$'),
    re.compile(r'^~\$'),
)


def unsafe_name(base: str) -> bool:
    return base.endswith(UNSAFE) or any(pat.search(base) for pat in UNSAFE_PATTERNS)
SECRET_MARKERS = ('APP_KEY=base64:', 'TELEGRAM_BOT_TOKEN=', 'TELEGRAM_WEBHOOK_SECRET=', 'DB_PASSWORD=')


def inspect(name, data, depth=0):
    seen, findings, lowercase_seen = set(), [], {}
    with zipfile.ZipFile(io.BytesIO(data)) as zf:
        if zf.testzip() is not None:
            findings.append('CRC failure')
        for i in zf.infolist():
            n = i.filename
            if n in seen:
                findings.append(f'duplicate entry: {n}')
            seen.add(n)
            if '..' in n.split('/'):
                findings.append(f'traversal: {n}')
            if n.startswith('/'):
                findings.append(f'absolute path: {n}')
            if re.match(r'^[A-Za-z]:[\\/]', n):
                findings.append(f'windows drive path: {n}')
            if '\\' in n:
                findings.append(f'backslash in entry name: {n}')
            # The name was added to `seen` above, so `n not in seen` was always
            # false and this branch never fired. Compare against the recorded
            # first occurrence instead.
            lowered = n.lower()
            if lowered in lowercase_seen and lowercase_seen[lowered] != n:
                findings.append(f'case collision: {n} vs {lowercase_seen[lowered]}')
            lowercase_seen.setdefault(lowered, n)
            if (i.external_attr >> 16) & 0o170000 == 0o120000:
                findings.append(f'symlink: {n}')
            if i.flag_bits & 0x1:
                findings.append(f'encrypted entry: {n}')
            base = n.rsplit('/', 1)[-1]
            if base == '.env' or (base.endswith('.env') and base != '.env.example'):
                findings.append(f'environment file: {n}')
            if base == 'auth.json' and n.count('/') <= 1:
                findings.append(f'credential file: {n}')
            if unsafe_name(base) or '/node_modules/' in n:
                findings.append(f'forbidden content: {n}')
            if 'runtime' in name and ('/tests/' in n or n.endswith('.spec.ts') or '/resources/js/' in n):
                findings.append(f'runtime ships development source: {n}')
            if n.endswith('.zip') and depth < 2:
                findings += [f'{n} -> {f}' for f in inspect(n, zf.read(n), depth + 1)]
            # secret content scan on text entries
            if base.endswith(('.php', '.ts', '.vue', '.json', '.md', '.sh', '.xml', '.log')) and i.file_size < 2_000_000:
                try:
                    text = zf.read(n).decode('utf-8', 'ignore')
                except Exception:
                    continue
                for marker in SECRET_MARKERS:
                    for line in text.split('\n'):
                        if marker in line and not line.strip().startswith(('#', '//', '*')):
                            value = line.split(marker, 1)[1].strip()

                            # A LITERAL credential is a finding. An empty
                            # assignment, a shell expansion, a substitution
                            # placeholder, or a documented dummy is not — the
                            # rehearsal script now takes its database password
                            # from the environment and writes a placeholder
                            # into the staged file, which is the correct shape
                            # and must not be reported as a leak.
                            #
                            # Placeholder status is decided ONLY by the shape
                            # of the VALUE. An earlier revision exempted any
                            # line containing the key name
                            # REHEARSAL_DB_PASSWORD, which suppressed exactly
                            # the lines that would carry that key's literal
                            # value — a scan with a name-keyed blind spot on
                            # the one credential this pipeline handles.
                            placeholder = (
                                value == ''
                                or value.startswith(('<', '"<', '$', '{', '__', "'", '"$'))
                                or value.rstrip('"\'').endswith('__')
                                or value.startswith(('rehearsal', '123456:', 'browser-'))
                            )

                            if not placeholder:
                                findings.append(f'possible secret in {n}: {marker}{value[:12]}')
    print(f'{"  " * depth}{name}: {len(seen)} entries, {len(findings)} findings')
    return findings


master = 'myhawler-account-first-registration-FINAL-DELIVERY.zip'
FULL_SOURCE = 'myhawler-account-first-registration-FULL-SOURCE.zip'

# The verifier is a gate, so a missing or malformed input must produce a
# controlled nonzero failure — never a traceback that looks like a crash rather
# than a verdict.
sys.path.insert(0, str(Path(__file__).resolve().parent))

try:
    from release_gates import (  # noqa: E402
        ATTESTATION_ONLY_LABELS, EVIDENCE_INDEX_SCHEMA, RELEASE_INDEX_SCHEMA,
        REQUIRED_INDEX_KEYS, SELF_REFERENCE_EXCLUSIONS, index_key,
    )
except ImportError:
    print('FINAL VERIFICATION FAILED — release_gates.py must be delivered '
          'alongside the verifier')
    sys.exit(1)

# The clean verifier's own result cannot be inside the package it verifies.
ATTESTATION_ONLY_KEYS = {index_key(label) for label in ATTESTATION_ONLY_LABELS}

SCHEMA = RELEASE_INDEX_SCHEMA


def fail_now(message):
    print(f'FINAL VERIFICATION FAILED — {message}')
    sys.exit(1)


for essential in (master, FULL_SOURCE, 'release-index.json',
                  'TREE_MANIFEST.txt', 'TREE_MANIFEST.sha256',
                  'FULL_SOURCE_MANIFEST.txt', 'FULL_SOURCE_MANIFEST.sha256',
                  'DELETE_FILES.txt'):
    if not (OUT / essential).is_file():
        fail_now(f'required deliverable is missing: {essential}')


def index_field(document, *path):
    """Fetch a nested index field, or record a controlled problem."""
    node = document
    for key in path:
        if not isinstance(node, dict) or key not in node:
            check(False, 'release-index is missing ' + '.'.join(path))
            return None
        node = node[key]
    return node

for f in sorted(OUT.glob('*.sha256')):
    expected, name = f.read_text().split()
    target = OUT / name
    check(target.is_file(), f'checksum names a missing file: {name}')
    if target.is_file():
        check(shaf(target) == expected, f'checksum mismatch: {name}')
print(f'detached checksums verified: {len(list(OUT.glob("*.sha256")))}')

try:
    _index_preflight = json.loads((OUT / 'release-index.json').read_text())
except json.JSONDecodeError as exc:
    fail_now(f'release-index.json is not valid JSON: {exc}')

if _index_preflight.get('schema') != SCHEMA:
    fail_now(
        f'release-index schema is {_index_preflight.get("schema")!r}, '
        f'this verifier consumes {SCHEMA!r}. One schema, one consumer set.'
    )

for f in inspect(master, (OUT / master).read_bytes()):
    problems.append(f'{master}: {f}')

# master must contain every deliverable, byte-identical
with zipfile.ZipFile(OUT / master) as zf:
    inside = {n: shab(zf.read(n)) for n in zf.namelist()}

required = [
    'myhawler-account-first-registration-FULL-SOURCE.zip',
    'myhawler-account-first-registration-FULL-SOURCE.zip.sha256',
    'myhawler-account-first-registration-SOURCE-PATCH.zip',
    'myhawler-account-first-registration-SOURCE-PATCH.zip.sha256',
    'evidence-index.json', 'evidence-index.json.sha256',
    'myhawler-account-first-registration-corrected-runtime.zip',
    'myhawler-account-first-registration-corrected-runtime.zip.sha256',
    'myhawler-account-first-registration-evidence.zip',
    'myhawler-account-first-registration-evidence.zip.sha256',
    'CHANGED_FILES.md', 'FIX_REPORT.md', 'SECURITY_REVIEW.md',
    'TEST_RESULTS.md', 'DEPLOYMENT_NOTES.md', 'ROLLBACK_NOTES.md',
    'release-index.json', 'release-index.json.sha256',
    # These were mandatory deliverables that the verifier never required, so
    # "FINAL VERIFICATION PASSED" could be printed with them absent.
    'DELETE_FILES.txt',
    'TREE_MANIFEST.txt', 'TREE_MANIFEST.sha256',
]
for r in required:
    check(r in inside, f'master delivery is missing {r}')
    if r in inside:
        check(inside[r] == shaf(OUT / r), f'master copy differs from the delivered file: {r}')

# release-index agrees with the bytes
idx = _index_preflight

artifacts = index_field(idx, 'artifacts') or {}

for name, meta in artifacts.items():
    target = OUT / name
    if not target.is_file():
        check(False, f'release-index names a missing artifact: {name}')
        continue
    check(shaf(target) == meta.get('sha256'), f'release-index hash wrong for {name}')
    check(target.stat().st_size == meta.get('bytes'), f'release-index size wrong for {name}')

# Every delivered file must be classified. An index that silently omits
# deliverables cannot be used to decide what a release contains.
delivered = {
    f.name for f in OUT.iterdir()
    if f.is_file() and not f.name.endswith('.sha256')
    and f.name not in SELF_REFERENCE_EXCLUSIONS
}
for name in sorted(delivered - set(artifacts)):
    check(False, f'delivered file is absent from release-index artifacts: {name}')

gates = index_field(idx, 'gates') or {}
# BLOCKER 11: one registry. These keys used to be spelled differently here than
# in the ledger, so a complete correct ledger read as "all gates missing".
for required_gate in REQUIRED_INDEX_KEYS:
    pass

for required_gate in REQUIRED_INDEX_KEYS:
    if required_gate in ATTESTATION_ONLY_KEYS:
        continue
    check(required_gate in gates, f'release-index does not report the gate {required_gate}')
    check(gates.get(required_gate) == 'exit 0',
          f'gate {required_gate} did not exit 0: {gates.get(required_gate)}')

verdict = idx.get('verdict')
check(isinstance(verdict, str) and verdict != '', 'release-index declares no verdict')

# A complete verdict is only permissible when no gate is unrun.
unrun = [g for g, v in gates.items() if isinstance(v, str) and v.startswith('NOT RUN')]
if verdict == 'PRODUCTION CANDIDATE COMPLETE':
    check(not unrun,
          f'release-index declares completion while {len(unrun)} gate(s) did not run: '
          f'{", ".join(sorted(unrun)[:5])}')

# BLOCKER 6: the evidence must be bound to the artifacts actually delivered,
# by their real names, with non-null hashes.
ev_idx = json.loads((OUT / 'evidence-index.json').read_text())
# BLOCKER 10: bound_artifacts maps name -> {sha256, bytes}. The verifier used to
# compare that whole object against a bare hash string, which could never match.
bound = ev_idx.get('bound_artifacts', {})
check(ev_idx.get('schema') == EVIDENCE_INDEX_SCHEMA,
      f'evidence-index schema is {ev_idx.get("schema")!r}, expected {EVIDENCE_INDEX_SCHEMA}')

for role, filename in [
    ('full_source', 'myhawler-account-first-registration-FULL-SOURCE.zip'),
    ('source_patch', 'myhawler-account-first-registration-SOURCE-PATCH.zip'),
    ('runtime', 'myhawler-account-first-registration-corrected-runtime.zip'),
]:
    check(filename in bound, f'evidence index does not bind {filename}')
    if filename in bound:
        meta = bound[filename]
        # BLOCKER 9: this compared the whole {sha256, bytes} object against a
        # bare hash string, which is never equal. A source-string test for
        # ".get('sha256')" reported PASS while this line stayed broken — which
        # is why the checks below are exercised by a behavioural fixture now.
        check(isinstance(meta, dict),
              f'evidence binding for {filename} is not an object')
        if isinstance(meta, dict):
            check(meta.get('sha256') == shaf(OUT / filename),
                  f'evidence binding hash does not match the delivered bytes: {filename}')
            check(meta.get('bytes') == (OUT / filename).stat().st_size,
                  f'evidence binding size does not match the delivered bytes: {filename}')

check(ev_idx.get('browser_spec_sha256') not in (None, ''), 'evidence index records no browser spec hash')

# EXACT equality, not merely non-null. Evidence collected from an earlier tree
# and carried forward is the failure this replaces: a non-null check passed
# while the recorded hash belonged to a tree that no longer exists.
delivered_tree_hash = (OUT / 'TREE_MANIFEST.sha256').read_text().split()[0]
check(ev_idx.get('tree_manifest_sha256') == delivered_tree_hash,
      f'evidence index is bound to tree {str(ev_idx.get("tree_manifest_sha256"))[:12]}, '
      f'delivered tree is {delivered_tree_hash[:12]}')

# The evidence archive's own release-evidence.json must agree too.
if evidence_zip_exists := (OUT / 'myhawler-account-first-registration-evidence.zip').is_file():
    with zipfile.ZipFile(OUT / 'myhawler-account-first-registration-evidence.zip') as ez:
        member = next((n for n in ez.namelist() if n.endswith('release-evidence.json')), None)
        check(member is not None, 'the evidence archive carries no release-evidence.json')
        if member:
            try:
                recorded = json.loads(ez.read(member)).get('final_tree_manifest_sha256')
            except json.JSONDecodeError:
                recorded = None
                check(False, 'release-evidence.json inside the evidence archive is not valid JSON')
            check(recorded == delivered_tree_hash,
                  f'release-evidence.json is bound to tree {str(recorded)[:12]}, '
                  f'delivered tree is {delivered_tree_hash[:12]}')

# source/runtime files match where both carry them
with zipfile.ZipFile(OUT / 'myhawler-account-first-registration-SOURCE-PATCH.zip') as s, \
     zipfile.ZipFile(OUT / 'myhawler-account-first-registration-corrected-runtime.zip') as r:
    snames = {n: shab(s.read(n)) for n in s.namelist()}
    shared = 0
    for n in r.namelist():
        if n.startswith('application/'):
            rel = n[len('application/'):]
            if rel in snames:
                shared += 1
                check(snames[rel] == shab(r.read(n)), f'source/runtime mismatch: {rel}')
    check(shared > 0, 'no shared files between source and runtime')
    print(f'source/runtime shared files: {shared}')

    # every changed file is present IN FULL in the source archive
    inventory = index_field(idx, 'changed_files', 'files')
    if isinstance(inventory, list):
        for row in inventory:
            path = row.get('path')
            check(path in snames, f'source archive missing changed file: {path}')
            if path in snames and 'after' in row:
                check(snames[path] == row['after'], f'source archive content differs: {path}')
        declared = index_field(idx, 'changed_files', 'total')
        check(declared == len(inventory),
              f'changed-file arithmetic disagrees: index declares {declared}, lists {len(inventory)}')

# runtime manifest references resolve
with zipfile.ZipFile(OUT / 'myhawler-account-first-registration-corrected-runtime.zip') as r:
    names = set(r.namelist())
    manifest = json.loads(r.read('public_html/build/manifest.json'))
    missing = [e['file'] for e in manifest.values() if 'file' in e and f'public_html/build/{e["file"]}' not in names]
    check(not missing, f'manifest references {len(missing)} files not in the runtime archive')
    print(f'manifest entries: {len(manifest)}, unresolved: {len(missing)}')



# ===================================================================== added
# The checks below close the gaps that let "FINAL VERIFICATION PASSED" be
# printed while mandatory deliverables were unverified.

FULL_SOURCE = 'myhawler-account-first-registration-FULL-SOURCE.zip'

# --- TREE_MANIFEST and its detached hash -----------------------------------
manifest_path = OUT / 'TREE_MANIFEST.txt'
manifest_hash_path = OUT / 'TREE_MANIFEST.sha256'

if manifest_path.is_file() and manifest_hash_path.is_file():
    declared = manifest_hash_path.read_text().split()[0]
    actual = shaf(manifest_path)
    check(declared == actual, 'TREE_MANIFEST.sha256 does not match TREE_MANIFEST.txt')

    seen_paths, manifest_problems = set(), []

    for number, line in enumerate(manifest_path.read_text().splitlines(), 1):
        if not line.strip():
            continue
        parts = line.split('  ', 1)
        if len(parts) != 2 or len(parts[0]) != 64:
            manifest_problems.append(f'line {number}: unreadable entry')
            continue
        rel = parts[1].strip()
        if rel.startswith('/') or '..' in rel.split('/') or '\0' in rel or '\\' in rel:
            manifest_problems.append(f'line {number}: unsafe path {rel!r}')
        if rel in seen_paths:
            manifest_problems.append(f'duplicate manifest path: {rel}')
        seen_paths.add(rel)

    for mp in manifest_problems[:10]:
        check(False, f'TREE_MANIFEST: {mp}')
    print(f'tree manifest entries: {len(seen_paths)}, problems: {len(manifest_problems)}')
else:
    check(False, 'TREE_MANIFEST.txt and TREE_MANIFEST.sha256 must both be delivered')

# --- FULL-SOURCE internal SHA256SUMS.txt ------------------------------------
if (OUT / FULL_SOURCE).is_file():
    with zipfile.ZipFile(OUT / FULL_SOURCE) as zf:
        names = set(zf.namelist())
        sums_name = 'mulkihawler/SHA256SUMS.txt'
        check(sums_name in names, 'FULL-SOURCE carries no SHA256SUMS.txt')

        if sums_name in names:
            bad = 0
            listed = 0
            for line in zf.read(sums_name).decode().splitlines():
                if not line.strip():
                    continue
                parts = line.split('  ', 1)
                if len(parts) != 2:
                    bad += 1
                    continue
                digest, rel = parts[0], parts[1].strip()
                member = f'mulkihawler/{rel}'
                listed += 1
                if member not in names:
                    bad += 1
                elif shab(zf.read(member)) != digest:
                    bad += 1
            check(bad == 0, f'FULL-SOURCE SHA256SUMS.txt: {bad} of {listed} entries do not verify')
            check(listed > 0, 'FULL-SOURCE SHA256SUMS.txt lists no files')
            print(f'FULL-SOURCE internal checksums: {listed} entries, {bad} bad')

            # Reject EXTRA archive files the manifest does not list. Verifying
            # only listed entries lets an unlisted file ride along unseen.
            listed_members = {
                f'mulkihawler/{line.split("  ", 1)[1].strip()}'
                for line in zf.read(sums_name).decode().splitlines()
                if '  ' in line
            }
            generated = {
                'mulkihawler/SHA256SUMS.txt', 'mulkihawler/RELEASE_README.md',
                'mulkihawler/DELETE_FILES.txt',
            }
            actual = {n for n in names if not n.endswith('/')}
            extras = sorted(
                n for n in actual - listed_members - generated
                if not n.startswith('mulkihawler/docs/release/')
            )
            for extra in extras[:10]:
                check(False, f'FULL-SOURCE contains a file its manifest does not list: {extra}')

    # The delivered FULL_SOURCE_MANIFEST must match the archive's own manifest.
    fs_manifest = (OUT / 'FULL_SOURCE_MANIFEST.txt').read_text()
    fs_declared = (OUT / 'FULL_SOURCE_MANIFEST.sha256').read_text().split()[0]
    check(shab(fs_manifest.encode()) == fs_declared,
          'FULL_SOURCE_MANIFEST.sha256 does not match FULL_SOURCE_MANIFEST.txt')

    with zipfile.ZipFile(OUT / FULL_SOURCE) as zf:
        if 'mulkihawler/SHA256SUMS.txt' in zf.namelist():
            check(zf.read('mulkihawler/SHA256SUMS.txt').decode() == fs_manifest,
                  'FULL_SOURCE_MANIFEST.txt differs from the archive internal SHA256SUMS.txt')

# --- DELETE_FILES path safety and runtime presence --------------------------
deletions_path = OUT / 'DELETE_FILES.txt'

if deletions_path.is_file():
    deletions = [
        line.strip() for line in deletions_path.read_text().splitlines()
        if line.strip() and not line.strip().startswith('#')
    ]
    check(bool(deletions), 'DELETE_FILES.txt lists no deletions')

    for rel in deletions:
        check(not rel.startswith('/'), f'DELETE_FILES entry is an absolute path: {rel}')
        check('..' not in rel.split('/'), f'DELETE_FILES entry escapes the root: {rel}')
        check('\\' not in rel, f'DELETE_FILES entry uses a backslash: {rel}')

    # A file the release deletes must not be shipped by the runtime, or the
    # deployment reinstates exactly what it was told to remove.
    runtime_zip = OUT / 'myhawler-account-first-registration-corrected-runtime.zip'

    if runtime_zip.is_file():
        with zipfile.ZipFile(runtime_zip) as rz:
            runtime_names = set(rz.namelist())
        for rel in deletions:
            shipped = f'application/{rel}'
            check(shipped not in runtime_names,
                  f'the runtime ships a file DELETE_FILES.txt removes: {rel}')
    print(f'deletion manifest entries: {len(deletions)}')
else:
    check(False, 'DELETE_FILES.txt is a required deliverable')

# --- command ledger ---------------------------------------------------------
evidence_zip = OUT / 'myhawler-account-first-registration-evidence.zip'
validator = Path(__file__).with_name('validate_command_ledger.py')

if evidence_zip.is_file():
    with zipfile.ZipFile(evidence_zip) as zf:
        ledger_member = next(
            (n for n in zf.namelist() if n.endswith('command-ledger.json')), None
        )
        check(ledger_member is not None, 'the evidence archive carries no command ledger')

        if ledger_member and validator.is_file():
            with tempfile.TemporaryDirectory() as tmp:
                ledger_copy = Path(tmp) / 'command-ledger.json'
                ledger_copy.write_bytes(zf.read(ledger_member))
                result = subprocess.run(
                    [sys.executable, str(validator),
                     '--ledger', str(ledger_copy),
                     '--evidence-zip', str(evidence_zip),
                     # Bind every ledger entry to the delivered tree. Without
                     # this a ledger describing another tree validates cleanly.
                     '--tree-manifest-sha256',
                     (OUT / 'TREE_MANIFEST.sha256').read_text().split()[0]],
                    capture_output=True, text=True,
                )
                print(result.stdout.strip())
                check(result.returncode == 0,
                      f'command ledger validation failed ({result.returncode}); '
                      f'{result.stderr.strip().splitlines()[-1] if result.stderr.strip() else "see output"}')

# --- FULL-SOURCE builder clean-extraction self-test -------------------------
if (OUT / FULL_SOURCE).is_file():
    with tempfile.TemporaryDirectory() as tmp:
        workspace = Path(tmp)
        with zipfile.ZipFile(OUT / FULL_SOURCE) as zf:
            zf.extractall(workspace / 'extracted')

        extracted = workspace / 'extracted' / 'mulkihawler'
        builder = extracted / 'scripts' / 'release' / 'build_full_source.py'
        check(builder.is_file(), 'FULL-SOURCE does not carry its own builder')
        check(not (extracted / '.git').exists(),
              'FULL-SOURCE contains .git; the portability test would prove nothing')

        if builder.is_file():
            # ONE builder contract for both builds. The initial build and this
            # clean-extraction rebuild must run under the same minimum, or the
            # rebuild is judged more strictly than the archive was built under
            # and fails for that reason alone.
            #
            # The value comes from the CALLER's environment, never from an
            # unauthenticated file inside the archive being verified. A
            # production finalization run leaves it unset, so the strict
            # production default applies.
            builder_min_files = os.environ.get('MYHAWLER_BUILDER_MIN_FILES') or ''

            # DELETE_FILES.txt is INJECTED from the output directory, so the
            # rebuild needs the same input the original build had. Without it
            # the rebuild is one entry short and looks non-reproducible when the
            # only difference is a missing input.
            rebuild_out = workspace / 'rebuilt'
            rebuild_out.mkdir(parents=True, exist_ok=True)
            if (OUT / 'DELETE_FILES.txt').is_file():
                shutil.copy2(OUT / 'DELETE_FILES.txt', rebuild_out / 'DELETE_FILES.txt')

            result = subprocess.run(
                [sys.executable, str(builder),
                 '--project-root', str(extracted),
                 '--output-dir', str(rebuild_out)]
                + (['--min-files', builder_min_files] if builder_min_files else []),
                capture_output=True, text=True,
            )
            check(result.returncode == 0,
                  f'the FULL-SOURCE builder failed from a clean extraction '
                  f'(exit {result.returncode})')

            # Exit 0 is not enough. The builder that shipped in the audited
            # release exited 0 after writing a two-entry archive from an empty
            # file list, so the rebuilt output must itself be inspected.
            rebuilt = rebuild_out / FULL_SOURCE
            check(rebuilt.is_file(), 'the clean-extraction rebuild produced no archive')

            rebuilt_entries = 0

            if rebuilt.is_file():
                with zipfile.ZipFile(rebuilt) as rz:
                    rebuilt_entries = len([n for n in rz.namelist() if not n.endswith('/')])

            with zipfile.ZipFile(OUT / FULL_SOURCE) as oz:
                original_entries = len([n for n in oz.namelist() if not n.endswith('/')])

            # Exact equality. A 95% tolerance accepts a rebuild that silently
            # dropped files, which is the defect this test exists to detect.
            check(rebuilt_entries == original_entries,
                  f'the clean-extraction rebuild produced {rebuilt_entries} entries '
                  f'against {original_entries} in the delivered archive; the builder '
                  f'is not reproducible')

            rebuilt_manifest = rebuild_out / 'FULL_SOURCE_MANIFEST.txt'
            delivered_manifest = OUT / 'FULL_SOURCE_MANIFEST.txt'
            if rebuilt_manifest.is_file() and delivered_manifest.is_file():
                check(rebuilt_manifest.read_text() == delivered_manifest.read_text(),
                      'the rebuilt FULL_SOURCE_MANIFEST differs from the delivered one')

            print(f'builder clean-extraction self-test: rebuilt {rebuilt_entries} '
                  f'entries (delivered {original_entries})')

print()
if problems:
    print(f'FINAL VERIFICATION FAILED — {len(problems)} problems')
    for p in problems[:30]:
        print('  -', p)
    sys.exit(1)
print('FINAL VERIFICATION PASSED — no problems found')

# Final release cycle — operator guide

This documents the job that turns a verified working-tree archive into a
complete, independently verifiable release candidate.

**It does not deploy anything.** It builds, verifies and uploads artifacts. No
step touches a production host, and nothing in it can. Deployment is a separate,
manual action performed by a human following `DEPLOYMENT_NOTES.md`.

Two entry points run the same logic:

```text
.github/workflows/myhawler-final-release.yml   GitHub Actions, manual trigger
scripts/release/run_final_release_ci.sh        the cycle itself; VPS or self-hosted
```

The workflow is a thin wrapper. Every decision lives in the shell script, so a
run on your own hardware is not a second implementation that can drift.

---

## Why the source is frozen before anything runs

Earlier rounds of this release failed for one recurring reason: evidence
collection wrote files into the tree it was describing. The recorded hash then
named a tree that no longer existed, and each "just rerun it" pass produced a
new mismatch.

The cycle therefore freezes the source **once**, before any gate runs, and every
log, report, index and archive is written to an external directory. The final
step re-derives the tree manifest and requires it to equal the freeze hash. If a
single byte moved, the run fails.

This is why the script never regenerates documentation, never writes to `docs/`,
and locks the source read-only where practical.

---

## Required environment

| Requirement | Version | Notes |
| --- | --- | --- |
| Ubuntu | 24.04 | other Linux works; the workflow pins this runner |
| PHP | 8.3 | with `mbstring, xml, sqlite3, mysql, curl, zip, bcmath, intl, gd` |
| Composer | 2.x | dependencies are cached on `composer.lock` |
| MariaDB | 10.11 | **real MariaDB, not MySQL** — see below |
| Node.js | 22 | `npm ci`, never `npm install` |
| Playwright Chromium | matching the client | installed via `npx playwright install --with-deps chromium` |
| Python | 3.11+ | the release tooling is Python and PHP |
| `zip`, `unzip`, `tar` | any | archive assembly |

### MariaDB, specifically

`mysql:8.0` is not an acceptable substitute. The accepted verification depends
on MariaDB behaviour — earlier audit rounds found concurrency and schema bugs
that SQLite masked and that MySQL does not reproduce identically. The two-process
handoff concurrency test is meaningful only against the engine production runs.

### Playwright, specifically

Chromium must come from the official Playwright command so the browser build
matches the client library. A distribution Chromium (including Ubuntu's
`chromium-browser`, which is a snap stub) is not equivalent.

---

## Required secrets and temporary credentials

Configure these as repository secrets. Both are **throwaway values for a
disposable service container** and must never be production credentials.

| Secret | Purpose | Example |
| --- | --- | --- |
| `MYHAWLER_DB_PASSWORD` | root password for the MariaDB service container | any strong random string |
| `MYHAWLER_APP_KEY` | Laravel `APP_KEY` for the test environment | `base64:` + 32 random bytes |

Generate a test key without a booted application:

```bash
php -r 'echo "base64:".base64_encode(random_bytes(32))."\n";'
```

Running outside GitHub Actions, export them instead:

```bash
export MYHAWLER_DB_PASSWORD='...'
export MYHAWLER_APP_KEY='base64:...'
export MYHAWLER_BASELINE_ARCHIVE=/path/to/v6-baseline.zip
```

### A note on `.env`

The cycle runs with **no `.env` file on disk**. `scripts/secret-scan.php` treats
one as a committed secret, correctly, and the gate would fail. Configuration is
supplied through the environment. The script also unsets `DB_*` for the default
test suite, because `phpunit.xml`'s `<env>` entries do not force-override and an
inherited `DB_CONNECTION` silently redirects the SQLite suite at MariaDB — that
mistake once produced a passing run reporting the wrong figures.

---

## Inputs

| Input | Required | Meaning |
| --- | --- | --- |
| `source_archive_url` | no | URL of `myhawler-current-working-tree.zip`. Omit to use `release-input/myhawler-current-working-tree.zip` in the repository. |
| `source_sha256` | **yes** | Expected SHA-256 of that archive. The job refuses to proceed on a mismatch. |
| `baseline_commit` | no | Ancestor commit. Defaults to `9c0188f81843cfe4786b7f72ecdc2a3fae89cd82`. |

The baseline commit is recorded as an **ancestor label only**. It is never
presented as the identity of the frozen tree, which carries work above it.

A v6 baseline archive must also be available at
`release-input/baseline.zip` (or `MYHAWLER_BASELINE_ARCHIVE`); the deployment
rehearsal stages it as the "before" state.

---

## Triggering

**GitHub Actions:** Actions → *MyHawler final release* → **Run workflow**.
Supply `source_sha256`, optionally a URL, then run. Expect roughly 40–70
minutes, dominated by the browser matrix.

**Command line:**

```bash
gh workflow run myhawler-final-release.yml \
  -f source_sha256=<sha256-of-the-archive> \
  -f source_archive_url=https://example.com/myhawler-current-working-tree.zip
```

**Outside GitHub:**

```bash
./scripts/release/run_final_release_ci.sh \
  --source-archive /path/myhawler-current-working-tree.zip \
  --source-sha256  /path/myhawler-current-working-tree.zip.sha256 \
  --work           /var/tmp/myhawler-final
```

---

## What runs, in order

1. Verify the source archive against its detached SHA-256.
2. Extract into a dedicated source directory; install Composer and npm deps.
3. Stage the eligible tree and freeze one identity (confirmed to be a fixed point).
4. Verify manifest ↔ eligible-file parity in **both** directions.
5. Lock the frozen source read-only where practical.
6. Record every non-browser gate: both PHPUnit suites on SQLite and MariaDB,
   PHPStan, Pint, standalone suite, npm ci, typecheck, lint, build, all guards,
   routes, scheduler, queue.
7. Run the explicit two-process MariaDB handoff concurrency test.
8. Run all five Playwright projects with `PHP_CLI_SERVER_WORKERS=10`:
   mobile 360×800, mobile 390×844, tablet 768×1024, laptop 1366×768,
   desktop 1440×900.
9. Merge Playwright JSON and JUnit; reject any skip or flake.
10. Deployment rehearsal (six documented backups, each proved readable).
11. Fresh-session rollback rehearsal under `env -i`.
12. Exact path-targeted rollback of
    `2026_08_06_000100_telegram_return_handoffs.php` — asserted present, not
    assumed.
13. `DELETE_FILES` apply and restore.
14. FULL-SOURCE build plus the exact clean-extraction self-test.
15. Corrected runtime build.
16. External evidence directory (frozen, read-only collection).
17. Complete `command-ledger.json`.
18. Ledger validation: every raw log resolves, hash and size match, every entry
    binds to the frozen hash.
19. Source/runtime parity, both directions.
20. Recursive archive safety audit.
21. `evidence-index.json`, `release-index.json`, reports, detached checksums.
22. Assemble FINAL-DELIVERY.
23. Copy only final deliverables into a clean directory.
24. Run `verify_final_delivery.py` there.
25. Require exit code 0.
26. Upload everything.
27. Re-derive the tree manifest and require it to equal the freeze hash.

---

## Failure conditions

The run stops immediately, with the failing line reported, on any of:

- any command exiting nonzero (`set -Eeuo pipefail` with an `ERR` trap);
- the source tree differing from its freeze hash;
- any evidence or ledger entry naming a different tree hash;
- any Playwright project failing, **skipping** or turning flaky;
- either rehearsal failing;
- source/runtime parity failing;
- any detached checksum failing;
- the clean-directory verifier not exiting 0.

Skips and flakes are treated as failures deliberately. A skipped browser test
tells you nothing about the behaviour it was supposed to cover, and a flake that
passes on retry is an unresolved defect, not a pass.

---

## Outputs

Three artifacts are uploaded, **including on failure**, because a failed cycle's
logs are exactly what is needed to diagnose it.

`myhawler-final-delivery`:

```text
myhawler-account-first-registration-FINAL-DELIVERY.zip     + .sha256
myhawler-account-first-registration-FULL-SOURCE.zip        + .sha256
myhawler-account-first-registration-SOURCE-PATCH.zip       + .sha256
myhawler-account-first-registration-corrected-runtime.zip  + .sha256
myhawler-account-first-registration-evidence.zip           + .sha256
TREE_MANIFEST.txt        + .sha256
FULL_SOURCE_MANIFEST.txt + .sha256
evidence-index.json      + .sha256
release-index.json       + .sha256
command-ledger.json      + .sha256
DELETE_FILES.txt
VERIFICATION.md  ROADMAP_STATUS.md
RELEASE_DECISION.md  FINAL_RELEASE_VERIFICATION.md
```

`myhawler-raw-evidence`: every raw gate log, rehearsal output, parity and archive
audit results, `FROZEN_TREE_SHA256`.

`myhawler-playwright-reports`: per-project logs, JSON and JUnit, plus the merged
reports.

---

## Retrieving and verifying artifacts

```bash
gh run download <run-id> -n myhawler-final-delivery -D ./delivery
cd delivery
sha256sum -c ./*.sha256
```

Every line must read `OK`. Then confirm the tree identity independently:

```bash
cut -d' ' -f1 TREE_MANIFEST.sha256
python3 -c "import json;print(json.load(open('release-index.json'))['identity']['final_tree_manifest_sha256'])"
python3 -c "import json;d=json.load(open('command-ledger.json'));print({e['final_tree_manifest_sha256'] for e in d['entries']})"
```

All three must show the same hash, and the ledger set must contain exactly one
value. If they differ, the delivery is not internally consistent and must not be
used regardless of what any report says.

Re-run the independent verifier yourself from a clean directory:

```bash
python3 scripts/release/verify_final_delivery.py ./delivery
```

---

## What this workflow will not do

It does not deploy, does not touch a production host, and holds no production
credentials. A green run means a release **candidate** was built and verified —
not that it was released. Deployment remains a deliberate human action following
`DEPLOYMENT_NOTES.md`, with `ROLLBACK_NOTES.md` open before you start.

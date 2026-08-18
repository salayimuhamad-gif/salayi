# MyHawler v7 — post-seal trust-chain repair: changed files

17 files changed against checkpoint
`a32961233ad1061ab44cc538f427841eaa1791911e5d4fcdf8eefddc6de80f2b`
(tree `e43851ca15de538275f39b2e77e0930244ade0053f9421bd3756248031c51824`).
Nothing added, nothing removed, no product code touched. Complete corrected
files ship in `changed-files.zip`; before/after hashes in
`changed-files-hashes.md`.

## Release tooling

- **scripts/release/record_command.py** — `--allow-nonzero` removed: the
  wrapper's exit code is now always the child's (BLOCKER 1). Entries store
  exact `argv` as a JSON array plus a shell-quoted display `command`
  (BLOCKER 4); a redacted map of the actual child environment; `clean_env`
  and the exact `clean_env_allow_list` applied; `database_env_passed`.
  Secret-bearing KEY=VALUE argv tokens are stored `[REDACTED]` so the exact
  argv record cannot leak what the env redaction protects. Signal deaths are
  recorded and propagated as `128+N` with the raw signal in
  `termination_signal`, keeping captured == measured for every death mode.
- **scripts/release/write_attestation.py** — rewritten (BLOCKER 2): every
  gate derives from the measured attestation ledger; raw logs are re-hashed
  from disk and must match measured SHA-256, byte count and resolved path
  inside the attestation-evidence root; the runner's captured exits arrive as
  mandatory `--observed-exit LABEL=CODE` cross-checks; any contradiction is a
  controlled nonzero exit with no attestation written; sealed evidence ZIP
  and master (with their detached checksums) are bound; schema v2.
- **scripts/release/release_gates.py** — new gates `evidence-finalizer`,
  `playwright-remaining-merge`, `browser-spec-closure`;
  `ATTESTATION_ONLY_LABELS` now four labels; `--remaining-specs` CLI prints
  the canonical inventory the runner executes; `verify_spec_closure()` +
  `--verify-spec-closure DIR` prove disk == registry both ways with unique
  basenames; `PLAYWRIGHT_REMAINING_INTENTIONAL_SKIPS` names the five specs
  reviewed as intentionally skipping; attestation schema bumped to v2.
- **scripts/release/merge_playwright.py** — remaining mode validates the
  generated JSON report against the canonical inventory (BLOCKER 5): every
  intended spec represented by executed tests, every project executed, no
  account-first scenario, nothing outside the inventory, zero failures and
  flakes, skips only from reviewed specs; an absent report fails instead of
  merging vacuously.
- **scripts/release/run_final_release_ci.sh** — remaining-suite argv built
  from the registry (no `--grep-invert`); `playwright-remaining-merge` and
  `browser-spec-closure` recorded; `finalize_evidence.py` recorded under
  `evidence-finalizer` into the attestation ledger (BLOCKER 6); post-seal
  captures switched to ERR-trap-safe `|| RC=$?` with `assert_recorded_exit`
  cross-checks against the measured ledger (BLOCKER 1); new attestation
  invocation with `--observed-exit`; attestation upload set asserted to exist
  before success (BLOCKER 3); rollback rehearsal no longer embeds
  `REHEARSAL_*` assignments (including the DB password) in recorded argv —
  secrets reach the child only through `--clean-env`'s environment.
- **scripts/release/validate_command_ledger.py** — uniform schema extended
  with `argv` (shape-validated), `termination_signal`, `child_environment`,
  `clean_env`, `clean_env_allow_list`.
- **scripts/release/write_release_evidence.py** — artifact map renamed
  `pre_seal_component_artifacts` with an in-document scope note (BLOCKER 7);
  sealed artifacts are bound by the external attestation instead.
- **scripts/release/verify_final_delivery.py** — the delivered-package secret
  scan decides placeholder status only by value shape; the name-keyed
  `REHEARSAL_DB_PASSWORD` exemption (blind to that key's literal value) is
  gone.
- **scripts/release/build_v7_evidence.py** — source/runtime parity over zero
  shared files now fails instead of writing a vacuous PASS log.
- **scripts/release/generate_release_reports.py** — SECURITY_REVIEW names the
  archive audit by its real label `component-archive-audit`; the dead
  `archive-audit` spelling silently dropped the row.

## Workflow

- **.github/workflows/myhawler-final-release.yml** — the attestation artifact
  uploads the real material: `attestation-ledger.json`, the whole
  `attestation-evidence/` directory (all four post-seal raw logs),
  `final-attestation.json` + `.sha256`, `final-verification.log`
  (BLOCKER 3). The two advertised paths the runner never created are gone.

## Tests

- **tests/Standalone/release_tooling_test.py** — 131 → 218 checks. New
  behavioural regressions for every audit proof plus the adversarial-review
  round (signal deaths, argv redaction, scan blind spot, spec closure,
  ERR-trap-safe captures, report label). One pre-existing vacuous check fixed:
  the zero-parity test passed a stale `--runtime-dir` flag and only proved
  argparse rejects unknown arguments.
- **tests/Standalone/release_e2e_test.py** — 32 → 52 assertions; runs the
  real runner offline-stub to cleanroom PASS and now also proves the
  attestation ledger/gates/sealed bindings, the workflow-upload contract
  (every advertised path exists), the pre-seal scope of
  release-evidence.json, and the remaining-merge execution.

## Documentation / identity

- **CHANGELOG.md** — authoritative `[4.0.0-step31]` entry for this round.
- **TREE_MANIFEST.txt / TREE_MANIFEST.sha256 / SHA256SUMS.txt** — regenerated
  identity of the corrected tree:
  `94772a8cdda19f415c3c32b78d5339e53aa383f1c4e394172e56f22c49926577`
  (staging fixed point confirmed twice; manifest verified against the tree
  and against the extracted delivery ZIP).

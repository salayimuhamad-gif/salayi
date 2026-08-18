# MyHawler v7 — post-seal trust-chain repair: test results

Date: 2026-08-07 (UTC). Environment: offline release-engineering container
(PHP 8.4.19 CLI, Python 3.11.15 / 3.12, Node 22.22.2, bash 5.x). No network
access to packagist.org or registry.npmjs.org; no MariaDB; no Chromium run.

## Input identity (continued from, verified before any work)

```text
files - 2026-08-07T013605.315.zip
  b265dd0845c20a12cce8f23f0e482469f83032ec7c1fb9fa05995e1a32422b88   PASS
myhawler-current-working-tree.zip (checkpoint)
  a32961233ad1061ab44cc538f427841eaa1791911e5d4fcdf8eefddc6de80f2b   PASS
TREE_MANIFEST.txt (checkpoint)
  e43851ca15de538275f39b2e77e0930244ade0053f9421bd3756248031c51824   PASS
checkpoint manifest vs extracted tree: 944/944 entries                PASS
```

## Output identity (this delivery)

```text
myhawler-current-working-tree.zip
  988039f4fd0ee6520d628bd8666c704e25cb946bb960d6d73983f3d70fd43935
TREE_MANIFEST.txt (= SHA256SUMS.txt content; 944 entries)
  94772a8cdda19f415c3c32b78d5339e53aa383f1c4e394172e56f22c49926577
```

17 files changed (14 content, 3 regenerated identity files). No file added or
removed. `app/`, `resources/`, `lang/`, `database/`, `config/`, `routes/`,
`public/` are byte-identical to the checkpoint — no product functionality or
translation was touched.

## Test suites (all run twice: from the working tree AND from the extracted
## delivery ZIP)

```text
python3 tests/Standalone/release_tooling_test.py
  ALL 218 RELEASE TOOLING REGRESSIONS PASSED        (checkpoint had 131)

python3 tests/Standalone/release_e2e_test.py
  ALL 52 END-TO-END ORCHESTRATION ASSERTIONS PASSED (checkpoint had 32)
  (executes the real run_final_release_ci.sh in --offline-stub mode to a
   cleanroom PASS, including the new attestation phase)
```

New behavioural regressions cover every required proof:

```text
child exit 7: recorded 7 in ledger, received 7 by runner-style capture   PASS
--allow-nonzero refused outright                                          PASS
signal death: ledger 137 == propagated 137, termination_signal 9          PASS
attestation refuses observed exit != measured ledger exit                 PASS
attestation refuses raw-log SHA-256 mismatch                              PASS
attestation refuses raw-log byte-size mismatch                            PASS
attestation refuses missing raw log / missing gate / unconsumed exit      PASS
attestation refuses detached checksum contradicting sealed bytes          PASS
consistent failure -> NOT VERIFIED + nonzero exit                         PASS
all four post-seal raw logs + ledger + attestation exist at the exact
  paths the workflow uploads (e2e workflow-upload contract test)          PASS
argv stored as exact JSON array; joined command is display-only           PASS
secret-bearing KEY=VALUE argv tokens stored [REDACTED]; secrets never
  reach the ledger (env map and argv both)                                PASS
clean-env allow-list ({PATH,HOME,LANG,TERM} + REHEARSAL_*) recorded       PASS
remaining suite built from PLAYWRIGHT_REMAINING_SPECS registry;
  --grep-invert gone from executed commands                               PASS
merge --mode remaining: canonical coverage per spec + per project,
  account-first exclusion, rogue-spec rejection, unreviewed-skip
  rejection, no vacuous pass on an absent report                          PASS
browser-spec-closure gate: on-disk inventory == registry both ways,
  unique basenames (behavioural fixtures for all failure modes)           PASS
evidence-finalizer recorded through record_command into the attestation
  ledger and bound by the external attestation                            PASS
release-evidence.json: pre_seal_component_artifacts + documented scope    PASS
```

## Static checks (this environment)

```text
bash -n  scripts/release/*.sh                                  PASS
python3 -m py_compile scripts/release/*.py tests/Standalone/*  PASS (3.11 & 3.12)
workflow YAML parse (both .github/workflows files)             PASS
php -l   all 572 project PHP files                             PASS (0 failures)
php scripts/secret-scan.php                                    PASS (8 pre-existing warnings, no secrets)
staging fixed point (stage_tree twice, identical hash)         PASS
detached checksum verification (manifest vs tree, ZIP hash,
  extracted-copy re-verification)                              PASS
adversarial multi-agent review (22 agents, 2 rounds): 6 confirmed
  findings, all fixed and regression-tested in this delivery   DONE
```

## NOT run in this environment (see REMAINING_LIMITATIONS.md)

Pint, vue-tsc typecheck, ESLint, PHPUnit (SQLite/MariaDB), PHPStan, real
Playwright, MariaDB gates, deployment/rollback rehearsals, real GitHub Actions
final-release run, production Final Delivery. No PHP/TS/Vue source changed in
this round; those gates run as recorded gates on the real CI host.

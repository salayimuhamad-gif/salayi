# MyHawler v7 — exact remaining limitations after the post-seal trust-chain repair

Everything below is stated so nothing can be over-claimed. This delivery fixes
the offline tooling and proves it offline; it does NOT constitute a release.

## What this delivery does NOT prove

1. **Real Playwright execution.** The five account-first viewport projects,
   the remaining suite (nine canonical specs) and the new
   `playwright-remaining-merge` validation have NOT executed against a real
   browser in this environment (no Chromium run, no MariaDB, npm registry
   blocked by the network policy). The remaining-suite validation logic is
   proven against synthetic Playwright-shaped JSON reports only. First real
   run happens on the CI host.
2. **MariaDB gates.** focused/full/handoff-concurrency MariaDB suites,
   route/middleware/scheduler/queue checks, browser database preparation and
   fixture seeding: not executed here (no MariaDB server).
3. **Deployment and rollback rehearsals.** Not executed here. Note the
   rollback gate's invocation changed (secrets now reach it only through
   `--clean-env`'s environment, never argv); the rehearsal itself must be
   re-proven on the real runner.
4. **The real GitHub Actions final-release run.** The workflow was changed
   (attestation upload set) and has not been dispatched. The offline E2E
   proves the runner produces every advertised upload path, but
   actions/upload-artifact behaviour with the directory path is only proven
   by a real run.
5. **Production Final Delivery.** No production artifact was created from
   these offline-stub results, per instructions. The offline-stub cleanroom
   PASS is an orchestration proof, not a product verification.
6. **Pint / vue-tsc / ESLint / PHPUnit / PHPStan.** Not runnable here
   (packagist.org and registry.npmjs.org blocked; vendor/ and node_modules/
   are deliberately absent from the tree). Mitigation: no PHP, TypeScript or
   Vue source file changed in this round — every file those tools judge is
   byte-identical to the step30 checkpoint — and `php -l` passed on all 572
   PHP files. These all run as recorded gates on the real CI host.

## Known residual design limits (accepted, documented)

7. **Secret redaction is key-pattern based.** `record_command.py` redacts the
   child-environment values (and KEY=VALUE argv tokens) whose KEY matches the
   sensitive patterns (PASSWORD, PASSWD, SECRET, TOKEN, PRIVATE, CREDENTIAL,
   APIKEY, API_KEY, ACCESS_KEY, AUTH, *_KEY). A secret exported under a
   wholly innocuous name (for example a connection URL embedding a password
   under `DATABASE_URL`) would not be redacted by the key rule. The runner
   itself sets no such variable; operators of self-hosted runners must not
   either. The delivered-package scan (`verify_final_delivery.py`) remains a
   second, value-marker-based net and now has no name-keyed blind spot.
8. **Raw gate logs are not redacted.** If a third-party tool prints a secret
   into its own stdout/stderr, that lands in the raw log inside the evidence
   ZIP. The package scan matches assignment markers for four sensitive key
   families — the application key, the Telegram bot token, the Telegram
   webhook secret, and the database password — across every text entry
   including logs, and fails closed on a literal value; it cannot catch a
   secret carrying no marker. The canonical list lives in `SECRET_MARKERS` in
   `scripts/release/verify_final_delivery.py`.

   The exact marker strings are deliberately not reproduced in this file. This
   document is packaged into the SOURCE-PATCH, the corrected runtime and the
   FULL-SOURCE archives, and the delivery verifier scans Markdown along with
   everything else. It takes the remainder of a matching line as the candidate
   value, so an inline-code example followed by ordinary punctuation reads as a
   literal credential: writing the markers out here produced twelve findings —
   four examples across three packaged copies — and failed the clean-directory
   verifier on documentation that leaked nothing. Describing the key families
   instead keeps the policy legible without the document triggering the scanner
   against itself.
9. **The spec-closure gate is stubbed offline.** `browser-spec-closure` runs
   for real only on the CI host (the offline E2E fixture deliberately carries
   a single spec). Its logic is behaviourally tested against fixtures for
   every failure mode, and the shipped tree passes it directly
   (`release_gates.py --verify-spec-closure tests/Browser` exits 0 here).
10. **`merge_playwright --mode remaining` trusts Playwright's report shape.**
    Parsing handles nested suites and per-test `projectName`/`status`; a
    future Playwright major that changes the JSON schema would surface as a
    loud gate failure (missing coverage), not a silent pass.
11. **Attestation `--observed-exit` covers the four attestation gates only.**
    Ordinary (pre-seal) gates stop the release through the recorder's
    propagated exit under `set -e`; they are not double-entered into the
    attestation, by design.

## Next required step (unchanged from the audit's order)

Dispatch the real GitHub Actions `myhawler-final-release` workflow against
this tree (source SHA-256
`988039f4fd0ee6520d628bd8666c704e25cb946bb960d6d73983f3d70fd43935`,
expected frozen tree
`94772a8cdda19f415c3c32b78d5339e53aa383f1c4e394172e56f22c49926577`) with the
verified v6 baseline, on the required real CI host with MariaDB 10.11 and
Chromium. Only a green run there — with the attestation artifact carrying the
ledger and all four raw logs — is release evidence. Do not deploy from
anything less.

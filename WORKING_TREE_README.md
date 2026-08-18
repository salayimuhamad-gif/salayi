# MyHawler v7 — complete source tree

The complete application source as it stands, including work not present in the
baseline commit.

## Identity

```text
Baseline commit (ANCESTOR ONLY)  9c0188f81843cfe4786b7f72ecdc2a3fae89cd82
```

That commit is where this work started. It is **not** the identity of this tree,
which carries changes on top of it.

The identity of what you are holding is `TREE_MANIFEST.txt`, listing every
eligible file with its SHA-256. Its detached hash is `TREE_MANIFEST.sha256`, and
the authoritative copy of that hash travels **outside** this archive — a
manifest checked only against itself proves nothing, because an edit can rewrite
both. `SHA256SUMS.txt` is the same information in `sha256sum(1)` format, derived
from the same eligible set.

This README deliberately contains no file counts, gate results or round-specific
claims. Volatile numbers belong in the external evidence package, where they can
be regenerated without changing the tree they describe.

## What is deliberately absent

```text
vendor/            restore with: composer install
node_modules/      restore with: npm ci
.env               copy .env.example; no key, token or credential is in this tree
storage caches, logs, sessions, uploads, databases
docs/release-evidence.json
```

That last one is intentional and load-bearing. Run evidence used to live inside
the tree while recording that tree's own manifest hash — a self-referential
identity that could never converge, because updating the file changed the hash
it named. All mutable evidence now lives in an external evidence directory. See
`scripts/support/EvidencePath.php`; the collector refuses to write inside the
source tree.

If you are upgrading an existing installation from an overlay, apply
`DELETE_FILES.txt`: an overlay cannot delete, so the stale evidence file would
otherwise survive and misreport the tree.

## Restoring and verifying

```bash
composer install
npm ci
sha256sum -c SHA256SUMS.txt
```

Run the gates with **no `.env` on disk** — `scripts/secret-scan.php` treats one
as a committed secret. Supply `APP_KEY` and any `DB_*` values through the
environment instead, and unset `DB_*` for the default suite: `phpunit.xml`'s
`<env>` entries do not force-override, so an inherited `DB_CONNECTION` silently
redirects the SQLite suite at MariaDB.

```bash
vendor/bin/phpunit
vendor/bin/phpunit -c phpunit.mariadb.xml
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
php tests/Standalone/run.php
npm run typecheck && npm run lint && npm run build
npx playwright test
```

## Release tooling

`scripts/release/` holds the portable release harness — tree staging, manifest
writer, FULL-SOURCE builder with clean-extraction self-test, evidence builder,
command-ledger recorder and validator, final verifier, and the deployment and
rollback rehearsals. Every one takes its inputs as arguments or environment
variables; none requires a path from any particular machine.

Deployment and rollback procedures are `DEPLOYMENT_NOTES.md` and
`ROLLBACK_NOTES.md`. Read the rehearsal-status notice at the top of each before
relying on it.

## Status

This tree is a production candidate pending independent re-audit. It is not
production-ready, and this file makes no claim about which gates have passed —
consult the external evidence package for that.

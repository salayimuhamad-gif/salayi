# DEPLOY_HOSTINGER.md — Mulkihawler / MyHawler v6

This is the deployment procedure for `Mulkihawler_4.0.0-step30_Production_Deployment.zip`.
It describes the steps that the rehearsal actually performs, in the same order,
using the same commands. The rehearsal is `scripts/consumer-deployment-test.sh`,
and its machine-readable result is `evidence/deployment-rehearsal.json`.

Anything this document tells you to run has been run against the exact archive
you are deploying. Nothing here is aspirational.

## 0. Paths and interpreter

```text
Project root:  ~/domains/myhawler.com
Application:   ~/domains/myhawler.com/application
Public root:   ~/domains/myhawler.com/public_html
CLI PHP:       /opt/alt/php83/usr/bin/php
```

Use the CLI PHP path explicitly. Hostinger's default `php` on the shell is not
necessarily the version the site runs.

## 1. Verify the artifact BEFORE touching the live tree

```bash
cd ~
sha256sum -c Mulkihawler_4.0.0-step30_Production_Deployment.zip.sha256
```

Stop if this fails. A mismatched archive is not a deployment candidate, and
every later check assumes this one passed.

## 2. Keep the current release recoverable

```bash
TS=$(date +%Y%m%d-%H%M%S)
cd ~/domains/myhawler.com
cp -a application "application.backup-$TS"
cp -a public_html "public_html.backup-$TS"
mysqldump -u <db_user> -p <db_name> > ~/myhawler-db-$TS.sql
```

The database dump is not optional. Migrations in this release create and alter
cleanup-identity tables; a rollback that has no dump can restore code but not
data.

## 3. Stage the new release beside the live one

```bash
mkdir -p ~/releases/$TS
cd ~/releases/$TS
unzip -q ~/Mulkihawler_4.0.0-step30_Production_Deployment.zip
sha256sum -c application/SHA256SUMS.txt   # if present in the archive
```

Do not unzip over the live tree. Staging is what makes step 8 reversible.

## 4. Carry your configuration across

```bash
cp ~/domains/myhawler.com/application/.env ~/releases/$TS/application/.env
```

The archive ships `.env.example` only. It contains no `APP_KEY`, no
credentials, and no production data — verified by the packaging secret scan and
re-verified by the archive audit.

Never generate a new `APP_KEY` on an existing installation: encrypted columns
(phone ciphertext, MFA secrets, lead notes) become permanently unreadable.

## 5. Prove the release can run before it is live

```bash
cd ~/releases/$TS/application
/opt/alt/php83/usr/bin/php -v
/opt/alt/php83/usr/bin/composer check-platform-reqs --no-dev
/opt/alt/php83/usr/bin/php artisan about
```

`check-platform-reqs` is the step that catches a PHP or extension mismatch while
the current site is still serving. The rehearsal runs exactly this command.

## 6. Warm the caches in the staged tree

```bash
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:cache
```

Caching before activation means the first live request serves from a warm cache
rather than compiling under load.

## 7. Migrate

```bash
/opt/alt/php83/usr/bin/php artisan migrate --force
/opt/alt/php83/usr/bin/php artisan migrate:status
```

This release applies the strict cleanup chain — `001700` through `002000` —
which backfills `incident_uuid`, moves live-identity uniqueness from `job_key`
to `active_key`, and creates the cleanup Journal import ledger. Each migration
verifies its own contract and refuses rather than half-applying: if it stops,
the schema has not been changed and the message names what blocked it.

The rehearsal records 54 migrations applied on a fresh database. On an existing
installation only the new ones run.

## 8. Activate

```bash
cd ~/domains/myhawler.com
mv application application.previous-$TS
mv ~/releases/$TS/application application
rm -rf public_html/build
cp -a ~/releases/$TS/public_html/. public_html/
```

Move the application first, then replace the public build. The front controller
resolves the application through `MULKIHAWLER_APP_BASE`, defaulting to
`../application`, so the two halves must belong to the same release.

## 9. Smoke the live site

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://myhawler.com/
curl -sS https://myhawler.com/ | grep -o 'build/assets/[^"]*' | head -3
```

Expect `200`, and asset paths that exist under `public_html/build/assets/`.
The rehearsal asserts the same two things: a 200 front-controller response and a
home page whose referenced assets are present in the shipped manifest.

If either fails, go to `ROLLBACK_HOSTINGER.md` now. Do not debug on a broken
live site while a working release sits one `mv` away.

## 10. Background work

```bash
/opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --max-time=50
```

Cron entry (single cron, database queue — the Hostinger shape this project
targets):

```text
* * * * * cd ~/domains/myhawler.com/application && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

The rehearsal proves the queue worker runs and the scheduler registers against
the deployed artifact.

## 11. What the rehearsal verified on this exact archive

```text
27 of 27 checks passed
migrations applied:        54
front-controller status:   200
extraction, config cache, route cache, view cache: PASS
no database, no logs, no .env shipped:             PASS
Vite manifest present, home page references assets: PASS
```

Full detail: `evidence/deployment-rehearsal.json`.

# ROLLBACK_HOSTINGER.md — Mulkihawler / MyHawler v6

Written to be followed from a **fresh SSH session**, by someone who was not
present for the deployment and has no shell variables set. That constraint is
the point: a rollback document that assumes `$TS` is still exported is useless
in the situation it exists for.

The fresh-session rehearsal that validated this procedure recorded **11 passed,
0 failed**; its log is `evidence/rollback-rehearsal.log`.

## 0. Find what you are rolling back to

```bash
cd ~/domains/myhawler.com
ls -dt application.previous-* application.backup-* 2>/dev/null | head -5
ls -dt public_html.backup-* 2>/dev/null | head -5
ls -t ~/myhawler-db-*.sql 2>/dev/null | head -3
```

Pick the newest entry that predates the deployment you are reversing, and set it
once so the rest of this document is copy-paste safe:

```bash
PREV=application.previous-YYYYmmdd-HHMMSS      # from the listing above
PUB=public_html.backup-YYYYmmdd-HHMMSS
DUMP=~/myhawler-db-YYYYmmdd-HHMMSS.sql
```

If no `application.previous-*` exists, the deployment never reached activation,
the old release is still live, and there is nothing to roll back — investigate
the staged tree in `~/releases/` instead.

## 1. Decide whether the database must come back too

Code and schema roll back separately, and getting this wrong is the one
irreversible mistake available here.

- **Schema unchanged** (deployment failed before step 7, or `migrate` reported
  nothing to run): restore code only. Skip section 4.
- **Schema changed**: restore code *and* the dump. The strict cleanup chain
  makes `incident_uuid` NOT NULL and moves uniqueness to `active_key`; the older
  code does not populate those columns, so running it against the new schema
  writes rows the schema rejects.

Check what actually ran:

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan migrate:status | tail -20
```

## 2. Restore the application

```bash
cd ~/domains/myhawler.com
mv application application.failed-$(date +%Y%m%d-%H%M%S)
mv "$PREV" application
```

Keep the failed release. It is the only evidence of what went wrong, and it
costs nothing to retain.

## 3. Restore the public build

```bash
cd ~/domains/myhawler.com
rm -rf public_html/build
cp -a "$PUB"/build public_html/
cp -a "$PUB"/sw.js public_html/ 2>/dev/null || true
cp -a "$PUB"/workbox-*.js public_html/ 2>/dev/null || true
```

The build directory must be **replaced, not merged**. A merged directory leaves
the new release's chunks beside the old manifest; the browser then requests
assets that the restored manifest never names, and the service worker can serve
a chunk from a release that is no longer installed.

The rehearsal asserts precisely this: the restored manifest is byte-identical to
the pre-deployment one, no stale manifest survives, and the service worker is
restored.

## 4. Restore the database, only if section 1 said so

```bash
mysql -u <db_user> -p <db_name> < "$DUMP"
```

Restore the dump taken immediately before the deployment. A newer dump contains
the schema you are trying to leave.

## 5. Clear the caches the failed release left behind

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan route:clear
/opt/alt/php83/usr/bin/php artisan view:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:cache
```

A cached route table from the release you just removed will happily point at
controllers that are no longer there.

## 6. Prove the rollback worked

```bash
/opt/alt/php83/usr/bin/php artisan route:list | wc -l
/opt/alt/php83/usr/bin/php artisan about | head -20
curl -sS -o /dev/null -w '%{http_code}\n' https://myhawler.com/
curl -sS https://myhawler.com/ | grep -o 'build/assets/[^"]*' | head -3
```

The rehearsal's eleven checks, all of which you can reproduce above:

```text
pass  routes resolve (341)
pass  middleware aliases resolve
pass  queue worker runs
pass  scheduler registers
pass  no stale manifest from the rolled-back release
pass  service worker restored
pass  manifest assets present
pass  HTTP responds after rollback (200)
pass  asset served after rollback
```

Then fetch one asset the restored page names and confirm it returns 200. A home
page that renders while its assets 404 is a half-rolled-back site.

## 7. Background work

```bash
/opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --max-time=50
```

Confirm the cron entry still points at the application directory. It does unless
you changed the path during deployment.

## What this procedure deliberately does not do

It does not run `migrate:rollback`. Reversing this release's chain is a separate,
audited operation with its own preflight (`mulkihawler:rollback-wizard`, which
refuses without `--force` and refuses again if duplicate identities would make
the reversal lossy). Restoring the dump is faster, and it cannot half-apply.

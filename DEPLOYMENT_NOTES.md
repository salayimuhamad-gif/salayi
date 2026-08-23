# DEPLOYMENT_NOTES.md — MyHawler v7 release

The exact reversible procedure for this release: account-first registration
with passwords and a Telegram-or-WhatsApp verification choice, Telegram
password recovery, admin presence and activity, and the investment map
surface.

> **Rehearsal status.** `scripts/release/deploy_rehearsal.sh` executes this
> exact procedure on every final-release CI run, against a staged
> Hostinger-layout copy built from the sealed v6 baseline archive. The raw
> rehearsal output and its check counts ship in the external evidence package,
> which is the authoritative record for this document.

**This patch DOES change the schema.** It ships twelve forward-only migrations:

```text
app/Modules/Identity/Database/Migrations/2026_08_06_000100_telegram_return_handoffs.php
app/Modules/Identity/Database/Migrations/2026_08_09_000100_telegram_verification_tokens.php
app/Modules/Identity/Database/Migrations/2026_08_09_000200_password_recovery_challenges.php
app/Modules/Identity/Database/Migrations/2026_08_09_000200_profile_optional_details.php
app/Modules/Identity/Database/Migrations/2026_08_09_000300_add_last_seen_to_users.php
app/Modules/Knowledge/Database/Migrations/2026_08_16_000100_add_evidence_class_to_knowledge_events.php
app/Modules/Knowledge/Database/Migrations/2026_08_17_000100_backfill_knowledge_event_search_keys.php
app/Modules/Marketplace/Database/Migrations/2026_08_17_000200_backfill_offer_search_keys.php
app/Modules/Identity/Database/Migrations/2026_08_19_000100_whatsapp_account_verification.php
app/Modules/Market/Database/Migrations/2026_08_21_000100_backfill_price_record_scope_ids.php
app/Modules/Portfolio/Database/Migrations/2026_08_22_000100_valuation_rule_engine.php
app/Modules/Portfolio/Database/Migrations/2026_08_22_000200_valuation_rule_set_family_uniqueness.php
```

1. `telegram_return_handoffs` backs the secure Telegram return handoff.
2. `telegram_verification_tokens` backs the **permanent registration
   verification link** (see `docs/simplified-telegram-verification.md`). Without
   it, `/account/telegram/link` errors on the first render and nobody can
   finish a registration.
3. `password_recovery_challenges` backs Telegram password recovery: a
   fifteen-minute, single-use, identity-bound challenge that can only ever
   change a password — it never verifies, links, or signs anybody in.
4. `users.gender` and `users.date_of_birth` are two nullable optional profile
   columns. Both are additive and neither gates anything.
5. `users.last_seen_at` is the nullable presence column behind the admin
   activity view; it is written at most once per interval by throttled
   middleware.
6. `knowledge_events.evidence_class` is the nullable classification column
   behind the advisor's grounded market answers; rows without it read as
   `admin_observation`, so the advisor states them only as something the
   team recorded, never as a verified fact.
7. `backfill_knowledge_event_search_keys` is data-only: it derives the
   Sorani search key for existing knowledge events so admin search finds
   rows created before the key had a writer. Reversing it is a documented
   no-op — blanking the keys would only re-break the search.
8. `backfill_offer_search_keys` is the same data-only repair for existing
   marketplace offers, with the same no-op reversal.
9. `whatsapp_otps` and `users.whatsapp_verified_at` back the WhatsApp
   verification door: short-lived one-time codes delivered through Bird,
   hashed at rest, and the second verified-at stamp the shared
   verified-account rule reads. Without them the reclamation sweep and the
   verified-account queries name a column that does not exist.
10. `backfill_price_record_scope_ids` is data-only: it resolves the canonical
   internal `scope_id` for historical imported price records from their
   `scope_type` and `scope_external_id`, exactly as the fixed importer now
   does at accept time. Without it, every area- or project-scoped price
   imported before the fix stays invisible to the scoped market indices.
   Only uniquely, byte-exactly resolvable rows are touched; reversing it is
   a documented no-op — nulling the ids would only re-hide those records.
11. `valuation_rule_engine` is purely additive: five new tables behind the
   question-driven portfolio valuation adjustments (versioned rule sets,
   their questions and options, owners' persisted answers, and the immutable
   per-valuation adjustment snapshots) plus four nullable columns on
   `portfolio_valuations` (`base_midpoint`, `base_low`, `base_high`,
   `adjustment_total_percent`). No existing row is touched and nothing is
   backfilled. The whole surface sits behind the
   `portfolio.valuation_rules` feature flag, which ships OFF — with the
   flag off, valuation behaviour is byte-identical to the previous release.
12. `valuation_rule_set_family_uniqueness` is purely additive and data-free:
   a generated (virtual) `project_family` column on `valuation_rule_sets` —
   `coalesce(project_id, 0)`, computed by the database, never written by
   code — plus the unique index `vrs_family_version_unique` over
   `(scope_transaction, project_family, version)`. It exists because NULL
   `project_id` rows never collide in the original unique index, so global
   (no-project) rule-set versions were only advisorily unique; with the
   family key the database itself refuses a duplicate version. No existing
   row changes and nothing behaves differently unless a duplicate — which
   could previously corrupt the version sequence — is attempted.

Copying the code without running the migrations leaves the application querying
tables and columns that do not exist. The migration step below is mandatory,
not optional.

## Verified production state (2026-08-23) — read this before every count below

The twelve files above are the release inventory, but **how many of them a
host still has to run depends on which host you are standing on**. There are
exactly two valid contexts, and every migration count in this document must be
read against the right one:

| Context | Starting ledger | Already `Ran` from the inventory | To apply | Expected increase |
|---|---|---|---|---|
| **A — sealed-v6 rehearsal baseline** (what `scripts/release/deploy_rehearsal.sh` stages on every final-release CI run) | the v6 baseline count | none | all twelve | plus twelve — the number the rehearsal script pins |
| **B — live `myhawler.com` production** (verified read-only on 2026-08-23) | **59** `Ran` rows | the **protected five** below | the **seven** below | **plus seven: 59 → 66** |

Facts captured read-only from the live application
(`/home/u730182942/domains/myhawler.com/application`): environment
`production`, Laravel `12.64.0`, PHP CLI `8.3.30`, MariaDB `11.8.8-MariaDB-log`
(satisfies the generated-column requirement of
`valuation_rule_set_family_uniqueness`, which needs MariaDB 10.2 or newer),
migration ledger `59` rows, and `knowledge_events.evidence_class` **absent** —
so `add_evidence_class_to_knowledge_events` may legitimately run again after
its earlier recovery rollback.

**The protected five — already `Ran` on the live host. They belong to the
recovery history, not to this deployment, and they are OUTSIDE this release's
normal rollback scope. Never reverse them as part of this release:**

```text
2026_08_06_000100_telegram_return_handoffs
2026_08_09_000100_telegram_verification_tokens
2026_08_09_000200_password_recovery_challenges
2026_08_09_000200_profile_optional_details
2026_08_09_000300_add_last_seen_to_users
```

**The seven this release applies to the live host** (the three Aug-16/17 files
were applied once and rolled back during the production recovery, so they are
legitimately pending again; the last four have never run there):

```text
2026_08_16_000100_add_evidence_class_to_knowledge_events
2026_08_17_000100_backfill_knowledge_event_search_keys
2026_08_17_000200_backfill_offer_search_keys
2026_08_19_000100_whatsapp_account_verification
2026_08_21_000100_backfill_price_record_scope_ids
2026_08_22_000100_valuation_rule_engine
2026_08_22_000200_valuation_rule_set_family_uniqueness
```

One expected wrinkle: the CURRENT production source predates the seven files,
so before §7 copies the new code they do not appear in `migrate:status` at all
— not even as `Pending`. That is correct. They resolve as `Pending` only once
the new source is in place, which is why §8 re-checks them right before
migrating.

The rehearsal keeps context A on purpose — it proves the whole inventory
against the sealed baseline — and nothing in `deploy_rehearsal.sh`,
`rollback_rehearsal_v7.sh` or their CI gates changes for context B. On the
live host, use the context-B numbers in §3, §8, §12 and §15, and STOP the
moment reality disagrees with them. `artisan migrate` exiting 0 is not the
success condition; the reconciled ledger arithmetic is.

**Registration now requires a password**, and `/login` accepts a **phone number
or an email**. No existing account is altered by this and no existing
credential stops working; see §7 of
`docs/simplified-telegram-verification.md` for the existing-user matrix.
**Verification is a choice of two doors**: registration lands on
`/account/verify`, offering the existing Telegram Start and a WhatsApp
one-time code through Bird. Either door verifies the same account and one
success is enough. WhatsApp appears only when Bird is configured on the host
— with no Bird credentials the page offers Telegram and marks WhatsApp
unavailable, which is a correct state, not an error.
Password recovery over Telegram is a separate mechanism from every
verification, link and handoff token. The investment map (`/invest`) ships
behind the `map.investment` feature flag, which is **off** in the shipped
configuration — the routes deploy now and the surface is enabled by
configuration when the operator decides.

**This patch also DELETES a file.** A ZIP overlay cannot remove anything, so the
deletion is applied from `DELETE_FILES.txt` in step 6.

**Do not deploy automatically.** Deploy when you can watch it.

## 0. Paths

```text
Project root:  ~/domains/myhawler.com
Application:   ~/domains/myhawler.com/application
Public root:   ~/domains/myhawler.com/public_html
CLI PHP:       /opt/alt/php83/usr/bin/php
```

Use the CLI PHP path explicitly. Hostinger's default shell `php` is not
necessarily the version the site runs.

## 1. Verify the artifact before touching anything

```bash
cd ~
sha256sum -c myhawler-account-first-registration-corrected-runtime.zip.sha256
```

Stop if this fails. A mismatched archive is not a deployment candidate and every
later step assumes this one passed.

## 2. Mandatory backups

```bash
TS=$(date +%Y%m%d-%H%M%S)
cd ~/domains/myhawler.com
mkdir -p ~/patch-backup-$TS

cp -a application/app               ~/patch-backup-$TS/app
cp -a application/lang              ~/patch-backup-$TS/lang
cp -a application/config            ~/patch-backup-$TS/config
cp -a application/routes            ~/patch-backup-$TS/routes
cp -a application/bootstrap/app.php ~/patch-backup-$TS/bootstrap-app.php
cp -a public_html/build             ~/patch-backup-$TS/build
cp -a application/composer.json     ~/patch-backup-$TS/composer.json
cp -a application/composer.lock     ~/patch-backup-$TS/composer.lock
cp -a application/vendor            ~/patch-backup-$TS/vendor

mysqldump -u <db_user> -p <db_name> > ~/patch-backup-$TS/database.sql
```

All ten are mandatory. The Composer trio — `composer.json`, `composer.lock`
and the WHOLE `vendor/` directory — is backed up as ONE unit, because this
release replaces the dependency tree and a rollback must be able to put the
old code back together with the old dependencies: old code under a new
vendor, or the reverse, is precisely the code/dependency mismatch this
release closes. The WHOLE application-code directory is backed up —
this release touches several modules (Identity, Geography, Core, Projects) and
deletes a file, and a per-module selection tuned to one release is exactly how
a rollback ends up missing the directory it needs. `routes` and
`bootstrap/app.php` are included because the patch changes both, and the
rollback must be able to restore them: reverted code with the patched routes
still in place points at controllers that no longer exist. The database dump
is mandatory because this release migrates.

## 3. Record the migration count BEFORE anything changes

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan migrate:status | grep -c Ran
```

Write the number down — call it `BEFORE`. What it must be depends on the
context table at the top: on the sealed-v6 rehearsal baseline it is the
baseline count and §8 proves plus twelve; on **live production it must be
exactly `59`** and §8 proves plus seven. If the live count is anything other
than `59`, STOP — the host does not match the verified 2026-08-23 state and
the reconciliation must be redone before anything changes.

```bash
/opt/alt/php83/usr/bin/php artisan migrate:status \
  | grep -E 'telegram_return_handoffs|telegram_verification_tokens|password_recovery_challenges|profile_optional_details|add_last_seen_to_users|add_evidence_class_to_knowledge_events|backfill_knowledge_event_search_keys|backfill_offer_search_keys|whatsapp_account_verification|backfill_price_record_scope_ids|valuation_rule_engine|valuation_rule_set_family_uniqueness'
```

What to expect, by context:

- **Sealed-v6 rehearsal baseline:** nothing, or lines reading `Pending`. If
  any already says `Ran` there, the staged copy is not the sealed baseline;
  stop and investigate.
- **Live production:** exactly **five** lines, all `Ran` — the protected five
  (`telegram_return_handoffs`, `telegram_verification_tokens`,
  `password_recovery_challenges`, `profile_optional_details`,
  `add_last_seen_to_users`) — and nothing else, because the old source does
  not yet carry the seven new files. **If any of the protected five is NOT
  `Ran`, STOP.** Do not migrate, do not "fix" it by rolling anything back:
  live accounts depend on those five, and a missing one means the host does
  not match the verified state. If any of the seven ALREADY shows here
  before §7 copied the new code, stop the same way — something else deployed
  them.

Still before anything changes, prove the two remaining preconditions on the
live host (both verified true on 2026-08-23 — this re-proves them on
deployment day):

```bash
/opt/alt/php83/usr/bin/php artisan tinker --execute="
  echo Schema::hasColumn('knowledge_events', 'evidence_class') ? 'UNEXPECTED evidence_class — STOP' : 'evidence_class absent OK', PHP_EOL;
  echo DB::select('select version() as v')[0]->v, PHP_EOL;
"
```

Expect `evidence_class absent OK` (the Aug-16 file was rolled back during the
production recovery and may legitimately run again — but if the column is
somehow present, its re-run fails on a duplicate column: STOP and reconcile
first) and a MariaDB version of 10.2 or newer (verified: `11.8.8-MariaDB-log`),
which the generated family-key column requires.

## 4. Stage the runtime in a NEW directory

```bash
mkdir -p ~/patch-v7 && cd ~/patch-v7
unzip -q ~/myhawler-account-first-registration-corrected-runtime.zip
ls application public_html/build/manifest.json DELETE_FILES.txt \
   application/composer.lock application/vendor/autoload.php
```

The last two matter: this runtime SHIPS its dependency tree. A staged patch
without `application/vendor/autoload.php` is an old-format artifact from
before the dependency-parity fix and must not be deployed.

Never unzip over the live tree. Staging is what makes rollback possible.

## 5. Maintenance mode

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan down --render="errors::503"
```

Enter maintenance **before** any file or schema changes. Half-applied code
serving live traffic is the failure this prevents.

## 6. Apply the deletions

```bash
cd ~/domains/myhawler.com/application

while IFS= read -r rel; do
    case "$rel" in ''|\#*) continue ;; esac
    case "$rel" in /*|*..*) echo "REFUSED unsafe path: $rel"; continue ;; esac
    rm -f "./$rel"
    [ -e "./$rel" ] && echo "FAILED to delete: $rel" || echo "deleted: $rel"
done < ~/patch-v7/DELETE_FILES.txt
```

The two `case` guards are the point: only relative, allow-listed paths inside
the application directory are ever removed. Anything absolute or containing
`..` is refused rather than deleted.

## 7. Copy the runtime files

```bash
cd ~/domains/myhawler.com

cp -a ~/patch-v7/application/app/.    application/app/
cp -a ~/patch-v7/application/lang/.   application/lang/
cp -a ~/patch-v7/application/config/. application/config/

# This release's delta reaches outside app/: the Telegram password-recovery
# routes live in routes/auth.php and the presence middleware is registered in
# bootstrap/app.php. Copying app/ alone deploys controllers whose routes never
# arrive.
cp -a ~/patch-v7/application/routes/. application/routes/
cp -a ~/patch-v7/application/bootstrap/app.php application/bootstrap/app.php

# The Composer trio travels with the code, from the SAME checksum-verified
# staged runtime. The vendor tree is REPLACED, never merged — a merged vendor
# keeps orphaned classes beside a new autoloader the same way a merged build
# directory keeps stale chunks. NO Composer runs on this host, no package
# registry is contacted, and `composer update` must never be typed here: the
# dependency bytes below were installed --no-dev from the frozen lock inside
# the release cycle and are exactly what the rehearsal tested.
cp -a ~/patch-v7/application/composer.json application/composer.json
cp -a ~/patch-v7/application/composer.lock application/composer.lock
rm -rf application/vendor
cp -a ~/patch-v7/application/vendor application/

# REPLACED, never merged: Vite content-hashes filenames, so a merged directory
# leaves old chunks beside a new manifest and the browser requests assets the
# manifest never names.
rm -rf public_html/build
cp -a ~/patch-v7/public_html/build public_html/
```

Then prove the deployed dependency state BEFORE anything executes on it —
byte-equal lock, the production tree (never the CI dev tree), and the locked
CommonMark rather than the superseded 2.8.3 production was caught holding:

```bash
cd ~/domains/myhawler.com
cmp application/composer.lock ~/patch-v7/application/composer.lock \
  && echo "lock OK" || echo "LOCK MISMATCH — STOP"
[ -d application/vendor/phpunit ] \
  && echo "DEV TREE DEPLOYED — STOP" || echo "production tree OK"
/opt/alt/php83/usr/bin/php -r '
  require "application/vendor/autoload.php";
  echo "league/commonmark: ",
      \Composer\InstalledVersions::getPrettyVersion("league/commonmark"), PHP_EOL;'
```

Expect `lock OK`, `production tree OK`, and the CommonMark version the staged
lock names — never `2.8.3`. Any other outcome: STOP, do not continue to §8,
and restore per `ROLLBACK_NOTES.md` — the dependency state is part of the
release, not an accessory.

## 8. Run the migration — mandatory

The new source is in place now (§7), so on the live host the seven release
migrations must resolve as `Pending` — no more, no fewer — before anything
runs:

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan migrate:status \
  | grep -E 'add_evidence_class_to_knowledge_events|backfill_knowledge_event_search_keys|backfill_offer_search_keys|whatsapp_account_verification|backfill_price_record_scope_ids|valuation_rule_engine|valuation_rule_set_family_uniqueness'
```

Live production: exactly **seven** lines, each `Pending`. (Sealed-v6
rehearsal baseline: these seven plus the other five all read `Pending`.) Any
of the seven missing means §7 copied an incomplete tree; any already `Ran`
means something else migrated this host — STOP either way.

```bash
/opt/alt/php83/usr/bin/php artisan migrate --force
```

Then prove exactly what you expect:

```bash
/opt/alt/php83/usr/bin/php artisan migrate:status \
  | grep -E 'telegram_return_handoffs|telegram_verification_tokens|password_recovery_challenges|profile_optional_details|add_last_seen_to_users|add_evidence_class_to_knowledge_events|backfill_knowledge_event_search_keys|backfill_offer_search_keys|whatsapp_account_verification|backfill_price_record_scope_ids|valuation_rule_engine|valuation_rule_set_family_uniqueness'
```

Expect **twelve** lines, each reading **`Ran`** — in both contexts, but for
different reasons the arithmetic below tells apart: on the live host the
protected five were already `Ran` in §3 and only the seven moved
`Pending` → `Ran` now; on the sealed-v6 rehearsal baseline all twelve moved.

```bash
/opt/alt/php83/usr/bin/php artisan migrate:status | grep -c Ran
```

Live production: expect exactly **`BEFORE + 7`** — with the verified starting
ledger, **`59 + 7 = 66`**. (Sealed-v6 rehearsal baseline: `BEFORE`
plus twelve, the number `deploy_rehearsal.sh` pins.) Both directions matter:
a smaller increase means one of the seven never arrived, while a larger one
means something unintended came along. Either way STOP and look — do not
continue merely because `artisan migrate` exited 0, and do not "correct" a
shortfall by rolling anything back: the protected five must remain `Ran`
throughout.

Then prove the verification table is really there and really has no expiry
column, because the whole "your link never expires" promise rests on it — and
that the recovery table and the presence column arrived with it:

```bash
/opt/alt/php83/usr/bin/php artisan tinker --execute="
  echo Schema::hasTable('telegram_verification_tokens') ? 'table OK' : 'TABLE MISSING', PHP_EOL;
  echo Schema::hasColumn('telegram_verification_tokens', 'expires_at') ? 'UNEXPECTED expires_at' : 'no expiry column OK', PHP_EOL;
  echo Schema::hasTable('password_recovery_challenges') ? 'recovery table OK' : 'RECOVERY TABLE MISSING', PHP_EOL;
  echo Schema::hasColumn('users', 'date_of_birth') ? 'profile columns OK' : 'PROFILE COLUMNS MISSING', PHP_EOL;
  echo Schema::hasColumn('users', 'last_seen_at') ? 'presence column OK' : 'PRESENCE COLUMN MISSING', PHP_EOL;
  echo Schema::hasTable('whatsapp_otps') ? 'whatsapp table OK' : 'WHATSAPP TABLE MISSING', PHP_EOL;
  echo Schema::hasColumn('users', 'whatsapp_verified_at') ? 'whatsapp column OK' : 'WHATSAPP COLUMN MISSING', PHP_EOL;
  echo Schema::hasTable('valuation_rule_sets') ? 'valuation rules tables OK' : 'VALUATION RULES TABLES MISSING', PHP_EOL;
  echo Schema::hasColumn('portfolio_valuations', 'base_midpoint') ? 'valuation base columns OK' : 'VALUATION BASE COLUMNS MISSING', PHP_EOL;
  echo Schema::hasColumn('valuation_rule_sets', 'project_family') ? 'valuation family key OK' : 'VALUATION FAMILY KEY MISSING', PHP_EOL;
"
```

Expect `table OK`, `no expiry column OK`, `recovery table OK`,
`profile columns OK`, `presence column OK`, `whatsapp table OK`,
`whatsapp column OK`, `valuation rules tables OK`, `valuation base columns OK`,
`valuation family key OK`. The recovery table intentionally
DOES carry an expiry — its challenges die in fifteen minutes — which is the
designed asymmetry between the permanent verification link and the short-lived
recovery credential.

## 9. Verify the manifest resolves

```bash
cd ~/domains/myhawler.com/public_html/build
/opt/alt/php83/usr/bin/php -r '
$m = json_decode(file_get_contents("manifest.json"), true);
$missing = 0;
foreach ($m as $e) { if (isset($e["file"]) && !file_exists($e["file"])) { $missing++; } }
echo $missing === 0 ? "manifest OK\n" : "MISSING: $missing\n";'
```

Anything other than `manifest OK` means an incomplete copy. **Do NOT continue to
§13** — §13 lifts maintenance and would expose the incomplete release. Stay in
maintenance mode and go to §15 failure handling, which routes to
`ROLLBACK_NOTES.md`.

## 10. Rebuild caches

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan package:discover
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan route:clear
/opt/alt/php83/usr/bin/php artisan view:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:cache
```

`package:discover` comes FIRST and is not optional: the vendor tree changed
in §7, and the package manifest in `bootstrap/cache` was written for the old
one. It is the offline stand-in for the Composer post-autoload-dump hook the
production dependency build deliberately skipped, and it runs entirely
against the shipped vendor — no network. A cached route table from before
the patch keeps serving the old registration behaviour.

## 11. Routes, middleware, scheduler, queue

```bash
/opt/alt/php83/usr/bin/php artisan route:list | grep account/registration/abandon
/opt/alt/php83/usr/bin/php artisan route:list | grep account/return
/opt/alt/php83/usr/bin/php artisan route:list | grep forgot-password/telegram
/opt/alt/php83/usr/bin/php artisan route:list | grep invest/features
/opt/alt/php83/usr/bin/php artisan schedule:list | grep prune-unlinked
/opt/alt/php83/usr/bin/php artisan schedule:list | grep prune-return-handoffs
/opt/alt/php83/usr/bin/php artisan schedule:list | grep recovery:prune
/opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --max-time=50
```

Expect the abandon route, both return routes, the Telegram recovery route, the
investment-map route (present even while its feature flag is off — the flag
gates requests, not registration), and **all three** scheduled cleanups.
Middleware aliases are proven behaviourally in §12: an unresolvable alias
returns 500, so a working gated redirect proves they resolved.

Confirm the single cron entry is unchanged:

```text
* * * * * cd ~/domains/myhawler.com/application && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

See what the cleanups would do, without doing it:

```bash
/opt/alt/php83/usr/bin/php artisan mulkihawler:accounts:prune-unlinked --dry-run
/opt/alt/php83/usr/bin/php artisan mulkihawler:telegram:prune-return-handoffs --dry-run
```

On a site that has never run account-first, both report zero.

## 12. Offline gate — maintenance mode STAYS ON

Everything above (§6–§11) runs behind maintenance mode and must all pass before
the site is exposed to anyone. Confirm each, in order:

```text
runtime checksum verified                     §1
all ten backups taken and readable            §2
deletions applied and verified absent         §6
composer.json, composer.lock and vendor
    replaced from the staged runtime          §7
deployed lock byte-equal, production tree,
    locked CommonMark (never 2.8.3)           §7
package manifest rediscovered (shipped
    vendor) before caches                     §10
protected five already Ran; seven Pending     §3/§8
the seven release migrations moved to Ran     §8
ledger: live 59 -> 66 exactly (plus seven);
    sealed-v6 rehearsal: plus twelve          §8
verification table present, NO expires_at     §8
recovery table + presence column present      §8
whatsapp table + whatsapp column present      §8
valuation rules tables + base columns present §8
valuation family key present                  §8
public/build manifest resolves                §9
caches rebuilt without error                  §10
abandon, return, recovery, invest routes      §11
all three cleanups scheduled; dry runs zero   §11
queue drains                                  §11
```

Do not continue while any of these is unresolved. A failure here costs nothing:
the site is still showing the maintenance page and the previous release is
intact.

**Do not run HTTP smoke tests yet.** A Laravel application under `artisan down`
returns 503 to every public request, so `GET /` cannot return 200 while
maintenance is on. Any procedure that expects a 200 before `artisan up` is
describing something that cannot happen — earlier revisions of this document
did exactly that, and the rehearsal quietly did something different.

## 13. Lift maintenance — the controlled online phase begins here

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan up
```

From this moment the release is live and the clock is running. Have
`ROLLBACK_NOTES.md` open before you type this. Do not walk away between §13 and
§14.

## 14. Online smoke tests — run these immediately

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://myhawler.com/
curl -sS -o /dev/null -w '%{http_code}\n' https://myhawler.com/register
ASSET=$(curl -sS https://myhawler.com/ | grep -o 'build/assets/[^"]*' | head -1)
curl -sS -o /dev/null -w '%{http_code}\n' "https://myhawler.com/$ASSET"
```

Expect 200, 200, 200. The third one matters: a manifest that resolves on disk
(§9) does not prove the asset is actually reachable over HTTP.

Then the full journey by hand, with a spare number:

```text
1. open /register, choose العربية, fill the form INCLUDING a password, submit
2. you land on /ar/account/verify — the verification choice
3. the page is right-to-left and shows: تم إنشاء حسابك بنجاح
4. the page offers Telegram; WhatsApp appears only if Bird is configured on
   this host, and with no Bird credentials it must read as unavailable —
   never as an error page
5. choose Telegram; you land on /ar/account/telegram/link
6. press Open Telegram, then /start in the bot
7. the chat says to confirm in your browser, with a العودة إلى MyHawler
   button. Opening that button in a DIFFERENT browser must show the neutral
   "go back to your original tab" page — it must not sign anyone in.
8. back in the original tab, the candidate appears on its own; confirm it
9. the chat confirms success with a العودة إلى MyHawler button. Opening THAT
   button in a different browser signs you in once and lands on
   /ar/account/onboarding
10. reopening the same button a second time shows the neutral expired page
```

Step 9 is the handoff migration's payload: if the handoff table is missing,
this is where it fails. If Bird IS configured, verify the WhatsApp door with a
second spare number afterwards: request the code, type it back, and confirm it
lands on onboarding — either door alone must be enough.

## 15. Success criteria and failure handling

**Success — all true:**

```text
every offline gate in §12 passed
home 200, /register 200, referenced asset 200
the §14 walkthrough completes in Arabic, including the cold-browser handoff
the one-time handoff is genuinely one-time (step 10 shows the expired page)
```

Only then is the deployment complete. Leave the site up and watch the error log
for a few minutes.

**Failure during the OFFLINE phase (§1–§12)** — maintenance is still on, so
nothing is user-visible:

```text
checksum mismatch at §1               -> do not proceed; the artifact is wrong
a protected migration not Ran at §3   -> STOP; reconcile first — NEVER roll the
                                         protected five back to "fix" it
live ledger not exactly 59 at §3      -> STOP; the host does not match the
                                         verified state
live increase smaller than seven      -> a table or column is missing; roll back
                                         THIS release's migrations only (§ROLLBACK,
                                         production scope)
live increase larger than seven       -> stop and investigate before continuing
(sealed-v6 rehearsal: the same two rules with twelve)
expires_at present on the token table -> wrong migration ran; roll back
manifest reports MISSING              -> incomplete copy; roll back
lock mismatch, dev tree, or wrong
    CommonMark at the §7 check        -> the dependency state is wrong; roll
                                         back — never "fix" it with composer
                                         on this host
routes or schedules absent            -> incomplete copy; roll back
```

**Failure during the ONLINE phase (§13–§14)** — the site is live and broken.
Take it down first, then roll back:

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan down --render="errors::503"
```

```text
5xx on / or /register                -> down, then roll back
referenced asset 404                 -> down, then roll back
registration does not reach the
    verification choice              -> down, then roll back
choosing Telegram does not reach
    the linking page                 -> down, then roll back
the cold-browser handoff fails       -> down, then roll back
```

Do not debug on a live site while a working release sits one restore away. Go
straight to `ROLLBACK_NOTES.md`; the failed release is preserved there for
diagnosis afterwards. Never leave a failed release serving traffic while you
investigate.

# ROLLBACK_NOTES.md — MyHawler v7 release

This procedure is designed to be run under `env -i` — no inherited environment,
no warmed cache, nothing left from the deployment — because that is the
situation a rollback happens in: somebody opens a new SSH session on a site that
is misbehaving. A procedure that only works with the deployer's shell still open
is not a procedure.

> **Rehearsal status.** `scripts/release/rollback_rehearsal_v7.sh` executes
> this exact procedure on every final-release CI run, reversing the real
> deployed tree the deployment rehearsal produced. Its raw output and check
> counts ship in the external evidence package, which is the authoritative
> record for this document.

**This patch migrates.** It added nine tables — `telegram_return_handoffs`,
`telegram_verification_tokens`, `password_recovery_challenges`,
`whatsapp_otps`, and the valuation rule engine's five (`valuation_rule_sets`,
`valuation_questions`, `valuation_question_options`,
`portfolio_property_answers`, `portfolio_valuation_adjustments`) — plus four
nullable columns on `users` (`gender`, `date_of_birth`, `last_seen_at`,
`whatsapp_verified_at`), one nullable column on `knowledge_events`
(`evidence_class`), four nullable columns on `portfolio_valuations`
(`base_midpoint`, `base_low`, `base_high`, `adjustment_total_percent`), one
generated family-key column with its unique index on `valuation_rule_sets`
(`project_family`, `vrs_family_version_unique`), and
three data-only backfills (two search keys and the price-record scope ids)
whose reversal is a documented no-op. That
matters for the ORDER below, and it is the one thing people get wrong:
restoring a `mysqldump` does **not** drop a table created after that dump was
taken. Restoring the dump alone leaves the tables behind and reverted code
running against a schema it does not know. Each migration must be rolled back
deliberately, and its file must still be on disk when you do it.

**Reversal order is newest first**, which is also the order they are listed in
§5. The added tables hold foreign keys pointing outward (to `users`,
`projects` and the portfolio tables); nothing pre-existing depends on them,
so each drops cleanly.

> **What rolling back costs the customer.** The paragraph below describes the
> FULL twelve-migration reversal — the sealed-v6 rehearsal context. On the
> live host, the production-scope reversal (the seven only — see the scope
> section below) keeps the verification links, recovery challenges and
> presence column: those live in the protected five, which stay `Ran`. The
> live-host costs are the WhatsApp and valuation items, called out in the
> scope section. Reverting removes the permanent
> verification links. Anyone who registered during the patched period and has
> not yet pressed START loses their link — the reverted code has no table to
> read it from — and their account reverts to the old ten-minute, session-bound
> flow. Those accounts keep their password rows harmlessly (the column is
> pre-existing and nullable), but the old `/login` only accepts an email, so a
> customer who registered with a phone and no email cannot sign in on the
> reverted build. §12 covers how to find them. Any in-flight Telegram
> password-recovery challenge dies with its table — the person simply requests
> a reset again under whatever flow the reverted build offers — and the
> presence timestamps the patched period collected are dropped with their
> column. The WhatsApp door goes with the patch too: any in-flight one-time
> code dies with the `whatsapp_otps` table, and an account that verified
> through WhatsApp alone loses its verified status on the reverted build —
> the reverted code recognises only `telegram_verified_at`.

Written to be followed by someone who was not present for the deployment.

## Production rollback scope (verified 2026-08-23) — read this first

The twelve-file inventory above describes the SEALED-V6 REHEARSAL context:
`rollback_rehearsal_v7.sh` reverses all twelve against the staged baseline,
where this patch really did apply all twelve, and that rehearsal invariant is
unchanged. **Live `myhawler.com` is a different context.** The read-only
verification of 2026-08-23 confirmed the ledger held `59` `Ran` rows and that
the **protected five** below were already `Ran` there BEFORE this release —
they belong to the earlier recovery history, live accounts depend on them, and
they are **OUTSIDE this release's normal rollback scope**:

```text
2026_08_06_000100_telegram_return_handoffs
2026_08_09_000100_telegram_verification_tokens
2026_08_09_000200_password_recovery_challenges
2026_08_09_000200_profile_optional_details
2026_08_09_000300_add_last_seen_to_users
```

A rollback of THIS release on the live host may reverse **only the seven it
applied** — newest first, exactly the first seven path-targeted commands in
§5, ending at `add_evidence_class_to_knowledge_events`:

```text
2026_08_22_000200_valuation_rule_set_family_uniqueness
2026_08_22_000100_valuation_rule_engine
2026_08_21_000100_backfill_price_record_scope_ids
2026_08_19_000100_whatsapp_account_verification
2026_08_17_000200_backfill_offer_search_keys
2026_08_17_000100_backfill_knowledge_event_search_keys
2026_08_16_000100_add_evidence_class_to_knowledge_events
```

Preferred order of response on the live host, before any of §5 runs:
maintenance mode first, then **code rollback alone** — every one of the seven
is additive (new tables, nullable columns, a generated column and index on a
table this release created), so the previous build runs safely against the
migrated schema and reversing the database is usually unnecessary. Reverse
migrations only when actually required, and then only the seven, path by
path. Never use a broad `php artisan migrate:rollback`, `--step=N` or batch
rollback on the live host: the last batch is not guaranteed to contain only
this release, and a broad walk can reach the protected five. Reversing any of
the protected five is never part of this release's rollback — if someone
believes one of them must come out, that is a separate, explicitly authorized
emergency with its own plan, not a step in this document.

What the seven cost to reverse, before you reverse them: the valuation
reversal drops rule definitions, owners' stored answers and the per-valuation
adjustment snapshots created during the patched period; the WhatsApp reversal
kills in-flight codes and strips WhatsApp-only accounts of their verified
status (§2 counts them first); the three data-only backfills reverse as
documented no-ops — the derived values stay, only the ledger rows un-mark.

## 1. Identify and verify the backups, knowing nothing

```bash
cd ~/domains/myhawler.com
ls -dt ~/patch-backup-* | head -5
```

Pick the newest backup that predates the deployment, then set it once:

```bash
BACKUP=~/patch-backup-YYYYmmdd-HHMMSS
ls "$BACKUP"    # expect: app lang config routes bootstrap-app.php build
                #         composer.json composer.lock vendor database.sql
sha256sum "$BACKUP/build/manifest.json"
```

Record that manifest hash; §8 checks against it. If no such directory exists the
deployment never reached its file-copy step, the old release is still live, and
there is nothing to roll back — investigate `~/patch-v7` instead.

## 2. Count what the patched period created

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan tinker --execute='echo App\Modules\Identity\Models\User::whereNull("telegram_verified_at")->whereNull("whatsapp_verified_at")->whereNull("deleted_at")->count();'
```

Write the number down. Both verification doors exist in the patched schema, so
"never verified" means BOTH stamps are null. These are account-first
registrations that never verified; §11 decides what happens to them, and after
a database restore you cannot count them any more.

Count the WhatsApp-only group separately — the people this rollback strips a
verified status from:

```bash
/opt/alt/php83/usr/bin/php artisan tinker --execute='echo App\Modules\Identity\Models\User::whereNull("telegram_verified_at")->whereNotNull("whatsapp_verified_at")->count();'
```

They stay verified until §5 runs; afterwards the reverted build treats them as
unverified, and support needs this list more than any other.

## 3. Maintenance mode FIRST

```bash
/opt/alt/php83/usr/bin/php artisan down
```

Before any schema or file change. Everything below assumes the site is down.

## 4. Verify this patch's migrations are currently Ran

```bash
/opt/alt/php83/usr/bin/php artisan migrate:status \
  | grep -E 'telegram_return_handoffs|telegram_verification_tokens|password_recovery_challenges|profile_optional_details|add_last_seen_to_users|add_evidence_class_to_knowledge_events|backfill_knowledge_event_search_keys|backfill_offer_search_keys|whatsapp_account_verification|backfill_price_record_scope_ids|valuation_rule_engine|valuation_rule_set_family_uniqueness'
```

Expect **twelve** lines, each `Ran` — in both contexts. On the live host that
is the protected five (which were `Ran` before this release and MUST stay
`Ran`) plus the seven this release applied; only those seven are in scope for
§5. Any of the seven that says `Pending` or is absent never applied: skip it
in §5 and §6, but check why the deployment reported success. Any of the
protected five not `Ran` is a different problem entirely — stop and
investigate; nothing in this document reverses or repairs those five.

## 5. Roll back THAT migration, while its file still exists

`migrate:rollback` needs the migration file, and §7 is about to remove it. Roll
back one step and then prove it was the right one — never assume the last batch
contained only what you think it did:

Use a **path-targeted** rollback. `--step=1` reverses whatever the last batch
happens to contain, and if anything ran after this patch it reverses that
instead — detected only after the destructive command has already executed.
Naming the file removes the guess:

```bash
cd ~/domains/myhawler.com/application

# Prove the state BEFORE anything is reversed:
/opt/alt/php83/usr/bin/php artisan migrate:status \
  | grep -E 'telegram_return_handoffs|telegram_verification_tokens|password_recovery_challenges|profile_optional_details|add_last_seen_to_users|add_evidence_class_to_knowledge_events|backfill_knowledge_event_search_keys|backfill_offer_search_keys|whatsapp_account_verification|backfill_price_record_scope_ids|valuation_rule_engine|valuation_rule_set_family_uniqueness'   # expect twelve x Ran
/opt/alt/php83/usr/bin/php artisan migrate:status | grep -c Ran                                # record this number

# Newest first. Each is path-targeted and independently verifiable. The three
# data-only backfills (the two search keys and the price-record scope ids)
# reverse as documented no-ops (the derived values stay, by design), so
# reversing them only un-marks the migration rows. The family-uniqueness
# reversal drops only its own unique index and generated column — no data
# lives in either. The valuation rule engine
# reversal drops its five additive tables and four additive columns — rule
# definitions, stored answers and adjustment snapshots created during the
# patched period go with them, which is the documented cost of this rollback.
#
# ===================== PRODUCTION SCOPE STARTS HERE =====================
# The next SEVEN commands are this release's rollback on the live host —
# everything this deployment applied, newest first, and nothing else.
/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Portfolio/Database/Migrations/2026_08_22_000200_valuation_rule_set_family_uniqueness.php \
  --force

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Portfolio/Database/Migrations/2026_08_22_000100_valuation_rule_engine.php \
  --force

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Market/Database/Migrations/2026_08_21_000100_backfill_price_record_scope_ids.php \
  --force

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Identity/Database/Migrations/2026_08_19_000100_whatsapp_account_verification.php \
  --force

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Marketplace/Database/Migrations/2026_08_17_000200_backfill_offer_search_keys.php \
  --force

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Knowledge/Database/Migrations/2026_08_17_000100_backfill_knowledge_event_search_keys.php \
  --force

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Knowledge/Database/Migrations/2026_08_16_000100_add_evidence_class_to_knowledge_events.php \
  --force

# ================ PRODUCTION SCOPE ENDS HERE — STOP ====================
# On the live host, the rollback of THIS release is COMPLETE at this point.
# The five commands below reverse the PROTECTED five. They exist for the
# sealed-v6 rehearsal (rollback_rehearsal_v7.sh reverses the full inventory
# against the staged baseline, where this patch applied all twelve) and for
# a host that verifiably received all twelve FROM this patch. On
# myhawler.com the five predate this release, live accounts depend on them,
# and running any of the five commands below there is NOT a rollback of
# this release — it is an unauthorized reversal of the recovery history.
# =======================================================================

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Identity/Database/Migrations/2026_08_09_000300_add_last_seen_to_users.php \
  --force

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Identity/Database/Migrations/2026_08_09_000200_profile_optional_details.php \
  --force

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Identity/Database/Migrations/2026_08_09_000200_password_recovery_challenges.php \
  --force

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Identity/Database/Migrations/2026_08_09_000100_telegram_verification_tokens.php \
  --force

/opt/alt/php83/usr/bin/php artisan migrate:rollback \
  --path=app/Modules/Identity/Database/Migrations/2026_08_06_000100_telegram_return_handoffs.php \
  --force
```

`--realpath` is not needed: the path is resolved relative to the application
root. Verified against Laravel 12 / MariaDB 10.11.

The command prints `Migration not found` for other members of the batch. That
is informational, not an error — Laravel walks the batch but can only resolve
files under `--path`, and it does not reverse the ones it cannot resolve.

Prove the state AFTER:

```bash
/opt/alt/php83/usr/bin/php artisan migrate:status \
  | grep -E 'telegram_return_handoffs|telegram_verification_tokens|password_recovery_challenges|profile_optional_details|add_last_seen_to_users|add_evidence_class_to_knowledge_events|backfill_knowledge_event_search_keys|backfill_offer_search_keys|whatsapp_account_verification|backfill_price_record_scope_ids|valuation_rule_engine|valuation_rule_set_family_uniqueness'
/opt/alt/php83/usr/bin/php artisan migrate:status | grep -c Ran
```

What to expect, by context:

- **Live host, production scope (the seven):** the seven read `Pending`, the
  protected five STILL read **`Ran`**, and the count dropped by **exactly
  seven** — back to `59`. A protected migration reading `Pending` here means
  a command below the STOP divider was run: stop everything and get help
  before touching anything else.
- **Sealed-v6 rehearsal (all twelve):** twelve x `Pending`, count exactly
  twelve fewer.

If the count dropped by more than the context's number, an unrelated
migration was reversed: stop, re-apply with `migrate --force`, and get help
rather than continuing to unwind migrations blindly.

Running the same command a second time is a safe no-op — it exits 0 and the
count stays where it is.

## 6. Prove the tables and columns are gone

```bash
mysql -u <db_user> -p -N -B <db_name> -e "
  SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('telegram_return_handoffs','telegram_verification_tokens','password_recovery_challenges','whatsapp_otps','valuation_rule_sets','valuation_questions','valuation_question_options','portfolio_property_answers','portfolio_valuation_adjustments');
  SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name IN ('gender','date_of_birth','last_seen_at','whatsapp_verified_at');
  SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'knowledge_events'
      AND column_name = 'evidence_class';
  SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'portfolio_valuations'
      AND column_name IN ('base_midpoint','base_low','base_high','adjustment_total_percent');
"
```

What to expect, by context:

- **Live host, production scope (the seven reversed):** `3`, `3`, `0`, `0` —
  the three Telegram tables (`telegram_return_handoffs`,
  `telegram_verification_tokens`, `password_recovery_challenges`) REMAIN, and
  on `users` the three protected columns (`gender`, `date_of_birth`,
  `last_seen_at`) REMAIN while `whatsapp_verified_at` is gone. Anything less
  than `3`/`3` means a protected migration was reversed — stop and get help.
- **Sealed-v6 rehearsal (all twelve reversed):** `0`, `0`, `0` and `0`.

The rehearsal failed on exactly this class of check until the ordering above
was corrected, which is why it is a separate, explicit step.

## 7. Restore the application, keeping the failed release

```bash
TS=$(date +%Y%m%d-%H%M%S)
mkdir -p ~/failed-$TS
cp -a ~/domains/myhawler.com/application/app ~/failed-$TS/app

cd ~/domains/myhawler.com
rm -rf application/app
cp -a "$BACKUP/app" application/app

rm -rf application/lang
cp -a "$BACKUP/lang" application/lang

rm -rf application/config
cp -a "$BACKUP/config" application/config

rm -rf application/routes
cp -a "$BACKUP/routes" application/routes

cp -a "$BACKUP/bootstrap-app.php" application/bootstrap/app.php

# The Composer trio comes back as ONE unit with the code — old code must
# never run under the release's new dependency tree, and old dependencies
# must never sit under the new code. The vendor tree is REPLACED, never
# merged, and no Composer runs on this host in either direction.
cp -a "$BACKUP/composer.json" application/composer.json
cp -a "$BACKUP/composer.lock" application/composer.lock
rm -rf application/vendor
cp -a "$BACKUP/vendor" application/vendor

cmp application/composer.lock "$BACKUP/composer.lock" \
  && echo "restored lock OK" || echo "RESTORED LOCK MISMATCH — STOP"

# The restored code needs its package manifest rebuilt against the RESTORED
# vendor — offline, exactly as the deployment did against the shipped one.
/opt/alt/php83/usr/bin/php application/artisan package:discover
```

The WHOLE application-code directory moves, mirroring the backup: this release
touches several modules and deletes a file, and a whole-directory restore is
the only shape that undoes both without a per-release list. `routes` and
`bootstrap/app.php` must come back with it — the patched routes point at
controllers the restored code no longer defines, so leaving either behind is
the half-rollback where `route:cache` crashes on a class that does not exist.
Configuration is restored because the patch changed two config files that
reverted code never defined. The dependency trio must come back with it for
the same reason in the other direction: the release replaced `vendor/`, and
restored code over the new tree is the exact code/dependency mismatch the
dependency-parity fix exists to close.

Keep the failed release. It costs nothing and it is the only evidence of what
went wrong.

## 8. Restore the public build — replaced, never merged

```bash
cd ~/domains/myhawler.com
rm -rf public_html/build
cp -a "$BACKUP/build" public_html/build

sha256sum public_html/build/manifest.json
```

This must equal the hash recorded in §1. A merged directory is the classic
half-rollback: the old manifest with new chunks still beside it, so the browser
requests assets the restored manifest never names.

## 9. Database restore decision

The schema is already correct after §5 — the table is gone and nothing else
changed. So this decision is about DATA, not schema:

| Situation | Action |
|---|---|
| Rolling back within minutes; §2 count near zero | **Do not restore.** Keep the data; handle stranded accounts per §11. |
| Account-first ran long enough to create real accounts and you want the database exactly as it was | Restore. Every account created since the deployment disappears. |
| The unlinked-account cleanup ran and reclaimed rows you want back | Restore. |

If restoring:

```bash
mysql -u <db_user> -p <db_name> < "$BACKUP/database.sql"
```

Restore the dump taken immediately before the deployment; a newer one contains
the state you are leaving. In the rehearsal the restore was exercised and the
stranded count afterwards was 0, because restoring the dump removes exactly
those accounts.

## 10. Rebuild caches

```bash
cd ~/domains/myhawler.com/application
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan route:clear
/opt/alt/php83/usr/bin/php artisan view:clear
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:cache
```

## 11. Verify routes, middleware, scheduler and queue — CLI only, still offline

Maintenance mode is still ON for all of this. These are CLI checks only.

```bash
/opt/alt/php83/usr/bin/php artisan route:list | wc -l
/opt/alt/php83/usr/bin/php artisan route:list | grep account/registration/abandon   # expect NOTHING
/opt/alt/php83/usr/bin/php artisan route:list | grep account/return                 # expect NOTHING
/opt/alt/php83/usr/bin/php artisan route:list | grep forgot-password/telegram       # expect NOTHING
/opt/alt/php83/usr/bin/php artisan route:list | grep invest/features                # expect NOTHING
/opt/alt/php83/usr/bin/php artisan route:list | grep account/verify                 # expect NOTHING
/opt/alt/php83/usr/bin/php artisan route:list | grep account/telegram/link          # expect the route
/opt/alt/php83/usr/bin/php artisan schedule:list | grep prune-unlinked              # expect NOTHING
/opt/alt/php83/usr/bin/php artisan schedule:list | grep prune-return-handoffs       # expect NOTHING
/opt/alt/php83/usr/bin/php artisan schedule:list | grep recovery:prune              # expect NOTHING
/opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --max-time=50
```

The "expect nothing" checks are what prove the patch is genuinely gone rather
than partially removed.

**No HTTP checks here.** The site returns 503 to every request while `artisan
down` is in force, so a `curl` expecting 200 at this point cannot succeed. The
public checks move to §14, after maintenance is lifted.

## 12. Unlinked accounts from the patched period

If you did **not** restore the database, the accounts counted in §2 still exist:
unlinked, each holding its phone number under a unique index. Under the reverted
code they cannot finish registration through the form, and there is no scheduled
cleanup any more — it went with the patch.

Two groups, and the second is new to this patch:

- accounts from **before** the patch: no password, reachable only from a browser
  session that no longer exists;
- accounts registered **during** the patched period: they have a password, but
  their permanent verification link died with the table in §5, and the reverted
  `/login` only accepts an **email** — which these accounts do not have. So they
  are unreachable too, for a different reason. Accounts that verified through
  WhatsApp alone land in this group as well once §5 drops their column: to the
  reverted build they are indistinguishable from accounts that never verified.

Count both, and count the second group separately, because they are the people
most likely to contact support:

```bash
/opt/alt/php83/usr/bin/php artisan tinker --execute='
  echo App\Modules\Identity\Models\User::whereNull("telegram_verified_at")
        ->whereNotNull("password")->whereNull("email")->count(), PHP_EOL;'
```

```bash
/opt/alt/php83/usr/bin/php artisan tinker --execute='echo App\Modules\Identity\Models\User::whereNull("telegram_verified_at")->whereNull("deleted_at")->count();'
```

Options, in order of preference:

1. **Leave them and tell support.** They can still finish through
   `/account/telegram/link`, which the reverted code also serves, if the person
   still holds their browser session.
2. **Release the numbers by hand** for anyone who contacts support.
3. **Restore the database** (§9) if the count is large and none of them matter.

Do not leave this undecided: each row silently blocks a real phone number from
registering.

## 13. Lift maintenance — the online phase begins here

Every offline check above must have passed first: migration reversed, table
absent, application and build restored, caches rebuilt, CLI checks clean.

```bash
/opt/alt/php83/usr/bin/php artisan up
```

## 14. Public rollback smoke — run immediately

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://myhawler.com/
curl -sS -o /dev/null -w '%{http_code}\n' https://myhawler.com/register
ASSET=$(curl -sS https://myhawler.com/ | grep -o 'build/assets/[^"]*' | head -1)
curl -sS -o /dev/null -w '%{http_code}\n' "https://myhawler.com/$ASSET"
```

Expect 200, 200, 200. A working gated redirect also proves `account.active` and
`telegram.linked` resolved, because an unresolvable alias returns 500.

Then confirm the **previous** registration flow is live again:

```text
open /register, submit with a spare number
expect: the Telegram pending screen — NOT /account/telegram/link
expect: no account is created by the form
```

**If the public smoke fails, go back to maintenance mode before anything else:**

```bash
/opt/alt/php83/usr/bin/php artisan down
```

A half-restored site serving traffic is worse than a site that is honestly
down. Diagnose from behind the maintenance page, using the failed release
preserved in §7.

### Rollback success criteria

```text
all ten migrations reversed; the four tables, the four users columns and the evidence-class column absent
restored manifest hash equals the §1 hash; every entry resolves
route:list shows NO abandon, return, recovery, verify-choice or invest routes
schedule:list shows NONE of the three cleanups
queue worker runs
home 200 and a referenced asset 200
/register shows the Telegram pending screen and creates no account
the §2 count has been acted on per §12
```

These are the conditions the rehearsal asserts on every final-release CI run —
see the rehearsal status note at the top of this document. The raw
`rollback-rehearsal.log` and `rollback-rehearsal.json` for the release you are
rolling back travel in that release's external evidence package; cite those,
not output from any earlier round.

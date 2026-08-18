# Mulkihawler 4.0

Sorani-first real estate market intelligence for Erbil. Laravel 12 · PHP 8.3 ·
Vue 3 + Inertia + TypeScript · MySQL 8 · modular monolith.

> **Build status: `4.0.0-step30`** — the version declared by
> `config/mulkihawler.php`, which is the single source of truth for the
> release identity.
> All twenty modules carry an implementation; fifteen own migrations.
> **Verification totals are not repeated here.** They change with every test
> added, and a number copied into a README goes stale silently — this block
> carried counts that had been wrong for several rounds before anyone noticed.
> The current, generated figures live in
> [`docs/VERIFICATION.md`](docs/VERIFICATION.md), which is produced from
> recorded command output, and the gate-by-gate verdict is in
> [`docs/RELEASE_DECISION.md`](docs/RELEASE_DECISION.md).
> The installer steps that once returned HTTP 501 (migrate, seed, storage link,
> cache, health check, complete and lock) are implemented — see
> `app/Modules/Install/Services/InstallRunner.php`, which documents why they
> waited for the backup and rollback that make them safe.
> **Read [`docs/ROADMAP_STATUS.md`](docs/ROADMAP_STATUS.md) before deploying
> anything.** It states exactly what has been executed and what has not.

---

## Principles this codebase enforces mechanically

These are not conventions to remember; each one has a guard that fails the
build.

| Principle | Enforced by |
| --- | --- |
| Sorani is the authoring language, not a translation target | `scripts/lang-parity.php --strict` in CI. `fallback_locale` is `ckb`, so a missing key renders the key — never silent English. |
| Money is never a float | `App\Modules\Core\ValueObjects\Decimal` (bcmath). `Decimal::toFloat()` is named to be conspicuous at a call site. |
| An unknown feature flag is OFF | `FeatureFlagResolver::resolve()` returns `false` for anything not in `config/features.php`. |
| No secret reaches a repository or a browser | `scripts/secret-scan.php` in CI; `HandleInertiaRequests` shares only settings marked public and not secret. |
| Every migration is reversible | `scripts/migration-guard.php` — a missing or empty `down()` fails the build, and a destructive operation in `up()` needs an explicit acknowledgement comment. |
| Audit rows cannot be edited | `AuditLog` throws on `updating` and `deleting`; the table has no `updated_at`. |
| Administrators must use MFA | `EnsureMfaConfirmed` gates every admin route in three states, bound to the session id. |

---

## Layout

```
app/
  Modules/<Domain>/          one of twenty domains, each self-contained
    Models/ Services/ Policies/ Http/ Jobs/ Events/
    Database/Migrations/     discovered by convention
    Routes/{web,api,admin,console}.php
  Http/Middleware/           cross-cutting only
  Support/helpers.php        settings(), feature(), decimal(), is_rtl()
bootstrap/providers.php      explicit, ordered module registration
config/                      + mulkihawler.php, features.php, installer.php,
                               localization.php
lang/{ckb,ar,en}/            ckb is authored first
scripts/                     lang-parity, secret-scan, migration-guard
tests/Standalone/            runs with no vendor directory
docs/
```

A module needs exactly one line in `bootstrap/providers.php`. Migrations,
routes and translations are found by `ModuleServiceProvider` by convention;
`Routes/admin.php` is automatically wrapped in `web + auth + mfa`.

---

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm ci && npm run dev
```

Then visit `/install` for the guided installer, or sign in directly if you
seeded an administrator.

### Verification that needs no dependencies

```bash
php tests/Standalone/run.php
php scripts/lang-parity.php --strict
php scripts/secret-scan.php
php scripts/migration-guard.php
```

### Full check

```bash
composer ci    # pint --test, phpstan, lang parity, phpunit
```

---

## Deployment

Hostinger shared hosting is the target. See
[`docs/HOSTINGER_DEPLOYMENT.md`](docs/HOSTINGER_DEPLOYMENT.md) for the split
layout, the single cron entry, and why cache, session and queue all run on the
database.

---

## Roadmap

| Step | Scope | State |
| --- | --- | --- |
| 1 | Foundation | Delivered |
| 2 | Geography and projects | Delivered |
| 3 | Market intelligence | Delivered |
| 4 | AI advisor | Delivered |
| 5 | Companies, marketplace, advertising | Delivered |
| 6 | Portfolio, leads, notifications | Delivered |
| 7 | Content, knowledge, imports, analytics | Delivered |
| 8 | Installer completion, backup, rollback, release | Delivered |

All twenty modules under `app/Modules/` carry an implementation; fifteen own
migrations. The earlier edition of this table still described steps 2-7 as
"registered, empty" while the header of this same file claimed a fully verified
build — two statements about the same tree that could not both be true.

For gate-by-gate status see [`docs/RELEASE_DECISION.md`](docs/RELEASE_DECISION.md),
which is generated from recorded evidence rather than written by hand.

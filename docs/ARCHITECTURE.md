# Architecture

A modular monolith. One deployable, twenty internally-isolated domains. Chosen
over microservices because the deployment target is shared hosting with a
single PHP process and no orchestration, and over a conventional flat Laravel
app because the spec describes twenty domains that will be built across eight
steps by a small team.

## Adding a module

1. Create `app/Modules/<Domain>/` with the standard subdirectories.
2. Extend `ModuleServiceProvider`, implement `moduleName()`.
3. Add one line to `bootstrap/providers.php`.

That is the whole procedure. The base class discovers by convention:

| Path | Loaded as |
| --- | --- |
| `Database/Migrations/` | Migrations |
| `Routes/web.php` | `web` middleware |
| `Routes/api.php` | `api`, prefixed `/api/v1`, named `api.v1.` |
| `Routes/admin.php` | `web + auth + mfa`, prefixed `/admin`, named `admin.` |
| `Routes/console.php` | Scheduled commands |
| `Resources/lang/` | Namespaced translations |
| `Config/config.php` | Merged as `modules.<domain>` |

Every load is guarded by `is_dir` / `is_file`, which is why the fourteen empty
Step 2+ modules boot without error.

`Routes/admin.php` getting `auth + mfa` automatically matters: it means an
administrative route cannot be exposed by forgetting a middleware, which is the
usual way admin surfaces leak.

## Cross-module rules

A module may **not** import another module's Eloquent models. It reads through
a contract resolved from the container:

```php
// Core declares
interface SettingsRepository { ... }

// Branding implements and binds
$this->app->singleton(SettingsRepository::class, ...);

// Any module consumes
settings('branding.site_name');
```

`Core` holds contracts and value objects and depends on nothing.
`Branding`, `Identity`, `Localization` and `Operations` depend only on `Core`.
Everything else may depend on those five.

Boot order in `bootstrap/providers.php` is explicit and ordered rather than
auto-discovered, because `Core` must bind the settings and feature-flag
contracts before any module reads them during its own registration.

## The morph map

`CoreServiceProvider` calls `Relation::enforceMorphMap()`. Polymorphic columns
store `'user'`, not `App\Modules\Identity\Models\User`. Two consequences:

- Renaming or moving a class never orphans a polymorphic row.
- A database dump does not leak internal namespaces.

Entries are **appended, never renamed** once a release has shipped. The stored
string is data.

## Value objects

`Decimal` is the only numeric type permitted for money, area, index values and
anything derived from them. It is immutable, backed by bcmath, and rounds
half-up.

`SoraniText` is stateless. Its two entry points are not interchangeable:

- `normalize()` — canonical cleanup, safe to persist, idempotent, never changes
  a letter that carries meaning in Sorani.
- `searchKey()` — aggressive folding for the index, changes meaning by design,
  must never be written back over source text.

## Feature flags

Precedence: database override → config default → **OFF**.

The third rule is the point. An unknown flag — a typo, a flag from a future
step referenced early, a stale name after a rename — resolves to OFF. The
resolution logic lives in `FeatureFlagResolver` with no storage dependency so
it can be tested exhaustively without a database, and so the rules cannot drift
between the admin UI, the middleware and the seeder.

Flags in `features.requires_super_admin` are gated in **both** directions:
disabling `advertising` mid-campaign has contractual consequences, so it needs
the same authority as enabling it.

## Audit

`AuditLogger` never throws into the caller's transaction. An audit write that
fails is loud in the security log but must not roll back the business action
that succeeded — losing an audit row is bad; silently losing a project publish
because of it is worse.

`AuditLog` itself throws on `updating` and `deleting`, and the table has no
`updated_at` column. An audit row that can be edited is not an audit row.

Actor name is denormalised into `actor_label` so the log stays readable after
an account is erased under a data-rights request.

## Request pipeline

```
EnsureInstalled          before anything that touches the database
  → SetLocale            url > user > session > browser > default
  → RecordAuditContext   X-Request-Id for log correlation
  → HandleInertiaRequests
```

`EnsureInstalled` runs first because on a fresh upload there is no schema, no
`APP_KEY` and no session table; any database-touching middleware would fatal
with a driver error instead of showing the installer.

In `SetLocale`, session sits **above** browser. A visitor whose phone reports
`ar` but who tapped "کوردی" must stay in Sorani on the next page; letting the
`Accept-Language` header win would silently undo an explicit choice.

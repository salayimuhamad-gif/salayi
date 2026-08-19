# Admin capability matrix — Super Admin audit and repair

The requirement-by-requirement comparison of `PermissionRegistry::catalogue()`
(one hundred and seven permissions) against the implemented Admin Panel:
routes, middleware, controllers, Inertia pages, navigation and frontend
conditions. Produced for the Super Admin visibility audit; kept because the
category-4 list below is the honest statement of what the catalogue promises
that the product does not yet implement.

The verdicts here are enforced by `tests/Feature/SuperAdminCoverageTest.php`
(the coverage matrix), `tests/Feature/AdministratorRolesTest.php` and
`tests/Feature/SystemSettingsTest.php` — a capability that regresses from
category 1 fails the build, it does not fail silently.

## Root causes found (why the Super Admin panel was incomplete)

1. **The roles machinery did not exist.** `UsersController` administers
   ordinary members only and 404s every role-holding target "administered
   through the roles machinery" — while `identity.roles.view`,
   `identity.roles.assign`, and the rest of the operator lifecycle had no
   route, page or action anywhere. A Super Admin could not list operators,
   see roles, or change an assignment. **Fixed**: the
   `/admin/administrators` surface (`AdministratorsController`) implements
   listing, role assignment, suspension, reactivation and session revocation
   end-to-end, with escalation and lockout guards.
2. **A broken navigation link.** The sidebar's Ratings entry pointed at
   `admin.projects.ratings.index` — a per-project route
   (`admin/projects/{project}/ratings`) no sidebar link can generate a URL
   for. **Fixed**: entry removed; ratings are reached from the project they
   belong to, which already links them.
3. **Imports was invisible on exactly the terms that made it reachable.** The
   entry sat under the Market group, whose parent requires the
   `market.intelligence` flag AND `market.prices.view` — while the import
   routes require neither. A Super Admin lost the entry whenever the flag was
   off (the default), and a GIS/Places Manager never saw it at all.
   **Fixed**: top-level entry gated exactly like its routes.
4. **Place categories was never linked.** `admin.places.categories.index` is
   an implemented page reachable only by typing the URL. **Fixed**: child
   entry under Geography.
5. **The System group's parent gate was stricter than its children.** The
   parent required `system.settings.view`, hiding the Users/Audit children
   from operators who hold identity or audit permissions without the settings
   one. **Fixed**: parent has no own permission; it renders exactly when a
   child does.
6. **Feature flags default off and used to gate admin surfaces for everyone,
   including Super Admin** — with defaults, the Market, Marketplace,
   Companies and Places sections vanished from a Super Admin's panel
   entirely. **Fixed, with the flag's real meaning preserved**: a flag is a
   LAUNCH switch for the public product, not an authorisation. A Super Admin
   now sees every implemented admin section regardless of flag state, and
   the admin routes behind a disabled flag admit a Super Admin as an audited
   preview (`feature.preview_while_disabled` security event on every use).
   Ordinary administrators remain flag-gated exactly as before, and the
   PUBLIC and API surfaces of a disabled feature stay dark for everyone —
   including Super Admin — until the flag is explicitly enabled on the
   Features page.
7. **A frontend super-admin heuristic.** `FeatureFlags.vue` derived "is super
   admin" from `permissions.length > 0 && is_admin` — true for every
   administrative user — so super-admin-only toggles rendered usable for a
   System Admin and failed server-side on click. **Fixed**: the shared auth
   payload now carries a real `is_super_admin` boolean.
8. **One route/permission mismatch.** Import rollback's route accepted
   `imports.run` while its button required `imports.rollback`; the declared
   rollback permission authorised nothing. **Fixed**: the route now requires
   `imports.rollback` (a tightening — the run-but-not-rollback role was never
   shown the action it could technically call).
9. **Roughly forty declared permissions have no implemented feature** — see
   category 4. They are not hidden from Super Admin; they do not exist. No
   placeholder UI was built for them.

## Category 1 — implemented in backend AND reachable in the Admin UI

Everything below is routed, permission-gated, linked from navigation (or from
its owning page where it is contextual), and answers a Super Admin. The
coverage matrix asserts this list stays true.

- **system**: `settings.view/update` (Settings page),
  `features.view/update` (Feature flags page), `integrations.update`
  (Settings page, Super Admin only by design — credentials).
- **identity**: `users.view/suspend/contact/update` (member accounts surface),
  `roles.view/assign` (administrators surface — new),
  `sessions.revoke` (both surfaces).
- **audit**: `logs.view` (audit log page with facets).
- **branding**: `settings.view/update` (branding page, incl. asset upload).
- **geography**: `areas.view/create/update` (+ `areas.publish` enforced on
  the transition), `places.view/create/update` and place categories.
- **projects**: `view/create/create_scoped/create_unscoped/update`,
  `publish` (transition + button), `media.manage` (wizard),
  `ratings.update` (per-project ratings page), `developers.manage`.
- **market**: `prices.view/approve`, `indices.view/configure` (+ index value
  publishing under `configure`).
- **knowledge**: `events.view/create/publish` (+ `review` enforced on the
  transition).
- **content**: `view/create/update/publish`.
- **companies**: `view/create/update/update_own/verify/associations.manage`
  and the company↔developer queue (under `projects.create_unscoped`).
- **marketplace**: `offers.view/moderate/manage_own` (+ media queue and
  moderation).
- **leads**: `view/assign/contact` (sales workspace).
- **imports**: `view/run/rollback` (price imports).

## Category 2 — existed in backend, was missing from navigation/UI (repaired)

| Capability | Repair |
|---|---|
| `identity.roles.view` / `identity.roles.assign` | Whole surface implemented: `/admin/administrators` + promotion from the member page |
| `/admin/imports/prices` | Top-level nav entry matching its routes |
| `/admin/places/categories` | Geography child entry |
| System children for non-settings holders | Parent gate loosened to its children |

## Category 3 — had UI that was incorrectly hidden, broken or mismatched (repaired)

| Defect | Repair |
|---|---|
| Ratings sidebar link could never generate its URL | Entry removed; per-project entry path kept |
| Super-admin-only flag toggles mislabelled for other admins | Real `is_super_admin` shared prop |
| Rollback route accepted `imports.run` | Requires `imports.rollback`, as the button always said |

## Category 4 — declared permissions with NO implemented feature (no UI built, by instruction)

These names exist in the catalogue so roles and reviews can reference a
stable vocabulary before the feature ships. Nothing routes them; the
`system` group's Health page covers the operational *viewing* needs of some.

- **system**: `integrations.view` (folded into `settings.view`'s page),
  `backups.view`, `backups.run`, `backups.restore`, `maintenance.toggle`,
  `releases.view`, `queue.view`, `queue.retry` (the Health page shows queue
  depth and failures under `settings.view`; no retry action exists).
- **identity**: `users.create` (accounts exist only through registration),
  `users.delete` (no deletion workflow; suspension is the lever),
  `consents.view` (consent state appears on the member page; no dedicated
  register).
- **audit**: `logs.export`, `audit.security.view` (security events land in
  the same audit table; no separate view or export).
- **branding**: `assets.view`, `assets.revert`, `pwa.update` (the branding
  page uploads under `settings.update`; no revert history, no PWA editor).
- **localization**: all seven (`translations.view/update/review/publish`,
  `glossary.view/update`, `languages.toggle`) — no translation management UI
  exists; languages are enabled via System Settings under `settings.update`.
- **geography**: `places.verify`, `places.import` (verification happens via
  the update transition; no dedicated import).
- **projects**: `archive` (no archive transition surface).
- **market**: `prices.import` (imports run under the `imports.*` family),
  `indices.publish` (publishing runs under `configure`),
  `methodology.update`.
- **knowledge**: — (all four implemented or enforced on transitions).
- **content**: `schedule` (no scheduling fields).
- **companies**: `subscriptions.manage`, `subscriptions.view` (no
  subscription surface).
- **marketplace**: `offers.publish`, `offers.archive` (both happen through
  the moderate/manage_own transition).
- **advertising**: all four — no advertising module surfaces exist.
- **leads**: `export`, `score.override`.
- **advisor**: all four — the advisor is a public surface; no admin review UI.
- **analytics**: all three (the admin dashboard renders under its own
  identity gating; no dedicated analytics pages).
- **imports**: `approve` (the accept step runs under `run`).

## Security boundaries preserved (and two tightened)

- `Gate::before` + `hasPermission()` remain the single Super Admin source of
  truth; nothing checks a user id, email or other production identity.
- MFA, `account.active`, CSRF and session semantics untouched — asserted by
  the coverage tests (an unenrolled Super Admin still lands in MFA setup, a
  suspended one is logged out at the door).
- **Administrative role mutation is a rank, not a permission**: only a real
  Super Admin may grant or remove ANY administrative role. A System Admin
  legitimately holds `identity.roles.assign` and may view the role map, but
  cannot assign roles to anyone — including themselves — because
  accumulating Product Owner or Market Data Manager grants widens effective
  permissions one role at a time. Refusals are security-audited.
- **Every mutating action against a Super Admin target is rank-guarded**:
  role edits, suspension, reactivation and forced logout all refuse a
  non-Super-Admin actor (audited `identity.administrators.escalation_denied`),
  and the UI never offers those controls on a super-admin row to a lesser
  administrator.
- The last active, unsuspended Super Admin cannot lose the role or be
  suspended: the lockout answer is a validation error naming the remedy.
- The administrators payload is phone-free and pinned by test; the settings
  page ships `*_configured` booleans and never credential values, also
  pinned.
- No default passwords are created and none can be revealed. **Operational
  recovery for an administrator who lost every credential path** (password +
  Telegram + email): a one-time CLI reset on the server sets a NEW, properly
  hashed password —
  `php artisan tinker --execute="App\Modules\Identity\Models\User::query()->whereKey(<id>)->first()->forceFill(['password' => Illuminate\Support\Facades\Hash::make('<new strong password>')])->save();"`
  — then rotate it immediately through the profile, and audit the event by
  hand. The existing password is never recoverable, by design.

## Production deployment

This change ships through the repository's standard release engineering
(`DEPLOYMENT_NOTES.md` / final-release workflow), not by hand. Its own delta
is code-only: **no migrations, no config keys, no environment changes**.
Affected paths: `app/` (Identity controller + routes, Core navigation,
Imports routes, HTTP middleware), `lang/` (three locales), `resources/js/` +
`public/build` (rebuilt assets), `tests/`, this document. After deploy,
`route:cache` must be rebuilt (the notes' §10 already requires it). Roles
need no seeding step — the assignment path creates registry-defined role rows
on first use.

Rollback: restore the previous release per `ROLLBACK_NOTES.md`; there is no
schema to reverse. Role assignments made through the new surface live in the
pre-existing `role_user` table and survive rollback harmlessly.

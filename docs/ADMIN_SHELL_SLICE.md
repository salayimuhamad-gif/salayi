# Admin shell slice — acceptance criteria and status

The first production-quality vertical slice: administrative shell, navigation,
and authentication. Built after the eight-step roadmap closed.

## Verification — what actually ran

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 244/244 |
| **`vue-tsc --noEmit`** | **PASS** — 0 errors |
| **`vite build`** | **PASS** — 26 artifacts, 263 KB bundle (92 KB gzipped), PWA service worker generated |
| Standalone logic suite | **PASS** — 1,028 assertions |
| Translation parity | **PASS** — 565 keys, ckb/ar/en |
| Secret scan | **PASS** |
| Migration guard | **PASS** |

**npm is reachable in this environment even though Packagist is not.** That is
new: the frontend has now been genuinely type-checked and compiled, which no
step of the eight-step roadmap could do. The PHP side remains unexecuted.

## Acceptance criteria

| # | Criterion | Status |
| --- | --- | --- |
| 1 | Sorani is the default language | **Met** — `ckb` is default and fallback; `t()` falls through to the key, never English |
| 2 | Arabic and English optional | **Met** — 565 keys in parity; locale switcher in both layouts |
| 3 | Full RTL/LTR support | **Met in source** — logical properties throughout (`ms-`/`me-`/`ps-`/`start-`), `dir` resolved server-side to avoid a flash, toggle knob travel mirrored, email/password inputs forced LTR inside RTL pages. **Not visually confirmed** |
| 4 | Luxury, modern, responsive | **Met in source** — indigo/brass palette, Noto Kufi Arabic as the primary face, 8-point spacing, calm easing, sidebar collapses under `lg`. **Not visually confirmed** |
| 5 | Role-based navigation | **Met** — `AdminNavigation::for()` filters server-side; an unreachable section is absent from the payload, not hidden |
| 6 | Authentication | **Met in source** — login, logout, password reset, session regeneration, throttling, uniform error messages |
| 7 | Administrator MFA | **Met in source** — enrolment, challenge, recovery codes shown once, session-bound marker |
| 8 | Roles, permissions, feature flags | **Met** — flag screen with Super-Admin gating; permission-gated routes |
| 9 | Branding settings | **Met in source** — name, taglines ×3, palette, PWA identity, versioned asset slots |
| 10 | Loading / empty / error / mobile / dark / reduced-motion states | **Met** — `AppSkeleton`, `AppEmptyState`, `AppAlert`, responsive sidebar, `useTheme`, `motion-safe:` on the only animation |
| 11 | No placeholder-only implementation | **Partially** — see gaps |
| 12 | Migrations, seeders, tests, documentation | **Met** — no new migrations needed; `PlaceCategorySeeder` added earlier; 12 feature tests written |

## Database changes

**None.** Every table this slice needs was created in Step 1 — `users`, `roles`,
`role_user`, `sessions`, `password_reset_tokens`, `site_settings`,
`feature_flags`, `branding_assets`, `audit_logs`, `scheduler_heartbeats`. That
the shell required no schema change is the clearest evidence the Step 1
foundation was scoped correctly.

## Remaining issues

1. **No screenshots.** Rendering needs a booted Laravel serving Inertia
   responses; Composer is unreachable. The frontend compiles, but nothing has
   drawn a pixel. I will not simulate them.
2. **The PHP side is unexecuted.** Controllers, middleware and the 12 feature
   tests are written and linted; none has run.
3. **Navigation targets are stubs.** Most items point at `admin.dashboard`
   because their pages do not exist yet. The tree, permissions and flags are
   real; the destinations are not.
4. **No super-admin seeder.** The installer collects the first administrator at
   step 16 and the seed step creates them, but that path has never run — so
   there is currently no way to obtain a login without inserting a row by hand.
5. **Branding asset upload has no preview or revert UI.** The backend versions
   assets correctly and the slot list renders; the interface to replace or roll
   back one is not built.
6. **Recovery codes cannot be regenerated.** Shown once at enrolment, by
   design; no route exists to issue a fresh set.

## Rollback

This slice is additive. Nothing existing was deleted, and no migration was
added, so rollback is a file operation:

```bash
# Remove the slice
rm -rf resources/js/Components/ui resources/js/Layouts/AdminLayout.vue \
       resources/js/Layouts/AuthLayout.vue resources/js/Pages/Auth \
       resources/js/Pages/Admin resources/js/Composables/useTheme.ts \
       resources/js/lib/i18n.ts resources/js/Types/ziggy.d.ts
rm -rf app/Modules/Identity/Http/Controllers/Auth \
       app/Modules/Identity/Http/Controllers/Admin \
       app/Modules/Branding/Http/Controllers
rm -f  app/Modules/Core/Support/AdminNavigation.php routes/auth.php \
       app/Modules/Identity/Routes/admin.php app/Modules/Branding/Routes/admin.php \
       tests/Feature/AdminShellTest.php lang/*/nav.php lang/*/admin.php

# Revert the two edited files
git checkout -- bootstrap/app.php app/Http/Middleware/HandleInertiaRequests.php \
                resources/js/Types/inertia.d.ts resources/js/Pages/Public/Offline.vue
npm run build
```

**Do not revert `resources/js/Pages/Public/Offline.vue` blindly.** It carries an
independent bug fix — see below.

## A latent bug this slice exposed

`Public/Offline.vue` has called `window.location.reload()` from its template
since Step 1. A Vue template resolves identifiers against the component
instance, so `window` is not in scope and the retry button could never have
worked. Eight steps of `php -l` could not see it; the first `vue-tsc` run found
it immediately.

That is the concrete argument for running the toolchain rather than trusting
static review, and it is the same argument that applies to the still-unexecuted
PHP.

## Ready for the next slices

- `AdminLayout` takes a `#title` slot and renders flash state, so a project CRUD
  page needs no shell work.
- `AdminNavigation` already declares the projects and geography subtrees behind
  their permissions; a real route replaces `admin.dashboard` in one line each.
- `AppCard`, `AppInput`, `AppButton`, `AppToggle`, `AppSkeleton`,
  `AppEmptyState` and `AppAlert` cover the form and list surfaces a project
  wizard needs.
- The public project profile can reuse `PublicLayout`, the branding tokens and
  the `.numeral` bidi isolation without change.

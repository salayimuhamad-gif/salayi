# Company administration

The prerequisite for the marketplace, and the first time three Step 5 guards
face a request.

## What this exercises that had never run

**Verification as a separate act.** Its own permission, its own endpoint, its
own audit entry at warning severity. A company showing as verified on a project
page is a claim buyers act on — it cannot be a side effect of an editor
correcting a description. Asserted: an editor with full company-edit rights and
no `companies.verify` gets a 403.

**Admin-controlled associations** (spec 37.4). `CompanyScope::mayGrantProjectAssociation()`
was written in Step 5 and never called. It is now the gate on the only route
that can create one, so a company cannot grant itself "official developer".

**The disclosure guard.** `CompanyProjectAssociation::saving()` throws when a
sponsored or advertising relationship has no disclosure label. The controller
catches that and returns a field error rather than a 500, and the form shows
the field the moment either condition becomes true.

## One rule added here

**An official-status role requires a verified company.** `AssociationRole::assertsOfficialStatus()`
already distinguished official developer, official sales partner and verified
reseller from the rest; nothing enforced it. "Official developer" on a project
page from an unverified company is precisely the assertion this product cannot
make loosely, so the request refuses it and the form warns while the role is
being chosen rather than after.

## Licence handling

Two checks, both because a licence is the evidence a verifier weighs:

- **A licence number without its issuing authority is refused.** Nobody can
  check it, so it is not evidence.
- **An expired licence is refused at entry**, so a verifier is never deciding
  against a field that lapsed two years ago. The list also badges expiry beside
  the verification status rather than burying it in the record.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 301/301 |
| `vue-tsc --noEmit` | **PASS** — 0 errors |
| `vite build` | **PASS** — 47 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 837 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

133 feature tests exist; 10 are new. **None has run.**

## Remaining issues

1. **Nothing has run.**
2. **No branch administration.** `company_branches` has a schema and encrypted
   per-branch contact fields; there is no screen. Spec 37.4 requires a branch to
   have its own contact data and nothing yet lets anyone enter it.
3. **No staff management.** `company_staff` carries per-membership portal
   permissions and nothing assigns them, so no company user can exist.
4. **No company portal.** This is the admin view of a company; the company's own
   login, offer management and lead inbox are all absent.
5. **No public company profile.** Spec 18.1 lists it; associations are stored
   with display priority and nothing renders them on a project page.
6. **Logo upload is not wired**, though the branding asset pattern exists.

Item 3 blocks the portal, and the portal is where `CompanyScope`'s lead
boundary — the most consequential thing in Step 5 — would finally be exercised.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Admin/Companies
rm -f  app/Modules/Companies/Http/Controllers/Admin/CompanyController.php        app/Modules/Companies/Http/Requests/CompanyRequest.php        app/Modules/Companies/Routes/admin.php tests/Feature/CompanyAdminTest.php
git checkout -- app/Modules/Core/Support/AdminNavigation.php                 app/Http/Middleware/HandleInertiaRequests.php lang/*/companies.php
npm run build
```

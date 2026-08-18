# Project Creation Wizard — slice notes

Spec 12.1, 37.2.

## Rollback

The wizard is additive. Nothing existing changed shape, so removing it does not
touch any published project.

The Wizard-era rollback set is SIXTEEN migrations, across three modules.

The count, the table below and `RollbackWizardSchema::MIGRATIONS` are the same
list — previously they disagreed three ways at once, which is worse than any
one of them being wrong, because there was no way to tell which to believe. Several alter tables it does not own, so the
scope column matters more than the count.

| # | Module | Migration | Tables touched | Scope |
|---|---|---|---|---|
| 000300 | Projects | `create_project_drafts` | `project_drafts` | Wizard-only |
| 000400 | Projects | `complete_project_wizard` | `project_drafts`, `project_prices`, `projects` | **shared** |
| 000500 | Projects | `create_project_draft_media` | `project_draft_media` | Wizard-only |
| 000600 | Projects | `membership_project_rights` | `company_staff`, `project_draft_media` | **shared** |
| 000700 | Projects | `media_cleanup_and_association_provenance` | `project_media`, `company_project_associations` | **shared** |
| 000800 | Projects | `association_provenance` | `company_project_associations` | **shared** |
| 000900 | Projects | `creation_permission_evidence` | `company_project_associations` | **shared** |
| 001000 | Projects | `association_lifecycle_constraint` | `company_project_associations` (CHECK / triggers) | **shared** |
| 001100 | Companies | `create_company_developer_associations` | `company_developer_associations` | new domain |
| 001200 | Companies | `developer_association_lifecycle` | `company_developer_associations` | new domain |
| 001300 | Projects | `purge_state_and_orphan_outbox` | `project_drafts`, `orphaned_files` | **shared** |
| 001400 | Projects | `orphan_source_provenance` | `orphaned_files` | Wizard-only |
| 001500 | Marketplace | `offer_media_cleanup_and_moderation` | `offer_media` | **shared** |
| 001600 | Projects | `media_handoff_linkage` | `project_media`, `project_draft_media`, `offer_media` | **shared** |
| 001700 | Projects | `cleanup_job_identity` | `orphaned_files` | Wizard-only |
| 001500 | Projects | `orphan_two_phase_state` | `orphaned_files` | Wizard-only |
| 001800 | Projects | `journal_entry_idempotency` | `cleanup_journal_imports`, `orphaned_files` | Wizard-only |
| 001900 | Projects | `immutable_cleanup_incidents` | `orphaned_files`, `cleanup_journal_imports` | Wizard-only |
| 002000 | Projects | `reconcile_cleanup_ledger_schema` | `orphaned_files`, `cleanup_journal_imports` | Wizard-only |

Three migrations dated in the same window belong to OTHER work and are not part
of this rollback: `Leads/000400_create_phone_reveals`,
`Leads/000500_create_lead_notes_and_tasks`, and
`Operations/000600_add_missing_status_indexes`. Counting by date prefix would
sweep them in.

**Do NOT use `migrate:rollback --step=10`.** Laravel reverses the last ten
migrations GLOBALLY, in batch order. A single deploy is one batch, so on a real
database that command also reverses the Leads and Operations migrations listed
above — silently, and with no way to tell afterwards which went.

Reversal executes each migration's own `down()` directly rather than going
through `migrate:rollback --path`. That flag only reverses migrations in the
LATEST batch, so a Wizard migration recorded in an older batch — the normal case
on any system that has deployed since — was silently a no-op. Each migration's
schema objects are verified gone BEFORE its `migrations` row is removed, so an
interruption leaves a state a retry can resume from.

The inventory has ONE source of truth: `RollbackWizardSchema::MIGRATIONS`.
The table above, the count in prose and the command all read from it, and
`scripts/rollback-inventory.php` fails the build if they disagree — previously
three places carried three different numbers, which is worse than any single
one of them being wrong, because there was no way to know which to believe.

```bash
# Show exactly what would be reversed, and what shares a batch but will be kept.
php artisan mulkihawler:rollback-wizard --dry-run

# Reverse only the Wizard-era migrations, newest first.
php artisan mulkihawler:rollback-wizard --force
```

The command reverses a named list one migration at a time. Order is fixed:
the lifecycle constraint goes before the columns it checks, or the CHECK is
left referencing a dropped column — which some engines accept and then fail on
the next write. A failure stops with a known state rather than a half-reversed
schema, and the unrelated migrations sharing those batches are reported as
preserved.

### What must SURVIVE a rollback

- **`projects.created_by`** — created by `create_projects_tables`, not by any
  Wizard migration. 000400 does not touch it.
- **`company_project_associations.approved_by` / `approved_at`** — created by
  the base companies migration. 000800 adds only `rejected_*`, `revoked_*` and
  `created_via_project_draft_id`, and drops only those. An earlier draft of
  000800 dropped the approval columns, which would have destroyed the approval
  history of every association on the platform.

### Consequences, per area

| Reversed | Effect |
|---|---|
| `project_drafts` | All in-progress drafts lost. **Submitted projects are unaffected** — they are ordinary `projects` rows. |
| `project_prices` | Wizard-entered price ranges lost. No market index reads this table, so no index changes. |
| `project_draft_media` | Un-promoted uploads lost; **files on disk are NOT removed** by the rollback. Run `mulkihawler:prune-project-drafts` first, or the bytes are orphaned. |
| `company_staff.may_manage_projects` | Every per-membership project right is revoked. Company users lose Wizard access until it is re-granted — deliberate: the pre-migration state had no such grant. |
| `project_media.cleanup_*` | Pending cleanup flags lost; failed deletions revert to being invisible. Retry before rolling back. |
| Association provenance | `created_via_project_draft_id`, creation evidence and `rejected_*`/`revoked_*` lost. **Pending associations that relied on provenance stop being manageable**, because the evidence they depended on no longer exists. Approved associations are unaffected. |
| `management_status` | Reversed by 000700. Rows revert to `is_approved` alone, which cannot distinguish pending from rejected from revoked — review before reversing. |
| `orphaned_files` | Dropped by 001300. Any unresolved entries are lost — drain the backlog with `mulkihawler:sweep-orphaned-files --dry-run` first, or the files they name become unfindable. |
| `project_drafts.purge_status` | Dropped by 001300. A draft mid-purge reverts to ordinary and editable, which is safe: the sweep simply will not finish it. |
| Lifecycle CHECK | Dropped by 001000. Model-level guards remain; query-builder writes stop being constrained. |
| `company_developer_associations` | Dropped by 001100. Developer permission reverts to the previous derivation from existing projects — which reintroduces the first-project deadlock. Companies keep their projects; only the ability to name a developer on a NEW one is affected. |

To remove the feature entirely, also revert:

- `app/Modules/Projects/Routes/admin.php` — the six wizard routes
- `app/Modules/Projects/Http/Controllers/Admin/ProjectWizardController.php`
- `app/Modules/Projects/Models/ProjectDraft.php`
- `app/Modules/Projects/Support/WizardStep.php`
- `resources/js/Pages/Admin/Projects/Wizard.vue`
- the `wizard.creation` block in `lang/{ckb,ar,en}/projects.php`

`ProjectController@create` is untouched and remains the single-form path, so
removing the wizard leaves project creation working.

## Media durability model

Bytes are written before the row that names them exists, in three places:
draft upload, final upload, and promotion. Each has a window in which a failure
leaves a file nothing references. The mechanisms below close those windows, and
each exists because the one before it can itself fail.

**1. Compensation.** A failed database step removes the file it just wrote.

**2. `cleanup_pending`.** When the removal fails, the row survives carrying the
flag. The row is the reference; deleting it would lose the file. Retry
commands select these rows hourly.

**3. The `orphaned_files` outbox.** For the two cases the row cannot cover:

  - the compensation failed and there IS no row (the insert is what failed);
  - the row reached `CLEANUP_ATTEMPT_CEILING` (5) and the retry commands stop
    selecting it — so the row is handed over before it becomes a dead end.

The outbox is deliberately independent of every other table, because it has to
survive precisely the situations where the related row does not exist.
`mulkihawler:sweep-orphaned-files` drains it hourly, claiming rows under a lock
so overlapping runs do not fight, and exiting non-zero while work remains.

**Ordering rule.** Physical deletion always happens AFTER the database commit.
A row restored by a rolled-back transaction must never point at bytes that are
already gone: an orphaned file is a cost, a broken reference is a defect.

**Recording never throws.** `OrphanedFile::recordSafely()` is used on failure
paths — including inside `afterRollback`, where an exception would replace the
original error with a database one and lose both.

## Cleanup

`mulkihawler:prune-project-drafts` deletes drafts untouched for
`mulkihawler.wizard.draft_retention_days` (default 30, `--days` to override,
`--dry-run` to preview). Scheduled nightly at 03:20.

Two rules the command will not break: a **submitted** draft is never deleted by
age, because it is the audit trail linking a project to who entered it; and
deleting a draft never deletes its project.

## Corrected after review (round 3)

Undefined `companyIdFor()` (every wizard request was a 500) · duplicate
`projects.created_by` migration · `ProjectType` PSR-4 filename · `Project`
`$fillable` silently dropping five wizard fields · optional steps persisted
unvalidated · `AssociationRole` defaulting silently · submitted drafts
deletable · unscoped media claiming · unpublished area selectable · unvalidated
WKT · pricing payloads without `price_from`.

`tests/Standalone/StructureTest.php` now catches the first four class of defect
without a framework; it fails on the previous delivery and passes on this one.

## Association provenance

`management_status` is a PHP enum with a CHECK constraint where the engine
supports it. A PENDING association grants management rights only when it
carries `created_via_project_draft_id` pointing at a draft scoped to the acting
company, and `created_by` identifying a user who held `may_manage_projects`
there. Legacy rows backfill to `legacy_review`, which grants nothing — running
a migration must never widen authorisation.

## Delivered Wizard surface

Every required function of the slice is implemented. Recorded here so the
inventory is checkable rather than assumed:

| Function | Where |
|---|---|
| Interactive map picker, point selection | `Components/map/MapPicker.vue` |
| Latitude / longitude typed entry | same, alongside the map |
| Boundary drawing, vertex move and delete, ring delete, clear | same |
| Polygon and MultiPolygon emission and parsing | same, validated by `ValidWkt` |
| Apply Suggested Area, explicit | Wizard location step, sets `area_was_suggested` |
| Nearby-place preview, km recalculation | Wizard location step → `nearby` |
| Pricing range, currency, type, period, effective date, source, confidence | Wizard pricing step → `project_prices` |
| Asking-price qualifier notice | Wizard pricing step |
| Draft-owned media upload | `uploadMedia` → `project_draft_media` |
| Drag ordering plus keyboard-reachable arrows | Wizard media step → `updateMedia` |
| Cover selection | same, reconciled by `ProjectMediaService` |
| ckb / ar / en alt text | same |
| Ratings hand-off | Wizard review step → `/admin/projects/{id}/ratings` |
| Publication-review hand-off | Wizard review step → project edit |
| Translated review screen | Wizard review step, `reviewLabel` / `reviewValue` |
| Administrator draft listing | `Pages/Admin/Projects/DraftAdmin.vue` |
| Draft recovery and purge | `ProjectDraftAdminController` |
| Retention notice and renewal | Wizard review step → `touch` |
| Loading, empty, validation, stale-version, submitted, recoverable-error, fatal-error | Wizard.vue |
| Permission-denied, feature-disabled | `Pages/Admin/Projects/WizardUnavailable.vue` |

The map picker deliberately offers no snapping, boolean geometry or hole
cutting. This is where somebody outlines a compound in Erbil, not a CAD tool,
and each additional mode is another way to produce geometry the validator will
reject.


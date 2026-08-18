# PHPStan remediation — 4.0.0-step30

**Original findings: 1747. Final findings: 0.** Level 6, verified by the direct
`composer stan` command (exit 0, `[OK] No errors`) and by the safe
machine-readable measurement in the same working-tree state.

No baseline. No suppressions. No `@phpstan-ignore`. No excluded production
paths. No level reduction. Analyser errors: 0.

## Commands

 ```
 composer stan                      exit 0   [OK] No errors
 php scripts/phpstan-measure.php    exit 0   PHPStan findings: 0
 ```

## Closed identifiers

| Identifier | Findings closed |
| --- | --- |
| `missingType.generics` | 123 |
| `missingType.iterableValue` | 71 |
| `nullsafe.neverNull` | 69 |
| `argument.type` | 32 |
| `nullCoalesce.offset` | 27 |
| `property.notFound` | 20 |
| `assign.propertyType` | 16 |
| `identical.alwaysTrue` | 15 |
| `method.resultUnused` | 12 |
| `function.alreadyNarrowedType` | 12 |
| `arrayValues.list` | 10 |
| `method.notFound` | 9 |
| `nullCoalesce.expr` | 9 |
| `identical.alwaysFalse` | 6 |
| `notIdentical.alwaysTrue` | 4 |
| `argument.templateType` | 4 |
| `array.invalidKey` | 4 |
| `missingType.parameter` | 4 |
| `cast.string` | 4 |
| `method.alreadyNarrowedType` | 4 |
| *(remaining long tail)* | the balance to 1747 |

## Production defects found by static analysis, and their regression tests

| Defect | Effect | Test |
| --- | --- | --- |
| `Project::phases()` referenced a `ProjectPhase` model that did not exist | fatal on every call | `ProjectPhaseTest` |
| `(string) $enum` in `RatingController` / `ProjectRatingService` | fatal `Error`; ratings list and recalculation both died | ratings route + aggregation tests |
| `OfferMedia::url()` did not exist | fatal on the offer moderation queue and public browser | `MediaUrlTest` |
| `EnsureInstalled` and `InstallServiceProvider` read `env()` | with `config:cache`, an installed site redirected into the installer | `InstallSessionGuardTest` |
| `Offer::area()` relation never declared | public listings showed no area, silently | `OfferAreaRelationTest` |
| `PortfolioController` ordered by a non-existent `created_at` | valuation history query failed outright | portfolio tests |
| `AuditLog::$subject_type/$subject_id` (real columns `auditable_*`) | audit table showed an empty subject for every entry | operations tests |
| `HasCoordinates` / `HasTrilingualNames` wrote columns some models lack | save rejected by SQLite and MySQL | area/place/map tests |
| `Str::macro()` registered with a static closure | cannot be bound; fatal on `$this` use | helper tests |
| Float array keys in the map tolerance table | silently truncated to int | map explorer tests |
| Duplicate `area_assigned_at` cast in `Project` | one silently shadowed the other | project tests |

## Historical checkpoint counts

Recorded for provenance only; these are **not** current totals:
1747 → 532 → 386 → 353 → 279 → 259 → 232 → 214 → 196 → 169 → 141 → 119 → 101 → 53 → 3 → **0**.

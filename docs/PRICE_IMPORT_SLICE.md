# Price import — validator, orchestration, and a Decimal defect

Step 3's exit criterion "one year of historical data can be imported" has been
unmet since Step 3. `PriceType`, `IndexCalculator` and `OutlierDetector` were
proven across 141 assertions and nothing could feed any of them.

## The defect this uncovered

Writing the row validator surfaced a genuine bug in `Decimal::tryParse`, which
has been in the codebase since Step 1 and was never covered by a test:

```
BEFORE                          AFTER
'240k'        -> 240.0000       -> REFUSED
'about 240k'  -> 240.0000       -> REFUSED
'12abc'       -> 12.0000        -> REFUSED
'$240,000'    -> 240000.0000    -> 240000.0000   (unchanged)
'٢٤٠٬٠٠٠'      -> 240000.0000    -> 240000.0000   (unchanged)
```

`tryParse` stripped **every** non-numeric character, so a spreadsheet cell
reading `240k` imported as **240**. In a price import that silently turns a
240,000 transaction into 240 — and the result is a plausible number, not an
obvious error, so every index, valuation and market figure built on that row
would be quietly wrong with nothing to indicate it.

Currency symbols and separators still strip, because `$240,000` is a number a
human wrote. Letters no longer do: a cell containing letters is not a number the
importer should guess at, it is a cell a person needs to look at.

**The full suite still passes**, which means no existing assertion depended on
the lenient behaviour — the bug had no coverage at all.

## The validator

All twelve of spec 14.3's requirements, storage-free so preview and accept
validate identically. A preview that disagrees with the commit is how an
operator accepts rows that then fail.

Errors block; warnings do not, and the distinction is deliberate:

- **Errors**: unknown scope/property/price type, unknown currency, unparseable
  or future dates, unparseable or negative decimals, a minimum above a maximum,
  a median outside its range, an unresolvable scope, a duplicate period.
- **Warnings**: publication before effect, more sources than observations, and
  **unrealistic jumps**. A 45% move is worth attention and is not proof of a
  mistake — Erbil genuinely moves that fast, and rejecting a real move would
  corrupt the series as surely as accepting a typo. The human decides.

Jump detection groups by scope, property type, unit type **and price type**, so
an asking price is never the baseline for a verified one. That is Step 3's
separation carried into the importer, and it is asserted.

Duplicate detection catches collisions **within the same upload**, not only
against what is already stored.

## The orchestration

Three phases, separate on purpose: **preview** validates and writes no prices;
**accept** commits chosen rows; **rollback** undoes an unpublished batch.

Separating them is what makes partial acceptance possible at all. An importer
that validates and writes in one pass can only offer all-or-nothing, and an
operator facing 400 rows where 12 are wrong will either abandon the import or
accept the 12.

**Imported rows land as `draft`.** Spec 14.1's separation only holds if a human
confirms what a spreadsheet asserted; an import that publishes directly makes
the review step optional in practice.

**Rollback refuses once published.** After publication a figure may already be
in an index, a valuation, or a screenshot someone acted on. The correction is a
new batch with an audit trail, not an erasure.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 277/277 |
| Standalone logic suite | **PASS** — 1073 assertions, 0 failures |
| Translation parity | **PASS** — 705 keys |
| Secret scan / migration guard | **PASS** |

45 new assertions cover the validator.

## Remaining issues

1. **No UI.** There is no upload screen, no preview table, no accept button.
   The service is callable and nothing calls it. This is the same gap the
   nearby calculator had before its triggers slice, and it is the next thing to
   build.
2. **No spreadsheet parsing.** `preview()` takes an array of rows; nothing
   turns an `.xlsx` or `.csv` into one. `DATA_IMPORT_TEMPLATES.zip` defines the
   columns; a parser must map them.
3. **Nothing has run.** The service has never touched a database.
4. **No column mapping.** Spec 14.3 lists it; the validator expects exact
   Appendix B column names.
5. **`existingSlots()` loads every price record** to build the duplicate set.
   Fine for thousands of rows, wrong for hundreds of thousands.

## Rollback

Additive; no migration.

```bash
rm -rf app/Modules/Imports/Services app/Modules/Imports/Support
rm -f  tests/Standalone/PriceImportTest.php
git checkout -- app/Modules/Imports/Providers/ImportsServiceProvider.php                 app/Modules/Core/ValueObjects/Decimal.php
```

**Do not revert `Decimal.php` without deciding about the parsing defect
separately** — reverting restores the behaviour that turns `240k` into `240`.

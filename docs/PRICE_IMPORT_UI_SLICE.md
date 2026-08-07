# Price import UI — upload, preview, accept, roll back

Closes the gap from the previous slice: the import service was complete and
callable, and nothing could call it.

## Verification

| Check | Result |
| --- | --- |
| `php -l`, whole tree | **PASS** 284/284 |
| `vue-tsc --noEmit` | **PASS** — 0 errors, first pass |
| `vite build` | **PASS** — 41 entries |
| Standalone logic suite | **PASS** — 1090 assertions, 0 failures |
| Translation parity | **PASS** — 750 keys, ckb/ar/en |
| Secret scan / migration guard | **PASS** |

19 new assertions cover the CSV reader.

## Why CSV only

`CsvReader` uses PHP's built-in `fgetcsv`. Reading `.xlsx` needs
PhpSpreadsheet, which is a Composer dependency, and this build has never been
able to reach Packagist. CSV covers the generated templates and needs nothing —
so the constraint is stated in the UI rather than presented as a design choice.

## Two parsing details that would otherwise waste an afternoon

**The Excel BOM.** Excel writes a UTF-8 byte-order mark on the first cell. Left
in place, the first column header never matches anything, and the operator sees
"missing required field" on all four hundred rows — an error pointing nowhere
near the cause. Stripped, and asserted.

**Semicolon delimiters.** Excel on an Arabic or Kurdish Windows locale writes
semicolons, because the comma is the decimal separator there. Assuming a comma
yields one column containing the entire row. The delimiter is detected from the
header line, and both semicolon and tab files are asserted.

Neither is exotic. Both are what an Erbil operator's actual export looks like.

## Interface decisions

**The preview lives in the session, not the database.** Writing a preview would
mean price rows exist before anyone agreed to them, and a half-reviewed batch
abandoned at lunchtime would leave records nobody chose to create.

**Missing columns are answered once**, before validation runs, rather than as
the same error repeated on every row.

**Invalid rows cannot be selected.** The server re-checks regardless — posting a
row number does not force a bad row through — but offering the checkbox would
misrepresent what is possible.

**"Nothing has been written yet" is stated on the preview.** An operator who
assumes the upload already saved will not review it.

**Rollback disappears once a batch is published**, because after that a figure
may already be in an index, a valuation, or a screenshot someone acted on.

**Every row error names its field**, so a four-hundred-row file is fixable
rather than merely rejected.

## Remaining issues

1. **Nothing has run.** No file has been uploaded, no row written.
2. **No XLSX.** See above — a Composer dependency away.
3. **No column mapping.** Spec 14.3 lists it; headers must match Appendix B
   exactly. A locale-translated header row will not import.
4. **The preview is capped at 5,000 rows** and larger files are truncated with a
   warning. A full multi-year backfill needs chunked processing, which this does
   not do.
5. **No batch publication screen.** Accepted rows land as `draft` by design;
   the screen that reviews and publishes them does not exist, so imported prices
   currently stop one step short of being usable.

Item 5 is the next gap: the import now works and its output is not yet reachable
by the index engine.

## Rollback

Additive; no migration.

```bash
rm -rf resources/js/Pages/Admin/Imports
rm -f  app/Modules/Imports/Support/CsvReader.php        app/Modules/Imports/Http/Controllers/Admin/PriceImportController.php        app/Modules/Imports/Routes/admin.php        tests/Standalone/CsvReaderTest.php lang/*/imports.php
git checkout -- app/Modules/Imports/Providers/ImportsServiceProvider.php                 app/Modules/Core/Support/AdminNavigation.php                 app/Http/Middleware/HandleInertiaRequests.php
npm run build
```

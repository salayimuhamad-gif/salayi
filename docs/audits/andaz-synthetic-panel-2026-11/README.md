# Andaz (andaz.app) — 1,000-persona synthetic community & website audit

Working folder for the audit requested on 2026-11-03. Report language is Arabic; code and data are English.

**Honesty statement.** All 1,000 personas are AI-generated. They are not research participants. Outcomes derived from them are *synthetic panel outcomes*, not conversion, retention or market probabilities. Nothing about the live website is claimed unless it appears in `evidence/evidence_log.csv` as a category-A observation with date, URL, locale, viewport, steps and result.

## Status

Direct browser access to https://andaz.app/ is blocked by this environment's network policy. On 2026-11-03 the owner approved using the connected cloud scraping workspace instead (text and metadata of cloud-rendered pages; no screenshots or interaction) and confirmed that andaz.app does not run this repository's code. See `report/00_STATUS_AND_ACCESS.md`.

## Layout

| Path | What |
|---|---|
| `report/00_STATUS_AND_ACCESS.md` | Current status, exactly what is verified / unverified, and the action needed from the owner |
| `report/01_METHODOLOGY.md` | Evidence categories, panel composition and assumptions, task catalogue, evaluation engine, delegation & reconciliation, shared-model bias |
| `report/12_validation_plan_draft.md` | Real-user validation plan (draft) |
| `panel/personas.json` · `panel/personas.csv` | The 1,000 persona records (P0001–P1000) |
| `panel/composition.json` | Realised panel composition |
| `evidence/evidence_log.csv` | Evidence log (E-… ids) |
| `archive/excluded_repository_code_analysis/` | Repository code analysis, EXCLUDED on 2026-11-03 after the owner confirmed andaz.app is a different product |
| `tools/narrative_batch_brief.md` | Brief for the 10 non-overlapping narrative batches (run only after the evidence log exists) |
| `tools/generate_panel.py` | Deterministic panel generator (`python3 -I generate_panel.py --out ../panel`) |
| `tools/pw_audit.mjs` | Read-only Playwright evidence harness (`AUDIT_BASE=https://andaz.app node pw_audit.mjs`) |
| `tools/evaluate_panel.py` | Evidence-driven outcome engine (`python3 -I evaluate_panel.py --root ..`) — needs `evidence/feature_availability.json` and `evidence/impact_rules.json`, written only from live evidence |

Pending deliverables (after live evidence): executive verdict, issue register & developer backlog, synthetic outcomes by subgroup, top-10 exit/return reasons, 20 persona walkthroughs, mobile-first proposal, phased roadmap, downside/base/upside scenarios, final validation plan.

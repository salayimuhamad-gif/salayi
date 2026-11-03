# Andaz (andaz.app → andaz.krd) — 1,000-persona synthetic community & website audit

**Report language:** Arabic (`report/`). Code, data and this index: English. Audit date: 2026-11-03.

**Honesty statement.** All 1,000 personas are AI-generated; they are not research participants and their outcomes are *synthetic panel outcomes*, not conversion or market probabilities. Every statement about the live website is tied to a category-A evidence row in `evidence/evidence_log.csv` (date, URL, locale, steps, observed result). Live evidence was collected **through cloud-rendered page metadata and structured data only** (188 pages + sitemap + contact scan), because this environment's network policy blocks direct browser access; design, interaction, performance and accessibility layers are therefore **untested** and are listed as such.

## Start here

1. `report/03_EXECUTIVE_VERDICT.md` — verdict, strongest value, biggest obstacles, readiness, three owner decisions.
2. `report/00_STATUS_AND_ACCESS.md` — what was verified, what was not, credits spent, the one action needed for a second pass.
3. `report/06_ISSUE_REGISTER.md` + `evidence/dev_backlog.csv` — what to fix / simplify / remove / add / preserve, with acceptance criteria.

## Full index

| Deliverable | Path |
|---|---|
| Status, access routes, spend, open question on code lineage | `report/00_STATUS_AND_ACCESS.md` |
| Methodology (evidence categories, panel assumptions, task catalogue, evaluation engine, delegation, shared-model bias) | `report/01_METHODOLOGY.md` |
| Scope and evidence coverage (inspected / partial / inaccessible / untested) | `report/02_SCOPE_AND_EVIDENCE_COVERAGE.md` |
| Executive verdict | `report/03_EXECUTIVE_VERDICT.md` |
| Synthetic outcomes by subgroup, confidence, sensitivity | `report/04_SYNTHETIC_OUTCOMES.md` (narrative) · `report/outcome_tables.md` (generated) |
| Ten exit reasons / ten return reasons | `report/05_TOP10_EXIT_AND_RETURN_REASONS.md` |
| Issue register and fix/simplify/remove/add/preserve table | `report/06_ISSUE_REGISTER.md` |
| Twenty persona walkthroughs (all reactions simulated) | `report/07_PERSONA_WALKTHROUGHS.md` |
| Mobile-first proposal (home, discovery, project profile) | `report/08_MOBILE_FIRST_PROPOSAL.md` |
| Phased roadmap with dependencies | `report/09_ROADMAP.md` |
| Downside / base / upside scenarios and evidence needed for viability | `report/10_SCENARIOS.md` |
| Real-user validation plan | `report/12_VALIDATION_PLAN.md` |
| Persona registry P0001–P1000 (attributes, tasks, outcomes, narratives — 350 LLM-written, 650 engine-template, disclosed per record in `narrative_source`) | `panel/personas.json` · `panel/personas.csv` · `panel/outcomes.json` · `panel/outcomes.csv` · `panel/narratives/batch_NN.jsonl` · `panel/reconciliation.csv` |
| Panel composition (realised counts) | `panel/composition.json` |
| Evidence log (E-ACC-*, E-SITE-*) and raw exports | `evidence/evidence_log.csv` · `evidence/raw/` |
| Evaluation inputs | `evidence/feature_availability.json` · `evidence/impact_rules.json` |
| Developer backlog | `evidence/dev_backlog.csv` |
| Tools | `tools/generate_panel.py` (deterministic panel) · `tools/evaluate_panel.py` (outcome engine) · `tools/pw_audit.mjs` (read-only Playwright harness, ready for the second pass) · `tools/narrative_batch_brief.md` |
| Excluded material | `archive/excluded_repository_code_analysis/` (repository code analysis, excluded after the owner stated andaz.app is a different product) |

## Reproduce

```bash
cd docs/audits/andaz-synthetic-panel-2026-11/tools
python3 -I generate_panel.py --out ../panel      # same 1,000 personas, byte for byte (seed 20261103)
python3 -I evaluate_panel.py --root ..           # outcomes + report/outcome_tables.md from the evidence rules
AUDIT_BASE=https://andaz.krd AUDIT_OUT=../evidence/live node pw_audit.mjs   # second pass, once the host is allowed
```

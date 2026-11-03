# Andaz market simulation (3 November 2026)

- `REPORT.ar.md` — Arabic version of the report with a dedicated weaknesses section.
- `REPORT.md` — the full six-step report (evidence base, 1,000-person synthetic panel, 50 voices, panel summary, expert critics, verdict and experiments).
- `build_panel.py` — seeded generator for the synthetic panel and reaction model. Every distribution and weight is a modelling assumption; edit and re-run to test alternatives.
- `panel.csv` — the 1,000 generated rows (conservative baseline), one per fictional persona.
- `summary.json` — the aggregates the report quotes, for both the conservative baseline and the unconstrained variant.

Regenerate with:

```bash
python3 docs/research/andaz-market-simulation/build_panel.py
```

Nothing here is a real survey or evidence of market demand.

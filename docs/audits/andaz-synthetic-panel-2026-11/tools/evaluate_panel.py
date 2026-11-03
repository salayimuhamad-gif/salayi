#!/usr/bin/env python3
"""
Andaz audit — synthetic panel evaluator.

Turns (persona attributes) × (observed evidence) into per-task outcomes and an
adoption category for every persona, deterministically and transparently.

Why a rule engine and not 1,000 free-form LLM judgements
---------------------------------------------------------
* Every outcome must be traceable to evidence IDs from the live inspection.
* 1,000 independent free-form judgements by the same model are NOT 1,000
  independent opinions; they share one prior. A rule engine makes the shared
  prior explicit and auditable, and lets the panel weights be re-run
  (sensitivity analysis) without re-prompting anything.
* Narrative fields (confusion, simulated reaction, ...) are written afterwards
  in batches by LLM agents that receive the computed outcome and the applicable
  evidence IDs, and may flag disagreement; disagreements are reconciled and
  disclosed. See METHODOLOGY.md.

Inputs (all under the audit folder):
  panel/personas.json                 from generate_panel.py
  evidence/evidence_log.csv           live-inspection evidence (E-xxx ids)
  evidence/feature_availability.json  task_id -> available|partial|gated_login|absent|untested (+ evidence ids)
  evidence/impact_rules.json          list of rules: {id, evidence_ids, tasks, effect, weight, applies_if, improvement, note}
      effect ∈ block | major | minor | positive | trust_break | value_gap
      applies_if: {attribute: [allowed values]} — all listed attributes must match; {} = everyone

Outputs:
  panel/outcomes.json / outcomes.csv  per-persona structured outcomes
  report/outcome_tables.md            aggregate tables (all panel vs need-only, subgroups, sensitivity)

Run: python3 -I evaluate_panel.py --root ..
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
from collections import Counter, OrderedDict, defaultdict

TASK_LEVELS = ["completes", "struggles", "abandons", "cannot_assess"]
CATS = OrderedDict([
    ("A", "Would use the platform now"),
    ("B", "Would use it after specific improvements"),
    ("C", "Would reject it despite having a relevant need"),
    ("D", "Has no current relevant need"),
    ("E", "Cannot assess because evidence is insufficient"),
])
NEED_INTENTS = {"renter", "first_time_buyer", "upgrader", "seller", "landlord", "investor_local",
                "investor_diaspora", "broker", "developer_staff", "researching"}


def jitter(pid: str, salt: str = "") -> float:
    """Deterministic pseudo-noise in [-0.5, 0.5] so identical attribute vectors do not all tip the same way."""
    h = hashlib.sha256((pid + salt).encode()).hexdigest()
    return (int(h[:8], 16) / 0xFFFFFFFF) - 0.5


def tolerance(p: dict) -> float:
    """How much friction a persona absorbs before giving up (higher = more persistent)."""
    t = 3.0
    t += {"low": -1.2, "medium": 0.0, "high": 1.0}[p["patience"]]
    t += {"low": -1.0, "medium": 0.0, "high": 0.4, "very_high": 0.8}[p["digital_confidence"]]
    t += {"immediate": 1.0, "soon": 0.5, "someday": 0.0, "none": -1.0}[p["urgency"]]
    t += {"none": -0.4, "basic": 0.0, "moderate": 0.2, "expert": 0.4}[p["property_knowledge"]]
    if p["reading_ability"] == "low":
        t -= 0.6
    if p["accessibility_need"] != "none":
        t -= 0.3  # not a deficit of the person: the web is usually harder for them
    return t + jitter(p["id"], "tol")


def matches(p: dict, cond: dict) -> bool:
    for attr, allowed in cond.items():
        val = p.get(attr)
        if isinstance(val, bool):
            val = str(val).lower()
            allowed = [str(a).lower() for a in allowed]
        if attr == "budget_payment_mode":
            val = p.get("budget", {}).get("payment_mode")
        if val not in allowed:
            return False
    return True


def evaluate(p: dict, rules: list[dict], availability: dict) -> dict:
    tol = tolerance(p)
    task_results = OrderedDict()
    applied_all = []
    improvements = Counter()
    trust_breaks = 0
    value_gaps = 0
    positives = 0
    for t in p["tasks"]:
        avail = availability.get(t, {"status": "untested", "evidence_ids": []})
        status = avail["status"]
        applicable = [r for r in rules if t in r.get("tasks", []) and matches(p, r.get("applies_if", {}))]
        applied_all += [r["id"] for r in applicable]
        friction = 0.0
        blocked = False
        reasons = []
        for r in applicable:
            eff = r["effect"]
            w = float(r.get("weight", 1.0))
            if eff == "block":
                blocked = True
                reasons.append(r["id"])
            elif eff == "major":
                friction += 2.0 * w
                reasons.append(r["id"])
            elif eff == "minor":
                friction += 1.0 * w
                reasons.append(r["id"])
            elif eff == "positive":
                friction -= 1.0 * w
                positives += 1
            elif eff == "trust_break":
                friction += 1.5 * w
                trust_breaks += 1
                reasons.append(r["id"])
            elif eff == "value_gap":
                friction += 2.0 * w
                value_gaps += 1
                reasons.append(r["id"])
            if r.get("improvement"):
                improvements[r["improvement"]] += 1
        if status == "untested":
            level = "cannot_assess"
        elif status == "absent":
            level = "abandons"
            reasons.append("feature_absent")
            value_gaps += 1
        elif blocked:
            level = "abandons"
        else:
            if status == "gated_login":
                # a login/verification wall before value: privacy-sensitive and sceptical people leave
                gate_cost = 1.5
                if p["privacy_concern"] == "high":
                    gate_cost += 1.5
                if p["trust_disposition"] == "skeptical":
                    gate_cost += 1.0
                if p["urgency"] in {"immediate", "soon"}:
                    gate_cost -= 0.5
                friction += gate_cost
                reasons.append("login_gate")
            if status == "partial":
                friction += 1.0
                reasons.append("feature_partial")
            f = friction + jitter(p["id"], t) * 0.6
            if f <= tol * 0.45:
                level = "completes"
            elif f <= tol:
                level = "struggles"
            else:
                level = "abandons"
        task_results[t] = {"outcome": level, "friction": round(friction, 2), "tolerance": round(tol, 2),
                           "feature_status": status, "reasons": sorted(set(reasons)), "evidence_ids": sorted(set(avail.get("evidence_ids", []) + [e for r in applicable for e in r.get("evidence_ids", [])]))}

    levels = [v["outcome"] for v in task_results.values()]
    n = len(levels)
    n_cannot = levels.count("cannot_assess")
    core = [t for t in p["tasks"] if t != "T01"] or p["tasks"]
    core_levels = [task_results[t]["outcome"] for t in core]

    # ---- adoption category ----
    has_need = p["property_intent"] in NEED_INTENTS and not (p["property_intent"] == "researching" and p["urgency"] == "none")
    if p["age_band"] == "14-17":
        has_need = False
    if not has_need:
        cat = "D"
    elif n_cannot >= max(1, (n + 1) // 2):
        cat = "E"
    else:
        completes = core_levels.count("completes")
        abandons = core_levels.count("abandons")
        trust_fail = trust_breaks > 0 and p["trust_disposition"] in {"skeptical", "neutral"}
        if abandons == 0 and completes >= max(1, len(core_levels) - 1) and not trust_fail and value_gaps == 0:
            cat = "A"
        elif abandons == len(core_levels) and (trust_fail or value_gaps >= 2 or p["patience"] == "low"):
            cat = "C"
        elif abandons >= 1 or trust_fail or value_gaps:
            # would come back if the named improvements land; the sceptical and impatient reject instead
            if trust_fail and p["trust_disposition"] == "skeptical" and abandons >= 1:
                cat = "C"
            else:
                cat = "B"
        else:
            cat = "B" if (core_levels.count("struggles") >= 1 and positives == 0) else "A"

    would_return = {"A": "yes", "B": "yes_if_fixed", "C": "no", "D": "unlikely_no_need", "E": "unknown"}[cat]
    return {
        "id": p["id"], "adoption_category": cat, "adoption_label": CATS[cat], "has_relevant_need": has_need,
        "task_outcomes": task_results, "applied_rule_ids": sorted(set(applied_all)),
        "improvements_that_change_decision": [k for k, _ in improvements.most_common(3)],
        "would_return": would_return, "trust_breaks": trust_breaks, "value_gaps": value_gaps, "tolerance": round(tol, 2),
    }


def pct_table(counter: Counter, denom: int) -> list[tuple[str, int, int]]:
    rows = []
    for k in CATS:
        c = counter.get(k, 0)
        rows.append((k, c, round(100 * c / denom) if denom else 0))
    return rows


def md_table(title: str, rows: list[tuple[str, int, int]], denom: int) -> str:
    out = [f"**{title}** (denominator = {denom})", "", "| Category | Count | % |", "|---|---:|---:|"]
    for k, c, pc in rows:
        out.append(f"| {k} — {CATS[k]} | {c} | {pc}% |")
    total_pct = sum(r[2] for r in rows)
    out.append(f"| Total | {sum(r[1] for r in rows)} | {total_pct}%{' (rounding)' if total_pct != 100 else ''} |")
    return "\n".join(out) + "\n"


def subgroup(personas, outcomes, key, min_size=25):
    groups = defaultdict(list)
    for p in personas:
        groups[str(p[key])].append(outcomes[p["id"]]["adoption_category"])
    lines = [f"| {key} | n | A | B | C | D | E |", "|---|---:|---:|---:|---:|---:|---:|"]
    for g, cats in sorted(groups.items(), key=lambda kv: -len(kv[1])):
        if len(cats) < min_size:
            lines.append(f"| {g} | {len(cats)} | (too small for percentages) | | | | |")
            continue
        c = Counter(cats)
        n = len(cats)
        lines.append(f"| {g} | {n} | " + " | ".join(f"{round(100 * c[k] / n)}%" for k in CATS) + " |")
    return "\n".join(lines) + "\n"


def sensitivity(personas, outcomes):
    """Re-weight the panel under alternative plausible compositions; report category shares."""
    scenarios = {
        "panel_as_built": lambda p: 1.0,
        "more_low_digital_and_older": lambda p: (2.0 if p["digital_confidence"] == "low" else 1.0) * (1.5 if p["age_band"] in {"55-64", "65-74", "75+"} else 1.0),
        "more_diaspora_english": lambda p: 2.5 if p["location_context"] == "diaspora" else 1.0,
        "arabic_heavy": lambda p: 2.0 if p["primary_language"] == "ar" else 1.0,
        "mobile_budget_slow_network": lambda p: (1.8 if p["device"] == "android_budget" else 0.8) * (1.6 if p["network"] in {"slow_unstable", "4g_variable"} else 1.0),
        "buyers_and_renters_only": lambda p: 1.0 if p["property_intent"] in {"renter", "first_time_buyer", "upgrader"} else 0.0,
        "specialists_only": lambda p: 1.0 if p["is_specialist"] else 0.0,
    }
    lines = ["| Weighting scenario | effective n | A | B | C | D | E |", "|---|---:|---:|---:|---:|---:|---:|"]
    for name, wf in scenarios.items():
        tot = defaultdict(float)
        n = 0.0
        for p in personas:
            w = wf(p)
            if w <= 0:
                continue
            tot[outcomes[p["id"]]["adoption_category"]] += w
            n += w
        lines.append(f"| {name} | {round(n)} | " + " | ".join(f"{round(100 * tot[k] / n) if n else 0}%" for k in CATS) + " |")
    return "\n".join(lines) + "\n"


def main(root: str) -> None:
    with open(os.path.join(root, "panel", "personas.json"), encoding="utf-8") as f:
        personas = json.load(f)
    with open(os.path.join(root, "evidence", "impact_rules.json"), encoding="utf-8") as f:
        rules = json.load(f)
    with open(os.path.join(root, "evidence", "feature_availability.json"), encoding="utf-8") as f:
        availability = json.load(f)

    outcomes = OrderedDict()
    for p in personas:
        outcomes[p["id"]] = evaluate(p, rules, availability)

    os.makedirs(os.path.join(root, "report"), exist_ok=True)
    with open(os.path.join(root, "panel", "outcomes.json"), "w", encoding="utf-8") as f:
        json.dump(list(outcomes.values()), f, ensure_ascii=False, indent=1)
    with open(os.path.join(root, "panel", "outcomes.csv"), "w", encoding="utf-8", newline="") as f:
        w = csv.writer(f, lineterminator="\n")
        w.writerow(["id", "adoption_category", "has_relevant_need", "would_return", "trust_breaks", "value_gaps", "tolerance",
                    "task_outcomes", "applied_rule_ids", "improvements_that_change_decision"])
        for o in outcomes.values():
            w.writerow([o["id"], o["adoption_category"], o["has_relevant_need"], o["would_return"], o["trust_breaks"], o["value_gaps"], o["tolerance"],
                        json.dumps({t: v["outcome"] for t, v in o["task_outcomes"].items()}), ";".join(o["applied_rule_ids"]), ";".join(o["improvements_that_change_decision"])])

    # ---- aggregate tables ----
    all_c = Counter(o["adoption_category"] for o in outcomes.values())
    need = [o for o in outcomes.values() if o["has_relevant_need"]]
    need_c = Counter(o["adoption_category"] for o in need)
    completed = len([o for o in outcomes.values() if o["adoption_category"] != "E"])
    task_c = Counter()
    for o in outcomes.values():
        for t, v in o["task_outcomes"].items():
            task_c[(t, v["outcome"])] += 1
    tasks = sorted({t for o in outcomes.values() for t in o["task_outcomes"]})
    task_lines = ["| Task | assigned | completes | struggles | abandons | cannot_assess |", "|---|---:|---:|---:|---:|---:|"]
    for t in tasks:
        n = sum(task_c[(t, l)] for l in TASK_LEVELS)
        task_lines.append(f"| {t} | {n} | " + " | ".join(f"{task_c[(t, l)]} ({round(100 * task_c[(t, l)] / n) if n else 0}%)" for l in TASK_LEVELS) + " |")
    rule_hits = Counter(r for o in outcomes.values() for r in o["applied_rule_ids"])
    imp = Counter(i for o in outcomes.values() for i in o["improvements_that_change_decision"])

    md = ["# Synthetic panel outcomes (auto-generated by evaluate_panel.py)", "",
          "These are **synthetic panel outcomes** from AI-generated personas evaluated against the evidence log with the documented rule engine. They are not conversion, retention, market share or probability of commercial success.", "",
          f"Personas evaluated: {len(personas)}. Personas with a computable outcome (A–D): {completed}. Category E (insufficient evidence): {all_c.get('E', 0)}.", "",
          md_table("All panel", pct_table(all_c, len(personas)), len(personas)),
          md_table("Personas with a current relevant need", pct_table(need_c, len(need)), len(need)),
          "## Task-level outcomes", "", "\n".join(task_lines), "",
          "## Subgroups (percentages only where n ≥ 25)", ""]
    for key in ("age_band", "property_intent", "primary_language", "digital_confidence", "income_band", "location_context",
                "device", "network", "accessibility_need", "trust_disposition", "privacy_concern", "is_specialist", "specialist_domain"):
        md += [f"### by {key}", "", subgroup(personas, outcomes, key), ""]
    md += ["## Most frequently applied evidence rules", "", "| Rule | personas affected |", "|---|---:|"]
    md += [f"| {r} | {c} |" for r, c in rule_hits.most_common(30)]
    md += ["", "## Improvements most often named as decision-changing (B personas)", "", "| Improvement | personas |", "|---|---:|"]
    md += [f"| {i} | {c} |" for i, c in imp.most_common(20)]
    md += ["", "## Sensitivity to panel weights", "", sensitivity(personas, outcomes)]
    with open(os.path.join(root, "report", "outcome_tables.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(md))
    print("all:", dict(all_c), "need:", dict(need_c), "n_need:", len(need))


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
    main(os.path.abspath(ap.parse_args().root))

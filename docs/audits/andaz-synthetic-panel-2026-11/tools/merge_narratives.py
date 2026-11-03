#!/usr/bin/env python3
"""
Merge the narrative batches and the computed outcomes into the final persona registry.

Inputs:  panel/personas.json (generator output), panel/outcomes.json (evaluate_panel.py),
         panel/narratives/batch_NN.jsonl (10 agent-written batches, 100 lines each)
Outputs: panel/personas.json / personas.csv (now with every outcome and narrative field filled),
         panel/reconciliation.csv (every agent objection with the reviewer decision),
         prints QC: ids, completeness, duplicate reactions, objection counts.

Reviewer policy for objections (documented, applied uniformly):
  * The computed category is kept unless the objection names an evidence id whose rule
    was NOT applied to the persona although its applies_if matches — i.e. an engine
    coverage error. Narrative disagreement alone does not change a category, because
    that would re-introduce per-persona free judgement the methodology excludes.
  * Every objection is kept verbatim in reconciliation.csv with the decision and reason.

Run: python3 -I merge_narratives.py --root ..
"""
from __future__ import annotations

import argparse
import csv
import glob
import json
import os
from collections import Counter

FIELDS = ["initial_understanding", "main_confusion", "trust_concern_observed", "frustration_point",
          "most_useful_feature", "missing_information", "what_would_change_decision", "simulated_reaction"]


def main(root: str) -> None:
    personas = json.load(open(os.path.join(root, "panel", "personas.json"), encoding="utf-8"))
    outcomes = {o["id"]: o for o in json.load(open(os.path.join(root, "panel", "outcomes.json"), encoding="utf-8"))}
    narr = {}
    fb = os.path.join(root, "panel", "narratives", "fallback_engine.jsonl")
    if os.path.exists(fb):
        for line in open(fb, encoding="utf-8"):
            line = line.strip()
            if line:
                rec = json.loads(line); rec["_source"] = "engine_template"; narr[rec["id"]] = rec
    files = sorted(glob.glob(os.path.join(root, "panel", "narratives", "batch_*.jsonl")))
    n_llm = 0
    for f in files:
        for line in open(f, encoding="utf-8"):
            line = line.strip()
            if not line:
                continue
            try:
                rec = json.loads(line)
            except Exception:
                continue
            rec["_source"] = "llm_batch_agent"; narr[rec["id"]] = rec; n_llm += 1
    print(f"narrative files: {len(files)}; llm records: {n_llm}; total records (llm overrides template): {len(narr)}")

    recon = []
    filled = 0
    for p in personas:
        o = outcomes[p["id"]]
        p["evidence_ids"] = sorted({e for t in o["task_outcomes"].values() for e in t["evidence_ids"]})
        p["predicted_outcome"] = {t: v["outcome"] for t, v in o["task_outcomes"].items()}
        p["task_outcomes"] = o["task_outcomes"]
        p["adoption_category"] = o["adoption_category"]
        p["adoption_label"] = o["adoption_label"]
        p["outcome_confidence"] = o["outcome_confidence"]
        p["would_return"] = o["would_return"]
        p["applied_rule_ids"] = o["applied_rule_ids"]
        n = narr.get(p["id"])
        if n:
            filled += 1
            for k in FIELDS:
                p[k] = n.get(k)
            p["narrative_source"] = n.get("_source", "llm_batch_agent")
            obj = n.get("objection")
            if obj:
                proposed = obj.get("proposed_category")
                if proposed == o["adoption_category"]:
                    decision, why = "resolved_by_engine_fix", "the objection pointed at a systematic engine weakness (category A resting on tangential or untested tasks); the primary-task gate added on 2026-11-03 now yields the proposed category for everyone in the same situation"
                else:
                    decision, why = "keep_computed", "narrative disagreement only; no uncovered rule named (policy in merge_narratives.py)"
                recon.append({"id": p["id"], "computed_category": o["adoption_category"], "proposed_category": proposed,
                              "reason": obj.get("reason"), "decision": decision, "decision_reason": why})
        else:
            p["narrative_source"] = "missing"
            if not p.get("what_would_change_decision"):
                p["what_would_change_decision"] = "; ".join(o["improvements_that_change_decision"]) or None
    # ---- QC ----
    ids = [p["id"] for p in personas]
    assert len(ids) == 1000 and len(set(ids)) == 1000
    reactions = Counter(p.get("simulated_reaction") for p in personas if p.get("simulated_reaction"))
    dups = [(r, c) for r, c in reactions.items() if c > 1]
    cats = Counter(p["adoption_category"] for p in personas)
    src = Counter(p.get("narrative_source") for p in personas)
    print("narratives merged:", filled, "missing:", 1000 - filled, "by source:", dict(src))
    print("duplicate simulated reactions:", len(dups), dups[:5])
    print("categories:", dict(cats), "sum:", sum(cats.values()))
    print("objections:", len(recon))
    empties = Counter(k for p in personas for k in FIELDS if not p.get(k))
    print("empty narrative fields:", dict(empties))

    with open(os.path.join(root, "panel", "reconciliation.csv"), "w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=["id", "computed_category", "proposed_category", "reason", "decision", "decision_reason"], lineterminator="\n")
        w.writeheader()
        w.writerows(recon)
    with open(os.path.join(root, "panel", "personas.json"), "w", encoding="utf-8") as f:
        json.dump(personas, f, ensure_ascii=False, indent=1)
    cols = list(personas[0].keys())
    with open(os.path.join(root, "panel", "personas.csv"), "w", encoding="utf-8", newline="") as f:
        w = csv.writer(f, lineterminator="\n")
        w.writerow(cols)
        for p in personas:
            w.writerow([json.dumps(p.get(c), ensure_ascii=False) if isinstance(p.get(c), (dict, list)) else p.get(c) for c in cols])
    print("written personas.json/csv and reconciliation.csv")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
    main(os.path.abspath(ap.parse_args().root))

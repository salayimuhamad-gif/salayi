#!/usr/bin/env python3
"""
Post-processing correction (2026-11-03): guarantee that every persona with a current need carries at least one
goal-carrying ("primary") task in its 2–4 assigned tasks. The original generator sampled tasks at random from
the intent pool, so about half of the personas never received the task that tests their actual goal; narrative
objections on batch 08 exposed that category A could then rest on tangential tasks only.

Deterministic: adds the first primary task of the intent if none is present; if the persona already has four
tasks, the last non-T01, non-primary task is replaced. Attributes are untouched. Run before evaluate_panel.py.
"""
import json, os, sys, csv
from collections import Counter
PRIMARY = {"renter": ["T02"], "first_time_buyer": ["T03", "T02"], "upgrader": ["T03"], "seller": ["T14"], "landlord": ["T14"],
           "investor_local": ["T15", "T03"], "investor_diaspora": ["T15", "T03"], "broker": ["T07", "T14", "T17"],
           "developer_staff": ["T03", "T17"], "researching": ["T15", "T03", "T06"]}
root = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
path = os.path.join(root, "panel", "personas.json")
personas = json.load(open(path, encoding="utf-8"))
changed = 0
for p in personas:
    prim = PRIMARY.get(p["property_intent"])
    if not prim or p["age_band"] == "14-17":
        continue
    if any(t in p["tasks"] for t in prim):
        continue
    if len(p["tasks"]) < 4:
        p["tasks"].append(prim[0])
    else:
        for i in range(len(p["tasks"]) - 1, 0, -1):
            if p["tasks"][i] not in prim:
                p["tasks"][i] = prim[0]; break
    p["tasks_corrected"] = True
    changed += 1
json.dump(personas, open(path, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
freq = Counter(t for p in personas for t in p["tasks"])
comp_path = os.path.join(root, "panel", "composition.json")
comp = json.load(open(comp_path, encoding="utf-8"))
comp["task_frequency"] = dict(sorted(freq.items()))
comp["tasks_assigned_total"] = sum(freq.values())
comp["task_correction_note"] = f"{changed} personas received their goal-carrying task on 2026-11-03 (ensure_primary_tasks.py)."
json.dump(comp, open(comp_path, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print("personas corrected:", changed, "task frequency:", dict(sorted(freq.items())), "total", sum(freq.values()))

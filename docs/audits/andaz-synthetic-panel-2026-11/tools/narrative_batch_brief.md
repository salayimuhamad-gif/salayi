# Brief for narrative-batch agents (used only AFTER the evidence log exists)

You receive ONE batch of 100 personas (non-overlapping id range) as JSON, the shared evidence log (`evidence/evidence_log.csv`), the feature-availability file, the impact rules, and the computed outcome for each persona from `evaluate_panel.py`.

Your job: write the qualitative fields for each persona in everyday language, grounded ONLY in evidence IDs that apply to that persona. Return JSON lines, one object per persona:

```
{"id":"P0xxx",
 "initial_understanding":"<what this persona thinks the site is after the first screen, 1 sentence, cite E-ids>",
 "main_confusion":"<the single most confusing label/step for THIS persona, quote the exact interface label in its original language, cite E-id>",
 "trust_concern_observed":"<what on the observed site raised or lowered trust for this persona, cite E-id; 'none observed' is allowed>",
 "frustration_point":"<the moment this persona would sigh or close the tab, tied to their abandonment_trigger and an E-id>",
 "most_useful_feature":"<observed feature that helps THIS persona; 'none for this persona' is allowed>",
 "missing_information":"<the information this persona needed and did not find, specific>",
 "what_would_change_decision":"<one concrete change>",
 "simulated_reaction":"<1–2 sentences in the persona's everyday voice; mark nothing as a real quote; keep culturally plausible; may be in the persona's language (ckb/ar/en) followed by an English gloss>",
 "objection":null | {"proposed_category":"A|B|C|D|E","reason":"<why the computed category seems wrong for this persona>"}}
```

Rules:
1. Never invent features, screens, prices or behaviour not present in the evidence log. If the persona's task touched an untested area, say so.
2. Vary the reasoning: a 70-year-old seller with high digital confidence must not read like a 70-year-old seller with low digital confidence. Use the persona's goal, concern, device, network, language, reading ability and accessibility need.
3. Do not stereotype by age, gender, occupation, income or education. Competence is in the attributes, not in the demographics.
4. Keep each field short. No marketing language. No praise without evidence.
5. If you disagree with the computed adoption category, fill `objection`; do NOT silently change anything else.
6. Label every quotation-like sentence as simulated (the report does this automatically; do not write "said").

Output file: `panel/narratives/batch_<nn>.jsonl`. Report back: count written, count of objections, and any evidence IDs you found contradictory or unclear.

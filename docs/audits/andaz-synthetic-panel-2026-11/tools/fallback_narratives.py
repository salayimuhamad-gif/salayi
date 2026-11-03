#!/usr/bin/env python3
"""
Deterministic fallback for persona narrative fields.

Used ONLY for personas whose LLM-written narrative batch did not arrive (the delegated
writers were cut off by an account rate limit on 2026-11-03). It assembles the qualitative
fields from the persona's own attributes, the rules that actually applied to them, and the
evidence ids behind those rules. It is explicitly labelled `narrative_source = "engine_template"`
so nobody mistakes it for an independent LLM judgement. Simulated reactions are composed from
short, evidence-bound phrase banks in the persona's primary language and are labelled as
simulations by the report.

Run: python3 -I fallback_narratives.py --root ..  (writes panel/narratives/fallback_engine.jsonl
for the ids that have no LLM record)
"""
from __future__ import annotations

import argparse
import glob
import hashlib
import json
import os

LANG_PHRASES = {
    # key -> {lang: phrase}
    "no_contact": {"ckb": "پڕۆژەکە باشە، بەڵام پەیوەندی بە کێ بکەم؟", "ar": "المشروع جيد، لكن أتصل بمن؟", "en": "Fine project, but who do I even call?"},
    "market_soon": {"ckb": "بۆ نیشاندەرەکان هاتم و «بەم زووانە»م بینی.", "ar": "جئت من أجل المؤشرات فوجدت «قريباً».", "en": "I came for the market numbers and got 'coming soon'."},
    "no_identity": {"ckb": "ناوی کۆمپانیا و ناونیشان لە کوێیە؟", "ar": "أين اسم الشركة والعنوان؟", "en": "No company name, no address. Who runs this?"},
    "english_auth": {"ckb": "لاپەڕەی هەژمار بە ئینگلیزییە… بۆ من نییە.", "ar": "صفحة الحساب بالإنجليزية… ليست لي.", "en": "The sign-in page switched to English on me."},
    "no_price": {"ckb": "یەکە و نهۆم هەیە، نرخ نییە.", "ar": "وحدات وطوابق، ولا سعر واحد.", "en": "Units and floors, and not one price."},
    "raw_key": {"ckb": "«map.investment»؟ ئەمە شکاوە.", "ar": "«map.investment»؟ هذه صفحة مكسورة.", "en": "'map.investment' as a title? Broken."},
    "area_names": {"ckb": "ناوی گەڕەکەکان بە کوردی باشن.", "ar": "أسماء الأحياء بالكردية لا بالعربية.", "en": "'kwrany nwe' — I cannot read where this is."},
    "rich_data": {"ckb": "زانیاری پڕۆژە باشە: یەکە، نهۆم، ڕووبەر.", "ar": "بيانات المشروع مفيدة: وحدات وطوابق ومساحات.", "en": "Solid project facts: units, floors, areas."},
    "valuation": {"ckb": "«بەهای ڕاستەقینەی خانووبەرەکەت» — ئەوەی من دەمەوێت.", "ar": "«القيمة الحقيقية لعقارك» — هذا ما أريده.", "en": "'The true value of your property' — that is my question."},
    "dual_promise": {"ckb": "ئەمە بۆ کڕین و فرۆشتنە یان بۆ نرخاندن؟", "ar": "هل هذا للبيع والشراء أم لتقدير السعر؟", "en": "Is this a marketplace or a valuation tool?"},
    "dead_end": {"ckb": "لاپەڕەی 404 بێ هیچ ڕێگەیەک.", "ar": "صفحة 404 بلا أي مخرج.", "en": "A bare 404 and nowhere to go."},
    "no_need": {"ckb": "ماڵپەڕی خانووبەرەیە. ئێستا پێویستم نییە.", "ar": "موقع عقارات. لا أحتاجه الآن.", "en": "A property site. Not something I need now."},
    "untested": {"ckb": "ئەو بەشەی من دەمویست نەبینرا.", "ar": "الجزء الذي أحتاجه لم يُرصد.", "en": "The part I needed was not observable."},
}
GLOSS = {
    "no_contact": "(Good project, but who do I call?)", "market_soon": "(Came for indicators, found 'coming soon'.)",
    "no_identity": "(Where is the company name and address?)", "english_auth": "(The account page is in English; not for me.)",
    "no_price": "(Units and floors, no price.)", "raw_key": "('map.investment'? Broken page.)",
    "area_names": "(Area names are not in my language.)", "rich_data": "(Useful project facts.)",
    "valuation": "(The valuation promise is what I want.)", "dual_promise": "(Marketplace or valuation tool?)",
    "dead_end": "(A bare 404.)", "no_need": "(A property site; no need now.)", "untested": "(The part I needed was not observable.)",
}
RULE_TO_KEY = {
    "R06": "no_contact", "R07": "no_contact", "R02": "market_soon", "R03": "no_identity", "R05": "no_identity", "R23": "no_identity",
    "R12": "english_auth", "R13": "english_auth", "R08": "no_price", "R09": "no_price", "R04a": "raw_key", "R04b": "raw_key",
    "R14": "area_names", "R15": "area_names", "R27": "area_names", "R10": "rich_data", "R11": "rich_data", "R21": "rich_data", "R28": "rich_data",
    "R20": "valuation", "R01": "dual_promise", "R16": "dual_promise", "R17": "dead_end", "R18": "dead_end", "R19": "no_identity", "R22": "rich_data", "R24": "dead_end", "R25": "rich_data",
}
EVID = {
    "no_contact": "E-SITE-08, E-SITE-17", "market_soon": "E-SITE-14", "no_identity": "E-SITE-03, E-SITE-17", "english_auth": "E-SITE-12",
    "no_price": "E-SITE-08, E-SITE-22", "raw_key": "E-SITE-15", "area_names": "E-SITE-20, E-SITE-23", "rich_data": "E-SITE-08, E-SITE-22",
    "valuation": "E-SITE-04, E-SITE-16", "dual_promise": "E-SITE-04", "dead_end": "E-SITE-17", "no_need": "E-SITE-04", "untested": "",
}


def lang_of(p):
    l = p["primary_language"]
    if l == "other":
        return "ar" if "ar" in (p.get("also_reads") or "") else ("en" if "en" in (p.get("also_reads") or "") else "ckb")
    return l


def pick(seq, pid, salt):
    if not seq:
        return None
    h = int(hashlib.sha256((pid + salt).encode()).hexdigest()[:8], 16)
    return seq[h % len(seq)]


def build(p, o, rules):
    pid = p["id"]
    applied = [r.split("-")[0] for r in o["applied_rule_ids"]]
    neg_keys, pos_keys = [], []
    for r in applied:
        k = RULE_TO_KEY.get(r)
        if not k:
            continue
        eff = next((x["effect"] for x in rules if x["id"].startswith(r + "-")), "minor")
        (pos_keys if eff == "positive" else neg_keys).append(k)
    neg_keys = list(dict.fromkeys(neg_keys)); pos_keys = list(dict.fromkeys(pos_keys))
    cannot = [t for t, v in o["task_outcomes"].items() if v["outcome"] == "cannot_assess"]
    lang = lang_of(p)
    cat = o["adoption_category"]
    # fields
    if p["age_band"] == "14-17" or p["property_intent"] == "no_need":
        init = "Reads the hero promise and the description (E-SITE-04) and understands this is an Erbil property site; has no purchasing role."
    else:
        init = "From the hero «بەهای ڕاستەقینەی خانووبەرەکەت» and the description about Erbil properties, indicators and offers (E-SITE-04), understands it as an Erbil property-information site" + (" but has to reconcile the valuation promise with the discovery promise." if "dual_promise" in neg_keys else ".")
    main_conf = {
        "dual_promise": "Whether the site is for valuing a property or for finding one (E-SITE-04).",
        "english_auth": "The account pages appearing in English at the Kurdish/Arabic path (E-SITE-12).",
        "area_names": "Area names shown in another language or as a transliteration (E-SITE-20).",
        "raw_key": "A page titled 'map.investment' (E-SITE-15).",
        "no_price": "Project pages with units and floors but no price or payment basis (E-SITE-08).",
    }
    mc = next((main_conf[k] for k in ["raw_key", "area_names", "english_auth", "no_price", "dual_promise"] if k in neg_keys), "No specific confusion observable in the evidence available for this persona's tasks.")
    trust = ("No about/contact/privacy/terms pages and no social profiles were found (E-SITE-03, E-SITE-17)" if "no_identity" in neg_keys else
             ("Developer names and the provenance phrase on project pages (E-SITE-08) are reassuring; operator identity not observed" if "rich_data" in pos_keys else "none observed for this persona's tasks"))
    frust = {
        "no_contact": "Reaching a suitable project and finding no phone, WhatsApp or form in any observed page (E-SITE-08, E-SITE-17).",
        "market_soon": "Opening «نیشاندەرەکانی بازاڕ» and finding a 'coming soon' page after the homepage promised indicators (E-SITE-14).",
        "no_identity": "Looking for who operates the site and finding 404 at /about and /contact (E-SITE-17).",
        "english_auth": "Tapping the account entry and landing on an English page (E-SITE-12).",
        "no_price": "A project page with units, floors and areas but no price (E-SITE-08).",
        "raw_key": "The investment map page headed 'map.investment' (E-SITE-15).",
        "area_names": "An area page named 'kwrany nwe' or a Kurdish name on the Arabic site (E-SITE-20).",
        "dead_end": "A 404 page with only '404' and no way back (E-SITE-17).",
    }
    fp = next((frust[k] for k in ["no_contact", "market_soon", "no_identity", "no_price", "english_auth", "raw_key", "area_names", "dead_end"] if k in neg_keys), "No frustration point observable in the evidence for this persona's tasks.")
    useful = ("Project facts (developer, status, units, floors, unit areas) and coordinates on every project (E-SITE-08, E-SITE-22)" if "rich_data" in pos_keys else
              ("The valuation promise «بەهای ڕاستەقینەی خانووبەرەکەت» (E-SITE-04)" if "valuation" in pos_keys else
               ("81 named area pages (E-SITE-23)" if p["property_intent"] in ("no_need", "researching") else "none for this persona")))
    missing = {
        "no_contact": "A way to contact the lister or the platform.", "no_price": "Price or price range, payment basis and update date.",
        "market_soon": "Dated, sourced market indicators.", "no_identity": "Operator identity, address, legal pages.",
        "english_auth": "Account pages in the persona's language.", "area_names": "Area names in the persona's language.",
    }
    miss = next((missing[k] for k in ["no_price", "no_contact", "market_soon", "no_identity", "english_auth", "area_names"] if k in neg_keys), ("Observable evidence for the core task." if cannot else "Nothing specific for this persona's tasks."))
    change = "; ".join(o["improvements_that_change_decision"][:2]) or ("Not applicable — no current need." if cat == "D" else "Observable evidence for the untested tasks.")
    # reaction
    if cat == "D":
        key = "no_need"
    elif cat == "E" and cannot and not neg_keys:
        key = "untested"
    elif neg_keys:
        key = pick(neg_keys, pid, "neg")
    elif pos_keys:
        key = pick(pos_keys, pid, "pos")
    else:
        key = "untested"
    phrase = LANG_PHRASES[key][lang]
    reaction = f"{phrase} {GLOSS[key]}"
    return {
        "id": pid, "initial_understanding": init, "main_confusion": mc, "trust_concern_observed": trust, "frustration_point": fp,
        "most_useful_feature": useful, "missing_information": miss, "what_would_change_decision": change,
        "simulated_reaction": reaction, "objection": None, "narrative_source": "engine_template",
        "evidence_refs": EVID.get(key, ""),
    }


def main(root):
    personas = json.load(open(os.path.join(root, "panel", "personas.json"), encoding="utf-8"))
    outcomes = {o["id"]: o for o in json.load(open(os.path.join(root, "panel", "outcomes.json"), encoding="utf-8"))}
    rules = json.load(open(os.path.join(root, "evidence", "impact_rules.json"), encoding="utf-8"))
    have = set()
    for f in glob.glob(os.path.join(root, "panel", "narratives", "batch_*.jsonl")):
        for l in open(f, encoding="utf-8"):
            l = l.strip()
            if l:
                try:
                    have.add(json.loads(l)["id"])
                except Exception:
                    pass
    out = []
    for p in personas:
        if p["id"] in have:
            continue
        out.append(build(p, outcomes[p["id"]], rules))
    path = os.path.join(root, "panel", "narratives", "fallback_engine.jsonl")
    with open(path, "w", encoding="utf-8") as f:
        for rec in out:
            f.write(json.dumps(rec, ensure_ascii=False) + "\n")
    print(f"LLM narratives present: {len(have)}; engine-template fallbacks written: {len(out)} -> {path}")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
    main(os.path.abspath(ap.parse_args().root))

#!/usr/bin/env python3
"""
Synthetic 1,000-person panel for the Andaz market simulation (2026-11-03).

Every attribute distribution below is a MODELLING ASSUMPTION. Where a public
source informed the assumption it is named in the report; nothing here is a
sample of real people. Reactions are produced by a transparent scoring model
with seeded noise so that every aggregate in the report is derived from the
same 1,000 rows (counts always sum to 1,000).

Run:  python3 build_panel.py  -> writes panel.csv and summary.json next to it.
"""
import csv, json, random, statistics, os
from collections import Counter, defaultdict

SEED = 20261103
rng = random.Random(SEED)
HERE = os.path.dirname(os.path.abspath(__file__))

def pick(dist):
    """dist: list of (label, weight). Weights need not sum to 1."""
    r = rng.random() * sum(w for _, w in dist)
    acc = 0.0
    for label, w in dist:
        acc += w
        if r <= acc:
            return label
    return dist[-1][0]

# ---------------------------------------------------------------- attributes
AGE = [("18-24", 16), ("25-34", 31), ("35-44", 24), ("45-54", 15), ("55-64", 9), ("65+", 5)]
GENDER = [("female", 48), ("male", 52)]
LOCATION = [("erbil_city", 58), ("erbil_governorate_town_rural", 17), ("other_kri", 10),
            ("rest_of_iraq", 8), ("diaspora_abroad", 7)]
INCOME = [("<500", 18), ("500-1000", 32), ("1000-2000", 28), ("2000-4000", 15), (">4000", 7)]  # USD/month household
EDUCATION = [("below_secondary", 15), ("secondary", 30), ("diploma_or_bachelor", 45), ("postgraduate", 10)]
OCCUPATION = [("public_employee", 25), ("private_employee", 19), ("self_employed_business", 17),
              ("student", 10), ("homemaker", 9), ("unemployed", 7), ("retired", 6),
              ("real_estate_agent", 4), ("developer_or_market_professional", 3)]
HOUSING = [("owner_occupier", 45), ("renter", 25), ("living_with_family", 25), ("other", 5)]
DIGITAL = [("low", 20), ("medium", 45), ("high", 35)]
READING = [("low", 15), ("medium", 35), ("high", 50)]
NUMERACY = [("low", 30), ("medium", 45), ("high", 25)]
STYLE = [("analytical", 20), ("practical", 25), ("emotional", 12), ("skeptical", 18),
         ("impatient", 10), ("traditional", 10), ("early_adopter", 5)]
AI_ATT = [("enthusiastic", 18), ("cautious", 42), ("resistant", 17), ("indifferent", 23)]
READINESS = [("immediate_0_6m", 9), ("future_6_24m", 20), ("casual_browsing", 26), ("no_current_need", 45)]
DEVICE = [("android_phone", 72), ("iphone", 24), ("desktop_mainly", 4)]
PAYMENT = [("digital_wallet_or_card", 45), ("cash_only", 55)]

def experience_for(occ):
    if occ == "real_estate_agent":
        return "agent"
    if occ == "developer_or_market_professional":
        return "developer_or_professional"
    return pick([("none", 40), ("beginner", 28), ("occasional", 19), ("experienced_owner", 9), ("investor", 4)])

def language_for(loc):
    if loc == "rest_of_iraq":
        return pick([("arabic", 85), ("sorani", 10), ("english", 5)])
    if loc == "other_kri":
        return pick([("sorani", 55), ("badini_or_other", 35), ("arabic", 10)])
    if loc == "diaspora_abroad":
        return pick([("sorani", 55), ("english", 30), ("arabic", 15)])
    return pick([("sorani", 80), ("arabic", 12), ("english", 4), ("badini_or_other", 4)])

def life_circumstance(age, occ):
    if occ == "student":
        return "student"
    if occ == "retired":
        return "retiree"
    if occ == "homemaker":
        return pick([("busy_parent", 70), ("homemaker_no_children", 30)])
    if occ == "unemployed":
        return "unemployed"
    if occ == "self_employed_business":
        return "business_owner"
    if age in ("25-34", "35-44", "45-54"):
        return pick([("busy_parent", 55), ("salaried_worker", 45)])
    return "salaried_worker"

panel = []
for i in range(1, 1001):
    age = pick(AGE)
    occ = pick(OCCUPATION)
    # A 65+ person is rarely a student; keep plausibility without hard rules.
    if age == "65+" and occ == "student":
        occ = "retired"
    if age == "18-24" and occ == "retired":
        occ = "student"
    loc = pick(LOCATION)
    p = dict(
        id=f"P{i:04d}", age=age, gender=pick(GENDER), location=loc, income_usd_month=pick(INCOME),
        education=pick(EDUCATION), occupation=occ, housing=pick(HOUSING),
        experience=experience_for(occ), digital_skill=pick(DIGITAL), reading_confidence=pick(READING),
        numeracy_terminology=pick(NUMERACY), decision_style=pick(STYLE), ai_attitude=pick(AI_ATT),
        buying_readiness=pick(READINESS), device=pick(DEVICE), payment_access=pick(PAYMENT),
        primary_language=language_for(loc),
    )
    p["life_circumstance"] = life_circumstance(age, occ)
    if p["experience"] in ("investor",) and p["buying_readiness"] == "no_current_need":
        p["buying_readiness"] = "casual_browsing"
    panel.append(p)

# ------------------------------------------------------------ target segments
def is_core_target(p):
    return (p["buying_readiness"] in ("immediate_0_6m", "future_6_24m")
            or p["experience"] in ("investor", "agent", "developer_or_professional", "experienced_owner")
            or (p["housing"] == "owner_occupier" and p["experience"] != "none"))

def segment(p):
    if p["experience"] == "agent":
        return "agents_brokers"
    if p["experience"] == "developer_or_professional":
        return "developers_professionals"
    if p["experience"] == "investor":
        return "individual_investors"
    if p["location"] == "diaspora_abroad":
        return "diaspora"
    if p["location"] == "rest_of_iraq":
        return "rest_of_iraq_considering_erbil"
    if p["buying_readiness"] == "immediate_0_6m":
        return "active_buyers_0_6m"
    if p["buying_readiness"] == "future_6_24m":
        return "future_buyers_6_24m"
    if p["housing"] == "owner_occupier" and p["experience"] in ("experienced_owner", "occasional"):
        return "owners_curious_about_value"
    if p["buying_readiness"] == "casual_browsing":
        return "casual_browsers"
    return "no_current_need"

for p in panel:
    p["segment"] = segment(p)
    p["core_target"] = is_core_target(p)

# ----------------------------------------------------------------- reactions
def base_score(p):
    s = 0.0
    s += {"immediate_0_6m": 2.2, "future_6_24m": 1.3, "casual_browsing": 0.2, "no_current_need": -1.4}[p["buying_readiness"]]
    s += {"none": -0.3, "beginner": 0.5, "occasional": 0.5, "experienced_owner": 0.6, "investor": 1.2,
          "agent": 0.1, "developer_or_professional": 0.4}[p["experience"]]
    s += {"low": -1.1, "medium": 0.0, "high": 0.6}[p["digital_skill"]]
    s += {"low": -0.7, "medium": 0.0, "high": 0.3}[p["reading_confidence"]]
    s += {"low": -0.4, "medium": 0.0, "high": 0.3}[p["numeracy_terminology"]]
    s += {"analytical": 0.8, "practical": 0.2, "emotional": 0.0, "skeptical": -0.7, "impatient": -0.5,
          "traditional": -0.9, "early_adopter": 1.1}[p["decision_style"]]
    s += {"enthusiastic": 0.7, "cautious": 0.0, "resistant": -0.9, "indifferent": -0.4}[p["ai_attitude"]]
    s += {"18-24": 0.1, "25-34": 0.3, "35-44": 0.3, "45-54": 0.0, "55-64": -0.4, "65+": -0.8}[p["age"]]
    s += {"erbil_city": 0.3, "erbil_governorate_town_rural": -0.2, "other_kri": -0.3, "rest_of_iraq": -0.1,
          "diaspora_abroad": 0.9}[p["location"]]
    s += {"<500": -0.4, "500-1000": -0.1, "1000-2000": 0.1, "2000-4000": 0.3, ">4000": 0.3}[p["income_usd_month"]]
    # Sorani primary: product is Sorani-first (repo), but the advisor's Sorani path has no model (repo). Small net plus.
    s += {"sorani": 0.2, "arabic": 0.0, "english": 0.1, "badini_or_other": -0.3}[p["primary_language"]]
    if p["housing"] == "renter" and p["buying_readiness"] in ("immediate_0_6m", "future_6_24m"):
        s += 0.3
    return s

# Conservative baseline: thresholds chosen so that >=40% of first reactions are
# indifferent/confused/negative. This is an imposed stress-test constraint.
def classify(score, p, noise):
    v = score + noise
    if p["digital_skill"] == "low" and rng.random() < 0.45:
        return "confused"
    if p["experience"] == "agent" and rng.random() < 0.35:
        return "negative"
    if v >= 2.0:
        return "positive"
    if v >= 0.6:
        return "curious"
    if v >= -0.6:
        return "indifferent"
    if v >= -1.6:
        return "confused" if rng.random() < 0.35 else "negative"
    return "negative"

def usage(reaction, p, v):
    if reaction == "positive":
        return "would_use"
    if reaction == "curious":
        return "would_use" if v >= 1.3 and rng.random() < 0.6 else "might_use"
    if reaction == "indifferent":
        return "might_use" if rng.random() < 0.35 else "would_not_use"
    if reaction == "confused":
        return "might_use" if rng.random() < 0.25 else "would_not_use"
    return "would_not_use" if rng.random() < 0.85 else "might_use"

def return_pattern(use, p):
    if use == "would_not_use":
        return "never"
    pro = p["experience"] in ("agent", "developer_or_professional", "investor")
    if use == "would_use":
        if pro:
            return pick([("regular_weekly_or_more", 55), ("occasional_during_decision", 35), ("once", 10)])
        if p["buying_readiness"] in ("immediate_0_6m", "future_6_24m"):
            return pick([("regular_weekly_or_more", 20), ("occasional_during_decision", 65), ("once", 15)])
        return pick([("regular_weekly_or_more", 6), ("occasional_during_decision", 44), ("once", 50)])
    # might_use
    if pro:
        return pick([("regular_weekly_or_more", 15), ("occasional_during_decision", 45), ("once", 40)])
    return pick([("regular_weekly_or_more", 2), ("occasional_during_decision", 30), ("once", 68)])

def pay(use, p):
    if use == "would_not_use":
        return "would_not_pay"
    pro = p["experience"] in ("agent", "developer_or_professional", "investor")
    inc = p["income_usd_month"]
    cash_pen = 0.5 if p["payment_access"] == "cash_only" else 1.0
    if use == "would_use":
        base = 0.42 if pro else {"immediate_0_6m": 0.30, "future_6_24m": 0.16, "casual_browsing": 0.06, "no_current_need": 0.03}[p["buying_readiness"]]
    else:
        base = 0.12 if pro else {"immediate_0_6m": 0.08, "future_6_24m": 0.05, "casual_browsing": 0.02, "no_current_need": 0.01}[p["buying_readiness"]]
    base *= {"<500": 0.5, "500-1000": 0.8, "1000-2000": 1.0, "2000-4000": 1.3, ">4000": 1.5}[inc]
    base *= cash_pen
    if p["location"] == "diaspora_abroad":
        base *= 1.6
    r = rng.random()
    if r < base:
        return "would_pay"
    if r < base + (0.35 if use == "would_use" else 0.22):
        return "might_pay"
    return "would_not_pay"

def wtp(p, payint):
    """Stated willingness to pay, by service. USD unless noted. Zero allowed."""
    if payint == "would_not_pay":
        return dict(per_question_usd=0, valuation_report_usd=0, monthly_pro_usd=0)
    pro = p["experience"] in ("agent", "developer_or_professional", "investor")
    mult = {"<500": 0.5, "500-1000": 0.75, "1000-2000": 1.0, "2000-4000": 1.4, ">4000": 2.0}[p["income_usd_month"]]
    if p["location"] == "diaspora_abroad":
        mult *= 1.5
    soft = 0.6 if payint == "might_pay" else 1.0
    q = round(rng.choice([0.25, 0.5, 0.75, 1, 1.5, 2]) * mult * soft, 2)
    rep = round(rng.choice([3, 5, 8, 10, 15, 20, 25]) * mult * soft, 0)
    if pro:
        monthly = round(rng.choice([10, 15, 20, 30, 50, 75, 100]) * mult * soft, 0)
    else:
        monthly = round(rng.choice([0, 0, 0, 2, 3, 5, 8]) * mult * soft, 0)
    return dict(per_question_usd=q, valuation_report_usd=rep, monthly_pro_usd=monthly)

OBJECTIONS = {
    "where_do_prices_come_from": "Trust: who verifies the prices? Asking price vs real deal price unclear",
    "register_or_verify_before_value": "Friction: must register / verify phone (Telegram or WhatsApp) before the advisor or valuation",
    "stale_or_thin_data": "Data: too few projects/prices, or figures could be out of date",
    "prefer_agent_or_family": "Habit: I ask a broker, a relative, or a Facebook group instead",
    "ai_cannot_be_trusted_for_money": "AI distrust: a chatbot should not advise on a 100,000 dollar decision",
    "no_current_need": "Relevance: I am not buying or selling anything",
    "sponsored_bias_suspected": "Bias: developers or advertisers may pay to look good",
    "too_complex_or_too_much_text": "Usability: map, charts and terminology are too much on a phone",
    "paying_for_information": "Pricing: information should be free; I will not pay for answers",
    "cannot_pay_online": "Payment: no card or wallet, or I do not trust online payment",
    "not_a_listings_site": "Expectation gap: I wanted listings with phone numbers, there is no 'call the owner'",
    "threat_to_my_business": "Professional conflict: price transparency weakens my negotiating position",
    "name_unclear": "Brand: 'Andaz' does not tell me what it does",
    "sorani_quality_or_language": "Language: wording is formal or technical in Sorani/Arabic; or my dialect is not served",
    "privacy_of_my_property_data": "Privacy: I do not want to enter my property and price into a website",
    "valuation_is_only_an_estimate": "Value: an estimate range is not an official valuation I can use",
}

def objections_for(p, reaction, use):
    probs = defaultdict(float)
    probs["where_do_prices_come_from"] = 0.42 if p["decision_style"] in ("skeptical", "analytical") else 0.30
    probs["register_or_verify_before_value"] = 0.38 if p["decision_style"] in ("impatient", "skeptical") else 0.26
    probs["stale_or_thin_data"] = 0.33 if p["experience"] in ("investor", "agent", "developer_or_professional", "experienced_owner") else 0.18
    probs["prefer_agent_or_family"] = 0.40 if p["decision_style"] == "traditional" or p["digital_skill"] == "low" else 0.20
    probs["ai_cannot_be_trusted_for_money"] = {"enthusiastic": 0.04, "cautious": 0.22, "resistant": 0.60, "indifferent": 0.15}[p["ai_attitude"]]
    probs["no_current_need"] = 0.55 if p["buying_readiness"] == "no_current_need" else 0.05
    probs["sponsored_bias_suspected"] = 0.28 if p["decision_style"] in ("skeptical", "analytical") else 0.12
    probs["too_complex_or_too_much_text"] = {"low": 0.55, "medium": 0.18, "high": 0.06}[p["digital_skill"]] + (0.1 if p["reading_confidence"] == "low" else 0)
    probs["paying_for_information"] = {"<500": 0.40, "500-1000": 0.32, "1000-2000": 0.22, "2000-4000": 0.14, ">4000": 0.10}[p["income_usd_month"]]
    probs["cannot_pay_online"] = 0.30 if p["payment_access"] == "cash_only" else 0.03
    probs["not_a_listings_site"] = 0.30 if p["buying_readiness"] in ("immediate_0_6m", "future_6_24m") else 0.14
    probs["threat_to_my_business"] = 0.55 if p["experience"] == "agent" else (0.15 if p["experience"] == "developer_or_professional" else 0.0)
    probs["name_unclear"] = 0.14
    probs["sorani_quality_or_language"] = 0.22 if p["primary_language"] in ("badini_or_other",) else (0.08 if p["primary_language"] == "sorani" else 0.05)
    probs["privacy_of_my_property_data"] = 0.22 if p["housing"] == "owner_occupier" else 0.06
    probs["valuation_is_only_an_estimate"] = 0.20 if p["housing"] == "owner_occupier" or p["experience"] == "investor" else 0.06
    if reaction in ("negative", "confused"):
        for k in probs:
            probs[k] *= 1.3
    chosen = [k for k, v in probs.items() if rng.random() < v]
    return chosen

ATTRACTIONS = {
    "prices_in_one_place": "Project prices and price history in one place instead of calling five offices",
    "map_with_boundaries": "Seeing where a project actually is, its boundary and what is nearby",
    "asking_vs_verified_labelled": "Asking price and verified deal price are kept separate and labelled",
    "sorani_first": "Works properly in Sorani (and Arabic) with RTL",
    "ai_advisor_structured": "The advisor organises my budget and needs and points to matching projects",
    "portfolio_value_tracking": "A private place to track my properties and an estimated value range",
    "compare_areas": "Comparing two or three areas side by side on the same evidence",
    "remote_research_from_abroad": "Researching Erbil from abroad before trusting a relative with my money",
    "developer_status_and_delays": "Construction status and delay warnings per project",
}

def attractions_for(p, reaction):
    probs = defaultdict(float)
    active = p["buying_readiness"] in ("immediate_0_6m", "future_6_24m")
    probs["prices_in_one_place"] = 0.55 if active else 0.30
    probs["map_with_boundaries"] = 0.40 if p["digital_skill"] != "low" else 0.15
    probs["asking_vs_verified_labelled"] = 0.45 if p["decision_style"] in ("analytical", "skeptical") or p["experience"] in ("investor", "developer_or_professional") else 0.15
    probs["sorani_first"] = 0.45 if p["primary_language"] == "sorani" and p["reading_confidence"] != "high" else (0.25 if p["primary_language"] == "sorani" else 0.05)
    probs["ai_advisor_structured"] = {"enthusiastic": 0.55, "cautious": 0.25, "resistant": 0.03, "indifferent": 0.12}[p["ai_attitude"]]
    probs["portfolio_value_tracking"] = 0.40 if p["housing"] == "owner_occupier" or p["experience"] in ("investor", "experienced_owner") else 0.06
    probs["compare_areas"] = 0.30 if active or p["experience"] == "investor" else 0.10
    probs["remote_research_from_abroad"] = 0.70 if p["location"] in ("diaspora_abroad", "rest_of_iraq") else 0.0
    probs["developer_status_and_delays"] = 0.45 if active or p["experience"] in ("investor", "experienced_owner") else 0.15
    if reaction in ("negative", "indifferent"):
        for k in probs:
            probs[k] *= 0.5
    return [k for k, v in probs.items() if rng.random() < v]

def simulate(constrained=True):
    rows = []
    rng.seed(SEED + (1 if constrained else 2))
    for p in panel:
        s = base_score(p)
        noise = rng.gauss(0, 0.9)
        if constrained:
            reaction = classify(s, p, noise)
        else:
            # Unconstrained variant: same model, no forced confusion/agent-negative overrides,
            # and thresholds relaxed by 0.5 (a more generous reception).
            v = s + noise
            reaction = ("positive" if v >= 1.5 else "curious" if v >= 0.1 else "indifferent" if v >= -1.1
                        else ("confused" if rng.random() < 0.35 else "negative") if v >= -2.1 else "negative")
        v = s + noise
        use = usage(reaction, p, v)
        ret = return_pattern(use, p)
        payint = pay(use, p)
        row = dict(p)
        row.update(first_reaction=reaction, usage_intent=use, return_pattern=ret, payment_intent=payint,
                   **wtp(p, payint))
        row["objections"] = ";".join(objections_for(p, reaction, use))
        row["attractions"] = ";".join(attractions_for(p, reaction))
        rows.append(row)
    return rows

def pct(n, d):
    return round(100.0 * n / d, 1)

def table(rows, key, denom=None):
    c = Counter(r[key] for r in rows)
    d = denom or len(rows)
    return {k: {"count": v, "pct": pct(v, d)} for k, v in sorted(c.items(), key=lambda kv: -kv[1])}

def summarise(rows, label):
    n = len(rows)
    out = {"label": label, "n": n}
    for key in ["first_reaction", "usage_intent", "return_pattern", "payment_intent"]:
        out[key] = table(rows, key)
    cross = Counter((r["usage_intent"], r["payment_intent"]) for r in rows)
    out["use_x_pay"] = {f"{u}|{pm}": c for (u, pm), c in sorted(cross.items())}
    out["negative_indifferent_confused_pct"] = pct(sum(1 for r in rows if r["first_reaction"] in ("negative", "indifferent", "confused")), n)
    # segments
    seg = defaultdict(list)
    for r in rows:
        seg[r["segment"]].append(r)
    out["segments"] = {}
    for s, rs in sorted(seg.items(), key=lambda kv: -len(kv[1])):
        m = len(rs)
        out["segments"][s] = {
            "n": m,
            "would_use_pct": pct(sum(1 for r in rs if r["usage_intent"] == "would_use"), m),
            "might_use_pct": pct(sum(1 for r in rs if r["usage_intent"] == "might_use"), m),
            "would_not_use_pct": pct(sum(1 for r in rs if r["usage_intent"] == "would_not_use"), m),
            "would_pay_pct": pct(sum(1 for r in rs if r["payment_intent"] == "would_pay"), m),
            "might_pay_pct": pct(sum(1 for r in rs if r["payment_intent"] == "might_pay"), m),
            "regular_return_pct": pct(sum(1 for r in rs if r["return_pattern"] == "regular_weekly_or_more"), m),
            "top_objections": [k for k, _ in Counter(o for r in rs for o in r["objections"].split(";") if o).most_common(3)],
        }
    # core vs non-core
    core = [r for r in rows if r["core_target"]]
    noncore = [r for r in rows if not r["core_target"]]
    out["core_target"] = {"n": len(core), "usage_intent": table(core, "usage_intent"), "payment_intent": table(core, "payment_intent"),
                          "first_reaction": table(core, "first_reaction")}
    out["non_target"] = {"n": len(noncore), "usage_intent": table(noncore, "usage_intent"), "payment_intent": table(noncore, "payment_intent")}
    # objections (multi-select; denominator = all 1,000)
    oc = Counter(o for r in rows for o in r["objections"].split(";") if o)
    out["objections"] = [{"key": k, "label": OBJECTIONS[k], "count": v, "pct_of_panel": pct(v, n)} for k, v in oc.most_common()]
    out["mean_objections_per_person"] = round(sum(len([o for o in r["objections"].split(";") if o]) for r in rows) / n, 2)
    ac = Counter(a for r in rows for a in r["attractions"].split(";") if a)
    out["attractions"] = [{"key": k, "label": ATTRACTIONS[k], "count": v, "pct_of_panel": pct(v, n)} for k, v in ac.most_common()]
    # WTP by segment among would/might pay
    def q(vals, qq):
        vals = sorted(vals)
        if not vals:
            return None
        idx = min(len(vals) - 1, int(qq * (len(vals) - 1)))
        return vals[idx]
    out["wtp"] = {}
    groups = {
        "consumers_active_buyers": [r for r in rows if r["segment"] in ("active_buyers_0_6m", "future_buyers_6_24m")],
        "owners_curious_about_value": [r for r in rows if r["segment"] == "owners_curious_about_value"],
        "diaspora": [r for r in rows if r["segment"] == "diaspora"],
        "individual_investors": [r for r in rows if r["segment"] == "individual_investors"],
        "agents_brokers": [r for r in rows if r["segment"] == "agents_brokers"],
        "developers_professionals": [r for r in rows if r["segment"] == "developers_professionals"],
        "casual_or_no_need": [r for r in rows if r["segment"] in ("casual_browsers", "no_current_need", "rest_of_iraq_considering_erbil")],
    }
    for g, rs in groups.items():
        payers = [r for r in rs if r["payment_intent"] in ("would_pay", "might_pay")]
        out["wtp"][g] = {
            "n_segment": len(rs), "n_would_or_might_pay": len(payers),
            "zero_payment_pct": pct(len(rs) - len(payers), len(rs)) if rs else None,
            "per_question_usd_p25_p50_p75": [q([r["per_question_usd"] for r in payers], x) for x in (0.25, 0.5, 0.75)],
            "valuation_report_usd_p25_p50_p75": [q([r["valuation_report_usd"] for r in payers], x) for x in (0.25, 0.5, 0.75)],
            "monthly_pro_usd_p25_p50_p75": [q([r["monthly_pro_usd"] for r in payers], x) for x in (0.25, 0.5, 0.75)],
        }
    return out

def composition():
    comp = {}
    for key in ["age", "gender", "location", "income_usd_month", "education", "occupation", "housing", "experience",
                "digital_skill", "reading_confidence", "numeracy_terminology", "decision_style", "ai_attitude",
                "buying_readiness", "device", "payment_access", "primary_language", "life_circumstance", "segment"]:
        comp[key] = table(panel, key)
    comp["core_target"] = table(panel, "core_target")
    return comp

if __name__ == "__main__":
    constrained = simulate(True)
    unconstrained = simulate(False)
    fields = list(constrained[0].keys())
    with open(os.path.join(HERE, "panel.csv"), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        w.writerows(constrained)
    summary = {
        "seed": SEED,
        "composition": composition(),
        "conservative_baseline": summarise(constrained, "conservative baseline (>=40% non-positive first reactions imposed)"),
        "unconstrained_variant": summarise(unconstrained, "unconstrained variant (same model, forced overrides removed, thresholds relaxed)"),
    }
    with open(os.path.join(HERE, "summary.json"), "w", encoding="utf-8") as f:
        json.dump(summary, f, indent=1, ensure_ascii=False)
    print(json.dumps({k: summary["conservative_baseline"][k] for k in ["n", "first_reaction", "usage_intent", "return_pattern", "payment_intent", "use_x_pay", "negative_indifferent_confused_pct", "mean_objections_per_person"]}, indent=1))

#!/usr/bin/env python3
"""
Andaz audit — synthetic panel generator (P0001–P1000).

What this is
------------
A deterministic generator for 1,000 AI-authored persona records used as a
purposive stress-test sample for the Andaz website audit. The personas are
NOT real research participants and the panel is NOT a probability sample of
the Erbil / Kurdistan Region market. Quotas were chosen to stress-test the
product across ages, incomes, languages, devices, abilities and intents, not
to mirror population weights.

Design
------
* Exact quotas (enforced by shuffled allocation pools) on four primary
  dimensions: age band, location context, primary interface language, and
  property intent. Constrained bands (teens 14–17, adults 75+) draw intents
  from restricted pools so minors are never independent purchasers.
* Every other attribute is drawn from conditional distributions that depend
  on the primary dimensions (e.g. digital confidence varies by age band but
  every band contains low AND very-high confidence people; income depends on
  occupation and location with noise). Realised counts are reported in
  composition.json and must be read as the actual panel composition.
* Goal / concern / abandonment-trigger sentences are assembled from
  intent-specific template libraries combined with persona attributes, so
  no two records share the same full attribute vector (checked).
* Outcome fields are left as "pending_evidence". They are filled by
  evaluate_panel.py only after the live-site evidence log exists. Nothing in
  this file encodes a judgement about the website.

Run:  python3 -I generate_panel.py --out ../panel
"""
from __future__ import annotations

import argparse
import csv
import json
import os
import random
from collections import Counter, OrderedDict

SEED = 20261103
N = 1000
rng = random.Random(SEED)

# --------------------------------------------------------------------------
# Primary quotas (exact)
# --------------------------------------------------------------------------
AGE_BANDS = OrderedDict([
    ("14-17", 40), ("18-24", 150), ("25-34", 250), ("35-44", 220),
    ("45-54", 150), ("55-64", 100), ("65-74", 55), ("75+", 35),
])
AGE_RANGE = {
    "14-17": (14, 17), "18-24": (18, 24), "25-34": (25, 34), "35-44": (35, 44),
    "45-54": (45, 54), "55-64": (55, 64), "65-74": (65, 74), "75+": (75, 88),
}
LOCATIONS = OrderedDict([
    ("erbil_centre", 300), ("erbil_suburb", 250), ("erbil_rural", 100),
    ("kri_other_city", 120), ("iraq_other", 80), ("diaspora", 150),
])
LANGUAGES = OrderedDict([
    ("ckb", 520),   # Sorani Kurdish
    ("ar", 270),    # Arabic
    ("en", 160),    # English
    ("other", 50),  # Kurmanji/Badini, Turkmen, Assyrian — reads a second language
])
INTENTS = OrderedDict([
    ("renter", 170), ("first_time_buyer", 180), ("upgrader", 90), ("seller", 70),
    ("landlord", 60), ("investor_local", 80), ("investor_diaspora", 60),
    ("broker", 50), ("developer_staff", 20), ("researching", 120), ("no_need", 100),
])
assert sum(AGE_BANDS.values()) == N
assert sum(LOCATIONS.values()) == N
assert sum(LANGUAGES.values()) == N
assert sum(INTENTS.values()) == N

# Intents allowed per age band (minors are never purchasers; 75+ realistic set)
INTENTS_ALLOWED = {
    "14-17": {"no_need", "researching"},
    "18-24": set(INTENTS) - {"developer_staff"} | {"developer_staff"},
    "75+": {"seller", "landlord", "researching", "no_need", "renter", "investor_local",
            "investor_diaspora", "upgrader"},
}
# Weights that make intent pools realistic within bands without forbidding variety
INTENT_WEIGHTS_BY_BAND = {
    "14-17": {"no_need": 3, "researching": 2},
    "18-24": {"renter": 5, "first_time_buyer": 2, "researching": 3, "no_need": 2,
              "broker": 1, "developer_staff": 1, "investor_local": 0.5,
              "investor_diaspora": 0.5, "upgrader": 0.2, "seller": 0.2, "landlord": 0.2},
    "25-34": {"renter": 4, "first_time_buyer": 5, "researching": 2, "no_need": 1,
              "broker": 1.5, "developer_staff": 1, "investor_local": 1.5,
              "investor_diaspora": 1.5, "upgrader": 1, "seller": 0.5, "landlord": 0.7},
    "35-44": {"renter": 2, "first_time_buyer": 3, "researching": 1.5, "no_need": 1,
              "broker": 1.5, "developer_staff": 1, "investor_local": 2,
              "investor_diaspora": 2, "upgrader": 3, "seller": 1.5, "landlord": 1.5},
    "45-54": {"renter": 1, "first_time_buyer": 1.5, "researching": 1.5, "no_need": 1,
              "broker": 1, "developer_staff": 0.7, "investor_local": 2,
              "investor_diaspora": 1.5, "upgrader": 2.5, "seller": 2.5, "landlord": 2.5},
    "55-64": {"renter": 0.7, "first_time_buyer": 0.7, "researching": 1.5, "no_need": 1.5,
              "broker": 0.7, "developer_staff": 0.3, "investor_local": 1.5,
              "investor_diaspora": 1, "upgrader": 1.5, "seller": 3, "landlord": 3},
    "65-74": {"renter": 0.7, "first_time_buyer": 0.3, "researching": 1.5, "no_need": 2,
              "broker": 0.3, "developer_staff": 0.1, "investor_local": 1,
              "investor_diaspora": 0.7, "upgrader": 1, "seller": 3, "landlord": 2.5},
    "75+": {"renter": 0.7, "researching": 1.5, "no_need": 3, "investor_local": 0.5,
            "investor_diaspora": 0.3, "upgrader": 0.5, "seller": 3, "landlord": 2},
}

# Language weights by location (pool-constrained → exact totals still hold)
LANG_WEIGHTS_BY_LOC = {
    "erbil_centre": {"ckb": 6, "ar": 2.5, "en": 1.2, "other": 0.6},
    "erbil_suburb": {"ckb": 7, "ar": 2, "en": 0.6, "other": 0.5},
    "erbil_rural": {"ckb": 8, "ar": 1, "en": 0.2, "other": 0.4},
    "kri_other_city": {"ckb": 6, "ar": 1.5, "en": 0.8, "other": 1.5},
    "iraq_other": {"ckb": 0.6, "ar": 7, "en": 1, "other": 0.4},
    "diaspora": {"ckb": 3, "ar": 1.5, "en": 5, "other": 0.5},
}
# Diaspora investors must live in the diaspora; local investors must not.
LOC_CONSTRAINTS_BY_INTENT = {
    "investor_diaspora": {"diaspora"},
    "investor_local": set(LOCATIONS) - {"diaspora"},
}


def weighted_pick(pool: Counter, weights: dict, allowed: set | None = None) -> str:
    """Pick a key from the remaining pool using weights; falls back to any remaining key."""
    candidates = [k for k, c in pool.items() if c > 0 and (allowed is None or k in allowed)]
    if not candidates:
        candidates = [k for k, c in pool.items() if c > 0]
    w = [max(weights.get(k, 0.05), 0.01) for k in candidates]
    choice = rng.choices(candidates, weights=w, k=1)[0]
    pool[choice] -= 1
    return choice


def pick(dist: dict) -> str:
    keys = list(dist.keys())
    return rng.choices(keys, weights=[dist[k] for k in keys], k=1)[0]


# --------------------------------------------------------------------------
# Secondary attributes (conditional)
# --------------------------------------------------------------------------
GENDER_DIST = {"female": 48.5, "male": 50.5, "unspecified": 1.0}

EDUCATION_BY_BAND = {
    "14-17": {"in_secondary_school": 100},
    "18-24": {"secondary": 30, "vocational_diploma": 15, "university_student": 40, "bachelor": 15},
    "25-34": {"primary_or_none": 5, "secondary": 25, "vocational_diploma": 15, "bachelor": 42, "postgraduate": 13},
    "35-44": {"primary_or_none": 8, "secondary": 30, "vocational_diploma": 15, "bachelor": 37, "postgraduate": 10},
    "45-54": {"primary_or_none": 12, "secondary": 33, "vocational_diploma": 15, "bachelor": 32, "postgraduate": 8},
    "55-64": {"primary_or_none": 18, "secondary": 35, "vocational_diploma": 12, "bachelor": 28, "postgraduate": 7},
    "65-74": {"primary_or_none": 28, "secondary": 35, "vocational_diploma": 10, "bachelor": 22, "postgraduate": 5},
    "75+": {"primary_or_none": 40, "secondary": 33, "vocational_diploma": 7, "bachelor": 16, "postgraduate": 4},
}

# Occupations (user-specified list is fully covered). Specialist flag marks
# people whose professional judgement is reported separately.
OCCUPATIONS = {
    # key: (label_en, specialist_domain or None)
    "real_estate_agent": ("Real estate agent / broker", "real_estate"),
    "developer_staff": ("Property developer staff (sales/marketing)", "real_estate"),
    "architect": ("Architect", "built_environment"),
    "civil_engineer": ("Civil engineer", "built_environment"),
    "interior_designer": ("Interior designer", "design"),
    "ux_designer": ("UX / product designer", "design"),
    "software_engineer": ("Software engineer", "technology"),
    "it_support": ("IT support technician", "technology"),
    "lawyer": ("Lawyer", "legal"),
    "accountant": ("Accountant", "finance"),
    "bank_employee": ("Bank employee", "finance"),
    "teacher": ("School teacher", None),
    "university_lecturer": ("University lecturer", None),
    "student_university": ("University student", None),
    "student_secondary": ("Secondary-school student", None),
    "doctor": ("Doctor", None),
    "nurse": ("Nurse", None),
    "pharmacist": ("Pharmacist", None),
    "civil_servant": ("Civil servant (government employee)", None),
    "police_security": ("Police / security forces", None),
    "shopkeeper": ("Shopkeeper", None),
    "entrepreneur": ("Small-business owner / entrepreneur", None),
    "taxi_driver": ("Taxi / delivery driver", None),
    "truck_driver": ("Truck driver", None),
    "electrician": ("Electrician", None),
    "plumber": ("Plumber", None),
    "carpenter": ("Carpenter / furniture maker", None),
    "construction_worker": ("Construction worker", None),
    "farmer": ("Farmer / livestock", None),
    "restaurant_worker": ("Restaurant / hotel worker", None),
    "hotel_manager": ("Hotel / hospitality manager", None),
    "barber_salon": ("Barber / salon worker", None),
    "athlete": ("Professional athlete", None),
    "coach": ("Sports coach / gym trainer", None),
    "homemaker": ("Homemaker", None),
    "retired": ("Retired", None),
    "unemployed": ("Unemployed / looking for work", None),
    "oil_gas_worker": ("Oil & gas field worker", None),
    "ngo_worker": ("NGO / humanitarian worker", None),
    "journalist": ("Journalist / media", None),
    "translator": ("Translator / interpreter", None),
    "tailor": ("Tailor", None),
    "mechanic": ("Car mechanic", None),
    "nurse_assistant": ("Care worker / nursing assistant", None),
    "marketing": ("Marketing / social-media manager", None),
    "data_analyst": ("Data analyst", "technology"),
    "surveyor": ("Land surveyor", "built_environment"),
    "pensioner_farmer": ("Retired farmer", None),
    "cleaner": ("Cleaner / domestic worker", None),
    "security_guard": ("Security guard", None),
}

OCC_WEIGHTS_BY_BAND = {
    "14-17": {"student_secondary": 100},
    "18-24": {"student_university": 32, "unemployed": 8, "restaurant_worker": 6, "barber_salon": 4,
              "taxi_driver": 4, "shopkeeper": 3, "construction_worker": 4, "it_support": 3,
              "software_engineer": 4, "nurse": 3, "teacher": 3, "real_estate_agent": 3,
              "marketing": 3, "athlete": 2, "coach": 2, "homemaker": 3, "electrician": 2,
              "mechanic": 2, "tailor": 1, "cleaner": 2, "security_guard": 2, "ux_designer": 2,
              "developer_staff": 1, "civil_servant": 2, "farmer": 2},
    "25-34": {"software_engineer": 5, "civil_engineer": 4, "architect": 3, "teacher": 6,
              "nurse": 4, "doctor": 3, "pharmacist": 2, "civil_servant": 7, "shopkeeper": 4,
              "entrepreneur": 4, "taxi_driver": 4, "construction_worker": 3, "electrician": 2,
              "plumber": 1, "carpenter": 1, "restaurant_worker": 3, "barber_salon": 2,
              "homemaker": 6, "unemployed": 5, "real_estate_agent": 5, "developer_staff": 3,
              "lawyer": 2, "accountant": 3, "bank_employee": 2, "ux_designer": 2,
              "interior_designer": 2, "marketing": 3, "ngo_worker": 2, "journalist": 1,
              "translator": 1, "athlete": 1, "coach": 1, "oil_gas_worker": 2, "it_support": 2,
              "data_analyst": 2, "police_security": 3, "mechanic": 2, "cleaner": 1,
              "security_guard": 2, "surveyor": 1, "farmer": 2, "university_lecturer": 1},
    "35-44": {"software_engineer": 3, "civil_engineer": 4, "architect": 3, "teacher": 7,
              "nurse": 3, "doctor": 4, "pharmacist": 2, "civil_servant": 9, "shopkeeper": 6,
              "entrepreneur": 5, "taxi_driver": 4, "truck_driver": 2, "construction_worker": 3,
              "electrician": 2, "plumber": 2, "carpenter": 2, "restaurant_worker": 2,
              "hotel_manager": 1, "barber_salon": 1, "homemaker": 8, "unemployed": 3,
              "real_estate_agent": 5, "developer_staff": 3, "lawyer": 3, "accountant": 3,
              "bank_employee": 2, "interior_designer": 1, "marketing": 2, "ngo_worker": 2,
              "journalist": 1, "university_lecturer": 3, "oil_gas_worker": 2, "police_security": 3,
              "mechanic": 2, "farmer": 3, "cleaner": 1, "security_guard": 1, "surveyor": 1,
              "data_analyst": 1, "ux_designer": 1},
    "45-54": {"civil_engineer": 3, "architect": 2, "teacher": 7, "nurse": 2, "doctor": 4,
              "civil_servant": 11, "shopkeeper": 7, "entrepreneur": 5, "taxi_driver": 4,
              "truck_driver": 2, "construction_worker": 2, "electrician": 2, "plumber": 1,
              "carpenter": 2, "restaurant_worker": 1, "hotel_manager": 2, "homemaker": 9,
              "unemployed": 3, "real_estate_agent": 5, "developer_staff": 2, "lawyer": 3,
              "accountant": 3, "bank_employee": 2, "university_lecturer": 3, "oil_gas_worker": 1,
              "police_security": 2, "mechanic": 2, "farmer": 4, "software_engineer": 1,
              "marketing": 1, "journalist": 1, "cleaner": 1, "security_guard": 1, "coach": 1},
    "55-64": {"teacher": 6, "doctor": 3, "civil_servant": 10, "shopkeeper": 8, "entrepreneur": 4,
              "taxi_driver": 3, "carpenter": 1, "homemaker": 10, "retired": 14, "unemployed": 2,
              "real_estate_agent": 4, "lawyer": 2, "accountant": 2, "university_lecturer": 3,
              "farmer": 5, "civil_engineer": 2, "architect": 1, "hotel_manager": 1,
              "pensioner_farmer": 1, "security_guard": 1, "mechanic": 1, "developer_staff": 1},
    "65-74": {"retired": 45, "homemaker": 12, "shopkeeper": 8, "farmer": 6, "pensioner_farmer": 5,
              "real_estate_agent": 3, "university_lecturer": 2, "doctor": 2, "lawyer": 1,
              "entrepreneur": 3, "taxi_driver": 1, "civil_servant": 2},
    "75+": {"retired": 60, "homemaker": 15, "pensioner_farmer": 8, "shopkeeper": 4, "farmer": 3,
            "real_estate_agent": 1, "entrepreneur": 1},
}

# Occupation can be overridden by intent (brokers and developer staff)
INTENT_FORCES_OCC = {"broker": "real_estate_agent", "developer_staff": "developer_staff"}

INCOME_BANDS = ["very_low", "low", "middle", "upper_middle", "high"]  # USD/month household
INCOME_LABEL = {
    "very_low": "< $500 / month household", "low": "$500–1,000 / month", "middle": "$1,000–2,000 / month",
    "upper_middle": "$2,000–4,000 / month", "high": "> $4,000 / month",
}
INCOME_BASE_BY_OCC = {  # index into INCOME_BANDS (centre), noise applied
    "real_estate_agent": 2.6, "developer_staff": 2.8, "architect": 2.8, "civil_engineer": 2.7,
    "interior_designer": 2.4, "ux_designer": 2.8, "software_engineer": 3.0, "it_support": 2.0,
    "lawyer": 2.9, "accountant": 2.5, "bank_employee": 2.4, "teacher": 1.7, "university_lecturer": 2.5,
    "student_university": 0.9, "student_secondary": 1.6, "doctor": 3.3, "nurse": 1.8, "pharmacist": 2.5,
    "civil_servant": 1.8, "police_security": 1.7, "shopkeeper": 1.9, "entrepreneur": 2.7,
    "taxi_driver": 1.2, "truck_driver": 1.4, "electrician": 1.5, "plumber": 1.4, "carpenter": 1.5,
    "construction_worker": 0.9, "farmer": 1.1, "restaurant_worker": 0.9, "hotel_manager": 2.4,
    "barber_salon": 1.1, "athlete": 2.2, "coach": 1.5, "homemaker": 1.5, "retired": 1.3,
    "unemployed": 0.5, "oil_gas_worker": 2.6, "ngo_worker": 2.3, "journalist": 1.8,
    "translator": 1.8, "tailor": 1.0, "mechanic": 1.4, "nurse_assistant": 1.0, "marketing": 2.1,
    "data_analyst": 2.6, "surveyor": 2.3, "pensioner_farmer": 0.8, "cleaner": 0.5, "security_guard": 0.9,
}
LOC_INCOME_SHIFT = {"erbil_centre": 0.2, "erbil_suburb": 0.0, "erbil_rural": -0.5,
                    "kri_other_city": -0.1, "iraq_other": -0.1, "diaspora": 1.1}

EMPLOYMENT_BY_OCC = {
    "civil_servant": "stable_public", "teacher": "stable_public", "police_security": "stable_public",
    "university_lecturer": "stable_public", "doctor": "stable_mixed", "nurse": "stable_public",
    "retired": "pension", "pensioner_farmer": "pension", "homemaker": "no_own_income",
    "unemployed": "unemployed", "student_university": "student", "student_secondary": "student",
    "shopkeeper": "self_employed", "entrepreneur": "self_employed", "real_estate_agent": "commission",
    "taxi_driver": "irregular", "construction_worker": "irregular", "restaurant_worker": "irregular",
    "cleaner": "irregular", "security_guard": "irregular", "farmer": "seasonal",
}

HOUSING_BY_INTENT = {
    "renter": {"renting": 65, "with_family": 30, "temporary": 5},
    "first_time_buyer": {"renting": 55, "with_family": 42, "temporary": 3},
    "upgrader": {"owns_home": 100},
    "seller": {"owns_home": 70, "owns_multiple": 30},
    "landlord": {"owns_multiple": 85, "owns_home": 15},
    "investor_local": {"owns_home": 50, "owns_multiple": 30, "renting": 20},
    "investor_diaspora": {"owns_abroad": 55, "renting_abroad": 35, "owns_multiple": 10},
    "broker": {"renting": 35, "owns_home": 45, "with_family": 15, "owns_multiple": 5},
    "developer_staff": {"renting": 40, "owns_home": 40, "with_family": 20},
    "researching": {"renting": 35, "owns_home": 35, "with_family": 25, "owns_multiple": 5},
    "no_need": {"with_family": 45, "owns_home": 35, "renting": 20},
}

DIGITAL_BY_BAND = {  # low / medium / high / very_high
    "14-17": {"low": 6, "medium": 30, "high": 44, "very_high": 20},
    "18-24": {"low": 8, "medium": 30, "high": 42, "very_high": 20},
    "25-34": {"low": 10, "medium": 35, "high": 38, "very_high": 17},
    "35-44": {"low": 16, "medium": 42, "high": 32, "very_high": 10},
    "45-54": {"low": 26, "medium": 44, "high": 24, "very_high": 6},
    "55-64": {"low": 36, "medium": 42, "high": 18, "very_high": 4},
    "65-74": {"low": 48, "medium": 36, "high": 13, "very_high": 3},
    "75+": {"low": 60, "medium": 30, "high": 8, "very_high": 2},
}
PROPERTY_KNOWLEDGE_BY_INTENT = {
    "renter": {"none": 25, "basic": 45, "moderate": 25, "expert": 5},
    "first_time_buyer": {"none": 30, "basic": 45, "moderate": 22, "expert": 3},
    "upgrader": {"none": 5, "basic": 35, "moderate": 45, "expert": 15},
    "seller": {"none": 8, "basic": 37, "moderate": 40, "expert": 15},
    "landlord": {"none": 3, "basic": 30, "moderate": 47, "expert": 20},
    "investor_local": {"none": 3, "basic": 22, "moderate": 45, "expert": 30},
    "investor_diaspora": {"none": 10, "basic": 35, "moderate": 40, "expert": 15},
    "broker": {"none": 0, "basic": 5, "moderate": 35, "expert": 60},
    "developer_staff": {"none": 0, "basic": 10, "moderate": 45, "expert": 45},
    "researching": {"none": 35, "basic": 40, "moderate": 20, "expert": 5},
    "no_need": {"none": 50, "basic": 35, "moderate": 12, "expert": 3},
}
PATIENCE = {"low": 30, "medium": 45, "high": 25}
TRUST = {"skeptical": 35, "neutral": 45, "trusting": 20}
PRIVACY = {"high": 30, "medium": 45, "low": 25}
URGENCY_BY_INTENT = {
    "renter": {"immediate": 30, "soon": 45, "someday": 20, "none": 5},
    "first_time_buyer": {"immediate": 10, "soon": 45, "someday": 40, "none": 5},
    "upgrader": {"immediate": 8, "soon": 40, "someday": 47, "none": 5},
    "seller": {"immediate": 20, "soon": 50, "someday": 25, "none": 5},
    "landlord": {"immediate": 25, "soon": 45, "someday": 25, "none": 5},
    "investor_local": {"immediate": 10, "soon": 40, "someday": 45, "none": 5},
    "investor_diaspora": {"immediate": 5, "soon": 35, "someday": 55, "none": 5},
    "broker": {"immediate": 40, "soon": 40, "someday": 15, "none": 5},
    "developer_staff": {"immediate": 40, "soon": 40, "someday": 15, "none": 5},
    "researching": {"immediate": 0, "soon": 15, "someday": 55, "none": 30},
    "no_need": {"immediate": 0, "soon": 0, "someday": 20, "none": 80},
}

DEVICE_BY_INCOME = {
    "very_low": {"android_budget": 78, "android_flagship": 2, "iphone": 8, "desktop": 7, "tablet": 5},
    "low": {"android_budget": 65, "android_flagship": 6, "iphone": 16, "desktop": 9, "tablet": 4},
    "middle": {"android_budget": 45, "android_flagship": 12, "iphone": 28, "desktop": 10, "tablet": 5},
    "upper_middle": {"android_budget": 25, "android_flagship": 17, "iphone": 40, "desktop": 12, "tablet": 6},
    "high": {"android_budget": 10, "android_flagship": 18, "iphone": 52, "desktop": 14, "tablet": 6},
}
SCREEN_BY_DEVICE = {
    "android_budget": {"small_<=360px": 45, "medium_361-414px": 50, "large_phone_>414px": 5},
    "android_flagship": {"medium_361-414px": 55, "large_phone_>414px": 45},
    "iphone": {"small_<=360px": 10, "medium_361-414px": 70, "large_phone_>414px": 20},
    "desktop": {"laptop_1366px": 55, "desktop_1920px": 45},
    "tablet": {"tablet_768-1024px": 100},
}
NETWORK_BY_LOC = {
    "erbil_centre": {"wifi_fast": 25, "4g_good": 40, "4g_variable": 28, "slow_unstable": 7},
    "erbil_suburb": {"wifi_fast": 18, "4g_good": 35, "4g_variable": 35, "slow_unstable": 12},
    "erbil_rural": {"wifi_fast": 5, "4g_good": 20, "4g_variable": 40, "slow_unstable": 35},
    "kri_other_city": {"wifi_fast": 18, "4g_good": 35, "4g_variable": 35, "slow_unstable": 12},
    "iraq_other": {"wifi_fast": 15, "4g_good": 30, "4g_variable": 38, "slow_unstable": 17},
    "diaspora": {"wifi_fast": 60, "4g_good": 32, "4g_variable": 7, "slow_unstable": 1},
}
DATA_CAP = {"erbil_rural": 45, "iraq_other": 35, "erbil_suburb": 30, "kri_other_city": 30,
            "erbil_centre": 20, "diaspora": 5}  # % who worry about mobile-data cost

ACCESS_BY_BAND = {  # none / low_vision / colour_vision / motor / screen_reader / reading_cognitive / keyboard_only
    "14-17": {"none": 90, "colour_vision": 4, "reading_cognitive": 4, "motor": 1, "screen_reader": 1},
    "18-24": {"none": 90, "colour_vision": 4, "reading_cognitive": 3, "motor": 1, "screen_reader": 1, "keyboard_only": 1},
    "25-34": {"none": 89, "colour_vision": 4, "reading_cognitive": 2, "motor": 2, "screen_reader": 1.5, "low_vision": 1, "keyboard_only": 0.5},
    "35-44": {"none": 86, "colour_vision": 3, "reading_cognitive": 2, "motor": 2, "screen_reader": 1.5, "low_vision": 4.5, "keyboard_only": 1},
    "45-54": {"none": 76, "colour_vision": 3, "reading_cognitive": 2, "motor": 3, "screen_reader": 1.5, "low_vision": 13.5, "keyboard_only": 1},
    "55-64": {"none": 62, "colour_vision": 2, "reading_cognitive": 2, "motor": 5, "screen_reader": 2, "low_vision": 26, "keyboard_only": 1},
    "65-74": {"none": 45, "colour_vision": 2, "reading_cognitive": 3, "motor": 8, "screen_reader": 2, "low_vision": 39, "keyboard_only": 1},
    "75+": {"none": 30, "colour_vision": 1, "reading_cognitive": 5, "motor": 14, "screen_reader": 2, "low_vision": 47, "keyboard_only": 1},
}
READING_BY_EDU = {
    "in_secondary_school": {"low": 10, "basic": 40, "good": 40, "high": 10},
    "primary_or_none": {"low": 55, "basic": 35, "good": 10, "high": 0},
    "secondary": {"low": 15, "basic": 45, "good": 35, "high": 5},
    "vocational_diploma": {"low": 8, "basic": 40, "good": 42, "high": 10},
    "university_student": {"low": 3, "basic": 25, "good": 50, "high": 22},
    "bachelor": {"low": 2, "basic": 20, "good": 50, "high": 28},
    "postgraduate": {"low": 0, "basic": 8, "good": 45, "high": 47},
}

HOUSEHOLD_BY_BAND = {
    "14-17": {"teen_with_parents": 100},
    "18-24": {"single_with_parents": 55, "single_alone": 10, "student_shared": 12, "couple_no_kids": 12,
              "parents_young_kids": 9, "divorced_or_widowed": 1, "large_multigen": 1},
    "25-34": {"single_with_parents": 25, "single_alone": 12, "couple_no_kids": 20, "parents_young_kids": 30,
              "large_multigen": 7, "divorced_or_widowed": 4, "student_shared": 2},
    "35-44": {"single_with_parents": 6, "single_alone": 8, "couple_no_kids": 10, "parents_young_kids": 38,
              "parents_teens": 22, "large_multigen": 10, "divorced_or_widowed": 6},
    "45-54": {"single_alone": 7, "couple_no_kids": 8, "parents_young_kids": 15, "parents_teens": 40,
              "large_multigen": 15, "divorced_or_widowed": 10, "single_with_parents": 5},
    "55-64": {"single_alone": 9, "couple_no_kids": 18, "parents_teens": 25, "parents_adult_kids": 25,
              "large_multigen": 13, "divorced_or_widowed": 10},
    "65-74": {"single_alone": 12, "couple_no_kids": 25, "parents_adult_kids": 28, "large_multigen": 18,
              "divorced_or_widowed": 17},
    "75+": {"single_alone": 14, "couple_no_kids": 18, "parents_adult_kids": 22, "large_multigen": 24,
            "divorced_or_widowed": 22},
}

# Areas (Erbil + KRI). Tiers steer by budget; "anchor" services add realism.
AREAS_PREMIUM = ["Ankawa", "Gulan Street", "Dream City", "Italian Village", "English Village", "Empire World",
                 "Lebanese Village", "Royal City", "Ankawa 2", "Pirmam Road", "32 Park area", "Shorsh (new blocks)"]
AREAS_MID = ["Shorsh", "Bakhtiari", "Kurdistan Street", "Mamostayan", "Zanko", "Havalan", "Nawroz", "Ashti",
             "Setaqan", "Majidi Mall area", "Zhyan City", "Hawler New City", "Atconz / New Azadi", "Brayati",
             "Rizgari", "Minara", "Iskan", "Azadi", "Mufti", "Bnaslawa"]
AREAS_BUDGET = ["Daratu", "Kasnazan", "Bahrka", "Bnaslawa", "Khabat", "Kalak (Khabat)", "Rizgari", "Setaqan",
                "Qushtapa", "Shawes", "Banislawa", "Gazna", "Brayati", "Taajil", "Zanyari"]
AREAS_RURAL = ["Shaqlawa", "Koya", "Soran", "Khabat", "Makhmur", "Rawanduz", "Harir", "Salahaddin", "Choman"]
ANCHORS = ["Family Mall", "Majidi Mall", "Rizgary Hospital", "Salahaddin University", "Erbil Citadel",
           "Erbil International Airport", "the 100-metre road", "the 60-metre road", "Sami Abdulrahman Park",
           "Ankawa church area", "Cihan University", "Hawler Medical University", "the Ministry of Education",
           "a girls' secondary school", "a primary school", "the Christian cemetery road", "Erbil Stadium",
           "Nishtiman market", "Gulan Park", "the ring road"]


def choose_areas(location: str, income: str, intent: str, n: int = 2) -> list[str]:
    if location == "erbil_rural" and intent in {"renter", "first_time_buyer", "seller", "landlord"}:
        pool = AREAS_RURAL + AREAS_BUDGET[:4]
    elif intent in {"investor_diaspora", "investor_local", "developer_staff"}:
        pool = AREAS_PREMIUM + AREAS_MID[:8]
    elif income in {"high", "upper_middle"}:
        pool = AREAS_PREMIUM + AREAS_MID[:6]
    elif income == "middle":
        pool = AREAS_MID + AREAS_PREMIUM[:3]
    else:
        pool = AREAS_BUDGET + AREAS_MID[:6]
    return rng.sample(pool, k=min(n, len(pool)))


def budget_for(intent: str, income: str, savings: str) -> dict:
    """Realistic Erbil-ish ranges (USD). Rent monthly; purchase total."""
    b = {}
    rent_centre = {"very_low": (180, 350), "low": (300, 550), "middle": (450, 900),
                   "upper_middle": (800, 1600), "high": (1400, 3500)}
    buy_centre = {"very_low": (40000, 70000), "low": (55000, 95000), "middle": (80000, 160000),
                  "upper_middle": (140000, 320000), "high": (280000, 900000)}
    if intent == "renter":
        lo, hi = rent_centre[income]
        b["rent_monthly_usd"] = rng.randrange(lo, hi + 1, 10)
    elif intent in {"first_time_buyer", "upgrader", "investor_local", "investor_diaspora"}:
        lo, hi = buy_centre[income]
        if intent == "upgrader":
            lo, hi = int(lo * 1.3), int(hi * 1.4)
        if intent == "investor_diaspora":
            lo, hi = int(lo * 1.2), int(hi * 1.6)
        v = rng.randrange(lo, hi + 1, 5000)
        b["purchase_budget_usd"] = v
        if savings in {"no_savings", "small_savings", "can_pay_installments"}:
            b["payment_mode"] = "installments_required"
            b["down_payment_capacity_usd"] = int(v * rng.choice([0.1, 0.15, 0.2, 0.25, 0.3]))
        elif savings == "cash_partial":
            b["payment_mode"] = "mixed_cash_and_installments"
            b["down_payment_capacity_usd"] = int(v * rng.choice([0.4, 0.5, 0.6]))
        else:
            b["payment_mode"] = "cash"
    elif intent == "landlord":
        b["expected_rent_monthly_usd"] = rng.randrange(300, 2000, 50)
    elif intent == "seller":
        b["expected_sale_price_usd"] = rng.randrange(60000, 650000, 5000)
    return b


SAVINGS_BY_INCOME = {
    "very_low": {"no_savings": 65, "small_savings": 30, "can_pay_installments": 5},
    "low": {"no_savings": 35, "small_savings": 40, "can_pay_installments": 22, "cash_partial": 3},
    "middle": {"no_savings": 12, "small_savings": 30, "can_pay_installments": 40, "cash_partial": 15, "cash_full": 3},
    "upper_middle": {"small_savings": 10, "can_pay_installments": 35, "cash_partial": 35, "cash_full": 20},
    "high": {"can_pay_installments": 15, "cash_partial": 35, "cash_full": 50},
}
FINANCING_CONSTRAINT_BY_SAVINGS = {
    "no_savings": ["cannot buy without family support", "needs long installment plan with low down payment",
                   "would need a rare bank mortgage", "can only rent"],
    "small_savings": ["needs developer installments (3–7 years)", "depends on selling family land first",
                      "needs a co-buyer (sibling/spouse)", "could afford a down payment but not monthly payments yet"],
    "can_pay_installments": ["prefers developer installments over bank", "wants total cost including fees stated clearly",
                             "fears hidden charges in installment plans", "can commit to monthly payments if job stays stable"],
    "cash_partial": ["cash for ~half, installments for the rest", "wants a cash discount clearly stated",
                     "comparing cash vs installment total cost"],
    "cash_full": ["cash buyer, negotiates on price", "wants proof of ownership and delivery date before paying",
                  "may buy more than one unit if data convinces"],
}

# --------------------------------------------------------------------------
# Text libraries for goals / concerns / abandonment triggers
# --------------------------------------------------------------------------
GOALS = {
    "renter": [
        "Find a {rooms}-bedroom apartment to rent in {area1} or {area2} for under ${rent}/month, near {anchor}.",
        "Rent a small furnished flat under ${rent}/month close to {anchor}; move within weeks.",
        "Find a family house to rent in {area1} with a yard and parking, budget ${rent}/month.",
        "See whether rents in {area1} fit ${rent}/month before contacting any agent.",
        "Find a flat near {anchor} to share with colleagues, each paying a share of ${rent}/month.",
    ],
    "first_time_buyer": [
        "Buy a first apartment in {area1} or {area2} for about ${buy}, paying in installments.",
        "Understand what ${buy} actually buys in Erbil today — unit sizes, areas, delivery dates.",
        "Find a ready (not under-construction) apartment around ${buy} near {anchor}.",
        "Compare two or three projects in {area1} at ${buy} and understand the full installment cost.",
        "Check if any project accepts a {dp}% down payment on a ${buy} unit.",
    ],
    "upgrader": [
        "Move from the current home to a larger house in {area1}; budget about ${buy}.",
        "Find a villa or duplex in {area1} or {area2} around ${buy} with a clear delivery date.",
        "Evaluate whether selling now and buying in {area1} makes sense at ${buy}.",
    ],
    "seller": [
        "Understand how to list a {ptype} in {area1} for sale at about ${sale} and what it costs.",
        "Check recent asking prices in {area1} to price a {ptype} realistically near ${sale}.",
        "Find out whether this platform lets an owner sell directly or only through agents.",
    ],
    "landlord": [
        "Advertise a {ptype} in {area1} for rent at ${rentexp}/month and find serious tenants.",
        "Check typical rents in {area1} to set the price for a {ptype} near ${rentexp}/month.",
        "Understand whether listing is free, who sees the listing and how tenants contact me.",
    ],
    "investor_local": [
        "Identify areas in Erbil where prices are rising and find a unit around ${buy} to buy and rent out.",
        "Compare price trends between {area1} and {area2} before committing ${buy}.",
        "Check which projects near {anchor} have verified prices and credible developers.",
    ],
    "investor_diaspora": [
        "From abroad, find a trustworthy project in {area1} around ${buy} that can be bought remotely.",
        "Understand the real price level in Erbil versus what relatives report, with a budget of ${buy}.",
        "Shortlist two projects in {area1}/{area2} to visit on the next trip home; budget ${buy}.",
    ],
    "broker": [
        "See whether the platform is a lead source worth listing clients' properties on, and what it costs.",
        "Check how listings are attributed and whether agent profiles exist and look professional.",
        "Compare the platform's price data against my own knowledge of {area1}.",
    ],
    "developer_staff": [
        "Assess whether our projects should be published here, how a project page looks, and how leads reach us.",
        "Check how competitor projects in {area1} are presented and priced on the platform.",
        "Understand the verification and update process for project prices.",
    ],
    "researching": [
        "Get a feel for Erbil prices in {area1} out of curiosity; no decision yet.",
        "Check whether moving from {origin} to Erbil is affordable; browse prices around {anchor}.",
        "Help a relative compare two areas ({area1} vs {area2}) by sharing links.",
        "Understand what the site offers compared with the Telegram and Facebook groups I use.",
    ],
    "no_need": [
        "Was sent a link by a family member; look around without any plan to buy or rent.",
        "Check what area {area1} is like because friends talk about it; no property need.",
        "Teenager helping parents: see if a project near {anchor} has a school, gym and good internet nearby.",
        "Landed here by chance while looking for something else in Erbil.",
    ],
}
GOAL_TEEN = [
    "Help parents compare two projects near {anchor}: schools, parks, internet, how far from friends.",
    "Check what the family's possible new area ({area1}) looks like on the map and share a link in the family chat.",
    "Curious about prices after hearing parents discuss moving; no purchasing role.",
]

CONCERNS_BY_TRUST = {
    "skeptical": [
        "Listings or prices may be fake, outdated or planted by agents.",
        "Who is behind this site? No obvious company name, address or phone.",
        "My phone number will be sold to agents and I'll get endless calls.",
        "Prices shown may be marketing prices, not what people actually pay.",
        "Developers may be presented as 'verified' without saying what that means.",
    ],
    "neutral": [
        "Information might be incomplete — size, floor, delivery date, fees.",
        "Not sure whether prices include registration fees and whether USD or IQD applies.",
        "Unclear whether I must register before seeing anything useful.",
        "The map or photos may not reflect the real location or current state.",
    ],
    "trusting": [
        "Mainly worried about wasting time if the inventory is small.",
        "Hope the site works on my phone without heavy downloads.",
        "Would like someone to call me back quickly if I ask a question.",
    ],
}
CONCERNS_EXTRA = {
    "privacy_high": ["Does not want to give a phone number, Telegram or WhatsApp just to browse.",
                     "Worried about location tracking and what the site stores."],
    "network_slow": ["Pages with large images or maps may not load on a weak connection.",
                     "Mobile data is expensive; heavy pages cost money."],
    "data_cap": ["Watches mobile-data usage; a heavy page is a real cost."],
    "low_vision": ["Small grey text and low contrast are hard to read; needs text enlargement to work."],
    "motor": ["Small buttons and tight tap targets are hard to hit with a tremor."],
    "screen_reader": ["Unlabelled buttons, images without descriptions and maps with no text alternative block the task."],
    "keyboard_only": ["Needs visible focus and keyboard-reachable menus and filters."],
    "colour_vision": ["Price movement shown only by red/green colour is unreadable."],
    "reading_cognitive": ["Long paragraphs, jargon and dense cards are exhausting; needs short labels and clear steps."],
    "lang_other": ["Interface may not exist in a language I read comfortably; relies on a second language."],
    "diaspora": ["Needs to judge trust remotely without visiting; fears remote-buyer scams."],
    "installments": ["Installment plans may hide the real total cost and penalties."],
    "seller": ["Does not want to pay for a listing that nobody sees."],
    "broker": ["The platform may compete with agents or expose client data."],
}
ABANDON_TRIGGERS = {
    "generic": [
        "Forced to register, link Telegram or verify WhatsApp before seeing prices or contact details.",
        "Homepage does not explain in one screen what the site is for.",
        "Search or filters return nothing for my budget with no suggestion of what to do next.",
        "Prices are missing or shown as 'contact us' on most items.",
        "Cannot find a phone number or clear way to contact the lister.",
        "Page takes too long to load or the map never finishes loading.",
        "Text is too small or layout breaks when I enlarge text.",
        "The site is in a language I cannot read and I cannot find the switch.",
        "I see too many unfamiliar terms (indices, intelligence, verified price) and no explanation.",
        "A button I pressed does nothing or leads to an error page.",
        "I am asked to download another app or join a channel to continue.",
        "The listings look old or there is no 'updated' date.",
    ],
    "impatient": [
        "More than two taps before I see actual properties with prices.",
        "A hero banner or animation delays the first useful content.",
    ],
    "skeptical": [
        "No company name, address or legal identity anywhere on the site.",
        "'Verified' labels without an explanation of what was verified.",
        "The same unit appears with two different prices.",
    ],
    "accessibility": [
        "Buttons have no readable names for my screen reader.",
        "Focus disappears into the map and I cannot get back to the page.",
        "Red/green is the only cue for price direction.",
        "Tap targets are too small and close together.",
    ],
    "network": [
        "Heavy images or map tiles stall on 3G; I give up before the first result.",
    ],
    "diaspora": [
        "No way to understand the location or neighbourhood without being there.",
        "Prices only in one currency with no idea of the exchange basis.",
    ],
    "seller_landlord": [
        "No clear way to list a property, or listing requires contacting an agent.",
        "Unclear whether listing costs money and who sees it.",
    ],
    "broker": [
        "No agent profile, no attribution, no lead-handling information.",
    ],
    "no_need": [
        "Nothing catches attention in the first screen; closes the tab.",
    ],
}

# Task catalogue (ids used by evaluate_panel.py; evidence IDs attached later)
TASKS = OrderedDict([
    ("T01", "Understand within ~30 seconds what the platform is, whom it serves and why to use it."),
    ("T02", "Find a home/unit within a stated budget and preferred area."),
    ("T03", "Check a project's location, unit types, availability and prices."),
    ("T04", "Compare two suitable properties/projects."),
    ("T05", "Understand cash price vs installment terms and total cost."),
    ("T06", "Find nearby services or understand the map location of a project/area."),
    ("T07", "Check who supplied an advertisement/project and whether it appears trustworthy."),
    ("T08", "Identify a suitable contact method and prepare an inquiry (without sending)."),
    ("T09", "Save a useful option and find it again later."),
    ("T10", "Ask the AI adviser a practical question and judge clarity, honesty and usefulness."),
    ("T11", "Understand whether registration, Telegram/WhatsApp verification or payment is required."),
    ("T12", "Switch interface language and continue the same task."),
    ("T13", "Recover from an empty search, wrong filter or unclear error."),
    ("T14", "Understand how an owner/landlord/agent can list a property or request a valuation."),
    ("T15", "Understand market/price-trend data, its source and how fresh it is."),
    ("T16", "Check an area's amenities (schools, parks, internet, distance) and share a link with family."),
    ("T17", "Assess data credibility, methodology transparency and legal/attribution clarity as a specialist."),
])
TASKS_BY_INTENT = {
    "renter": ["T02", "T08", "T07", "T13", "T06", "T12", "T11"],
    "first_time_buyer": ["T02", "T03", "T05", "T04", "T07", "T08", "T09", "T10", "T11"],
    "upgrader": ["T03", "T04", "T05", "T06", "T09", "T10"],
    "seller": ["T14", "T15", "T07", "T11", "T08"],
    "landlord": ["T14", "T15", "T11", "T08"],
    "investor_local": ["T15", "T03", "T04", "T07", "T10", "T09"],
    "investor_diaspora": ["T15", "T03", "T06", "T07", "T12", "T10", "T05"],
    "broker": ["T07", "T14", "T15", "T17", "T11"],
    "developer_staff": ["T03", "T07", "T17", "T14", "T15"],
    "researching": ["T15", "T03", "T06", "T12", "T10", "T13"],
    "no_need": ["T16", "T06", "T12", "T13"],
}


def assign_tasks(intent: str, band: str, specialist: bool, lang: str) -> list[str]:
    pool = list(TASKS_BY_INTENT[intent])
    if band == "14-17":
        pool = ["T16", "T06", "T12", "T13", "T03"]
    k = rng.choice([1, 2, 3]) if intent not in {"no_need"} else rng.choice([1, 2])
    chosen = rng.sample(pool, k=min(k, len(pool)))
    if specialist and "T17" not in chosen and rng.random() < 0.7:
        chosen.append("T17")
    if lang == "other" and "T12" not in chosen and rng.random() < 0.6:
        chosen.append("T12")
    tasks = ["T01"] + chosen
    return tasks[:4]


# Names — plausible for the region/diaspora; purely for readability.
NAMES_F_KU = ["Shilan", "Dilan", "Rozhin", "Hana", "Avan", "Lana", "Sara", "Niyan", "Chro", "Zhiyan", "Shadan",
              "Awat", "Hero", "Bahar", "Dashne", "Narin", "Tara", "Shano", "Rezhna", "Kazhal", "Gulala", "Bafrin"]
NAMES_M_KU = ["Aram", "Hemin", "Rebaz", "Karwan", "Shwan", "Dler", "Hawre", "Bryar", "Zana", "Soran", "Bashdar",
              "Rawand", "Peshraw", "Ako", "Hiwa", "Aso", "Rebin", "Shvan", "Dana", "Baran", "Kawa", "Halkawt"]
NAMES_F_AR = ["Fatima", "Noor", "Zainab", "Maryam", "Huda", "Rana", "Sumaya", "Aya", "Dalia", "Rasha", "Hind", "Lina"]
NAMES_M_AR = ["Ahmed", "Mustafa", "Omar", "Ali", "Hussein", "Yousif", "Haider", "Karim", "Saif", "Bilal", "Mohammed", "Waleed"]
NAMES_F_AS = ["Maryam", "Nahrain", "Ramina", "Shamiram", "Atorina", "Sabrina"]
NAMES_M_AS = ["Sargon", "Ashur", "Yousif", "Fadi", "Rami", "Ninos"]
NAMES_F_TK = ["Aysel", "Gulshan", "Leyla", "Nazan"]
NAMES_M_TK = ["Ercan", "Yashar", "Orhan", "Sadettin"]
SURNAME_HINT = ["Barzani-area family", "Soran-origin family", "Koya family", "Ankawa family", "Sulaymani family",
                "Duhoki family", "Baghdad-raised family", "Kirkuk-origin family", "Halabja family", "Erbil old-town family"]


def make_name(gender: str, lang: str, location: str) -> str:
    if lang == "other":
        kind = rng.choice(["as", "tk", "ku"])  # Assyrian, Turkmen, Kurmanji-speaking Kurds
    elif lang == "ar" and location == "iraq_other":
        kind = "ar"
    elif lang == "ar":
        kind = rng.choice(["ar", "ku", "ku"])
    else:
        kind = rng.choice(["ku", "ku", "ku", "ar"])
    table = {
        ("as", "female"): NAMES_F_AS, ("as", "male"): NAMES_M_AS,
        ("tk", "female"): NAMES_F_TK, ("tk", "male"): NAMES_M_TK,
        ("ar", "female"): NAMES_F_AR, ("ar", "male"): NAMES_M_AR,
        ("ku", "female"): NAMES_F_KU, ("ku", "male"): NAMES_M_KU,
    }
    g = gender if gender in {"female", "male"} else rng.choice(["female", "male"])
    return rng.choice(table[(kind, g)])


LOCATION_LABEL = {
    "erbil_centre": "Erbil city centre", "erbil_suburb": "Erbil suburb / new district",
    "erbil_rural": "Erbil governorate town or village", "kri_other_city": "Other KRI city",
    "iraq_other": "Elsewhere in Iraq", "diaspora": "Diaspora",
}
LOCATION_DETAIL = {
    "erbil_centre": ["Shorsh", "Bakhtiari", "Ankawa", "Kurdistan Street", "Mamostayan", "Azadi", "Iskan", "Brayati",
                     "Nawroz", "Zanko", "Minara", "Setaqan", "Havalan", "Mufti", "Rizgari"],
    "erbil_suburb": ["Kasnazan", "Bahrka", "Daratu", "Bnaslawa", "Hawler New City", "Zhyan City", "Atconz", "Pirmam Road",
                     "Gulan (new blocks)", "Qushtapa", "Shawes", "Gazna", "Taajil"],
    "erbil_rural": ["Shaqlawa", "Koya", "Soran", "Khabat", "Makhmur", "Rawanduz", "Harir", "Choman", "Mergasor", "Kalak"],
    "kri_other_city": ["Sulaymaniyah", "Duhok", "Halabja", "Zakho", "Ranya", "Kalar", "Akre"],
    "iraq_other": ["Baghdad", "Mosul", "Kirkuk", "Basra", "Najaf", "Tikrit", "Ramadi"],
    "diaspora": ["Germany", "United Kingdom", "Sweden", "Netherlands", "United States", "Australia", "Turkey",
                 "United Arab Emirates", "Norway", "Canada", "Finland", "Denmark"],
}
LANG_LABEL = {"ckb": "Sorani Kurdish", "ar": "Arabic", "en": "English", "other": "Other (Kurmanji/Badini, Turkmen or Assyrian)"}
SECOND_LANG = {
    "ckb": [["ar"], ["en"], ["ar", "en"], []],
    "ar": [["ckb"], ["en"], [], ["ckb", "en"]],
    "en": [["ckb"], ["ar"], ["ckb", "ar"], []],
    "other": [["ar"], ["ckb"], ["en"], ["ar", "en"], ["ckb", "ar"]],
}
PTYPES = ["apartment", "house", "villa", "shop", "plot of land", "duplex", "studio"]
ROOMS = [1, 2, 2, 3, 3, 4]


def main(out_dir: str) -> None:
    os.makedirs(out_dir, exist_ok=True)

    # ---- allocate primary dimensions ----
    band_list = []
    for b, c in AGE_BANDS.items():
        band_list += [b] * c
    rng.shuffle(band_list)

    order = list(range(N))
    # most-constrained bands first for intent allocation
    def band_rank(i):
        return {"14-17": 0, "75+": 1}.get(band_list[i], 2)
    order_intent = sorted(order, key=lambda i: (band_rank(i), rng.random()))

    intent_pool = Counter(INTENTS)
    intents = [None] * N
    for i in order_intent:
        b = band_list[i]
        allowed = INTENTS_ALLOWED.get(b)
        intents[i] = weighted_pick(intent_pool, INTENT_WEIGHTS_BY_BAND[b], allowed)

    # location: constrained by intent (diaspora investors), then weighted toward realism
    loc_pool = Counter(LOCATIONS)
    locations = [None] * N
    order_loc = sorted(order, key=lambda i: (0 if intents[i] in LOC_CONSTRAINTS_BY_INTENT else 1, rng.random()))
    for i in order_loc:
        allowed = LOC_CONSTRAINTS_BY_INTENT.get(intents[i])
        weights = {k: 1.0 for k in LOCATIONS}
        if intents[i] in {"broker", "developer_staff"}:
            weights.update({"erbil_centre": 3, "erbil_suburb": 2, "diaspora": 0.05, "iraq_other": 0.3})
        if intents[i] in {"renter", "first_time_buyer", "seller", "landlord", "upgrader"}:
            weights.update({"diaspora": 0.25, "iraq_other": 0.5})
        locations[i] = weighted_pick(loc_pool, weights, allowed)

    # language: weighted by location
    lang_pool = Counter(LANGUAGES)
    langs = [None] * N
    order_lang = sorted(order, key=lambda i: rng.random())
    for i in order_lang:
        langs[i] = weighted_pick(lang_pool, LANG_WEIGHTS_BY_LOC[locations[i]])

    personas = []
    seen_vectors = set()
    for i in range(N):
        pid = f"P{i + 1:04d}"
        band = band_list[i]
        intent = intents[i]
        location = locations[i]
        lang = langs[i]
        age = rng.randint(*AGE_RANGE[band])
        gender = pick(GENDER_DIST)
        education = pick(EDUCATION_BY_BAND[band])
        occupation = INTENT_FORCES_OCC.get(intent) or pick(OCC_WEIGHTS_BY_BAND[band])
        if band == "14-17":
            occupation = "student_secondary"
        if band in {"65-74", "75+"} and occupation in {"student_university"}:
            occupation = "retired"
        occ_label, specialist_domain = OCCUPATIONS[occupation]
        specialist = specialist_domain is not None

        centre = INCOME_BASE_BY_OCC[occupation] + LOC_INCOME_SHIFT[location] + rng.gauss(0, 0.7)
        if band == "14-17":  # household income, not the teen's
            centre = rng.gauss(1.9, 0.9)
        idx = min(4, max(0, int(round(centre))))
        income = INCOME_BANDS[idx]
        savings = pick(SAVINGS_BY_INCOME[income])
        if intent in {"no_need", "researching"} and rng.random() < 0.5:
            fin = "not applicable — no purchase planned"
        elif band == "14-17":
            fin = "not applicable — minor, household influencer only"
        else:
            fin = rng.choice(FINANCING_CONSTRAINT_BY_SAVINGS[savings])
        employment = EMPLOYMENT_BY_OCC.get(occupation, pick({"stable_private": 55, "irregular": 20, "self_employed": 25}))
        if band == "14-17":
            employment = "student"
        housing = pick(HOUSING_BY_INTENT[intent])
        if band == "14-17":
            housing = "with_family"
        if location == "diaspora" and housing in {"renting", "owns_home"} and intent not in {"investor_diaspora", "seller", "landlord"}:
            housing = housing + "_abroad"
        household = pick(HOUSEHOLD_BY_BAND[band])
        children_u14 = 0
        if household in {"parents_young_kids", "large_multigen"}:
            children_u14 = rng.choice([1, 1, 2, 2, 3, 4])
        elif household == "parents_teens":
            children_u14 = rng.choice([0, 0, 1])
        elif household == "teen_with_parents":
            children_u14 = rng.choice([0, 1, 2])

        knowledge = pick(PROPERTY_KNOWLEDGE_BY_INTENT[intent])
        digital = pick(DIGITAL_BY_BAND[band])
        if occupation in {"software_engineer", "ux_designer", "data_analyst", "it_support"}:
            digital = rng.choice(["high", "very_high", "very_high"])
        patience = pick(PATIENCE)
        trust = pick(TRUST)
        privacy = pick(PRIVACY)
        urgency = pick(URGENCY_BY_INTENT[intent])
        if band == "14-17":
            urgency = "none"
        device = pick(DEVICE_BY_INCOME[income])
        if occupation in {"software_engineer", "data_analyst", "architect", "civil_engineer", "accountant", "lawyer"} and rng.random() < 0.35:
            device = "desktop"
        screen = pick(SCREEN_BY_DEVICE[device])
        network = pick(NETWORK_BY_LOC[location])
        data_cap = rng.random() * 100 < DATA_CAP[location]
        access = pick(ACCESS_BY_BAND[band])
        reading = pick(READING_BY_EDU[education])
        if access == "reading_cognitive":
            reading = rng.choice(["low", "basic"])
        if access == "low_vision" and rng.random() < 0.3:
            reading = "basic" if reading in {"good", "high"} else reading
        second_langs = rng.choice(SECOND_LANG[lang])
        if lang == "other" and not second_langs:
            second_langs = ["ar"]

        areas = choose_areas(location, income, intent)
        budget = budget_for(intent, income, savings)
        anchor = rng.choice(ANCHORS)
        fill = {
            "area1": areas[0], "area2": areas[1] if len(areas) > 1 else areas[0], "anchor": anchor,
            "rooms": rng.choice(ROOMS), "rent": budget.get("rent_monthly_usd", 500),
            "buy": f"{budget.get('purchase_budget_usd', 100000):,}",
            "dp": int(100 * budget.get("down_payment_capacity_usd", 20000) / max(budget.get("purchase_budget_usd", 100000), 1)) if "down_payment_capacity_usd" in budget else rng.choice([10, 15, 20, 30]),
            "sale": f"{budget.get('expected_sale_price_usd', 150000):,}", "rentexp": budget.get("expected_rent_monthly_usd", 600),
            "ptype": rng.choice(PTYPES), "origin": rng.choice(LOCATION_DETAIL[location]),
        }
        goal_tpl = rng.choice(GOAL_TEEN if band == "14-17" else GOALS[intent])
        goal = goal_tpl.format(**fill)

        concerns = [rng.choice(CONCERNS_BY_TRUST[trust])]
        if privacy == "high" and rng.random() < 0.7:
            concerns.append(rng.choice(CONCERNS_EXTRA["privacy_high"]))
        if network == "slow_unstable" or (network == "4g_variable" and rng.random() < 0.3):
            concerns.append(rng.choice(CONCERNS_EXTRA["network_slow"]))
        if data_cap and rng.random() < 0.5:
            concerns.append(CONCERNS_EXTRA["data_cap"][0])
        if access in CONCERNS_EXTRA:
            concerns.append(CONCERNS_EXTRA[access][0])
        if lang == "other":
            concerns.append(CONCERNS_EXTRA["lang_other"][0])
        if location == "diaspora" and rng.random() < 0.6:
            concerns.append(CONCERNS_EXTRA["diaspora"][0])
        if budget.get("payment_mode") in {"installments_required", "mixed_cash_and_installments"} and rng.random() < 0.6:
            concerns.append(CONCERNS_EXTRA["installments"][0])
        if intent in {"seller", "landlord"} and rng.random() < 0.5:
            concerns.append(CONCERNS_EXTRA["seller"][0])
        if intent == "broker" and rng.random() < 0.6:
            concerns.append(CONCERNS_EXTRA["broker"][0])
        main_concern = concerns[0] if len(concerns) == 1 else rng.choice(concerns)

        trig_pool = list(ABANDON_TRIGGERS["generic"])
        if patience == "low":
            trig_pool += ABANDON_TRIGGERS["impatient"] * 2
        if trust == "skeptical":
            trig_pool += ABANDON_TRIGGERS["skeptical"] * 2
        if access != "none":
            trig_pool += ABANDON_TRIGGERS["accessibility"] * 2
        if network in {"slow_unstable", "4g_variable"}:
            trig_pool += ABANDON_TRIGGERS["network"] * 2
        if location == "diaspora":
            trig_pool += ABANDON_TRIGGERS["diaspora"]
        if intent in {"seller", "landlord"}:
            trig_pool += ABANDON_TRIGGERS["seller_landlord"] * 3
        if intent == "broker":
            trig_pool += ABANDON_TRIGGERS["broker"] * 3
        if intent == "no_need":
            trig_pool += ABANDON_TRIGGERS["no_need"] * 3
        if privacy == "high":
            trig_pool += [ABANDON_TRIGGERS["generic"][0]] * 2
        trigger = rng.choice(trig_pool)

        tasks = assign_tasks(intent, band, specialist, lang)

        record = OrderedDict([
            ("id", pid),
            ("display_name", make_name(gender, lang, location)),
            ("age", age),
            ("age_band", band),
            ("gender", gender),
            ("purchasing_role", "household_influencer_minor" if band == "14-17" else "independent_adult"),
            ("education", education),
            ("occupation_key", occupation),
            ("occupation", occ_label),
            ("specialist_domain", specialist_domain or ""),
            ("is_specialist", specialist),
            ("household", household),
            ("children_under_14_in_household", children_u14),
            ("location_context", location),
            ("location_label", LOCATION_LABEL[location]),
            ("location_detail", rng.choice(LOCATION_DETAIL[location])),
            ("primary_language", lang),
            ("primary_language_label", LANG_LABEL[lang]),
            ("also_reads", ",".join(second_langs)),
            ("reading_ability", reading),
            ("income_band", income),
            ("income_label", INCOME_LABEL[income]),
            ("savings", savings),
            ("financing_constraint", fin),
            ("employment_stability", employment),
            ("housing_situation", housing),
            ("property_intent", intent),
            ("property_knowledge", knowledge),
            ("digital_confidence", digital),
            ("patience", patience),
            ("trust_disposition", trust),
            ("privacy_concern", privacy),
            ("urgency", urgency),
            ("device", device),
            ("screen", screen),
            ("network", network),
            ("mobile_data_cost_sensitive", data_cap),
            ("accessibility_need", access),
            ("preferred_areas", "; ".join(areas)),
            ("budget", budget),
            ("primary_goal", goal),
            ("main_concern", main_concern),
            ("other_concerns", " | ".join(c for c in concerns if c != main_concern)),
            ("abandonment_trigger", trigger),
            ("tasks", tasks),
            # ---- filled by evaluate_panel.py after the evidence log exists ----
            ("evidence_ids", []),
            ("initial_understanding", None),
            ("predicted_outcome", "pending_evidence"),
            ("task_outcomes", {}),
            ("main_confusion", None),
            ("trust_concern_observed", None),
            ("frustration_point", None),
            ("most_useful_feature", None),
            ("missing_information", None),
            ("would_return", None),
            ("what_would_change_decision", None),
            ("adoption_category", "pending_evidence"),
            ("simulated_reaction", None),
        ])
        vec = tuple(str(record[k]) for k in record if k not in {"id", "display_name"})
        assert vec not in seen_vectors, f"duplicate attribute vector at {pid}"
        seen_vectors.add(vec)
        personas.append(record)

    # ---- QC ----
    ids = [p["id"] for p in personas]
    assert len(ids) == N and len(set(ids)) == N
    assert Counter(p["age_band"] for p in personas) == Counter(AGE_BANDS)
    assert Counter(p["location_context"] for p in personas) == Counter(LOCATIONS)
    assert Counter(p["primary_language"] for p in personas) == Counter(LANGUAGES)
    assert Counter(p["property_intent"] for p in personas) == Counter(INTENTS)
    for p in personas:
        if p["age_band"] == "14-17":
            assert p["property_intent"] in {"no_need", "researching"} and p["purchasing_role"] == "household_influencer_minor"
        if p["property_intent"] == "seller":
            assert p["housing_situation"] in {"owns_home", "owns_multiple"}
        if p["property_intent"] == "investor_diaspora":
            assert p["location_context"] == "diaspora"
        if p["property_intent"] == "investor_local":
            assert p["location_context"] != "diaspora"
        assert 2 <= len(p["tasks"]) <= 4 and p["tasks"][0] == "T01"
    # every user-listed occupation appears at least once
    required_occ = ["real_estate_agent", "developer_staff", "architect", "civil_engineer", "interior_designer", "teacher",
                    "university_lecturer", "student_university", "doctor", "nurse", "lawyer", "accountant", "civil_servant",
                    "shopkeeper", "entrepreneur", "taxi_driver", "electrician", "construction_worker", "farmer",
                    "restaurant_worker", "barber_salon", "athlete", "coach", "homemaker", "retired", "unemployed",
                    "software_engineer"]
    occ_counts = Counter(p["occupation_key"] for p in personas)
    missing = [o for o in required_occ if occ_counts[o] == 0]
    assert not missing, f"missing occupations: {missing}"

    # ---- composition summary ----
    def dist(key):
        c = Counter(p[key] for p in personas)
        return OrderedDict(sorted(c.items(), key=lambda kv: (-kv[1], str(kv[0]))))
    composition = OrderedDict([
        ("panel_size", N), ("seed", SEED),
        ("note", "Purposive stress-test sample of AI-generated personas; NOT a probability sample; counts are realised values."),
        ("age_band", dist("age_band")), ("gender", dist("gender")), ("purchasing_role", dist("purchasing_role")),
        ("education", dist("education")), ("occupation", dist("occupation")), ("is_specialist", dist("is_specialist")),
        ("specialist_domain", dist("specialist_domain")), ("household", dist("household")),
        ("location_context", dist("location_context")), ("primary_language", dist("primary_language")),
        ("reading_ability", dist("reading_ability")), ("income_band", dist("income_band")), ("savings", dist("savings")),
        ("employment_stability", dist("employment_stability")), ("housing_situation", dist("housing_situation")),
        ("property_intent", dist("property_intent")), ("property_knowledge", dist("property_knowledge")),
        ("digital_confidence", dist("digital_confidence")), ("patience", dist("patience")),
        ("trust_disposition", dist("trust_disposition")), ("privacy_concern", dist("privacy_concern")),
        ("urgency", dist("urgency")), ("device", dist("device")), ("screen", dist("screen")), ("network", dist("network")),
        ("mobile_data_cost_sensitive", dist("mobile_data_cost_sensitive")), ("accessibility_need", dist("accessibility_need")),
        ("tasks_assigned_total", sum(len(p["tasks"]) for p in personas)),
        ("task_frequency", OrderedDict(sorted(Counter(t for p in personas for t in p["tasks"]).items()))),
        ("task_catalogue", TASKS),
    ])

    with open(os.path.join(out_dir, "personas.json"), "w", encoding="utf-8") as f:
        json.dump(personas, f, ensure_ascii=False, indent=1)
    with open(os.path.join(out_dir, "composition.json"), "w", encoding="utf-8") as f:
        json.dump(composition, f, ensure_ascii=False, indent=1)
    cols = list(personas[0].keys())
    with open(os.path.join(out_dir, "personas.csv"), "w", encoding="utf-8", newline="") as f:
        w = csv.writer(f, lineterminator="\n")
        w.writerow(cols)
        for p in personas:
            row = []
            for c in cols:
                v = p[c]
                if isinstance(v, (dict, list)):
                    v = json.dumps(v, ensure_ascii=False)
                row.append(v)
            w.writerow(row)
    print(f"wrote {N} personas to {out_dir}")
    for k in ("age_band", "location_context", "primary_language", "property_intent", "digital_confidence",
              "income_band", "accessibility_need", "device", "network", "is_specialist"):
        print(k, dict(composition[k]))


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "panel"))
    args = ap.parse_args()
    main(os.path.abspath(args.out))

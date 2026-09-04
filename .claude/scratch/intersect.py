"""Scratch: intersect candidate rules to size the winnable set per class."""

from __future__ import annotations

import csv
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

for stream in (sys.stdout, sys.stderr):
    try:
        stream.reconfigure(encoding="utf-8", errors="replace")
    except AttributeError:
        pass

ROWS = list(csv.DictReader(open("runs/ocr_rapidocr.digest.csv", encoding="utf-8-sig", newline="")))
BY_IMAGE: dict[str, list[dict[str, str]]] = defaultdict(list)
for row in ROWS:
    BY_IMAGE[row["image"]].append(row)


def fails(klass: str) -> list[dict[str, str]]:
    return [row for row in ROWS if row["class"] == klass and row["is_compliant"] == "no"]


def has(pattern: str, text: str) -> bool:
    return bool(re.search(pattern, text, re.IGNORECASE))


# ---------------------------------------------------------------- manufacturer
PIN = r"(?<!\d)[1-9]\d{2}\s?\d{3}(?!\d)"
BROAD_BY = (
    r"(?:manufact\w*|mfg|mfd|mfr|market\w*|mktd|mkt|packed|packd|pkd|pkg|repack\w*|import\w*|"
    r"distribut\w*|produced|processed)\s*[.&,:]{0,3}\s*(?:and|&)?\s*[.&,:]{0,3}\s*(?:by|bv)(?![a-z])"
)
GLUED_BY = r"(?:packed|marketed|manufactured|imported|distributed|mktd|mkt|repacked|mfd|mfg)by"
rows = fails("manufacturer_details")
pin = [r for r in rows if has(PIN, r["combined_text"])]
print(f"manufacturer failures={len(rows)} pincode={len(pin)}")
print(f"  pincode & broad-by      = {sum(has(BROAD_BY, r['combined_text']) or has(GLUED_BY, r['combined_text']) for r in pin)}")
print(f"  pincode & no by-form    = {sum(not (has(BROAD_BY, r['combined_text']) or has(GLUED_BY, r['combined_text'])) for r in pin)}")
print("  --- pincode present but no by-form, samples:")
for r in [r for r in pin if not (has(BROAD_BY, r["combined_text"]) or has(GLUED_BY, r["combined_text"]))][:8]:
    print(f"      {r['combined_text'][:150]}")

# ------------------------------------------------------------------------- mrp
MRP_MARKER = r"(?:\bm\.?\s*r\.?\s*p\b|₹|\brs\.?|\binr\b|maximum\s*retail|max\.?\s*retail|retail\s*sale\s*price)"
AMOUNT = r"(?<!\w)\d{1,4}(?:[., ]\d{2})?(?!\w)"


def tax_letters(text: str) -> float:
    """Best window match of the letter-run against 'inclusiveofalltaxes'."""
    import Levenshtein

    letters = re.sub(r"[^a-z]", "", text.lower())
    target = "inclusiveofalltaxes"
    best = 0.0
    if not letters:
        return 0.0
    for width in (12, 15, 19, 22):
        for index in range(0, max(1, len(letters) - width + 1)):
            best = max(best, Levenshtein.ratio(letters[index : index + width], target))
    return best


rows = fails("mrp_declaration")
marker = [r for r in rows if has(MRP_MARKER, r["combined_text"])]
both = [r for r in marker if has(AMOUNT, r["combined_text"])]
print(f"\nmrp failures={len(rows)} marker={len(marker)} marker&amount={len(both)}")
for cut in (0.70, 0.75, 0.80, 0.85, 0.90):
    print(f"  marker&amount & letterrun>={cut:.2f}  = {sum(tax_letters(r['combined_text']) >= cut for r in both)}")
print("  --- marker&amount with letterrun in [0.75,0.90), samples:")
shown = 0
for r in both:
    score = tax_letters(r["combined_text"])
    if 0.75 <= score < 0.90 and shown < 8:
        print(f"      {score:.2f}  {r['combined_text'][:130]}")
        shown += 1

# false-positive check: how many *passing* rows would a looser cut newly admit,
# and how many currently-failing rows have no tax wording at all
print("  --- letterrun on rows with NO 'tax' letters at all (should stay low):")
notax = [r for r in both if not has(r"t\s*a\s*[xk]", r["combined_text"])]
print(f"      n={len(notax)} max={max((tax_letters(r['combined_text']) for r in notax), default=0):.2f}")
buckets = Counter(round(tax_letters(r["combined_text"]), 1) for r in notax)
print(f"      {sorted(buckets.items())}")

# -------------------------------------------------------------------- consumer
FSSAI14 = r"(?<!\d)\d(?:[\s-]*\d){13}(?!\d)"
rows = fails("consumer_care_fssai")
in_class = sum(has(FSSAI14, r["combined_text"]) for r in rows)
elsewhere = 0
for r in rows:
    image_text = " ".join(other["combined_text"] for other in BY_IMAGE[r["image"]])
    if not has(FSSAI14, r["combined_text"]) and has(FSSAI14, image_text):
        elsewhere += 1
print(f"\nconsumer failures={len(rows)} 14-digit in class={in_class} only elsewhere in image={elsewhere}")
EMAIL = r"[\w.!#$%&'*+/=?^`{|}~-]+@[A-Za-z0-9][A-Za-z0-9.-]{0,61}\.[A-Za-z]{2,}"
HELP = r"(?<!\d)(?:(?:\+?91[\s-]*)?(?:1800|1860)(?:[\s-]*\d){6,10}|(?:\+?91[\s-]*)?[6-9]\d{4}[\s-]?\d{5})(?!\d)"
have_both = [r for r in rows if has(EMAIL, r["combined_text"]) and has(HELP, r["combined_text"])]
print(f"  email&helpline in class = {len(have_both)}")
rescuable = 0
for r in have_both:
    image_text = " ".join(other["combined_text"] for other in BY_IMAGE[r["image"]])
    if has(FSSAI14, image_text):
        rescuable += 1
print(f"  email&helpline & 14-digit anywhere in image = {rescuable}")

# ------------------------------------------------------------------ net qty
rows = fails("net_quantity")
print(f"\nnet failures={len(rows)}")
for r in rows:
    if re.search(r"(?<!\d)\d{2,4}\s*[6890]{1}(?!\d)", r["combined_text"]):
        print(f"      {r['combined_text'][:90]}")

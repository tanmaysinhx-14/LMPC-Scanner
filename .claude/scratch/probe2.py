"""Scratch: second round - cross-region availability and date-material sizing."""

from __future__ import annotations

import csv
import re
import sys
from collections import Counter, defaultdict

import Levenshtein

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


def passes(klass: str) -> list[dict[str, str]]:
    return [row for row in ROWS if row["class"] == klass and row["is_compliant"] == "yes"]


def image_text(row: dict[str, str], exclude_self: bool = False) -> str:
    return " ".join(
        other["combined_text"]
        for other in BY_IMAGE[row["image"]]
        if not (exclude_self and other is row)
    )


def has(pattern: str, text: str) -> bool:
    return bool(re.search(pattern, text, re.IGNORECASE))


_LETTERS = re.compile(r"[^a-z]")
_INCL = re.compile(r"i?n?c?l?u?s?i?v?e?")
TARGET = "inclusiveofalltaxes"


def tax_phrase_score(text: str) -> float:
    """Windowed letter-run match, gated on both an incl-ish and a tax-ish run."""
    letters = _LETTERS.sub("", text.lower())
    if not letters:
        return 0.0
    best = 0.0
    for tax in re.finditer(r"t[a4o]?[xk]e?s?", letters):
        left = max(0, tax.start() - 24)
        segment = letters[left : tax.end()]
        if not re.search(r"[io]n?c?l", segment):
            continue
        for start in range(0, len(segment)):
            window = segment[start:]
            if len(window) < 8:
                break
            best = max(best, Levenshtein.ratio(window, TARGET))
    return best


print("== mrp: tax phrase in class vs anywhere on the image")
MRP_MARKER = r"(?:\bm\.?\s*r\.?\s*p\b|₹|\brs\.?|\binr\b|maximum\s*retail|max\.?\s*retail|retail\s*sale)"
AMOUNT = r"(?<!\w)\d{1,4}(?:[., ]\d{2})?(?!\w)"
mrp_fail = fails("mrp_declaration")
base = [r for r in mrp_fail if has(MRP_MARKER, r["combined_text"]) and has(AMOUNT, r["combined_text"])]
print(f"  failures={len(mrp_fail)} marker&amount={len(base)}")
for cut in (0.72, 0.76, 0.80, 0.84):
    in_class = sum(tax_phrase_score(r["combined_text"]) >= cut for r in base)
    cross = sum(tax_phrase_score(image_text(r)) >= cut for r in base)
    print(f"  cut {cut}: in-class {in_class}   image-wide {cross}")
print("  gate check - score on failures with no tax letters at all:")
notax = [r for r in base if not has(r"t\s*a\s*[xk]", r["combined_text"])]
print(f"    n={len(notax)}  max={max((tax_phrase_score(r['combined_text']) for r in notax), default=0):.2f}")
print("  regression check - score on the 197 current passes:")
scores = [tax_phrase_score(r["combined_text"]) for r in passes("mrp_declaration")]
print(f"    min={min(scores):.2f}  below 0.76: {sum(s < 0.76 for s in scores)}  below 0.72: {sum(s < 0.72 for s in scores)}")

print("\n== date: what material is actually present in the 562 failures")
dates = fails("date_declarations")
DATE_ANY = (
    r"(?<!\d)\d{1,2}\s*[/.-]\s*\d{1,2}\s*[/.-]\s*\d{2,4}(?!\d)"
    r"|(?<!\d)(?:0?[1-9]|1[0-2])\s*[/.-]\s*20[23]\d(?!\d)"
    r"|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s*[-/. ]?\s*\d{2,4}"
    r"|(?<!\d)\d{1,2}\s*[-/. ]?\s*(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s*[-/. ]?\s*\d{2,4}"
)
PREFIX_ANY = (
    r"(?:pkd|pkg|pkt|packed|packing|packaging|mfg|mfd|mfd|manufactur|dom|"
    r"exp|expiry|expry|use\s*b|useb|best\s*before|bestbefore|bbe|ubd)"
)
DURATION = (
    r"(?:\d{1,3}|[oq]\d|\d[oq])\s*(?:months?|mnths?|mths?|days?|years?|yrs?)"
    r"|(?:months?|days?|years?)\s*from"
)
counts = Counter()
for row in dates:
    text = row["combined_text"]
    counts["date-shape present"] += bool(re.search(DATE_ANY, text, re.I))
    counts["prefix present"] += bool(re.search(PREFIX_ANY, text, re.I))
    counts["date AND prefix"] += bool(re.search(DATE_ANY, text, re.I) and re.search(PREFIX_ANY, text, re.I))
    counts["duration-ish"] += bool(re.search(DURATION, text, re.I))
    counts["duration AND from-mfg"] += bool(
        re.search(DURATION, text, re.I)
        and re.search(r"from\W*(?:the\W*)?(?:date\W*of\W*)?(?:mfg|mfd|manufactur|pack|pkd|pkg)", text, re.I)
    )
    counts["date-shape but NO prefix"] += bool(
        re.search(DATE_ANY, text, re.I) and not re.search(PREFIX_ANY, text, re.I)
    )
for label, count in counts.most_common():
    print(f"  {count:5d}  {label}")

print("\n  --- date AND prefix present yet failing, samples:")
shown = 0
for row in dates:
    text = row["combined_text"]
    if re.search(DATE_ANY, text, re.I) and re.search(PREFIX_ANY, text, re.I) and shown < 18:
        print(f"      {text[:120]}")
        shown += 1

print("\n  --- duration-ish samples:")
shown = 0
for row in dates:
    if re.search(DURATION, row["combined_text"], re.I) and shown < 14:
        print(f"      {row['combined_text'][:120]}")
        shown += 1

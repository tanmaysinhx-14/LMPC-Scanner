"""Scratch: count how often a candidate rule would fire over the digest.

Usage: python .claude/scratch/probe.py
"""

from __future__ import annotations

import csv
import re
import sys
from collections import Counter
from pathlib import Path

for stream in (sys.stdout, sys.stderr):
    try:
        stream.reconfigure(encoding="utf-8", errors="replace")
    except AttributeError:
        pass

ROWS = list(csv.DictReader(open("runs/ocr_rapidocr.digest.csv", encoding="utf-8-sig", newline="")))


def slice_rows(klass: str, verdict: str = "no") -> list[dict[str, str]]:
    return [row for row in ROWS if row["class"] == klass and row["is_compliant"] == verdict]


def report(title: str, rows: list[dict[str, str]], probes: dict[str, str]) -> None:
    print(f"\n== {title}  (n={len(rows)})")
    for label, pattern in probes.items():
        compiled = re.compile(pattern, re.IGNORECASE)
        hits = [row for row in rows if compiled.search(row["combined_text"])]
        print(f"  {len(hits):5d}  {label}")


dates = slice_rows("date_declarations")
report(
    "date_declarations failures",
    dates,
    {
        "any 4-digit year 20xx": r"20[23]\d",
        "spaced-out digit run (>=4 singles)": r"(?:(?<!\d)\d(?!\d)[ ]){3,}\d",
        "duration N months/days/years": r"\d{1,3}\s*(?:months?|days?|years?|mnths?|mths?)",
        "glued NNmonths": r"\d(?:months?|days?|years?)",
        "'from' + mfg/pkd word": r"from\s*(?:the\s*)?(?:date\s*of\s*)?(?:mfg|mfd|manufactur|packing|packag|pkd|pkg)",
        "best before (any spacing)": r"b\s*e\s*s\s*t\s*b?\s*e\s*f",
        "use before/by": r"us\s*e\s*b",
        "expiry-ish": r"exp|expiry|expry|use\s*by|useby|best\s*before|bbe|ubd",
        "packed-ish": r"pkd|pkg|packed|mfd|mfg|mfd|manufactur|dom|packing|packaging",
        "month name": r"jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec",
        "MM/YYYY only shape": r"(?<!\d)(?:0?[1-9]|1[0-2])\s*[/.-]\s*20[23]\d(?!\d)",
        "d/m/y full": r"(?<!\d)\d{1,2}\s*[/.-]\s*\d{1,2}\s*[/.-]\s*\d{2,4}(?!\d)",
        "no digits at all": r"^\D*$",
    },
)

mfr = slice_rows("manufacturer_details")
report(
    "manufacturer_details failures",
    mfr,
    {
        "pincode present": r"(?<!\d)[1-9]\d{2}\s?\d{3}(?!\d)",
        "current marker (mfd/mfg/packed/marketed/distributed/imported BY)": (
            r"\b(?:manufactured\s*(?:and|&)\s*marketed\s*by|manufactured\s+by|marketed\s+by|"
            r"mfg\.?\s*by|mfd\.?\s*by|packed\s+by|pkd\.?\s*by|distributed\s+by|imported\s+by)\b"
        ),
        "mktd/mkt + by": r"\bmk?t?d?\s*\.?\s*&?\s*(?:by|bv)\b",
        "glued <verb>by (no space)": r"(?:packed|marketed|manufactured|imported|distributed|mktd|mkt|repacked)by",
        "imported ... in india by": r"import\w*\s*\w*\s*(?:in\s*india\s*)?by",
        "'made in india'": r"made\s*in\s*india",
        "any of by-forms broadened": (
            r"(?:manufact\w*|mfg|mfd|mfr|market\w*|mktd|mkt|packed|pkd|pkg|repack\w*|import\w*|distribut\w*|"
            r"produced|processed)\s*[.&,:]*\s*(?:and|&)?\s*[.&,:]*\s*(?:by|bv|b[yv]\b)"
        ),
        "fssai lic word": r"(?:fssai|lic\.?\s*no|licence|license)",
        "14-digit run": r"(?<!\d)\d(?:[\s-]*\d){13}(?!\d)",
    },
)

mrp = slice_rows("mrp_declaration")
report(
    "mrp_declaration failures",
    mrp,
    {
        "any amount": r"(?<!\w)\d{1,4}(?:[., ]\d{2})?(?!\w)",
        "rupee/mrp marker": r"(?:\bm\.?\s*r\.?\s*p\b|₹|rs\.?|inr|maximum\s*retail)",
        "letters 'incl' anywhere": r"i\s*n\s*c\s*l",
        "letters 'tax'": r"t\s*a\s*x",
        "glued ofalltaxes": r"of\s*al{1,2}\s*ta[xk]e?s?",
        "alpha-run contains inclusiveofalltaxes-ish": r"incl?u?s?i?v?e?\W*o?f?\W*al{1,2}\W*ta[xk]",
        "2-decimal price": r"\d+[.,]\d{2}",
        "per-unit phrase": r"per\s*\d*\s*(?:kg|g|ml|l|gm|9|piece|pc)",
        "usp marker": r"\bu\s*s\s*p\b|unit\s*sale",
    },
)

net = slice_rows("net_quantity")
report(
    "net_quantity failures",
    net,
    {
        "has a number": r"\d",
        "has a lawful unit token": r"(?<!\w)(?:kg|g|mg|ml|l|cl)(?!\w)",
        "number then bare digit (g misread)": r"(?<!\d)\d{2,4}\s+[689](?!\d)",
        "number glued to 6/8": r"(?<!\d)\d{2,4}[689](?!\d)",
        "unlawful unit": r"(?<!\w)(?:kgs|gms|gm|mls|ls|mgs)(?!\w)",
        "multipack x": r"\d\s*[x*]\s*\d",
    },
)

consumer = slice_rows("consumer_care_fssai")
report(
    "consumer_care_fssai failures",
    consumer,
    {
        "14-digit run": r"(?<!\d)\d(?:[\s-]*\d){13}(?!\d)",
        "lic word": r"(?:fssai|lic\.?\s*n[o0]|licence|license)",
        "lic word + nearby 14 digits": r"(?:fssai|lic\.?\s*n[o0])[^0-9]{0,12}\d(?:[\s-]*\d){13}",
        "email with @": r"@",
        "1800/1860 helpline": r"(?<!\d)(?:1800|1860)(?:[\s-]*\d){6,}",
        "10-digit phone": r"(?<!\d)[6-9]\d{4}[\s-]?\d{5}(?!\d)",
        "www/domain": r"(?:www|\.com|\.in\b|\.coop)",
    },
)

print("\n== per-class totals")
print(Counter((row["class"], row["is_compliant"] or "-") for row in ROWS).most_common())

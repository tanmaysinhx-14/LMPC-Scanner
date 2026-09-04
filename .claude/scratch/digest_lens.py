"""Scratch: read the rapidocr digest and slice it however the question needs.

Not part of the shipped code. Usage:
    python .claude/scratch/digest_lens.py <class> [--fail|--pass|--all] [-n N] [--grep RE] [--nogrep RE]
"""

from __future__ import annotations

import argparse
import csv
import json
import re
import sys
from collections import Counter
from pathlib import Path

DIGEST = Path("runs/ocr_rapidocr.digest.csv")

# The corpus is full of CJK and Devanagari misreads; a cp1252 console kills the run.
for stream in (sys.stdout, sys.stderr):
    try:
        stream.reconfigure(encoding="utf-8", errors="replace")
    except AttributeError:
        pass


def load(path: Path = DIGEST) -> list[dict[str, str]]:
    with path.open(encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("klass", nargs="?", default=None)
    parser.add_argument("--verdict", choices=["fail", "pass", "all", "empty"], default="fail")
    parser.add_argument("-n", type=int, default=40)
    parser.add_argument("--grep", default=None)
    parser.add_argument("--nogrep", default=None)
    parser.add_argument("--field", default=None, help="count non-null values of this parsed field")
    parser.add_argument("--counts", action="store_true")
    parser.add_argument("--json", action="store_true", help="show parsed_json too")
    parser.add_argument("--digest", default=str(DIGEST))
    args = parser.parse_args(argv)

    rows = load(Path(args.digest))
    if args.klass:
        rows = [row for row in rows if row["class"] == args.klass]
    if args.verdict == "fail":
        rows = [row for row in rows if row["is_compliant"] == "no"]
    elif args.verdict == "pass":
        rows = [row for row in rows if row["is_compliant"] == "yes"]
    elif args.verdict == "empty":
        rows = [row for row in rows if row["is_compliant"] == ""]
    if args.grep:
        pattern = re.compile(args.grep, re.IGNORECASE)
        rows = [row for row in rows if pattern.search(row["combined_text"])]
    if args.nogrep:
        pattern = re.compile(args.nogrep, re.IGNORECASE)
        rows = [row for row in rows if not pattern.search(row["combined_text"])]

    if args.counts:
        print(f"{len(rows)} rows")
        print(Counter(row["class"] for row in rows).most_common())
        return 0

    if args.field:
        present = 0
        values: Counter[str] = Counter()
        for row in rows:
            try:
                parsed = json.loads(row["parsed_json"] or "{}")
            except json.JSONDecodeError:
                continue
            value = parsed.get(args.field)
            if value not in (None, "", [], {}):
                present += 1
                values[str(value)[:40]] += 1
        print(f"{args.field}: {present}/{len(rows)} present")
        for value, count in values.most_common(25):
            print(f"  {count:5d}  {value}")
        return 0

    print(f"# {len(rows)} rows matched", file=sys.stderr)
    for row in rows[: args.n]:
        print(f"[{row['class']}|{row['box_count']}box|{row['is_compliant'] or '-'}] {row['combined_text']}")
        if args.json:
            print(f"        {row['parsed_json']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

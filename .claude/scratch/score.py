"""Scratch: per-class pass table, and a diff against a snapshot digest."""

from __future__ import annotations

import csv
import json
import sys
from collections import OrderedDict, defaultdict

for stream in (sys.stdout, sys.stderr):
    try:
        stream.reconfigure(encoding="utf-8", errors="replace")
    except AttributeError:
        pass


def load(path: str) -> dict[tuple[str, str], dict[str, str]]:
    with open(path, encoding="utf-8-sig", newline="") as handle:
        return {(row["image"], row["class"]): row for row in csv.DictReader(handle)}


def table(rows: dict[tuple[str, str], dict[str, str]]) -> "OrderedDict[str, list[int]]":
    counts: OrderedDict[str, list[int]] = OrderedDict()
    for row in rows.values():
        bucket = counts.setdefault(row["class"], [0, 0, 0])
        if row["is_compliant"] == "yes":
            bucket[0] += 1
        elif row["is_compliant"] == "no":
            bucket[1] += 1
        else:
            bucket[2] += 1
    return counts


def show(label: str, rows: dict[tuple[str, str], dict[str, str]]) -> None:
    counts = table(rows)
    total_pass = sum(v[0] for v in counts.values())
    print(f"\n{label}: {total_pass}/{len(rows)} = {100 * total_pass / len(rows):.1f}%")
    for klass in sorted(counts, key=lambda k: -sum(counts[k][:2])):
        good, bad, none = counts[klass]
        denominator = good + bad
        share = f"{100 * good / denominator:.1f}%" if denominator else "n/a"
        print(f"  {klass:22s} pass {good:4d}  fail {bad:4d}  n/a {none:4d}   {share}")


if __name__ == "__main__":
    after = load(sys.argv[1] if len(sys.argv) > 1 else "runs/ocr_rapidocr.digest.csv")
    show("current", after)
    if len(sys.argv) > 2:
        before = load(sys.argv[2])
        show("baseline", before)
        gained = [key for key in after if before.get(key, {}).get("is_compliant") == "no" and after[key]["is_compliant"] == "yes"]
        lost = [key for key in after if before.get(key, {}).get("is_compliant") == "yes" and after[key]["is_compliant"] == "no"]
        print(f"\ngained {len(gained)}   lost {len(lost)}")
        by_class: dict[str, list[int]] = defaultdict(lambda: [0, 0])
        for key in gained:
            by_class[key[1]][0] += 1
        for key in lost:
            by_class[key[1]][1] += 1
        for klass, (up, down) in sorted(by_class.items()):
            print(f"  {klass:22s} +{up:4d}  -{down:4d}")
        for label, keys in (("LOST", lost), ("GAINED", gained)):
            print(f"\n--- {label} samples")
            for key in keys[:12]:
                print(f"  [{key[1]}] {after[key]['combined_text'][:110]}")
                print(f"      before {before[key]['parsed_json'][:150]}")
                print(f"      after  {after[key]['parsed_json'][:150]}")

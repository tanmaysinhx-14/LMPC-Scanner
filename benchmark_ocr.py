"""Head-to-head OCR benchmark on real label crops.

Why this exists
---------------
"EasyOCR isn't working fine" is a hypothesis, not a measurement. This harness
crops the *ground-truth* regions out of the dataset (so detector errors do not
contaminate the comparison), runs every registered backend over every prepared
variant, and reports accuracy proxies plus latency.

Two accuracy measures are produced:

* **field yield** - the share of crops whose text the rule engine can turn into a
  usable field (a date, an amount, a pincode...). No transcription needed, so it
  runs on the whole split today.
* **CER** - character error rate against a transcription CSV, when one is
  supplied with ``--truth``. This is the honest metric; ``--write-truth-template``
  emits a CSV skeleton to fill in.

CLI
---
    python benchmark_ocr.py --split test --limit 40
    python benchmark_ocr.py --backends rapidocr --classes mrp_declaration
    python benchmark_ocr.py --write-truth-template runs/ocr_truth.csv --limit 40
    python benchmark_ocr.py --truth runs/ocr_truth.csv
"""

from __future__ import annotations

import argparse
import csv
from dataclasses import dataclass, field
from datetime import datetime, timezone
import json
from pathlib import Path
import statistics
import sys
from typing import Any, Iterable, Sequence

import cv2
import numpy as np

import dataset_tools
import preprocessing

HERE = Path(__file__).resolve().parent
DEFAULT_OUT_DIR = HERE / "runs" / "ocr_benchmark"

#: Dietary marks carry no text, so they are excluded from an OCR benchmark.
SKIP_CANONICAL = {"dietary_symbol"}

__all__ = ["BenchmarkRow", "Crop", "collect_crops", "run_benchmark", "summarize"]


@dataclass(frozen=True)
class Crop:
    """One ground-truth region cut out of a dataset image."""

    image_stem: str
    split: str
    canonical_name: str
    raw_class_name: str
    image: np.ndarray
    box: tuple[int, int, int, int]

    @property
    def key(self) -> str:
        x1, y1, _x2, _y2 = self.box
        return f"{self.split}/{self.image_stem}#{self.canonical_name}@{x1},{y1}"

    @property
    def pixels(self) -> int:
        return self.image.shape[0] * self.image.shape[1]


@dataclass
class BenchmarkRow:
    """One (crop, backend, variant) measurement."""

    key: str
    canonical_name: str
    backend: str
    variant: str
    text: str
    confidence: float | None
    lines: int
    seconds: float
    crop_width: int
    crop_height: int
    scale: float
    parse_signal: float
    compliant: bool
    cer: float | None = None
    truth: str | None = None
    error: str | None = None

    def as_dict(self) -> dict[str, Any]:
        return {
            "key": self.key,
            "class": self.canonical_name,
            "backend": self.backend,
            "variant": self.variant,
            "text": self.text,
            "confidence": None if self.confidence is None else round(self.confidence, 4),
            "lines": self.lines,
            "seconds": round(self.seconds, 3),
            "crop_width": self.crop_width,
            "crop_height": self.crop_height,
            "scale": round(self.scale, 2),
            "parse_signal": round(self.parse_signal, 3),
            "compliant": int(self.compliant),
            "cer": None if self.cer is None else round(self.cer, 4),
            "truth": self.truth,
            "error": self.error,
        }


def _canonical_for(raw_class_name: str) -> str | None:
    from pipeline import _CANONICAL_CLASS_MAP

    return _CANONICAL_CLASS_MAP.get(raw_class_name)


def collect_crops(
    dataset: Path = dataset_tools.DEFAULT_DATASET,
    split: str = "test",
    limit: int | None = None,
    classes: Sequence[str] | None = None,
    margin: float = 0.04,
) -> list[Crop]:
    """Cut every labelled region out of ``split``, newest-first is not needed here.

    Reads the repaired 5-field labels, so run ``dataset_tools repair`` first;
    otherwise polygon rows are still honoured per row but you are benchmarking
    against boxes the trainer never saw.
    """

    wanted = set(classes) if classes else None
    crops: list[Crop] = []
    names = dataset_tools.class_names(dataset)

    for _split, label_path, rows in dataset_tools.load_rows(dataset, (split,)):
        image_path = dataset_tools._find_image(label_path)
        if image_path is None or not rows:
            continue
        image = cv2.imread(str(image_path))
        if image is None:
            continue
        height, width = image.shape[:2]
        for row in rows:
            raw_name = names[row.class_id] if 0 <= row.class_id < len(names) else str(row.class_id)
            canonical = _canonical_for(raw_name)
            if canonical is None or canonical in SKIP_CANONICAL:
                continue
            if wanted and canonical not in wanted:
                continue
            box = dataset_tools.row_to_bbox(row)
            if box is None:
                continue
            cx, cy, box_w, box_h = box
            pad_x, pad_y = box_w * margin, box_h * margin
            x1 = max(0, int((cx - box_w / 2 - pad_x) * width))
            y1 = max(0, int((cy - box_h / 2 - pad_y) * height))
            x2 = min(width, int((cx + box_w / 2 + pad_x) * width))
            y2 = min(height, int((cy + box_h / 2 + pad_y) * height))
            if x2 - x1 < 8 or y2 - y1 < 6:
                continue
            crops.append(
                Crop(label_path.stem, split, canonical, raw_name, image[y1:y2, x1:x2].copy(), (x1, y1, x2, y2))
            )
            if limit and len(crops) >= limit:
                return crops
    return crops


def _normalize_for_cer(text: str) -> str:
    """Case and whitespace are not what we are grading; characters are."""

    return " ".join(text.upper().split())


def character_error_rate(prediction: str, truth: str) -> float | None:
    """Levenshtein distance over the reference length; 0.0 is perfect."""

    reference = _normalize_for_cer(truth)
    if not reference:
        return None
    try:
        import Levenshtein
    except ImportError:
        return None
    distance = Levenshtein.distance(_normalize_for_cer(prediction), reference)
    return distance / len(reference)


def load_truth(path: Path) -> dict[str, str]:
    """Read a ``key,text`` transcription CSV written by --write-truth-template."""

    truth: dict[str, str] = {}
    with Path(path).open(newline="", encoding="utf-8") as handle:
        for record in csv.DictReader(handle):
            key = (record.get("key") or "").strip()
            text = (record.get("text") or "").strip()
            if key and text:
                truth[key] = text
    return truth


def write_truth_template(crops: Iterable[Crop], path: Path, crops_dir: Path | None = None) -> int:
    """Emit a CSV to transcribe by hand, optionally dumping the crop images too."""

    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    written = 0
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(["key", "class", "text"])
        for crop in crops:
            writer.writerow([crop.key, crop.canonical_name, ""])
            written += 1
            if crops_dir is not None:
                crops_dir.mkdir(parents=True, exist_ok=True)
                safe = crop.key.replace("/", "_").replace("#", "_").replace(",", "-").replace("@", "_at_")
                cv2.imwrite(str(crops_dir / f"{safe}.png"), crop.image)
    return written


def run_benchmark(
    crops: Sequence[Crop],
    backend_names: Sequence[str],
    truth: dict[str, str] | None = None,
    progress: bool = True,
) -> list[BenchmarkRow]:
    """Every crop x backend x variant, measured."""

    from ocr_backends import get_backend
    from pipeline import _parse_signal

    truth = truth or {}
    engines = []
    for name in backend_names:
        backend = get_backend(name)
        if not backend.is_available():
            print(f"skipping {name}: {backend.init_error}", file=sys.stderr)
            continue
        engines.append(backend)
    if not engines:
        raise RuntimeError("no requested OCR backend is usable on this machine")

    rows: list[BenchmarkRow] = []
    for index, crop in enumerate(crops, start=1):
        variants = preprocessing.build_variants(crop.image, crop.canonical_name)
        reference = truth.get(crop.key)
        for backend in engines:
            for variant in variants:
                result = backend.read(variant.image, allowlist=variant.allowlist)
                signal, compliant = _parse_signal(crop.canonical_name, result.text)
                rows.append(
                    BenchmarkRow(
                        key=crop.key,
                        canonical_name=crop.canonical_name,
                        backend=backend.name,
                        variant=variant.name,
                        text=result.text,
                        confidence=result.confidence,
                        lines=result.line_count,
                        seconds=result.elapsed_s,
                        crop_width=crop.image.shape[1],
                        crop_height=crop.image.shape[0],
                        scale=variant.scale,
                        parse_signal=signal,
                        compliant=compliant,
                        cer=character_error_rate(result.text, reference) if reference else None,
                        truth=reference,
                        error=result.error,
                    )
                )
        if progress and index % 10 == 0:
            print(f"  {index}/{len(crops)} crops", file=sys.stderr)
    return rows


def _mean(values: Iterable[float]) -> float | None:
    collected = [value for value in values if value is not None]
    return statistics.fmean(collected) if collected else None


def _group_stats(rows: Sequence[BenchmarkRow]) -> dict[str, Any]:
    non_empty = [row for row in rows if row.text.strip()]
    return {
        "crops": len(rows),
        "read_rate": round(len(non_empty) / len(rows), 3) if rows else 0.0,
        "field_yield": round(_mean([row.parse_signal for row in rows]) or 0.0, 3),
        "compliant_rate": round(sum(row.compliant for row in rows) / len(rows), 3) if rows else 0.0,
        "mean_confidence": round(_mean([row.confidence for row in non_empty]) or 0.0, 3),
        "mean_seconds": round(_mean([row.seconds for row in rows]) or 0.0, 3),
        "total_seconds": round(sum(row.seconds for row in rows), 1),
        "mean_cer": (lambda value: None if value is None else round(value, 4))(
            _mean([row.cer for row in rows if row.cer is not None])
        ),
        "errors": sum(1 for row in rows if row.error),
    }


def summarize(rows: Sequence[BenchmarkRow]) -> dict[str, Any]:
    """Aggregate by backend, by backend x variant, and by backend x class."""

    def bucket(key_fn) -> dict[str, Any]:
        groups: dict[str, list[BenchmarkRow]] = {}
        for row in rows:
            groups.setdefault(key_fn(row), []).append(row)
        return {key: _group_stats(value) for key, value in sorted(groups.items())}

    best_per_crop: dict[str, BenchmarkRow] = {}
    for row in rows:
        current = best_per_crop.get(row.key)
        if current is None or (row.parse_signal, row.confidence or 0) > (current.parse_signal, current.confidence or 0):
            best_per_crop[row.key] = row
    winners: dict[str, int] = {}
    for row in best_per_crop.values():
        winners[f"{row.backend}/{row.variant}"] = winners.get(f"{row.backend}/{row.variant}", 0) + 1

    return {
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "measurements": len(rows),
        "crops": len(best_per_crop),
        "by_backend": bucket(lambda row: row.backend),
        "by_backend_variant": bucket(lambda row: f"{row.backend}/{row.variant}"),
        "by_class": bucket(lambda row: f"{row.backend}/{row.canonical_name}"),
        "per_crop_winner": dict(sorted(winners.items(), key=lambda item: -item[1])),
    }


def render_summary(summary: dict[str, Any]) -> str:
    header = (
        f"{'backend/variant':<24}{'crops':>6}{'read':>7}{'yield':>7}"
        f"{'rule-ok':>9}{'conf':>7}{'s/crop':>8}{'CER':>8}"
    )
    lines = [
        f"{summary['crops']} crops, {summary['measurements']} measurements",
        "",
        header,
        "-" * len(header),
    ]
    for key, stats in summary["by_backend_variant"].items():
        cer = "-" if stats["mean_cer"] is None else f"{stats['mean_cer']:.3f}"
        lines.append(
            f"{key:<24}{stats['crops']:>6}{stats['read_rate']:>7.2f}{stats['field_yield']:>7.2f}"
            f"{stats['compliant_rate']:>9.2f}{stats['mean_confidence']:>7.2f}"
            f"{stats['mean_seconds']:>8.2f}{cer:>8}"
        )
    lines += ["", "totals per backend:"]
    for key, stats in summary["by_backend"].items():
        cer = "-" if stats["mean_cer"] is None else f"{stats['mean_cer']:.3f}"
        lines.append(
            f"  {key:<12} yield {stats['field_yield']:.2f}  conf {stats['mean_confidence']:.2f}  "
            f"{stats['mean_seconds']:.2f}s/crop  total {stats['total_seconds']:.0f}s  CER {cer}"
        )
    lines += ["", "best reading per crop came from:"]
    for key, count in summary["per_crop_winner"].items():
        lines.append(f"  {key:<24}{count}")
    return "\n".join(lines)


def write_rows_csv(rows: Sequence[BenchmarkRow], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(rows[0].as_dict())) if rows else None
        if writer is None:
            return
        writer.writeheader()
        for row in rows:
            writer.writerow(row.as_dict())


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dataset", type=Path, default=dataset_tools.DEFAULT_DATASET)
    parser.add_argument("--split", default="test", choices=list(dataset_tools.SPLITS))
    parser.add_argument("--limit", type=int, default=60, help="max crops (0 for the whole split)")
    parser.add_argument("--classes", nargs="*", help="canonical class names to restrict to")
    parser.add_argument("--backends", nargs="*", help="default: every registered backend")
    parser.add_argument("--truth", type=Path, help="transcription CSV to compute CER against")
    parser.add_argument("--write-truth-template", type=Path, help="write a CSV skeleton and exit")
    parser.add_argument("--dump-crops", type=Path, help="with --write-truth-template, also save crop images")
    parser.add_argument("--out-dir", type=Path, default=DEFAULT_OUT_DIR)
    parser.add_argument("--quiet", action="store_true")
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    if not args.dataset.is_dir():
        print(f"dataset not found: {args.dataset}", file=sys.stderr)
        return 2

    limit = args.limit if args.limit and args.limit > 0 else None
    crops = collect_crops(args.dataset, args.split, limit, args.classes)
    if not crops:
        print("no crops collected - check --split and --classes", file=sys.stderr)
        return 1
    print(f"{len(crops)} crops from {args.split}", file=sys.stderr)

    if args.write_truth_template:
        count = write_truth_template(crops, args.write_truth_template, args.dump_crops)
        print(f"wrote {count} rows to {args.write_truth_template}")
        if args.dump_crops:
            print(f"crop images in {args.dump_crops}")
        return 0

    from ocr_backends import backend_names as registered

    names = args.backends or registered()
    truth = load_truth(args.truth) if args.truth else None
    if args.truth and not truth:
        print(f"warning: no usable rows in {args.truth}", file=sys.stderr)

    rows = run_benchmark(crops, names, truth, progress=not args.quiet)
    summary = summarize(rows)

    args.out_dir.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    csv_path = args.out_dir / f"measurements_{stamp}.csv"
    json_path = args.out_dir / f"summary_{stamp}.json"
    write_rows_csv(rows, csv_path)
    json_path.write_text(json.dumps(summary, indent=2), encoding="utf-8")

    print()
    print(render_summary(summary))
    print()
    print(f"rows    : {csv_path}")
    print(f"summary : {json_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())








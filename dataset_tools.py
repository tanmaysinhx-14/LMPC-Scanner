"""Dataset label auditing and repair for the packaged-commodity YOLO dataset.

Why this module exists
----------------------
The Roboflow export mixes two annotation encodings inside the *same* label file:

* ``cls x1 y1 x2 y2 ... xn yn`` - a polygon (odd field count, 7 or more)
* ``cls cx cy w h``            - an axis-aligned YOLO box (exactly 5 fields)

``ultralytics.data.utils.verify_image_label`` decides per *file*, not per row::

    if any(len(x) > 6 for x in lb) and (not keypoint):   # is segment
        segments = [np.array(x[1:]).reshape(-1, 2) for x in lb]
        lb = np.concatenate((classes, segments2boxes(segments)), 1)

So a single polygon row makes every 5-field row in that file be reinterpreted as a
two-point polygon: ``cls cx cy w h`` becomes the points ``(cx, cy)`` and ``(w, h)``,
and ``segments2boxes`` spans a box between them. The resulting box is at the wrong
place and the wrong size, and the detector is trained to believe it.

``repair`` rewrites every row into the single 5-field form so the loader's
per-file heuristic can no longer misfire. Originals are copied aside first.

CLI
---
    python dataset_tools.py audit
    python dataset_tools.py repair [--dry-run]
    python dataset_tools.py verify
    python dataset_tools.py preview --split test --count 6
"""

from __future__ import annotations

import argparse
from collections import Counter, defaultdict
from dataclasses import dataclass, field
from datetime import datetime, timezone
import json
from pathlib import Path
import shutil
from typing import Iterable, Iterator, Sequence

DEFAULT_DATASET = Path(__file__).resolve().with_name("packaged-commodity-dataset")
SPLITS = ("train", "valid", "test")
BACKUP_DIR_NAME = "labels_original"
MANIFEST_NAME = "label_repair_manifest.json"

#: Row shapes we understand. Anything else is reported and left untouched.
BBOX_FIELDS = 5
MIN_POLYGON_FIELDS = 7  # class + at least three points

#: A box with a side below this fraction of the image is too small to learn from.
TINY_SIDE = 0.008

__all__ = [
    "AuditReport",
    "LabelRow",
    "RepairSummary",
    "SplitStats",
    "audit_dataset",
    "class_names",
    "format_bbox_row",
    "load_rows",
    "polygon_to_bbox",
    "read_label_file",
    "render_previews",
    "repair_dataset",
    "row_to_bbox",
    "ultralytics_trained_boxes",
    "verify_dataset",
]


@dataclass(frozen=True)
class LabelRow:
    """One annotation line, kept in whichever encoding the file used."""

    class_id: int
    values: tuple[float, ...]
    line_number: int
    raw: str

    @property
    def field_count(self) -> int:
        return len(self.values) + 1

    @property
    def is_bbox(self) -> bool:
        return len(self.values) == BBOX_FIELDS - 1

    @property
    def is_polygon(self) -> bool:
        return len(self.values) >= MIN_POLYGON_FIELDS - 1 and len(self.values) % 2 == 0

    @property
    def kind(self) -> str:
        if self.is_bbox:
            return "bbox"
        if self.is_polygon:
            return "polygon"
        return "unknown"


def class_names(dataset: Path = DEFAULT_DATASET) -> list[str]:
    """Read ``names`` out of ``data.yaml`` without requiring PyYAML."""

    data_yaml = Path(dataset) / "data.yaml"
    if not data_yaml.is_file():
        return []
    for line in data_yaml.read_text(encoding="utf-8").splitlines():
        if not line.startswith("names:"):
            continue
        body = line.split(":", 1)[1].strip()
        if body.startswith("[") and body.endswith("]"):
            return [item.strip().strip("'\"") for item in body[1:-1].split(",") if item.strip()]
    return []


def read_label_file(path: Path) -> list[LabelRow]:
    """Parse one label file, tolerating blank lines and trailing whitespace."""

    rows: list[LabelRow] = []
    for number, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        fields = raw.split()
        if not fields:
            continue
        try:
            class_id = int(float(fields[0]))
            values = tuple(float(value) for value in fields[1:])
        except ValueError:
            rows.append(LabelRow(-1, (), number, raw))
            continue
        rows.append(LabelRow(class_id, values, number, raw))
    return rows


def load_rows(dataset: Path = DEFAULT_DATASET, splits: Sequence[str] = SPLITS) -> Iterator[tuple[str, Path, list[LabelRow]]]:
    """Yield ``(split, label_path, rows)`` for every label file in the dataset."""

    for split in splits:
        label_dir = Path(dataset) / split / "labels"
        if not label_dir.is_dir():
            continue
        for path in sorted(label_dir.glob("*.txt")):
            yield split, path, read_label_file(path)


def _clamp(value: float, low: float = 0.0, high: float = 1.0) -> float:
    return max(low, min(high, value))


def polygon_to_bbox(values: Iterable[float]) -> tuple[float, float, float, float]:
    """Convert flat polygon coordinates to a clamped normalized ``cx cy w h`` box."""

    coordinates = list(values)
    xs = [_clamp(v) for v in coordinates[0::2]]
    ys = [_clamp(v) for v in coordinates[1::2]]
    x1, x2 = min(xs), max(xs)
    y1, y2 = min(ys), max(ys)
    return ((x1 + x2) / 2.0, (y1 + y2) / 2.0, x2 - x1, y2 - y1)


def row_to_bbox(row: LabelRow) -> tuple[float, float, float, float] | None:
    """The box the annotator *intended*, honouring each row's own encoding."""

    if row.is_bbox:
        cx, cy, width, height = row.values
        x1, x2 = _clamp(cx - width / 2.0), _clamp(cx + width / 2.0)
        y1, y2 = _clamp(cy - height / 2.0), _clamp(cy + height / 2.0)
        return ((x1 + x2) / 2.0, (y1 + y2) / 2.0, x2 - x1, y2 - y1)
    if row.is_polygon:
        return polygon_to_bbox(row.values)
    return None


def ultralytics_trained_boxes(rows: Sequence[LabelRow]) -> list[tuple[float, float, float, float] | None]:
    """Replicate what Ultralytics actually feeds the detector for one label file.

    Mirrors the per-file ``any(len(x) > 6 ...)`` branch in
    ``ultralytics.data.utils.verify_image_label`` so an audit can quantify the
    damage without importing torch.
    """

    treated_as_segments = any(row.field_count > 6 for row in rows)
    boxes: list[tuple[float, float, float, float] | None] = []
    for row in rows:
        if not treated_as_segments:
            boxes.append(row_to_bbox(row) if row.is_bbox else None)
            continue
        if len(row.values) < 2 or len(row.values) % 2:
            boxes.append(None)
            continue
        boxes.append(polygon_to_bbox(row.values))
    return boxes


def _iou(a: tuple[float, float, float, float], b: tuple[float, float, float, float]) -> float:
    def corners(box: tuple[float, float, float, float]) -> tuple[float, float, float, float]:
        cx, cy, width, height = box
        return (cx - width / 2, cy - height / 2, cx + width / 2, cy + height / 2)

    ax1, ay1, ax2, ay2 = corners(a)
    bx1, by1, bx2, by2 = corners(b)
    ix = max(0.0, min(ax2, bx2) - max(ax1, bx1))
    iy = max(0.0, min(ay2, by2) - max(ay1, by1))
    intersection = ix * iy
    union = max(0.0, ax2 - ax1) * max(0.0, ay2 - ay1) + max(0.0, bx2 - bx1) * max(0.0, by2 - by1) - intersection
    return intersection / union if union > 0 else 0.0


@dataclass
class SplitStats:
    """Counters for one split. ``corrupted_rows`` is the number the loader breaks."""

    split: str
    files: int = 0
    empty_files: int = 0
    rows: int = 0
    bbox_rows: int = 0
    polygon_rows: int = 0
    unknown_rows: int = 0
    mixed_files: int = 0
    corrupted_rows: int = 0
    tiny_rows: int = 0
    class_counts: Counter = field(default_factory=Counter)

    def merge(self, other: "SplitStats") -> None:
        self.files += other.files
        self.empty_files += other.empty_files
        self.rows += other.rows
        self.bbox_rows += other.bbox_rows
        self.polygon_rows += other.polygon_rows
        self.unknown_rows += other.unknown_rows
        self.mixed_files += other.mixed_files
        self.corrupted_rows += other.corrupted_rows
        self.tiny_rows += other.tiny_rows
        self.class_counts.update(other.class_counts)


@dataclass
class AuditReport:
    """Everything ``audit`` learned, in a shape that renders and serializes."""

    dataset: Path
    names: list[str] = field(default_factory=list)
    splits: dict[str, SplitStats] = field(default_factory=dict)
    mixed_file_names: list[str] = field(default_factory=list)
    unknown_row_notes: list[str] = field(default_factory=list)
    worst_corruptions: list[dict] = field(default_factory=list)

    @property
    def totals(self) -> SplitStats:
        total = SplitStats("all")
        for stats in self.splits.values():
            total.merge(stats)
        return total

    @property
    def is_clean(self) -> bool:
        total = self.totals
        return total.corrupted_rows == 0 and total.unknown_rows == 0

    def to_dict(self) -> dict:
        def stats_dict(stats: SplitStats) -> dict:
            payload = {
                key: getattr(stats, key)
                for key in ("files", "empty_files", "rows", "bbox_rows", "polygon_rows",
                            "unknown_rows", "mixed_files", "corrupted_rows", "tiny_rows")
            }
            payload["class_counts"] = dict(sorted(stats.class_counts.items()))
            return payload

        return {
            "dataset": str(self.dataset),
            "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "class_names": self.names,
            "totals": stats_dict(self.totals),
            "splits": {name: stats_dict(stats) for name, stats in self.splits.items()},
            "mixed_files": self.mixed_file_names,
            "unknown_rows": self.unknown_row_notes,
            "worst_corruptions": self.worst_corruptions,
        }

    def render(self) -> str:
        lines = [f"Dataset: {self.dataset}"]
        if self.names:
            lines.append(f"Classes ({len(self.names)}): {', '.join(self.names)}")
        header = f"{'split':<8}{'files':>7}{'rows':>7}{'bbox':>7}{'poly':>7}{'other':>7}{'mixed':>7}{'broken':>8}"
        lines += ["", header, "-" * len(header)]
        for name in list(self.splits) + ["all"]:
            stats = self.totals if name == "all" else self.splits[name]
            lines.append(
                f"{name:<8}{stats.files:>7}{stats.rows:>7}{stats.bbox_rows:>7}"
                f"{stats.polygon_rows:>7}{stats.unknown_rows:>7}{stats.mixed_files:>7}"
                f"{stats.corrupted_rows:>8}"
            )
        total = self.totals
        if total.files:
            share = 100.0 * total.mixed_files / total.files
            lines.append("")
            lines.append(f"Mixed-encoding files: {total.mixed_files}/{total.files} ({share:.1f}%)")
        if total.bbox_rows:
            share = 100.0 * total.corrupted_rows / total.bbox_rows
            lines.append(
                f"Box rows silently relocated by the loader: "
                f"{total.corrupted_rows}/{total.bbox_rows} ({share:.1f}% of all box rows)"
            )
        if total.tiny_rows:
            lines.append(
                f"Boxes with a side under {TINY_SIDE:.3f} of the image "
                f"(unusable as targets): {total.tiny_rows}"
            )
        lines.append("")
        lines.append("Per-class row counts (as authored):")
        for class_id, count in sorted(total.class_counts.items()):
            label = self.names[class_id] if 0 <= class_id < len(self.names) else f"<id {class_id}>"
            lines.append(f"  {class_id} {label:<24}{count:>6}")
        return "\n".join(lines + self._render_examples())

    def _render_examples(self, limit: int = 5) -> list[str]:
        if not self.worst_corruptions:
            return []
        lines = ["", f"Worst relocations (authored box -> box the detector was trained on):"]
        for item in self.worst_corruptions[:limit]:
            label = item["class_name"]
            authored = ", ".join(f"{v:.3f}" for v in item["authored"])
            trained = ", ".join(f"{v:.3f}" for v in item["trained"])
            lines.append(f"  {item['file']}:{item['line']}  {label}")
            lines.append(f"      authored ({authored})")
            lines.append(f"      trained  ({trained})   IoU {item['iou']:.4f}")
        if self.unknown_row_notes:
            lines += ["", "Rows in neither encoding:"]
            lines += [f"  {note}" for note in self.unknown_row_notes[:limit]]
        return lines


def audit_dataset(dataset: Path = DEFAULT_DATASET, splits: Sequence[str] = SPLITS) -> AuditReport:
    """Measure the mixed-encoding damage across every label file."""

    dataset = Path(dataset)
    names = class_names(dataset)
    report = AuditReport(dataset=dataset, names=names)

    for split, path, rows in load_rows(dataset, splits):
        stats = report.splits.setdefault(split, SplitStats(split))
        stats.files += 1
        if not rows:
            stats.empty_files += 1
            continue

        kinds = {row.kind for row in rows}
        mixed = "bbox" in kinds and "polygon" in kinds
        if mixed:
            stats.mixed_files += 1
            report.mixed_file_names.append(f"{split}/{path.name}")

        trained = ultralytics_trained_boxes(rows)
        for row, trained_box in zip(rows, trained):
            stats.rows += 1
            stats.class_counts[row.class_id] += 1
            if row.kind == "bbox":
                stats.bbox_rows += 1
            elif row.kind == "polygon":
                stats.polygon_rows += 1
            else:
                stats.unknown_rows += 1
                report.unknown_row_notes.append(
                    f"{split}/{path.name}:{row.line_number} ({row.field_count} fields) {row.raw.strip()[:60]}"
                )
                continue
            authored_box = row_to_bbox(row)
            if authored_box is not None and min(authored_box[2], authored_box[3]) < TINY_SIDE:
                stats.tiny_rows += 1
            _record_corruption(report, stats, split, path, row, trained_box, names)

    # Rank by how much label area the loader misplaces, so the examples are the
    # consequential relocations rather than the smallest boxes (which trivially
    # score IoU ~0 no matter where they land).
    report.worst_corruptions.sort(key=lambda item: -item["damage"])
    return report


def _record_corruption(
    report: AuditReport,
    stats: SplitStats,
    split: str,
    path: Path,
    row: LabelRow,
    trained_box: tuple[float, float, float, float] | None,
    names: Sequence[str],
) -> None:
    """Flag a row whose trained box drifted from the authored one."""

    authored = row_to_bbox(row)
    if authored is None:
        return
    if trained_box is None:
        overlap = 0.0
    else:
        overlap = _iou(authored, trained_box)
    if overlap > 0.99:
        return
    stats.corrupted_rows += 1
    authored_area = authored[2] * authored[3]
    trained_area = trained_box[2] * trained_box[3] if trained_box else 0.0
    report.worst_corruptions.append(
        {
            "file": f"{split}/{path.name}",
            "line": row.line_number,
            "class_id": row.class_id,
            "class_name": names[row.class_id] if 0 <= row.class_id < len(names) else f"<id {row.class_id}>",
            "authored": [round(v, 6) for v in authored],
            "trained": [round(v, 6) for v in trained_box] if trained_box else None,
            "iou": round(overlap, 6),
            "damage": round((1.0 - overlap) * max(authored_area, trained_area), 6),
        }
    )


def format_bbox_row(class_id: int, box: tuple[float, float, float, float]) -> str:
    """Render one normalized box as the canonical 5-field YOLO detection row."""

    cx, cy, width, height = box
    return f"{class_id} {cx:.6f} {cy:.6f} {width:.6f} {height:.6f}"


MIN_SIDE = 1e-4  # a box thinner than this in normalized units carries no signal


@dataclass
class RepairSummary:
    """Outcome of one ``repair`` invocation."""

    dataset: Path
    dry_run: bool
    files_scanned: int = 0
    files_rewritten: int = 0
    rows_converted: int = 0
    rows_unchanged: int = 0
    rows_dropped: list[str] = field(default_factory=list)
    backups_created: int = 0
    caches_removed: list[str] = field(default_factory=list)
    per_split: dict[str, int] = field(default_factory=dict)

    def render(self) -> str:
        mode = "DRY RUN - nothing written" if self.dry_run else "applied"
        lines = [
            f"Repair ({mode})",
            f"  label files scanned : {self.files_scanned}",
            f"  label files rewritten: {self.files_rewritten}",
            f"  polygon rows -> boxes: {self.rows_converted}",
            f"  rows already boxes   : {self.rows_unchanged}",
            f"  rows dropped         : {len(self.rows_dropped)}",
            f"  originals backed up  : {self.backups_created}",
        ]
        if self.per_split:
            lines.append("  rewritten per split  : " + ", ".join(
                f"{split}={count}" for split, count in sorted(self.per_split.items())
            ))
        for note in self.rows_dropped[:5]:
            lines.append(f"    dropped {note}")
        for cache in self.caches_removed:
            verb = "would remove cache" if self.dry_run else "removed stale cache"
            lines.append(f"  {verb}: {cache}")
        return "\n".join(lines)


def _remove_label_caches(dataset: Path, dry_run: bool) -> list[str]:
    """Ultralytics caches parsed labels in ``labels.cache``; stale ones mask a repair."""

    removed: list[str] = []
    for cache in sorted(dataset.rglob("*.cache")):
        removed.append(str(cache.relative_to(dataset)))
        if not dry_run:
            cache.unlink()
    return removed


def repair_dataset(
    dataset: Path = DEFAULT_DATASET,
    splits: Sequence[str] = SPLITS,
    dry_run: bool = False,
) -> RepairSummary:
    """Rewrite every label row as ``cls cx cy w h`` so the loader cannot misread it.

    Originals are copied to ``<split>/labels_original/`` before the first rewrite;
    a second run will not clobber that backup. A manifest records what changed.
    """

    dataset = Path(dataset)
    names = class_names(dataset)
    summary = RepairSummary(dataset=dataset, dry_run=dry_run)
    manifest_files: list[dict] = []

    for split, path, rows in load_rows(dataset, splits):
        summary.files_scanned += 1
        converted_rows: list[str] = []
        changed = False
        file_conversions = 0

        for row in rows:
            box = row_to_bbox(row)
            if box is None or min(box[2], box[3]) < MIN_SIDE:
                summary.rows_dropped.append(
                    f"{split}/{path.name}:{row.line_number} ({row.kind}, {row.field_count} fields)"
                )
                changed = True
                continue
            line = format_bbox_row(row.class_id, box)
            converted_rows.append(line)
            if row.is_bbox:
                summary.rows_unchanged += 1
            else:
                summary.rows_converted += 1
                file_conversions += 1
            if line != row.raw.strip():
                changed = True

        if not changed:
            continue

        summary.files_rewritten += 1
        summary.per_split[split] = summary.per_split.get(split, 0) + 1
        manifest_files.append(
            {
                "split": split,
                "file": path.name,
                "rows_before": len(rows),
                "rows_after": len(converted_rows),
                "polygons_converted": file_conversions,
            }
        )

        if dry_run:
            continue

        backup_dir = path.parent.parent / BACKUP_DIR_NAME
        backup_dir.mkdir(parents=True, exist_ok=True)
        backup = backup_dir / path.name
        if not backup.exists():
            shutil.copy2(path, backup)
            summary.backups_created += 1
        payload = "\n".join(converted_rows)
        path.write_text(payload + "\n" if payload else "", encoding="utf-8")

    summary.caches_removed = _remove_label_caches(dataset, dry_run)

    if not dry_run:
        manifest = {
            "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "dataset": str(dataset),
            "class_names": names,
            "backup_dir_name": BACKUP_DIR_NAME,
            "files_scanned": summary.files_scanned,
            "files_rewritten": summary.files_rewritten,
            "rows_converted": summary.rows_converted,
            "rows_unchanged": summary.rows_unchanged,
            "rows_dropped": summary.rows_dropped,
            "caches_removed": summary.caches_removed,
            "files": manifest_files,
        }
        (dataset / MANIFEST_NAME).write_text(json.dumps(manifest, indent=2), encoding="utf-8")

    return summary


def verify_dataset(dataset: Path = DEFAULT_DATASET, splits: Sequence[str] = SPLITS) -> list[str]:
    """Return a list of problems; an empty list means the loader cannot misread anything."""

    dataset = Path(dataset)
    names = class_names(dataset)
    problems: list[str] = []

    for split, path, rows in load_rows(dataset, splits):
        where = f"{split}/{path.name}"
        for row in rows:
            prefix = f"{where}:{row.line_number}"
            if not row.is_bbox:
                problems.append(f"{prefix} still has {row.field_count} fields ({row.kind})")
                continue
            if names and not 0 <= row.class_id < len(names):
                problems.append(f"{prefix} class id {row.class_id} outside 0..{len(names) - 1}")
            cx, cy, width, height = row.values
            if not all(0.0 <= v <= 1.0 for v in row.values):
                problems.append(f"{prefix} coordinates outside [0, 1]: {row.values}")
            if min(width, height) <= 0:
                problems.append(f"{prefix} degenerate box w={width} h={height}")
            if cx - width / 2 < -1e-6 or cx + width / 2 > 1 + 1e-6:
                problems.append(f"{prefix} box crosses the left/right image edge")
            if cy - height / 2 < -1e-6 or cy + height / 2 > 1 + 1e-6:
                problems.append(f"{prefix} box crosses the top/bottom image edge")
        trained = ultralytics_trained_boxes(rows)
        for row, trained_box in zip(rows, trained):
            authored = row_to_bbox(row)
            if authored and trained_box and _iou(authored, trained_box) < 0.99:
                problems.append(f"{where}:{row.line_number} loader would still relocate this box")
    return problems


PREVIEW_DIR = Path(__file__).resolve().with_name("runs") / "dataset_preview"
_IMAGE_SUFFIXES = (".jpg", ".jpeg", ".png", ".bmp", ".webp")


def _find_image(label_path: Path) -> Path | None:
    image_dir = label_path.parent.parent / "images"
    for suffix in _IMAGE_SUFFIXES:
        candidate = image_dir / (label_path.stem + suffix)
        if candidate.is_file():
            return candidate
    return None


def render_previews(
    dataset: Path = DEFAULT_DATASET,
    split: str = "test",
    count: int = 6,
    output_dir: Path = PREVIEW_DIR,
) -> list[Path]:
    """Draw current label boxes onto images so the repair can be eyeballed."""

    import cv2  # local import: audit/repair/verify must work without OpenCV

    dataset = Path(dataset)
    names = class_names(dataset)
    palette = [
        (60, 180, 75), (0, 165, 255), (40, 40, 220), (200, 130, 0),
        (180, 60, 200), (0, 200, 200), (120, 120, 60),
    ]
    out_dir = Path(output_dir) / split
    out_dir.mkdir(parents=True, exist_ok=True)

    written: list[Path] = []
    for _, label_path, rows in load_rows(dataset, (split,)):
        if len(written) >= count:
            break
        image_path = _find_image(label_path)
        if image_path is None or not rows:
            continue
        image = cv2.imread(str(image_path))
        if image is None:
            continue
        height, width = image.shape[:2]
        for row in rows:
            box = row_to_bbox(row)
            if box is None:
                continue
            cx, cy, bw, bh = box
            x1 = int((cx - bw / 2) * width)
            y1 = int((cy - bh / 2) * height)
            x2 = int((cx + bw / 2) * width)
            y2 = int((cy + bh / 2) * height)
            color = palette[row.class_id % len(palette)]
            label = names[row.class_id] if 0 <= row.class_id < len(names) else str(row.class_id)
            cv2.rectangle(image, (x1, y1), (x2, y2), color, 2)
            cv2.putText(image, label, (x1, max(14, y1 - 6)), cv2.FONT_HERSHEY_SIMPLEX, 0.45, color, 1, cv2.LINE_AA)
        target = out_dir / f"{label_path.stem}.jpg"
        cv2.imwrite(str(target), image)
        written.append(target)
    return written


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dataset", type=Path, default=DEFAULT_DATASET, help="dataset root holding train/valid/test")
    parser.add_argument("--splits", nargs="+", default=list(SPLITS), help="splits to operate on")
    sub = parser.add_subparsers(dest="command", required=True)

    audit = sub.add_parser("audit", help="report mixed encodings and the boxes the loader breaks")
    audit.add_argument("--json", type=Path, help="also write the full report to this path")

    repair = sub.add_parser("repair", help="rewrite every row as cls cx cy w h")
    repair.add_argument("--dry-run", action="store_true", help="report what would change, write nothing")

    sub.add_parser("verify", help="fail if any row could still be misread")

    preview = sub.add_parser("preview", help="render label boxes onto images for a visual check")
    preview.add_argument("--split", default="test")
    preview.add_argument("--count", type=int, default=6)
    preview.add_argument("--out", type=Path, default=PREVIEW_DIR)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    args = _build_parser().parse_args(argv)
    dataset = args.dataset
    if not dataset.is_dir():
        print(f"dataset not found: {dataset}")
        return 2
    splits = tuple(args.splits)

    if args.command == "audit":
        report = audit_dataset(dataset, splits)
        print(report.render())
        if args.json:
            args.json.write_text(json.dumps(report.to_dict(), indent=2), encoding="utf-8")
            print(f"\nwrote {args.json}")
        return 0 if report.is_clean else 1

    if args.command == "repair":
        summary = repair_dataset(dataset, splits, dry_run=args.dry_run)
        print(summary.render())
        if not args.dry_run:
            print(f"manifest: {dataset / MANIFEST_NAME}")
        return 0

    if args.command == "verify":
        problems = verify_dataset(dataset, splits)
        if not problems:
            print("OK - every label row is a single-encoding YOLO box, no row can be misread.")
            return 0
        print(f"{len(problems)} problem(s):")
        for problem in problems[:40]:
            print(f"  {problem}")
        if len(problems) > 40:
            print(f"  ... and {len(problems) - 40} more")
        return 1

    written = render_previews(dataset, args.split, args.count, args.out)
    for path in written:
        print(path)
    if not written:
        print("no previews rendered (missing images or empty labels)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())











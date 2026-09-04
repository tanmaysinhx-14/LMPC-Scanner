from __future__ import annotations

import argparse
from collections import OrderedDict
import csv
from datetime import datetime, timezone
import json
from pathlib import Path
import sys
import time
from typing import Any

import cv2
import numpy as np

import pipeline
import preprocessing
from ocr_backends import OcrLine, OcrResult, get_backend


IMAGE_EXTENSIONS = {".jpg", ".jpeg", ".png"}
#: What counts as a "low confidence" reading, per engine. RapidOCR returns a
#: calibrated PP-OCR score (dataset mean 0.77); EasyOCR's is uncalibrated and runs
#: far lower (mean 0.43) for text it reads correctly, so judging it on RapidOCR's
#: 0.5 flagged 5282 of 5419 readings in the full sweep as failures. These are
#: thresholds for *flagging crops to look at*, not for discarding text.
OCR_LOW_CONFIDENCE_BY_ENGINE = {"rapidocr": 0.5, "easyocr": 0.2, "tesseract": 0.5}
DEFAULT_OCR_LOW_CONFIDENCE = 0.5
DEFAULT_IMAGE_SIZE = 768
#: Images per throughput bucket. Small enough that the rate curve shows up within
#: the first minutes of a sweep, large enough that one box-heavy image does not
#: dominate a bucket.
THROUGHPUT_BUCKET_IMAGES = 25
#: Warn when a completed bucket is this many times slower per crop than the
#: opening one. The first full sweep ended at ~5x its opening rate, so 2x is well
#: clear of ordinary variation while still catching the problem early.
THROUGHPUT_WARN_RATIO = 2.0


def resolve_low_confidence(requested: float | None, engine_name: str) -> float:
    """The low-confidence threshold to use: explicit flag, else per-engine default."""

    if requested is not None:
        return float(requested)
    return OCR_LOW_CONFIDENCE_BY_ENGINE.get(engine_name, DEFAULT_OCR_LOW_CONFIDENCE)


def iter_image_paths(dataset_dir: Path) -> list[Path]:
    return sorted(
        (path for path in dataset_dir.rglob("*") if path.is_file() and path.suffix.lower() in IMAGE_EXTENSIONS),
        key=lambda path: path.relative_to(dataset_dir).as_posix().lower(),
    )


def clamp_confidence(value: float) -> float:
    return max(0.0, min(1.0, float(value)))


def parse_float(value: Any) -> float | None:
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return number if np.isfinite(number) else None


class TesseractBackend:
    name = "tesseract"

    def __init__(self) -> None:
        import pytesseract

        self._pytesseract = pytesseract

    def is_available(self) -> bool:
        try:
            self._pytesseract.get_tesseract_version()
        except Exception:
            return False
        return True

    def _config(self, allowlist: str | None) -> str:
        config = "--psm 6"
        if allowlist:
            safe_allowlist = allowlist.replace("'", "")
            config = f'{config} -c tessedit_char_whitelist="{safe_allowlist}"'
        return config

    def _read_lines(self, data: dict[str, list[Any]]) -> list[OcrLine]:
        groups: OrderedDict[tuple[str, str, str], dict[str, Any]] = OrderedDict()
        texts = data.get("text", [])
        confidences = data.get("conf", [])
        block_numbers = data.get("block_num", [])
        paragraph_numbers = data.get("par_num", [])
        line_numbers = data.get("line_num", [])
        left_values = data.get("left", [])
        top_values = data.get("top", [])
        width_values = data.get("width", [])
        height_values = data.get("height", [])

        for index, raw_text in enumerate(texts):
            text = str(raw_text or "").strip()
            if not text:
                continue
            native_confidence = parse_float(confidences[index] if index < len(confidences) else None)
            confidence = 0.0 if native_confidence is None or native_confidence < 0 else clamp_confidence(native_confidence / 100.0)
            key = (
                str(block_numbers[index] if index < len(block_numbers) else 0),
                str(paragraph_numbers[index] if index < len(paragraph_numbers) else 0),
                str(line_numbers[index] if index < len(line_numbers) else index),
            )
            group = groups.setdefault(key, {"texts": [], "confidences": [], "boxes": []})
            group["texts"].append(text)
            group["confidences"].append((len(text), confidence))
            left = int(parse_float(left_values[index] if index < len(left_values) else 0) or 0)
            top = int(parse_float(top_values[index] if index < len(top_values) else 0) or 0)
            width = int(parse_float(width_values[index] if index < len(width_values) else 0) or 0)
            height = int(parse_float(height_values[index] if index < len(height_values) else 0) or 0)
            group["boxes"].append((left, top, left + width, top + height))

        lines: list[OcrLine] = []
        for group in groups.values():
            weighted_total = sum(weight for weight, _ in group["confidences"])
            confidence = (
                sum(weight * value for weight, value in group["confidences"]) / weighted_total
                if weighted_total
                else 0.0
            )
            boxes = group["boxes"]
            lines.append(
                OcrLine(
                    " ".join(group["texts"]),
                    clamp_confidence(confidence),
                    (
                        min(box[0] for box in boxes),
                        min(box[1] for box in boxes),
                        max(box[2] for box in boxes),
                        max(box[3] for box in boxes),
                    ),
                )
            )
        return lines

    def read(self, image: np.ndarray, **kwargs: Any) -> OcrResult:
        if image is None or getattr(image, "size", 0) == 0:
            return OcrResult(backend=self.name, error="empty image")
        started = time.perf_counter()
        try:
            data = self._pytesseract.image_to_data(
                image,
                config=self._config(kwargs.get("allowlist")),
                output_type=self._pytesseract.Output.DICT,
            )
            return OcrResult(
                backend=self.name,
                lines=self._read_lines(data),
                elapsed_s=time.perf_counter() - started,
            )
        except Exception as exc:
            return OcrResult(
                backend=self.name,
                elapsed_s=time.perf_counter() - started,
                error=str(exc),
            )


def create_backend(name: str) -> Any:
    if name == "tesseract":
        backend = TesseractBackend()
        if not backend.is_available():
            raise RuntimeError(
                "Tesseract is unavailable. Install the Tesseract executable and ensure it is on PATH."
            )
        return backend
    backend = get_backend(name)
    if not backend.is_available():
        details = backend.describe()
        raise RuntimeError(f"OCR backend {name!r} is unavailable: {details.get('init_error') or 'initialization failed'}")
    return backend


def line_payload(line: OcrLine) -> dict[str, Any]:
    return {
        "text": line.text,
        "confidence": round(clamp_confidence(line.confidence), 6),
        "bbox": list(line.box),
    }


def ocr_payload(result: OcrResult) -> dict[str, Any]:
    return {
        "ocr_text": result.text,
        "ocr_confidence": None if result.confidence is None else round(clamp_confidence(result.confidence), 6),
        "ocr_lines": [line_payload(line) for line in result.lines if line.text.strip()],
        "ocr_error": result.error,
        "ocr_seconds": round(result.elapsed_s, 6),
        "ocr_backend": result.backend,
    }


def failure_reasons(record: dict[str, Any], low_confidence: float) -> list[str]:
    if record.get("ocr_status") == "not_applicable":
        return []
    reasons: list[str] = []
    text = str(record.get("ocr_text") or "").strip()
    confidence = record.get("ocr_confidence")
    if record.get("ocr_error"):
        reasons.append("ocr_error")
    if not text:
        reasons.append("empty_text")
    if confidence is None and text:
        reasons.append("confidence_unavailable")
    if confidence is not None and confidence < low_confidence:
        reasons.append("low_confidence")
    return reasons


def add_ocr_failure(
    failures: dict[str, dict[str, Any]],
    record: dict[str, Any],
    reasons: list[str],
) -> None:
    key = record["image_path"]
    image_failure = failures.setdefault(
        key,
        {
            "image_filename": record["image_filename"],
            "image_path": key,
            "failures": [],
        },
    )
    image_failure["failures"].append(
        {
            "source": record.get("ocr_source"),
            "detection_index": record.get("detection_index"),
            "detection_class": record.get("detection_class"),
            "bbox": record.get("bbox"),
            "ocr_text": record.get("ocr_text", ""),
            "ocr_confidence": record.get("ocr_confidence"),
            "reasons": reasons,
            "ocr_error": record.get("ocr_error"),
        }
    )


def make_detection_record(
    image_path: Path,
    dataset_dir: Path,
    detection_index: int,
    detection: dict[str, Any],
) -> dict[str, Any]:
    return {
        "record_type": "detection",
        "image_filename": image_path.name,
        "image_path": image_path.relative_to(dataset_dir).as_posix(),
        "detection_index": detection_index,
        "detection_class": detection.get("canonical_name") or detection.get("raw_class_name"),
        "raw_detection_class": detection.get("raw_class_name"),
        "canonical_class": detection.get("canonical_name"),
        "bbox": list(detection["bbox"]),
        "yolo_confidence": round(float(detection["yolo_confidence"]), 6),
        "ocr_source": "detection_crop",
        "ocr_engine": None,
        "ocr_text": "",
        "ocr_confidence": None,
        "ocr_lines": [],
        "ocr_error": None,
        "ocr_candidate_errors": [],
        "ocr_seconds": None,
        "ocr_variant": None,
        "ocr_score": None,
        "parsed": None,
        "ocr_status": "pending",
        "ocr_crop_bbox": None,
        "candidates": [],
    }


def _mark_image_done(
    output_handle: Any,
    image_path: Path,
    relative_path: str,
    seconds: float | None = None,
    crops: int | None = None,
) -> None:
    """Write a per-image completion marker and flush.

    ``--resume`` skips images whose marker is already in the output file. A
    zero-detection image leaves no other trace in the JSONL, so without this
    marker it would be reprocessed on every resume. Flushing after each image
    means a killed run leaves whole lines behind, which is what resume reads.

    The timing fields make throughput auditable per image rather than only per
    bucket - they are the finest grain at which the EasyOCR run's 4-6x slowdown can
    be attributed to particular images. See ``ThroughputTrace``.
    """

    record = {
        "record_type": "image_done",
        "image_filename": image_path.name,
        "image_path": relative_path,
    }
    if seconds is not None:
        record["seconds"] = round(seconds, 3)
    if crops is not None:
        record["crops"] = crops
    json.dump(record, output_handle, ensure_ascii=False)
    output_handle.write("\n")
    output_handle.flush()


def iter_records(jsonl_path: Path) -> Any:
    """Yield the parseable records of a JSONL file, skipping torn lines.

    A run killed mid-write leaves one partial line, and if it died without a
    trailing newline the next run's first record is spliced onto it. Both are
    unreadable and both are skipped here rather than crashing the reader.
    """

    with jsonl_path.open("r", encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            try:
                yield json.loads(line)
            except json.JSONDecodeError:
                continue


def _ensure_trailing_newline(output_file: Path) -> None:
    """Append a newline if the file does not end with one.

    Without this, resuming a run that was killed mid-write concatenates the new
    first record onto the old partial one, corrupting *both*: the readers skip the
    unparseable line, so a good record is silently lost on every resume. The full
    dataset run hit exactly this - line 1872 of ``runs/ocr_dataset.jsonl`` is a
    truncated detection with an ``image_done`` marker spliced into the middle.
    """

    if not output_file.is_file() or output_file.stat().st_size == 0:
        return
    with output_file.open("rb+") as handle:
        handle.seek(-1, 2)
        if handle.read(1) != b"\n":
            handle.write(b"\n")


def already_processed(output_file: Path) -> set[str]:
    """Relative image paths already completed in a prior run of this output file.

    An ``image_done`` marker is the direct answer, but files written before that
    marker existed have none, and re-OCR'ing them costs hours. For those, an image
    is treated as complete once records for a *different* image have appeared
    after it: the writer finishes one image before starting the next. The last
    image in the file is deliberately excluded - it may have been cut off
    mid-write, so it is redone.
    """

    marked: set[str] = set()
    inferred: list[str] = []
    seen: set[str] = set()
    for record in iter_records(output_file) if output_file.is_file() else ():
        image = record.get("image_path")
        if not image:
            continue
        image = str(image)
        if record.get("record_type") == "image_done":
            marked.add(image)
            continue
        if image not in seen:
            seen.add(image)
            inferred.append(image)
    # A marker is authoritative. Beyond those, every image that had a successor
    # finished; the one the file ends on is left out in case it was cut off.
    done = set(marked)
    done.update(inferred[:-1])
    return done


class ThroughputTrace:
    """Running seconds-per-crop, bucketed by image, so a slowdown is visible live.

    The first full sweep (EasyOCR on CUDA) took 4h15m. Holding class, crop size and
    line count constant, its cost per crop rose from 0.43 s over the split it
    processed first to 1.68 s and then 2.30 s over the two it processed later -
    roughly three quarters of the run was slowdown rather than work, and nothing
    said so until the output file was analysed afterwards.

    The RapidOCR sweep that followed, with this trace running, was flat: 56 buckets
    over 4h48m, 2.76 s/crop for the first three (ONNX warm-up) and then 1.24-2.29
    with no drift, ending at 1.80. Same machine, same images, same detector. So the
    degradation belongs to the CUDA/EasyOCR path, not to the harness, the content
    or thermals in general - which matters beyond this script, because the live app
    reads with EasyOCR on the same GPU.

    Seconds per crop rather than per image because images carry wildly different
    numbers of boxes, and buckets rather than a running mean because a mean over
    thousands of images barely moves once it is established.
    """

    def __init__(self, bucket_images: int = THROUGHPUT_BUCKET_IMAGES) -> None:
        self.bucket_images = max(1, int(bucket_images))
        self.buckets: list[dict[str, float]] = []
        self.total_seconds = 0.0
        self.total_crops = 0
        self._warned = False

    @staticmethod
    def _rate(bucket: dict[str, float]) -> float | None:
        """Seconds per crop, or ``None`` for a bucket whose images had no boxes."""

        crops = bucket["crops"]
        return bucket["seconds"] / crops if crops else None

    def record(self, seconds: float, crops: int) -> None:
        if not self.buckets or self.buckets[-1]["images"] >= self.bucket_images:
            self.buckets.append({"images": 0, "seconds": 0.0, "crops": 0})
        bucket = self.buckets[-1]
        bucket["images"] += 1
        bucket["seconds"] += float(seconds)
        bucket["crops"] += int(crops)
        self.total_seconds += float(seconds)
        self.total_crops += int(crops)

    @property
    def opening_rate(self) -> float | None:
        """Rate over the earliest bucket that saw any crops - the baseline."""

        for bucket in self.buckets:
            rate = self._rate(bucket)
            if rate is not None:
                return rate
        return None

    @property
    def recent_rate(self) -> float | None:
        for bucket in reversed(self.buckets):
            rate = self._rate(bucket)
            if rate is not None:
                return rate
        return None

    @property
    def overall_rate(self) -> float | None:
        return self.total_seconds / self.total_crops if self.total_crops else None

    def progress_note(self) -> str:
        """Rate suffix for the periodic progress line; empty until it means something."""

        overall = self.overall_rate
        if overall is None:
            return ""
        note = f", {overall:.2f}s/crop"
        recent = self.recent_rate
        if recent is not None and len(self.buckets) > 1:
            note += f" ({recent:.2f} recent)"
        return note

    def slowdown_warning(self) -> str | None:
        """Warn once, the first time a *completed* bucket is much slower than the opening one.

        Only completed buckets are compared, so a single slow image cannot trip
        it, and only once per run, so the tail of a genuinely degrading sweep does
        not bury the progress output.

        Checked against both real sweeps: it stays silent on the flat RapidOCR run
        (worst later bucket 0.83x the opening one) and would have fired early on the
        degrading EasyOCR one (3.9x by the second split). The known blind spot is a
        slow warm-up: RapidOCR opened at 2.76 s/crop before settling at 1.80, which
        lifts its warning bar to 5.5. Taking the baseline from the fastest bucket so
        far instead would have brought that run within 1.85x of warning on ordinary
        variation, so the opening bucket stays the baseline.
        """

        if self._warned or len(self.buckets) < 2:
            return None
        latest = self.buckets[-1]
        if latest["images"] < self.bucket_images:
            return None
        recent = self._rate(latest)
        opening = self.opening_rate
        if recent is None or not opening or recent < opening * THROUGHPUT_WARN_RATIO:
            return None
        self._warned = True
        return (
            f"slowdown: {recent:.2f}s/crop now vs {opening:.2f}s/crop at the start "
            f"({recent / opening:.1f}x). The first full sweep degraded the same way on identical "
            "work. Ctrl-C is safe - re-running with --resume continues from the last finished image."
        )

    def as_records(self) -> list[dict[str, float]]:
        """Bucket trace for the summary record, so the rate curve survives the run."""

        trace: list[dict[str, float]] = []
        for bucket in self.buckets:
            rate = self._rate(bucket)
            trace.append(
                {
                    "images": int(bucket["images"]),
                    "crops": int(bucket["crops"]),
                    "seconds": round(bucket["seconds"], 3),
                    "seconds_per_crop": round(rate, 4) if rate is not None else None,
                }
            )
        return trace


def process_image(
    image_path: Path,
    dataset_dir: Path,
    backend: Any,
    conf_threshold: float,
    image_size: int,
    full_image_ocr: bool,
    low_confidence: float,
    max_variants: int | None,
    class_filter: set[str] | None,
    output_handle: Any,
    zero_detection_images: list[dict[str, str]],
    ocr_failures: dict[str, dict[str, Any]],
    image_errors: list[dict[str, str]],
) -> tuple[int, int]:
    started = time.perf_counter()
    relative_path = image_path.relative_to(dataset_dir).as_posix()
    image = cv2.imread(str(image_path), cv2.IMREAD_COLOR)
    if image is None:
        error = {"image_filename": image_path.name, "image_path": relative_path, "error": "image could not be decoded"}
        image_errors.append(error)
        json.dump({"record_type": "image_error", **error}, output_handle, ensure_ascii=False)
        output_handle.write("\n")
        _mark_image_done(output_handle, image_path, relative_path, time.perf_counter() - started, 0)
        return 0, 0

    try:
        detections = pipeline.detect_only(image, conf=conf_threshold, imgsz=image_size)
    except Exception as exc:
        error = {"image_filename": image_path.name, "image_path": relative_path, "error": str(exc)}
        image_errors.append(error)
        json.dump({"record_type": "image_error", **error}, output_handle, ensure_ascii=False)
        output_handle.write("\n")
        _mark_image_done(output_handle, image_path, relative_path, time.perf_counter() - started, 0)
        return 0, 0

    if not detections:
        zero_detection_images.append({"image_filename": image_path.name, "image_path": relative_path})

    # --classes keeps only the requested canonical regions; zero-detection stats
    # above are still measured against the full detector output so they stay honest.
    if class_filter is not None:
        detections = [item for item in detections if item.get("canonical_name") in class_filter]

    height, width = image.shape[:2]
    detection_count = 0
    failure_count = 0

    for detection_index, detection in enumerate(detections):
        record = make_detection_record(image_path, dataset_dir, detection_index, detection)
        raw_class = detection.get("raw_class_name") or "unknown"
        canonical_class = detection.get("canonical_name")
        x1, y1, x2, y2 = pipeline._pad_box(detection["bbox"], width, height)
        record["ocr_crop_bbox"] = [x1, y1, x2, y2]
        crop = image[y1:y2, x1:x2]

        if crop.size == 0:
            record.update({"ocr_engine": getattr(backend, "name", None), "ocr_error": "empty detection crop", "ocr_status": "empty"})
        elif canonical_class == pipeline.DIETARY_CLASS:
            symbol = preprocessing.classify_dietary_symbol(crop)
            record.update(
                {
                    "ocr_source": "dietary_color_classifier",
                    "ocr_engine": "color_classifier",
                    "ocr_text": symbol,
                    "ocr_status": "not_applicable",
                    "parsed": symbol != "UNCERTAIN",
                }
            )
        else:
            try:
                reading = pipeline.read_region(crop, canonical_class or raw_class, backend, max_variants=max_variants)
                candidate_errors = [item["error"] for item in reading["candidates"] if item.get("error")]
                selected_text = reading["text"]
                selected_confidence = reading["confidence"]
                selected_status = "empty"
                if selected_text.strip() and selected_confidence is None:
                    selected_status = "confidence_unavailable"
                elif selected_text.strip() and selected_confidence < low_confidence:
                    selected_status = "low_confidence"
                elif selected_text.strip():
                    selected_status = "ok"
                record.update(
                    {
                        "ocr_engine": reading["backend"],
                        "ocr_text": selected_text,
                        "ocr_confidence": None if selected_confidence is None else round(clamp_confidence(selected_confidence), 6),
                        "ocr_error": candidate_errors[0] if not selected_text.strip() and candidate_errors else None,
                        "ocr_candidate_errors": candidate_errors,
                        "ocr_lines": next(
                            (item.get("ocr_lines", []) for item in reading["candidates"] if item.get("variant") == reading["variant"]),
                            [],
                        ),
                        "ocr_seconds": reading["seconds"],
                        "ocr_variant": reading["variant"],
                        "ocr_score": round(float(reading["score"]), 6),
                        "parsed": reading["parsed"],
                        "candidates": reading["candidates"],
                        "ocr_status": selected_status,
                    }
                )
            except Exception as exc:
                record.update({"ocr_engine": getattr(backend, "name", None), "ocr_error": str(exc), "ocr_status": "error"})

        json.dump(record, output_handle, ensure_ascii=False)
        output_handle.write("\n")
        detection_count += 1
        reasons = failure_reasons(record, low_confidence)
        if reasons:
            add_ocr_failure(ocr_failures, record, reasons)
            failure_count += 1

    if full_image_ocr:
        try:
            full_image_result = backend.read(image)
            full_image_record = {
                "record_type": "full_image_ocr",
                "image_filename": image_path.name,
                "image_path": relative_path,
                "detection_index": None,
                "detection_class": None,
                "raw_detection_class": None,
                "canonical_class": None,
                "bbox": None,
                "yolo_confidence": None,
                "ocr_source": "full_image",
                "ocr_engine": full_image_result.backend,
                "ocr_variant": None,
                "ocr_score": None,
                "parsed": None,
                "ocr_status": "ok" if full_image_result.text.strip() else "empty",
                **ocr_payload(full_image_result),
                "ocr_crop_bbox": None,
                "candidates": [],
            }
        except Exception as exc:
            full_image_record = {
                "record_type": "full_image_ocr",
                "image_filename": image_path.name,
                "image_path": relative_path,
                "detection_index": None,
                "detection_class": None,
                "bbox": None,
                "yolo_confidence": None,
                "ocr_source": "full_image",
                "ocr_engine": getattr(backend, "name", None),
                "ocr_text": "",
                "ocr_confidence": None,
                "ocr_lines": [],
                "ocr_error": str(exc),
                "ocr_candidate_errors": [],
                "ocr_seconds": None,
                "ocr_variant": None,
                "ocr_score": None,
                "parsed": None,
                "ocr_status": "error",
                "ocr_crop_bbox": None,
                "candidates": [],
            }
        json.dump(full_image_record, output_handle, ensure_ascii=False)
        output_handle.write("\n")
        reasons = failure_reasons(full_image_record, low_confidence)
        if reasons:
            add_ocr_failure(ocr_failures, full_image_record, reasons)
            failure_count += 1

    _mark_image_done(output_handle, image_path, relative_path, time.perf_counter() - started, detection_count)
    return detection_count, failure_count


def newest_pass_detections(jsonl_path: Path) -> list[dict[str, Any]]:
    """Detection records from the *latest* pass over each image.

    An output file can hold the same image twice: ``--resume`` after a killed run,
    or a re-run with a different engine appending to the same file. Aggregating
    across both would concatenate two engines' readings of the same box into one
    group and hand the parser doubled text, so only the last pass survives.

    A new pass over an image is recognised by an ``image_done`` marker, or - for
    files written before that marker existed - by ``detection_index`` restarting
    at 0 for an image that already has records. On the full dataset run this finds
    248 re-processed images and drops 1614 stale records, leaving exactly the 9420
    the final run reported.
    """

    passes: dict[str, list[list[dict[str, Any]]]] = {}
    for record in iter_records(jsonl_path):
        image = record.get("image_path")
        if not image:
            continue
        image = str(image)
        current = passes.setdefault(image, [[]])
        if record.get("record_type") == "image_done":
            if current[-1]:
                current.append([])
            continue
        if record.get("record_type") != "detection":
            continue
        if record.get("detection_index") == 0 and current[-1]:
            current.append([])
        current[-1].append(record)
    # A file ending in an image_done marker leaves a trailing empty pass, and an
    # image whose every pass was empty contributes nothing.
    return [
        record
        for image_passes in passes.values()
        for record in next((group for group in reversed(image_passes) if group), ())
    ]


#: Classes whose parser is given the whole pack's text, mirroring
#: ``rule_engine.validate_compliance``. Anything not listed here is judged on its
#: own region alone, so a reported gain cannot come from borrowed text.
_CROSS_REGION_CLASSES = frozenset({"consumer_care_fssai"})


def build_digest_rows(jsonl_path: Path) -> list[dict[str, Any]]:
    """Aggregate detection records to one row per (image, canonical class).

    This mirrors what ``pipeline.analyze_images`` does before the rule engine
    runs: every box of a class on an image is concatenated (MRP tag / price /
    "inclusive of all taxes" are split across separate boxes on purpose), so the
    parser must see the joined text, not a single crop - which is why the raw
    per-crop JSONL is misleading on its own. The join goes through
    ``pipeline.join_readings``, the same duplicate-dropping helper the app uses, so
    the digest cannot drift from what the rule engine will actually be given.
    """

    groups: OrderedDict[tuple[str, str], dict[str, Any]] = OrderedDict()
    for record in newest_pass_detections(jsonl_path):
        canonical = record.get("canonical_class")
        image = record.get("image_path")
        if not canonical or not image:
            continue
        group = groups.setdefault(
            (image, canonical),
            {"image": image, "class": canonical, "box_texts": [], "box_count": 0},
        )
        group["box_count"] += 1
        text = str(record.get("ocr_text") or "").strip()
        if text:
            group["box_texts"].append(text)

    rows: list[dict[str, Any]] = []
    combined_by_group = {
        key: pipeline.join_readings(group["box_texts"]) for key, group in groups.items()
    }
    # ``rule_engine.validate_compliance`` lets the consumer-care class see the
    # whole pack, because the FSSAI licence is usually printed in the manufacturer
    # block rather than beside the care line. The digest has to hand over the same
    # text or it scores a parser the app never runs. The single-region verdict is
    # kept alongside it so a gain can be attributed to the parser rather than to
    # the extra text.
    image_texts: dict[str, list[str]] = {}
    for (image, _), combined in combined_by_group.items():
        if combined:
            image_texts.setdefault(image, []).append(combined)

    for (image, canonical), group in groups.items():
        combined = combined_by_group[(image, canonical)]
        parser = pipeline._CLASS_PARSERS.get(canonical)
        parsed: dict[str, Any] = {}
        is_compliant: bool | None = None
        strict_is_compliant: bool | None = None
        if parser is not None and combined:
            try:
                strict = parser(combined)
                strict_is_compliant = bool(strict.get("is_compliant"))
                parsed, is_compliant = strict, strict_is_compliant
                if canonical in _CROSS_REGION_CLASSES:
                    parsed = parser(combined, " ".join(image_texts.get(image, ())))
                    is_compliant = bool(parsed.get("is_compliant"))
            except Exception as exc:  # a parser must never break the digest
                parsed = {"parser_error": str(exc)}
        rows.append(
            {
                "image": image,
                "class": canonical,
                "box_count": group["box_count"],
                "box_texts": group["box_texts"],
                "combined_text": combined,
                "parsed": parsed,
                "is_compliant": is_compliant,
                "strict_is_compliant": strict_is_compliant,
            }
        )
    return rows


def digest_paths(output_file: Path) -> tuple[Path, Path]:
    """`.digest.md` and `.digest.csv` siblings of the JSONL output file."""

    stem = output_file.with_suffix("")
    return (
        stem.with_name(stem.name + ".digest.md"),
        stem.with_name(stem.name + ".digest.csv"),
    )


def _compliance_mark(is_compliant: bool | None) -> str:
    if is_compliant is True:
        return "PASS"
    if is_compliant is False:
        return "FAIL"
    return "n/a"


def _csv_verdict(is_compliant: bool | None) -> str:
    return "" if is_compliant is None else ("yes" if is_compliant else "no")


def write_digest(rows: list[dict[str, Any]], md_path: Path, csv_path: Path, source: Path) -> None:
    """Write the aggregated digest as Markdown (to read) and CSV (to filter)."""

    with csv_path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(
            ["image", "class", "box_count", "is_compliant", "strict_is_compliant",
             "combined_text", "parsed_json"]
        )
        for row in rows:
            writer.writerow(
                [
                    row["image"],
                    row["class"],
                    row["box_count"],
                    _csv_verdict(row["is_compliant"]),
                    _csv_verdict(row["strict_is_compliant"]),
                    row["combined_text"],
                    json.dumps(row["parsed"], ensure_ascii=False, default=str),
                ]
            )

    by_class: OrderedDict[str, list[dict[str, Any]]] = OrderedDict()
    for row in rows:
        by_class.setdefault(row["class"], []).append(row)

    lines: list[str] = [
        "# OCR extraction digest",
        "",
        (
            f"Built from `{source.as_posix()}` - {len(rows)} image x class group(s). Each block is "
            "every box of one class on one image, concatenated the way "
            "`pipeline.analyze_images` concatenates them, then run through the matching "
            "`rule_engine` parser. Groups that do not parse compliant are listed first within "
            "each class - those are the ones to tune rules against."
        ),
        "",
        (
            "Two things the raw JSONL does not show. Only the *latest* pass over each image "
            "is used, so a resumed or re-run sweep does not blend two engines' readings of "
            "the same box. And `Combined` drops readings that repeat once case, spacing and "
            "punctuation are ignored - the same declaration photographed twice would "
            "otherwise reach the parser as two prices or two dates. `Box texts` still lists "
            "every box as read, so nothing is hidden."
        ),
        "",
    ]
    for canonical in sorted(by_class):
        group_rows = by_class[canonical]
        has_parser = pipeline._CLASS_PARSERS.get(canonical) is not None
        compliant = sum(1 for row in group_rows if row["is_compliant"] is True)
        attention = len(group_rows) - compliant
        header = f"## {canonical} - {len(group_rows)} group(s)"
        header += (
            f": {compliant} parsed-compliant, {attention} need attention"
            if has_parser
            else " (no rule parser; text shown for reference)"
        )
        lines.extend([header, ""])
        for row in sorted(group_rows, key=lambda item: (item["is_compliant"] is True, item["image"] or "")):
            lines.append(f"### [{_compliance_mark(row['is_compliant'])}] {row['image']}  ({row['box_count']} box(es))")
            if row["box_texts"]:
                lines.append("- Box texts:")
                lines.extend(f"    - `{text}`" for text in row["box_texts"])
            else:
                lines.append("- Box texts: _(no text read)_")
            lines.append(f"- Combined: `{row['combined_text']}`" if row["combined_text"] else "- Combined: _(empty)_")
            if row["parsed"]:
                lines.append(f"- Parsed: `{json.dumps(row['parsed'], ensure_ascii=False, default=str)}`")
            if row["is_compliant"] is not None:
                lines.append(f"- Compliant: {'yes' if row['is_compliant'] else 'no'}")
            lines.append("")
    md_path.write_text("\n".join(lines), encoding="utf-8")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser()
    # Not required, so --digest_only can rebuild a digest from an existing JSONL
    # without naming a dataset or weights it will not load; validate_args enforces
    # both for every other mode.
    parser.add_argument("--dataset_dir", type=Path, default=None)
    parser.add_argument("--yolo_weights", type=Path, default=None)
    parser.add_argument("--output_file", type=Path, required=True)
    parser.add_argument("--ocr_engine", choices=("rapidocr", "easyocr", "tesseract"), default="rapidocr")
    parser.add_argument("--conf_threshold", type=float, default=0.2)
    parser.add_argument(
        "--ocr_low_conf_threshold",
        type=float,
        default=None,
        help=(
            "confidence below which a reading is flagged for review; defaults per engine "
            f"({', '.join(f'{k} {v}' for k, v in sorted(OCR_LOW_CONFIDENCE_BY_ENGINE.items()))}) "
            "because EasyOCR's score is uncalibrated and sits far lower than RapidOCR's"
        ),
    )
    parser.add_argument("--full_image_ocr", action="store_true")
    parser.add_argument("--imgsz", type=int, default=DEFAULT_IMAGE_SIZE)
    parser.add_argument("--limit", type=int, default=None, help="process at most N images (after resume-skips)")
    parser.add_argument(
        "--classes",
        type=str,
        default=None,
        help="comma-separated canonical classes to keep, e.g. mrp_declaration,date_declarations",
    )
    parser.add_argument("--resume", action="store_true", help="skip images already recorded in --output_file and append")
    parser.add_argument(
        "--fast",
        action="store_true",
        # Percent signs are doubled because argparse interpolates help strings
        # against its own params dict, and a bare "% t" raises ValueError.
        help=(
            "one OCR variant per crop, i.e. --max_variants 1. Measurably lossy: on the full "
            "RapidOCR sweep it drops the share of class-groups the rule engine accepts from "
            "38.7%% to 33.7%% (net_quantity 64.9%% to 55.9%%). Prefer --max_variants 2"
        ),
    )
    parser.add_argument(
        "--max_variants",
        type=int,
        default=None,
        help="try at most N prepared variants per crop (default: all, 2-3 depending on class)",
    )
    parser.add_argument("--no_digest", action="store_true", help="skip the aggregated per-image per-class digest")
    parser.add_argument(
        "--digest_only",
        action="store_true",
        help="rebuild the digest from an existing --output_file without running YOLO or OCR",
    )
    return parser


def parse_class_filter(raw: str | None, parser: argparse.ArgumentParser) -> set[str] | None:
    if not raw:
        return None
    known = set(pipeline.RULE_CLASSES) | {pipeline.DIETARY_CLASS}
    requested = {item.strip() for item in raw.split(",") if item.strip()}
    unknown = requested - known
    if unknown:
        parser.error(f"unknown class(es): {', '.join(sorted(unknown))}. Known: {', '.join(sorted(known))}")
    return requested


def validate_args(args: argparse.Namespace, parser: argparse.ArgumentParser) -> None:
    if args.digest_only:
        if not args.output_file.is_file():
            parser.error(f"--digest_only needs an existing --output_file: {args.output_file}")
        if args.no_digest:
            parser.error("--digest_only and --no_digest contradict each other")
        return
    if args.dataset_dir is None:
        parser.error("--dataset_dir is required unless --digest_only is given")
    if args.yolo_weights is None:
        parser.error("--yolo_weights is required unless --digest_only is given")
    if not args.dataset_dir.is_dir():
        parser.error(f"dataset directory not found: {args.dataset_dir}")
    if not args.yolo_weights.is_file():
        parser.error(f"YOLO weights not found: {args.yolo_weights}")
    if not 0.0 <= args.conf_threshold <= 1.0:
        parser.error("--conf_threshold must be between 0 and 1")
    if args.ocr_low_conf_threshold is not None and not 0.0 <= args.ocr_low_conf_threshold <= 1.0:
        parser.error("--ocr_low_conf_threshold must be between 0 and 1")
    if args.imgsz <= 0:
        parser.error("--imgsz must be positive")
    if args.limit is not None and args.limit <= 0:
        parser.error("--limit must be positive")
    if args.max_variants is not None and args.max_variants <= 0:
        parser.error("--max_variants must be positive")
    if args.fast and args.max_variants is not None and args.max_variants != 1:
        parser.error("--fast is --max_variants 1; pass only one of them")


def write_digest_for(output_file: Path) -> None:
    """Build and write the digest for an existing JSONL output file."""

    md_path, csv_path = digest_paths(output_file)
    rows = build_digest_rows(output_file)
    write_digest(rows, md_path, csv_path, output_file)
    compliant = sum(1 for row in rows if row["is_compliant"] is True)
    strict = sum(1 for row in rows if row["strict_is_compliant"] is True)
    print(
        f"digest: {len(rows)} image x class group(s), {compliant} parsed-compliant "
        f"({strict} from single-region text alone)"
    )
    print(f"wrote {md_path}")
    print(f"wrote {csv_path}")


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    validate_args(args, parser)
    if args.digest_only:
        write_digest_for(args.output_file)
        return 0
    class_filter = parse_class_filter(args.classes, parser)
    max_variants = 1 if args.fast else args.max_variants

    image_paths = iter_image_paths(args.dataset_dir)
    if not image_paths:
        parser.error(f"no jpg, jpeg, or png images found under {args.dataset_dir}")

    total_discovered = len(image_paths)
    resuming = bool(args.resume) and args.output_file.is_file()
    processed = already_processed(args.output_file) if resuming else set()
    if processed:
        image_paths = [p for p in image_paths if p.relative_to(args.dataset_dir).as_posix() not in processed]
        print(f"resume: skipping {len(processed)} already-processed image(s)", file=sys.stderr)
    if args.limit is not None:
        image_paths = image_paths[: args.limit]
    pending_total = len(image_paths)

    try:
        from ultralytics import YOLO

        model = YOLO(str(args.yolo_weights))
        backend = create_backend(args.ocr_engine)
    except Exception as exc:
        print(f"initialization failed: {exc}", file=sys.stderr)
        return 2

    pipeline.model = model
    # Resolve after create_backend so the threshold follows the engine actually in
    # use, not the name asked for.
    engine_name = getattr(backend, "name", args.ocr_engine)
    low_confidence = resolve_low_confidence(args.ocr_low_conf_threshold, engine_name)
    if args.ocr_low_conf_threshold is None:
        print(f"low-confidence flag threshold: {low_confidence} (default for {engine_name})", file=sys.stderr)
    args.output_file.parent.mkdir(parents=True, exist_ok=True)
    zero_detection_images: list[dict[str, str]] = []
    ocr_failures: dict[str, dict[str, Any]] = {}
    image_errors: list[dict[str, str]] = []
    detection_count = 0
    failure_count = 0
    started = time.perf_counter()
    throughput = ThroughputTrace()

    open_mode = "a" if resuming else "w"
    if resuming:
        _ensure_trailing_newline(args.output_file)
    with args.output_file.open(open_mode, encoding="utf-8", newline="\n") as output_handle:
        for index, image_path in enumerate(image_paths, start=1):
            image_started = time.perf_counter()
            image_detections, image_failures = process_image(
                image_path=image_path,
                dataset_dir=args.dataset_dir,
                backend=backend,
                conf_threshold=args.conf_threshold,
                image_size=args.imgsz,
                full_image_ocr=args.full_image_ocr,
                low_confidence=low_confidence,
                max_variants=max_variants,
                class_filter=class_filter,
                output_handle=output_handle,
                zero_detection_images=zero_detection_images,
                ocr_failures=ocr_failures,
                image_errors=image_errors,
            )
            detection_count += image_detections
            failure_count += image_failures
            throughput.record(time.perf_counter() - image_started, image_detections)
            if index == 1 or index % 10 == 0 or index == pending_total:
                print(
                    f"processed {index}/{pending_total} images, {detection_count} detections, "
                    f"{failure_count} OCR failures{throughput.progress_note()}",
                    file=sys.stderr,
                )
            warning = throughput.slowdown_warning()
            if warning:
                print(warning, file=sys.stderr)

        summary = {
            "record_type": "summary",
            "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "dataset_dir": str(args.dataset_dir.resolve()),
            "yolo_weights": str(args.yolo_weights.resolve()),
            "ocr_engine": engine_name,
            "conf_threshold": args.conf_threshold,
            "ocr_low_conf_threshold": low_confidence,
            "ocr_low_conf_threshold_source": "flag" if args.ocr_low_conf_threshold is not None else "engine_default",
            "full_image_ocr": args.full_image_ocr,
            "fast_mode": bool(args.fast),
            "max_variants": max_variants,
            "class_filter": sorted(class_filter) if class_filter else None,
            "resumed": resuming,
            "limit": args.limit,
            "image_count": total_discovered,
            "images_processed_this_run": pending_total,
            "detection_record_count": detection_count,
            "ocr_failure_record_count": failure_count,
            "zero_detection_image_count": len(zero_detection_images),
            "image_error_count": len(image_errors),
            "zero_detection_images": zero_detection_images,
            "ocr_failure_images": list(ocr_failures.values()),
            "image_errors": image_errors,
            "elapsed_seconds": round(time.perf_counter() - started, 3),
            # Wall time inside process_image (YOLO + preprocessing + OCR + write),
            # summed per image. Compare against elapsed_seconds to see overhead,
            # and against the bucket trace to see whether the rate held.
            "processing_seconds_total": round(throughput.total_seconds, 3),
            "seconds_per_crop": round(throughput.overall_rate, 4) if throughput.overall_rate else None,
            "seconds_per_crop_opening": round(throughput.opening_rate, 4) if throughput.opening_rate else None,
            "throughput_bucket_images": throughput.bucket_images,
            "throughput_buckets": throughput.as_records(),
        }
        json.dump(summary, output_handle, ensure_ascii=False)
        output_handle.write("\n")

    print(f"wrote {args.output_file}")
    print(f"images this run: {pending_total} of {total_discovered} discovered; detections: {detection_count}; OCR failures: {failure_count}")
    print(f"zero detections: {len(zero_detection_images)}; image errors: {len(image_errors)}")
    if throughput.overall_rate is not None:
        line = f"throughput: {throughput.overall_rate:.2f}s/crop over {throughput.total_crops} crops"
        opening = throughput.opening_rate
        recent = throughput.recent_rate
        if opening and recent and len(throughput.buckets) > 1:
            line += f"; first bucket {opening:.2f}s/crop, last {recent:.2f}s/crop"
        print(line)

    if not args.no_digest:
        write_digest_for(args.output_file)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

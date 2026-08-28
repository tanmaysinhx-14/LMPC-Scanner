"""OCR helpers for text regions produced by the packaged-goods detector.

PaddleOCR is initialized lazily because Streamlit imports application modules
before a camera session exists, and PaddleOCR's model initialization is
expensive.  The engine is still configured for CPU inference so it does not
consume the GPU memory reserved for the detector.
"""

from __future__ import annotations

from threading import Lock
from typing import Any, Iterable

import numpy as np

try:
    from paddleocr import PaddleOCR
except Exception as exc:  # pragma: no cover - depends on native Paddle files
    PaddleOCR = None  # type: ignore[assignment,misc]
    _PADDLEOCR_IMPORT_ERROR: Exception | None = exc
else:
    _PADDLEOCR_IMPORT_ERROR = None


_ENGINE_LOCK = Lock()
_OCR_CALL_LOCK = Lock()
ocr: Any | None = None


def _get_ocr_engine() -> Any:
    """Return the shared CPU OCR engine, creating it on first use."""

    global ocr

    if ocr is not None:
        return ocr

    with _ENGINE_LOCK:
        if ocr is not None:
            return ocr

        if PaddleOCR is None:
            raise RuntimeError(
                "PaddleOCR could not be imported. Install a compatible "
                "paddleocr/paddlepaddle environment before starting OCR."
            ) from _PADDLEOCR_IMPORT_ERROR

        # Keep the requested PaddleOCR configuration as the primary path.  A
        # small compatibility fallback supports newer PaddleOCR releases that
        # replaced use_angle_cls/use_gpu with the device argument.
        try:
            ocr = PaddleOCR(use_angle_cls=True, lang='en', use_gpu=False, enable_mkldnn=False)
        except (TypeError, ValueError) as legacy_error:
            try:
                ocr = PaddleOCR(lang="en", device="cpu")
            except Exception:
                raise legacy_error

    return ocr


def _iter_crops(value: Any) -> Iterable[np.ndarray]:
    """Yield image arrays from either one crop or a list of crops."""

    if isinstance(value, np.ndarray):
        yield value
        return

    if value is None:
        return

    if isinstance(value, (list, tuple)):
        for item in value:
            yield from _iter_crops(item)


def _field(value: Any, name: str, default: Any = None) -> Any:
    """Read a key from dict-like Paddle results or an SDK result object."""

    if isinstance(value, dict):
        return value.get(name, default)
    return getattr(value, name, default)


def _box_position(box: Any) -> tuple[float, float]:
    """Return the top-left position of an OCR polygon for stable ordering."""

    try:
        points = np.asarray(box, dtype=float).reshape(-1, 2)
        if points.size:
            return float(points[:, 1].min()), float(points[:, 0].min())
    except (TypeError, ValueError):
        pass
    return (float("inf"), float("inf"))


def _append_text_record(records: list[tuple[float, float, str]], entry: Any) -> bool:
    """Parse one PaddleOCR 2.x ``[box, [text, score]]`` record."""

    if not isinstance(entry, (list, tuple)) or len(entry) < 2:
        return False

    text_score = entry[1]
    if not isinstance(text_score, (list, tuple)) or not text_score:
        return False
    text = text_score[0]
    if not isinstance(text, str):
        return False

    y, x = _box_position(entry[0])
    cleaned = " ".join(text.split())
    if cleaned:
        records.append((y, x, cleaned))
    return True


def _parse_ocr_result(raw_result: Any) -> list[str]:
    """Normalize PaddleOCR 2.x and 3.x result structures to text lines."""

    records: list[tuple[float, float, str]] = []

    def visit(value: Any) -> None:
        if value is None:
            return

        # PaddleOCR 3.x returns result objects with rec_texts/rec_polys.
        rec_texts = _field(value, "rec_texts")
        if rec_texts is not None:
            rec_polys = _field(value, "rec_polys", [])
            if rec_polys is None:
                rec_polys = []
            for index, text in enumerate(rec_texts):
                if not isinstance(text, str):
                    continue
                y, x = _box_position(rec_polys[index]) if index < len(rec_polys) else (
                    float("inf"),
                    float("inf"),
                )
                cleaned = " ".join(text.split())
                if cleaned:
                    records.append((y, x, cleaned))
            return

        if _append_text_record(records, value):
            return

        if isinstance(value, dict):
            # Some wrappers nest the actual OCR result under one of these
            # fields.  Avoid walking arbitrary metadata values.
            for key in ("ocr_res", "result", "res", "data"):
                nested = value.get(key)
                if nested is not None:
                    visit(nested)
            return

        if isinstance(value, (list, tuple)):
            for item in value:
                visit(item)

    visit(raw_result)
    records.sort(key=lambda record: (record[0], record[1]))
    return [record[2] for record in records]


def _run_ocr(engine: Any, crop: np.ndarray) -> Any:
    """Call the OCR API exposed by the installed PaddleOCR major version."""

    if hasattr(engine, "ocr"):
        try:
            return engine.ocr(crop, cls=True)
        except TypeError:
            return engine.ocr(crop)

    if hasattr(engine, "predict"):
        return engine.predict(crop)

    if callable(engine):
        return engine(crop)

    raise RuntimeError("The configured PaddleOCR object exposes no OCR method.")


def extract_text_from_crops(text_regions: dict) -> dict:
    """Extract and flatten OCR text for each detector region class.

    ``preprocessing.extract_and_process_rois`` supplies a list of crops for a
    class.  A single ndarray is accepted too, which keeps this function useful
    for callers that have only one detected region.
    """

    if not isinstance(text_regions, dict):
        raise TypeError("text_regions must be a dictionary of class names to crops")

    extracted: dict[str, str] = {}
    engine: Any | None = None

    for class_name, crop_value in text_regions.items():
        class_lines: list[str] = []
        for crop in _iter_crops(crop_value):
            if crop.size == 0:
                continue
            if engine is None:
                engine = _get_ocr_engine()
            with _OCR_CALL_LOCK:
                raw_result = _run_ocr(engine, crop)
            class_lines.extend(_parse_ocr_result(raw_result))

        extracted[str(class_name)] = " ".join(line for line in class_lines if line).strip()

    return extracted

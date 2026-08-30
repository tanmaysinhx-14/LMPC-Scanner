"""Detector + OCR pipeline for packaged-commodity label compliance.

Flow
----
``bytes`` -> YOLO region detection -> per-region crop preparation -> OCR across a
few prepared variants -> pick the variant whose text the rule engine can actually
parse -> aggregate one text blob per statutory class.

What changed from the first prototype
-------------------------------------
* The OCR engine is no longer hard-wired. ``ocr_backends`` supplies EasyOCR (now
  on the GPU) or RapidOCR (PP-OCR via ONNX Runtime) behind one interface.
* Crops are scaled by *measured text height* instead of a blind ``fx=3``, and
  deskewed first (see ``preprocessing``).
* Every region is read in two or three prepared variants and the winner is chosen
  by OCR confidence *and* whether ``rule_engine`` can parse the result. Reading a
  date as "01JUN27" beats a higher-confidence "0lJUNZ7" that no parser accepts.
* ``detect_only`` exposes detection without OCR, which is what the live WebRTC
  view needs for its overlay.
* The dietary (veg/non-veg) mark is classified and reported instead of dropped.

``process_image`` keeps its original two-tuple signature so existing callers and
``debug_view`` continue to work unchanged.
"""

from __future__ import annotations

from dataclasses import dataclass, field
import logging
import os
from threading import Lock
import time
from typing import Any, Callable, Sequence

import cv2
import numpy as np

import preprocessing
import rule_engine
from ocr_backends import OcrResult, resolve_backend

logger = logging.getLogger(__name__)

MODEL_PATH = os.environ.get("LMPC_MODEL_PATH", "runs/detect/train/weights/best.pt")
#: Detector confidence floor. Statutory panels are small, so the default 0.25 is
#: kept low deliberately; the report shows the score for every box.
DETECT_CONF = float(os.environ.get("LMPC_DETECT_CONF", "0.20"))
DETECT_IOU = float(os.environ.get("LMPC_DETECT_IOU", "0.5"))
DETECT_IMGSZ = int(os.environ.get("LMPC_DETECT_IMGSZ", "768"))
OCR_BACKEND = os.environ.get("LMPC_OCR_BACKEND") or None

RULE_CLASSES = (
    "product_name",
    "net_quantity",
    "mrp_declaration",
    "date_declarations",
    "batch_number",
    "manufacturer_details",
    "consumer_care_fssai",
)
DIETARY_CLASS = "dietary_symbol"

#: Detector class names (and the aliases a future retrain might introduce) mapped
#: onto the canonical names the rule engine and the report use.
_CANONICAL_CLASS_MAP = {
    "product_name": "product_name",
    "product_name_region": "product_name",
    "generic_name": "product_name",
    "generic_name_region": "product_name",
    "brand_region": "product_name",
    "product_identity_region": "product_name",
    "net_quantity": "net_quantity",
    "net_quantity_region": "net_quantity",
    "mrp_declaration": "mrp_declaration",
    "mrp_region": "mrp_declaration",
    "price_region": "mrp_declaration",
    "unit_sale_price_region": "mrp_declaration",
    "date_declarations": "date_declarations",
    "date_region": "date_declarations",
    "expiry_region": "date_declarations",
    "batch_number": "batch_number",
    "batch_number_region": "batch_number",
    "batch_region": "batch_number",
    "lot_number_region": "batch_number",
    "manufacturer_details": "manufacturer_details",
    "manufacturer_region": "manufacturer_details",
    "manufacturer_address_region": "manufacturer_details",
    "packer_region": "manufacturer_details",
    "importer_region": "manufacturer_details",
    "marketed_by_region": "manufacturer_details",
    "country_origin_region": "manufacturer_details",
    "consumer_care_fssai": "consumer_care_fssai",
    "consumer_care_region": "consumer_care_fssai",
    "consumer_support_region": "consumer_care_fssai",
    "fssai_region": "consumer_care_fssai",
    "dietary_symbol": DIETARY_CLASS,
    "dietary_symbol_region": DIETARY_CLASS,
    "veg_nonveg_region": DIETARY_CLASS,
}

#: Used to score OCR candidates: text a parser accepts is worth more than text
#: with a high recognizer score that no parser can use.
_CLASS_PARSERS: dict[str, Callable[[str], dict[str, Any]]] = {
    "product_name": rule_engine.parse_product_name,
    "net_quantity": rule_engine.parse_net_quantity,
    "mrp_declaration": rule_engine.parse_mrp_declaration,
    "date_declarations": rule_engine.parse_date_declarations,
    "batch_number": rule_engine.parse_batch_number,
    "manufacturer_details": rule_engine.parse_manufacturer_details,
    "consumer_care_fssai": rule_engine.parse_consumer_care_fssai,
}

#: Candidate score weights. Confidence dominates, parse success breaks ties, and
#: a small length term prefers the variant that recovered more of the panel.
_W_CONFIDENCE = 0.50
_W_PARSE = 0.35
_W_YIELD = 0.15
_YIELD_SATURATION = 40  # characters at which the length term maxes out

model: Any | None = None
_MODEL_LOCK = Lock()

__all__ = [
    "DIETARY_CLASS",
    "MODEL_PATH",
    "RULE_CLASSES",
    "ScanResult",
    "analyze_images",
    "decode_image",
    "detect_only",
    "process_image",
    "read_region",
    "warmup",
]


@dataclass
class ScanResult:
    """Everything one scan produced, for the report and the audit trail."""

    extracted: dict[str, str] = field(default_factory=dict)
    detections_by_image: list[list[dict[str, Any]]] = field(default_factory=list)
    dietary_status: str = "NOT_DETECTED"
    ocr_backend: str = ""
    detect_seconds: float = 0.0
    ocr_seconds: float = 0.0

    @property
    def detection_count(self) -> int:
        return sum(len(items) for items in self.detections_by_image)

    @property
    def classes_found(self) -> list[str]:
        return sorted({
            record["canonical_name"]
            for image in self.detections_by_image
            for record in image
            if record.get("canonical_name")
        })

    def summary(self) -> str:
        return (
            f"{self.detection_count} region(s) in {len(self.detections_by_image)} image(s); "
            f"detect {self.detect_seconds:.2f}s, ocr {self.ocr_seconds:.2f}s "
            f"via {self.ocr_backend or 'no backend'}"
        )


def _get_model() -> Any:
    global model
    if model is not None:
        return model
    with _MODEL_LOCK:
        if model is None:
            from ultralytics import YOLO  # late import keeps torch off the CLI path

            if not os.path.exists(MODEL_PATH):
                raise FileNotFoundError(
                    f"detector weights not found at {MODEL_PATH}. Train first "
                    f"(python train.py) or set LMPC_MODEL_PATH."
                )
            model = YOLO(MODEL_PATH)
    return model


def warmup() -> dict[str, Any]:
    """Load the detector and the OCR engine up front, e.g. on app start.

    Doing this lazily inside the first request is what made the prototype's first
    scan feel broken: EasyOCR alone took 15 s to initialise.
    """

    info: dict[str, Any] = {}
    try:
        _get_model()
        info["detector"] = MODEL_PATH
    except Exception as exc:
        info["detector_error"] = str(exc)
    try:
        backend = resolve_backend(OCR_BACKEND)
        backend.is_available()
        info["ocr"] = backend.describe()
    except Exception as exc:
        info["ocr_error"] = str(exc)
    return info


def _field(value: Any, name: str, default: Any = None) -> Any:
    if isinstance(value, dict):
        return value.get(name, default)
    return getattr(value, name, default)


def _read_image_bytes(image_bytes: Any) -> bytes:
    if hasattr(image_bytes, "read"):
        image_bytes = image_bytes.read()
    if not isinstance(image_bytes, (bytes, bytearray, memoryview)):
        raise TypeError("each image must be bytes-like")
    return bytes(image_bytes)


def _normalize_image_list(image_bytes_list: Any) -> list[bytes]:
    if isinstance(image_bytes_list, (bytes, bytearray, memoryview)) or hasattr(image_bytes_list, "read"):
        image_bytes_list = [image_bytes_list]
    if not isinstance(image_bytes_list, (list, tuple)):
        raise TypeError("image_bytes_list must be a list of image bytes")
    normalized = [_read_image_bytes(item) for item in image_bytes_list]
    if not normalized:
        raise ValueError("at least one image is required")
    return normalized


def decode_image(image_bytes: bytes) -> np.ndarray:
    """Bytes to BGR array, with a clear error instead of a ``None`` downstream."""

    encoded = np.frombuffer(_read_image_bytes(image_bytes), dtype=np.uint8)
    image = cv2.imdecode(encoded, cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError("The uploaded bytes are not a readable image")
    return image


def _class_name(names: Any, class_id: int) -> str:
    if isinstance(names, dict):
        return str(names.get(class_id, class_id))
    if isinstance(names, (list, tuple)) and 0 <= class_id < len(names):
        return str(names[class_id])
    return str(class_id)


def _new_record(raw_class_name: str, canonical: str | None, confidence: float,
                bbox: list[int]) -> dict[str, Any]:
    """The detection record shape ``debug_view`` and the report tabs rely on.

    The first seven keys are the original contract and must not be renamed; the
    rest are additive diagnostics.
    """

    return {
        "raw_class_name": raw_class_name,
        "canonical_name": canonical,
        "yolo_confidence": confidence,
        "bbox": bbox,
        "ocr_text": "",
        "ocr_confidence": None,
        "healed": False,
        "ocr_backend": None,
        "ocr_variant": None,
        "ocr_seconds": None,
        "parsed": None,
        "candidates": [],
    }


def detect_only(
    image: np.ndarray,
    conf: float = DETECT_CONF,
    iou: float = DETECT_IOU,
    imgsz: int = DETECT_IMGSZ,
) -> list[dict[str, Any]]:
    """Detect statutory regions without running OCR.

    This is the live-view path: the WebRTC overlay needs boxes at video rate and
    cannot afford OCR, which costs seconds per crop.
    """

    if image is None or getattr(image, "size", 0) == 0:
        return []
    height, width = image.shape[:2]
    predictions = _get_model().predict(image, conf=conf, iou=iou, imgsz=imgsz, verbose=False)

    records: list[dict[str, Any]] = []
    for prediction in predictions:
        names = _field(prediction, "names", {})
        boxes = _field(prediction, "boxes")
        if boxes is None:
            continue
        for box in boxes:
            try:
                class_id = int(box.cls[0].item())
                confidence = float(box.conf[0].item())
                x1, y1, x2, y2 = [int(value) for value in box.xyxy[0].tolist()]
            except (AttributeError, IndexError, TypeError, ValueError):
                continue
            raw_name = _class_name(names, class_id).strip().lower().replace(" ", "_")
            x1 = max(0, min(width, x1))
            y1 = max(0, min(height, y1))
            x2 = max(0, min(width, x2))
            y2 = max(0, min(height, y2))
            if x2 <= x1 or y2 <= y1:
                continue
            records.append(
                _new_record(raw_name, _CANONICAL_CLASS_MAP.get(raw_name), confidence, [x1, y1, x2, y2])
            )
    return records


#: Which parsed fields prove a reading is genuinely usable, per class. Full
#: ``is_compliant`` is too coarse to choose between variants - a date crop that
#: yields one readable date is clearly better than one that yields none, even
#: though neither satisfies Rule 6 on its own.
_PARSE_SIGNAL_FIELDS: dict[str, tuple[str, ...]] = {
    "net_quantity": ("value", "unit"),
    "mrp_declaration": ("mrp", "currency"),
    "date_declarations": ("manufacture_or_packaging_date", "expiry_or_use_by_date", "best_before_raw"),
    "batch_number": ("batch_number",),
    "manufacturer_details": ("pincode", "type"),
    "consumer_care_fssai": ("fssai_license_number", "helpline_number", "email"),
}


def _parse_signal(canonical_name: str, text: str) -> tuple[float, bool]:
    """How much usable structure a parser found in ``text``, and full compliance."""

    parser = _CLASS_PARSERS.get(canonical_name)
    if parser is None or not text:
        return 0.0, False
    try:
        parsed = parser(text)
    except Exception:  # a parser must never break a scan
        logger.debug("parser for %s raised on %r", canonical_name, text[:60], exc_info=True)
        return 0.0, False
    compliant = bool(parsed.get("is_compliant"))
    fields = _PARSE_SIGNAL_FIELDS.get(canonical_name)
    if not fields:
        return float(compliant), compliant
    present = sum(1 for name in fields if parsed.get(name) not in (None, "", []))
    return 0.6 * (present / len(fields)) + 0.4 * float(compliant), compliant


def _candidate_score(result: OcrResult, canonical_name: str) -> tuple[float, bool]:
    """Blend recognizer confidence, parsed structure and recovered length."""

    text = result.text
    if not text:
        return 0.0, False
    confidence = result.confidence or 0.0
    signal, compliant = _parse_signal(canonical_name, text)
    yield_term = min(1.0, len(text) / _YIELD_SATURATION)
    score = _W_CONFIDENCE * confidence + _W_PARSE * signal + _W_YIELD * yield_term
    return score, compliant


def read_region(
    crop: np.ndarray,
    canonical_name: str,
    backend: Any | None = None,
    stop_score: float = 0.92,
) -> dict[str, Any]:
    """OCR one crop across its prepared variants and return the best reading.

    Variants are tried cheapest-first and the loop stops early once a reading is
    confident *and* parseable, so the extra variants only cost time on the
    regions that actually need them.
    """

    engine = backend if backend is not None else resolve_backend(OCR_BACKEND)
    variants = preprocessing.build_variants(crop, canonical_name)
    best: dict[str, Any] = {
        "text": "",
        "confidence": None,
        "variant": None,
        "parsed": False,
        "score": 0.0,
        "seconds": 0.0,
        "backend": engine.name,
        "candidates": [],
    }
    for variant in variants:
        result = engine.read(variant.image, allowlist=variant.allowlist)
        best["seconds"] += result.elapsed_s
        score, parsed = _candidate_score(result, canonical_name)
        best["candidates"].append(
            {
                "variant": variant.name,
                "text": result.text,
                "confidence": result.confidence,
                "lines": result.line_count,
                "parsed": parsed,
                "score": round(score, 4),
                "seconds": round(result.elapsed_s, 3),
                "error": result.error,
            }
        )
        if score > best["score"]:
            best.update(
                {
                    "text": result.text,
                    "confidence": result.confidence,
                    "variant": variant.name,
                    "parsed": parsed,
                    "score": score,
                }
            )
        if parsed and (result.confidence or 0.0) >= stop_score:
            break
    return best


def _pad_box(bbox: Sequence[int], width: int, height: int, margin: float = 0.04) -> tuple[int, int, int, int]:
    """Grow the crop slightly: a tight box often clips ascenders and the last glyph."""

    x1, y1, x2, y2 = bbox
    pad_x = int(round((x2 - x1) * margin)) + 2
    pad_y = int(round((y2 - y1) * margin)) + 2
    return (
        max(0, x1 - pad_x),
        max(0, y1 - pad_y),
        min(width, x2 + pad_x),
        min(height, y2 + pad_y),
    )


def analyze_image(
    image: np.ndarray,
    extracted: dict[str, list[str]],
    detections: list[dict[str, Any]],
    backend: Any | None = None,
) -> str:
    """Detect, read and record every region in one image; returns dietary status."""

    engine = backend if backend is not None else resolve_backend(OCR_BACKEND)
    height, width = image.shape[:2]
    dietary_status = "NOT_DETECTED"

    for record in detect_only(image):
        canonical = record["canonical_name"]
        if canonical is None:
            detections.append(record)
            continue

        x1, y1, x2, y2 = _pad_box(record["bbox"], width, height)
        crop = image[y1:y2, x1:x2]
        if crop.size == 0:
            detections.append(record)
            continue

        if canonical == DIETARY_CLASS:
            symbol = preprocessing.classify_dietary_symbol(crop)
            record["ocr_text"] = symbol
            record["parsed"] = symbol != "UNCERTAIN"
            if symbol != "UNCERTAIN" and dietary_status == "NOT_DETECTED":
                dietary_status = symbol
            detections.append(record)
            continue

        reading = read_region(crop, canonical, engine)
        record.update(
            {
                "ocr_text": reading["text"],
                "ocr_confidence": reading["confidence"],
                "healed": reading["variant"] == "dotmatrix",
                "ocr_backend": reading["backend"],
                "ocr_variant": reading["variant"],
                "ocr_seconds": round(reading["seconds"], 3),
                "parsed": reading["parsed"],
                "candidates": reading["candidates"],
            }
        )
        detections.append(record)
        if reading["text"]:
            extracted.setdefault(canonical, []).append(reading["text"])
    return dietary_status


def analyze_images(image_bytes_list: Any, backend_name: str | None = None) -> ScanResult:
    """Full scan over one or more photographs of the same pack.

    Multiple images are treated as different faces of the same commodity, so
    their readings are concatenated per class before the rule engine sees them.
    """

    images = _normalize_image_list(image_bytes_list)
    engine = resolve_backend(backend_name or OCR_BACKEND)

    extracted: dict[str, list[str]] = {name: [] for name in RULE_CLASSES}
    result = ScanResult(ocr_backend=engine.name)
    dietary = "NOT_DETECTED"

    for image_bytes in images:
        image = decode_image(image_bytes)
        per_image: list[dict[str, Any]] = []
        started = time.perf_counter()
        status = analyze_image(image, extracted, per_image, engine)
        elapsed = time.perf_counter() - started
        ocr_time = sum(record.get("ocr_seconds") or 0.0 for record in per_image)
        result.ocr_seconds += ocr_time
        result.detect_seconds += max(0.0, elapsed - ocr_time)
        result.detections_by_image.append(per_image)
        if dietary == "NOT_DETECTED":
            dietary = status

    result.dietary_status = dietary
    result.extracted = {
        name: " ".join(text for text in extracted.get(name, []) if text).strip()
        for name in RULE_CLASSES
    }
    logger.info("scan complete: %s", result.summary())
    return result


def process_image(
    image_bytes_list: list[bytes],
) -> tuple[dict[str, str], list[list[dict[str, Any]]]]:
    """Backwards-compatible entry point: ``(aggregated_text, detections_by_image)``.

    Prefer ``analyze_images``, which also reports the dietary mark and timings.
    """

    scan = analyze_images(image_bytes_list)
    return scan.extracted, scan.detections_by_image








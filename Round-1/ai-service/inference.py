"""Legacy ONNX detection helper.

The deployed FastAPI path is ``main.py`` and uses the trained YOLOv8
classification model. This module is retained for offline experiments and
must not be treated as a second production inference contract.
"""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Any

import numpy as np
from PIL import Image

try:
    from .class_map import CIVIC_CLASSES
except ImportError:
    from class_map import CIVIC_CLASSES

logger = logging.getLogger(__name__)

MODEL_PATH = Path(__file__).resolve().parent / "models" / "yolov8n-civic.onnx"
CONFIDENCE_THRESHOLD = 0.45
IMAGE_SIZE = (640, 640)

_model: Any | None = None
_model_backend: str | None = None


def _load_model() -> None:
    """Load ONNX once, with ultralytics as the documented fallback."""

    global _model, _model_backend

    if MODEL_PATH.is_file():
        try:
            import onnxruntime as ort

            try:
                _model = ort.InferenceSession(
                    str(MODEL_PATH),
                    providers=["CUDAExecutionProvider", "CPUExecutionProvider"],
                )
            except Exception as provider_error:
                logger.warning(
                    "Could not initialize ONNX with the preferred providers; "
                    "retrying with CPUExecutionProvider: %s",
                    provider_error,
                )
                _model = ort.InferenceSession(
                    str(MODEL_PATH),
                    providers=["CPUExecutionProvider"],
                )
            _model_backend = "onnx"
            logger.info("Loaded civic ONNX model from %s", MODEL_PATH)
            return
        except Exception as error:
            logger.warning("Could not load civic ONNX model from %s: %s", MODEL_PATH, error)
    else:
        logger.warning(
            "Civic ONNX model not found at %s; activating ultralytics YOLOv8n fallback",
            MODEL_PATH,
        )

    try:
        from ultralytics import YOLO

        _model = YOLO("yolov8n.pt")
        _model_backend = "ultralytics"
        logger.warning("Ultralytics YOLOv8n pretrained fallback is active")
    except Exception as error:
        _model = None
        _model_backend = None
        logger.warning("Ultralytics fallback could not be loaded: %s", error)


def is_model_loaded() -> bool:
    """Return whether a usable inference backend was loaded at startup."""

    return _model is not None and _model_backend is not None


def compute_severity(class_id: int, bbox: list[float], img_size: tuple[int, int]) -> int:
    """Compute a bounded civic severity score from class and bounding-box area."""

    base = CIVIC_CLASSES[class_id]["severity_base"]
    bbox_area = (bbox[2] - bbox[0]) * (bbox[3] - bbox[1])
    img_area = img_size[0] * img_size[1]
    ratio = bbox_area / img_area if img_area > 0 else 0.0
    if ratio > 0.35:
        base = min(5, base + 1)
    if ratio < 0.05:
        base = max(1, base - 1)
    return base


def _fallback_result() -> dict:
    return {
        "category": "unknown",
        "severity": 1,
        "confidence": 0.0,
        "bbox": [],
        "raw_detections": [],
    }


def _as_bbox(values: Any) -> list[float]:
    """Convert a model box to the API's xyxy coordinate list."""

    return [round(float(value), 2) for value in values[:4]]


def _class_name(class_id: int) -> str:
    return CIVIC_CLASSES.get(class_id, {}).get("name", "unknown")


def _detections_from_rows(rows: np.ndarray) -> list[dict]:
    """Parse NMS rows in [x1, y1, x2, y2, confidence, class_id] form."""

    if rows.size == 0:
        return []

    rows = np.asarray(rows, dtype=np.float32)
    rows = np.squeeze(rows)
    if rows.ndim == 1:
        rows = rows.reshape(1, -1)
    if rows.ndim != 2:
        return []

    # Some exports use [attributes, detections] rather than [detections, attributes].
    if rows.shape[0] <= 16 and rows.shape[1] > rows.shape[0]:
        rows = rows.T

    detections = []
    for row in rows:
        if row.size < 6:
            continue
        confidence = float(row[4])
        if confidence < CONFIDENCE_THRESHOLD:
            continue
        class_id = int(row[5])
        bbox = _as_bbox(row[:4])
        detections.append(
            {
                "class": _class_name(class_id),
                "confidence": round(confidence, 3),
                "bbox": bbox,
                "class_id": class_id,
            }
        )
    return detections


def _detections_from_onnx_outputs(outputs: list[Any]) -> list[dict]:
    """Handle common ONNX NMS exports while keeping the required row format first."""

    if not outputs:
        return []

    # ONNX NonMaxSuppression-style exports commonly return boxes, scores, and IDs
    # as separate tensors. Support that shape in addition to a single row tensor.
    if len(outputs) >= 3:
        boxes = np.asarray(outputs[0])
        scores = np.asarray(outputs[1])
        class_ids = np.asarray(outputs[2])
        if boxes.size and boxes.shape[-1:] == (4,) and scores.size and class_ids.size:
            boxes = boxes.reshape(-1, 4)
            scores = scores.reshape(-1)
            class_ids = class_ids.reshape(-1)
            rows = np.column_stack((boxes, scores, class_ids))
            return _detections_from_rows(rows)

    return _detections_from_rows(np.asarray(outputs[0]))


def _run_onnx(image: Image.Image) -> list[dict]:
    input_image = image.resize(IMAGE_SIZE, Image.Resampling.LANCZOS)
    input_array = np.asarray(input_image, dtype=np.float32) / 255.0
    input_array = np.transpose(input_array, (2, 0, 1))[None, ...]
    input_name = _model.get_inputs()[0].name
    outputs = _model.run(None, {input_name: input_array})
    return _detections_from_onnx_outputs(outputs)


def _run_ultralytics(image_path: str) -> list[dict]:
    results = _model(image_path, imgsz=640, verbose=False)
    detections = []
    for result in results:
        boxes = getattr(result, "boxes", None)
        if boxes is None:
            continue
        xyxy = boxes.xyxy.detach().cpu().numpy()
        confidences = boxes.conf.detach().cpu().numpy()
        class_ids = boxes.cls.detach().cpu().numpy().astype(int)
        for bbox, confidence, class_id in zip(xyxy, confidences, class_ids):
            confidence = float(confidence)
            if confidence < CONFIDENCE_THRESHOLD:
                continue
            detections.append(
                {
                    "class": _class_name(int(class_id)),
                    "confidence": round(confidence, 3),
                    "bbox": _as_bbox(bbox),
                    "class_id": int(class_id),
                }
            )
    return detections


def run_inference(image_path: str) -> dict:
    """Run warm-model inference and return the API-ready detection fields."""

    if not is_model_loaded():
        return _fallback_result()

    try:
        with Image.open(image_path) as source_image:
            image = source_image.convert("RGB")
            image_size = IMAGE_SIZE
            if _model_backend == "onnx":
                detections = _run_onnx(image)
            else:
                detections = _run_ultralytics(image_path)

        public_detections = [
            {
                "class": detection["class"],
                "confidence": detection["confidence"],
                "bbox": detection["bbox"],
            }
            for detection in detections
        ]
        if not detections:
            return _fallback_result()

        primary = max(detections, key=lambda detection: detection["confidence"])
        class_id = primary["class_id"]
        if class_id not in CIVIC_CLASSES:
            return {
                "category": "unknown",
                "severity": 1,
                "confidence": primary["confidence"],
                "bbox": primary["bbox"],
                "raw_detections": public_detections,
            }

        return {
            "category": primary["class"],
            "severity": compute_severity(class_id, primary["bbox"], image_size),
            "confidence": primary["confidence"],
            "bbox": primary["bbox"],
            "raw_detections": public_detections,
        }
    except Exception:
        logger.exception("Model inference failed for %s; returning unknown fallback", image_path)
        return _fallback_result()


_load_model()

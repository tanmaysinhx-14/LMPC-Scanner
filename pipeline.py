from __future__ import annotations

import logging
from threading import Lock
from typing import Any

import cv2
import numpy as np
from ultralytics import YOLO
from preprocessing import heal_dot_matrix_text


MODEL_PATH = "runs/detect/train/weights/best.pt"
RULE_CLASSES = (
    "product_name",
    "net_quantity",
    "mrp_declaration",
    "date_declarations",
    "batch_number",
    "manufacturer_details",
    "consumer_care_fssai",
)
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
}
model: Any | None = None
_MODEL_LOCK = Lock()
_OCR_LOCK = Lock()
_DOT_MATRIX_CLASSES = {"mrp_declaration", "date_declarations", "batch_number"}
logger = logging.getLogger(__name__)

try:
    import easyocr
except Exception as exc:
    easyocr = None
    ocr_reader = None
    _OCR_INIT_ERROR = exc
else:
    try:
        ocr_reader = easyocr.Reader(['en'], gpu=False)
        _OCR_INIT_ERROR = None
    except Exception as exc:
        ocr_reader = None
        _OCR_INIT_ERROR = exc


def _get_model() -> Any:
    global model
    if model is not None:
        return model
    with _MODEL_LOCK:
        if model is None:
            model = YOLO(MODEL_PATH)
    return model


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
    normalized = [_read_image_bytes(image_bytes) for image_bytes in image_bytes_list]
    if not normalized:
        raise ValueError("at least one image is required")
    return normalized


def _process_single_image(
    image_bytes: bytes,
    extracted: dict[str, list[str]],
    detections: list[dict[str, Any]],
) -> None:
    encoded = np.frombuffer(image_bytes, dtype=np.uint8)
    image = cv2.imdecode(encoded, cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError("The uploaded bytes are not a readable image")

    predictions = _get_model().predict(image, verbose=False)

    for prediction in predictions:
        names = _field(prediction, "names", {})
        boxes = _field(prediction, "boxes")
        if boxes is None:
            continue

        height, width = image.shape[:2]
        for box in boxes:
            try:
                class_id = int(box.cls[0].item())
                confidence = float(box.conf[0].item())
                x1, y1, x2, y2 = [int(value) for value in box.xyxy[0].tolist()]
            except (AttributeError, IndexError, TypeError, ValueError):
                continue

            if isinstance(names, dict):
                class_name = str(names.get(class_id, class_id))
            elif isinstance(names, (list, tuple)) and 0 <= class_id < len(names):
                class_name = str(names[class_id])
            else:
                class_name = str(class_id)
            normalized_class_name = class_name.strip().lower().replace(" ", "_")
            canonical_name = _CANONICAL_CLASS_MAP.get(normalized_class_name)

            x1 = max(0, min(width, x1))
            y1 = max(0, min(height, y1))
            x2 = max(0, min(width, x2))
            y2 = max(0, min(height, y2))
            if x2 <= x1 or y2 <= y1:
                continue

            record: dict[str, Any] = {
                "raw_class_name": normalized_class_name,
                "canonical_name": canonical_name,
                "yolo_confidence": confidence,
                "bbox": [x1, y1, x2, y2],
                "ocr_text": "",
                "ocr_confidence": None,
                "healed": False,
            }

            if canonical_name is None:
                detections.append(record)
                continue

            crop = image[y1:y2, x1:x2]
            crop = cv2.resize(crop, None, fx=3, fy=3, interpolation=cv2.INTER_CUBIC)
            with _OCR_LOCK:
                raw_result = ocr_reader.readtext(crop, detail=1)
                raw_text = " ".join(item[1].strip() for item in raw_result if item[1].strip())
                raw_conf = (
                    sum(item[2] for item in raw_result) / len(raw_result)
                    if raw_result else None
                )
                if canonical_name in _DOT_MATRIX_CLASSES:
                    healed_crop = heal_dot_matrix_text(crop)
                    healed_result = ocr_reader.readtext(healed_crop, detail=1)
                    healed_text = " ".join(item[1].strip() for item in healed_result if item[1].strip())
                    healed_conf = (
                        sum(item[2] for item in healed_result) / len(healed_result)
                        if healed_result else None
                    )
                    text = healed_text or raw_text
                    ocr_confidence = healed_conf if healed_text else raw_conf
                    record["healed"] = bool(healed_text)
                    if canonical_name == "mrp_declaration":
                        logger.info(
                            "mrp_region OCR before=%r after=%r",
                            raw_text,
                            healed_text,
                        )
                else:
                    text = raw_text
                    ocr_confidence = raw_conf
            record["ocr_text"] = text
            record["ocr_confidence"] = ocr_confidence
            detections.append(record)
            extracted.setdefault(canonical_name, []).append(text)


def process_image(
    image_bytes_list: list[bytes],
) -> tuple[dict[str, str], list[list[dict[str, Any]]]]:
    images = _normalize_image_list(image_bytes_list)
    if ocr_reader is None:
        raise RuntimeError("EasyOCR could not be initialized") from _OCR_INIT_ERROR

    extracted: dict[str, list[str]] = {class_name: [] for class_name in RULE_CLASSES}
    detections_by_image: list[list[dict[str, Any]]] = []
    for image_bytes in images:
        image_detections: list[dict[str, Any]] = []
        _process_single_image(image_bytes, extracted, image_detections)
        detections_by_image.append(image_detections)

    aggregated = {
        class_name: " ".join(text for text in extracted.get(class_name, []) if text).strip()
        for class_name in RULE_CLASSES
    }
    return aggregated, detections_by_image

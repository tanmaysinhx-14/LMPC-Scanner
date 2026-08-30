from __future__ import annotations

from typing import Any

import cv2
import numpy as np


_UNMAPPED = (150, 150, 150)  # gray, BGR - detected but not wired to any report field
_HIGH = (60, 180, 75)  # green, BGR - yolo_confidence >= 0.7
_MEDIUM = (0, 165, 255)  # orange, BGR - 0.4 <= yolo_confidence < 0.7
_LOW = (40, 40, 220)  # red, BGR - yolo_confidence < 0.4


def _tier_color(canonical_name: str | None, yolo_confidence: float) -> tuple[int, int, int]:
    if canonical_name is None:
        return _UNMAPPED
    if yolo_confidence >= 0.7:
        return _HIGH
    if yolo_confidence >= 0.4:
        return _MEDIUM
    return _LOW


def draw_on_array(
    image: np.ndarray,
    detections: list[dict[str, Any]],
    show_ocr: bool = True,
    font_scale: float = 0.5,
) -> np.ndarray:
    """Draw every detection onto a BGR array in place and return it.

    ``show_ocr=False`` is the live-video case: ``pipeline.detect_only`` runs no
    OCR there, so an "ocr n/a" suffix on every box would be noise.
    """

    for detection in detections:
        x1, y1, x2, y2 = detection["bbox"]
        canonical_name = detection["canonical_name"]
        yolo_conf = detection["yolo_confidence"]
        label_class = canonical_name or f"UNMAPPED:{detection['raw_class_name']}"

        color = _tier_color(canonical_name, yolo_conf)
        cv2.rectangle(image, (x1, y1), (x2, y2), color, 2)

        label = f"{label_class} | yolo {yolo_conf:.2f}"
        if show_ocr:
            ocr_conf = detection.get("ocr_confidence")
            ocr_txt = f"{ocr_conf:.2f}" if ocr_conf is not None else "n/a"
            healed_tag = " (healed)" if detection.get("healed") else ""
            label = f"{label} | ocr {ocr_txt}{healed_tag}"

        (text_w, text_h), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, font_scale, 1)
        label_y = max(y1 - 6, text_h + 4)
        cv2.rectangle(image, (x1, label_y - text_h - 4), (x1 + text_w + 4, label_y + 2), color, -1)
        cv2.putText(
            image,
            label,
            (x1 + 2, label_y),
            cv2.FONT_HERSHEY_SIMPLEX,
            font_scale,
            (255, 255, 255),
            1,
            cv2.LINE_AA,
        )
    return image


def draw_detections(image_bytes: bytes, detections: list[dict[str, Any]]) -> np.ndarray:
    """Return an RGB array with every detection box, class, and confidence drawn on it."""
    encoded = np.frombuffer(image_bytes, dtype=np.uint8)
    image = cv2.imdecode(encoded, cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError("The uploaded bytes are not a readable image")

    draw_on_array(image, detections, show_ocr=True)
    return cv2.cvtColor(image, cv2.COLOR_BGR2RGB)

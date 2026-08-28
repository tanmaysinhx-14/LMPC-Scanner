# YOLO/OCR Detection Debug View — Implementation Spec

**Goal:** after "Analyze package" runs, show every YOLO detection drawn on the actual uploaded image — colored by YOLO confidence, labeled with both YOLO confidence and OCR confidence. This turns "the model gives absurd responses" into a visual, at-a-glance answer to "is this a detection problem or an OCR problem."

This is written against the real `pipeline.py` and `app.py` in the uploaded repo. Function names, variable names, and line references match that code, so this can go to Codex close to verbatim.

## Why this doesn't exist yet

`pipeline.process_image()` currently returns only `dict[str, str]` — aggregated OCR text per canonical field. Nothing about box location, assigned class, or either confidence score survives past `_process_single_image`. There is nothing downstream to draw. The change below adds that data without changing what the rule engine consumes.

---

## Change 1 — `pipeline.py`: carry detections out of the pipeline

Replace `_process_single_image` and `process_image` with:

```python
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
                "canonical_name": canonical_name,   # None if unmapped, e.g. dietary_symbol today
                "yolo_confidence": confidence,
                "bbox": [x1, y1, x2, y2],
                "ocr_text": "",
                "ocr_confidence": None,
                "healed": False,
            }

            if canonical_name is None:
                detections.append(record)
                continue  # unchanged behavior: not OCR'd, not sent to the rule engine

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
                        logger.info("mrp_region OCR before=%r after=%r", raw_text, healed_text)
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
```

Notes:
- `confidence = float(box.conf[0].item())` is new — `box.conf` was never read before.
- Detections with `canonical_name is None` are recorded and then skipped exactly like today's `continue` — rule-engine behavior is unchanged, this only adds visibility.
- `ocr_confidence` is the mean of the per-line confidences EasyOCR already returns from `detail=1`. If the healed pass produced text, its confidence is used; otherwise the raw pass's, matching the existing `text = healed_text or raw_text` fallback.
- **This changes `process_image`'s return type** from `dict[str, str]` to `tuple[dict[str, str], list[list[dict]]]`. That is the only breaking change in this spec — see Change 3a for the one call site that needs updating.

---

## Change 2 — new file `debug_view.py`: draw the overlay

```python
from __future__ import annotations

from typing import Any

import cv2
import numpy as np

_UNMAPPED = (150, 150, 150)   # gray, BGR — detected but not wired to any report field
_HIGH = (60, 180, 75)          # green, BGR — yolo_confidence >= 0.7
_MEDIUM = (0, 165, 255)        # orange, BGR — 0.4 <= yolo_confidence < 0.7
_LOW = (40, 40, 220)           # red, BGR — yolo_confidence < 0.4


def _tier_color(canonical_name: str | None, yolo_confidence: float) -> tuple[int, int, int]:
    if canonical_name is None:
        return _UNMAPPED
    if yolo_confidence >= 0.7:
        return _HIGH
    if yolo_confidence >= 0.4:
        return _MEDIUM
    return _LOW


def draw_detections(image_bytes: bytes, detections: list[dict[str, Any]]) -> np.ndarray:
    """Return an RGB array with every detection box, class, and confidence drawn on it."""
    encoded = np.frombuffer(image_bytes, dtype=np.uint8)
    image = cv2.imdecode(encoded, cv2.IMREAD_COLOR)  # BGR

    for detection in detections:
        x1, y1, x2, y2 = detection["bbox"]
        canonical_name = detection["canonical_name"]
        yolo_conf = detection["yolo_confidence"]
        ocr_conf = detection.get("ocr_confidence")
        label_class = canonical_name or f"UNMAPPED:{detection['raw_class_name']}"
        healed_tag = " (healed)" if detection.get("healed") else ""

        color = _tier_color(canonical_name, yolo_conf)
        cv2.rectangle(image, (x1, y1), (x2, y2), color, 2)

        ocr_txt = f"{ocr_conf:.2f}" if ocr_conf is not None else "n/a"
        label = f"{label_class} | yolo {yolo_conf:.2f} | ocr {ocr_txt}{healed_tag}"
        (text_w, text_h), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, 0.5, 1)
        label_y = max(y1 - 6, text_h + 4)
        cv2.rectangle(image, (x1, label_y - text_h - 4), (x1 + text_w + 4, label_y + 2), color, -1)
        cv2.putText(image, label, (x1 + 2, label_y), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 1, cv2.LINE_AA)

    return cv2.cvtColor(image, cv2.COLOR_BGR2RGB)  # Streamlit expects RGB, OpenCV works in BGR
```

The last line matters: skip that conversion and every box renders with blue and red swapped — easy to not notice at a glance, so it's called out explicitly here rather than left implicit.

---

## Change 3 — `app.py`: wire it into the existing Analyze flow

All edits are inside `_render_inspector()`. Add `import debug_view` near the top of the file, alongside `import pipeline`.

**a) Update the call (currently lines 742–743):**

```python
# before
extracted_data = pipeline.process_image(image_bytes_list)
results = validate_compliance(extracted_data)

# after
extracted_data, detections_by_image = pipeline.process_image(image_bytes_list)
results = validate_compliance(extracted_data)
```

**b) Store detections in `current_scan`** (the dict literal currently at lines 748–757) — add one key:

```python
"detections_by_image": detections_by_image,
```

**c) Add a tab and a render function.** Current tabs (line 772):

```python
tabs = st.tabs(["Compliance checklist", "Raw OCR", "Evidence and reports"])
with tabs[0]:
    _render_scan_results(current_scan["results"])
with tabs[1]:
    ...  # Raw OCR dataframe, unchanged
with tabs[2]:
    ...  # Evidence and reports, unchanged
```

Becomes:

```python
tabs = st.tabs(["Detection preview", "Compliance checklist", "Raw OCR", "Evidence and reports"])
with tabs[0]:
    _render_detection_preview(current_scan)
with tabs[1]:
    _render_scan_results(current_scan["results"])
with tabs[2]:
    ...  # Raw OCR dataframe, unchanged, just shifted from tabs[1] to tabs[2]
with tabs[3]:
    ...  # Evidence and reports, unchanged, just shifted from tabs[2] to tabs[3]
```

New function, placed near `_render_evidence`:

```python
def _render_detection_preview(scan: dict[str, Any]) -> None:
    detections_by_image = scan.get("detections_by_image") or []
    image_bytes_list = scan.get("image_bytes_list") or []
    image_names = scan.get("image_names") or []
    if not detections_by_image:
        st.info("No detections recorded for this scan.")
        return
    st.caption(
        "Box color = YOLO confidence (green high, orange medium, red low). "
        "Gray = detected but not mapped to a report field yet (e.g. dietary_symbol today). "
        "Label shows YOLO confidence and OCR confidence for that crop."
    )
    for index, (image_bytes, detections) in enumerate(zip(image_bytes_list, detections_by_image)):
        name = image_names[index] if index < len(image_names) else f"Panel {index + 1}"
        annotated = debug_view.draw_detections(image_bytes, detections)
        st.image(annotated, caption=f"{name} — {len(detections)} detection(s)", use_container_width=True)
```

That covers every call site — `pipeline.process_image` is called from exactly one place in the current codebase (line 742), so there is nothing else to update.

---

## Reading the result

- Box missing, misplaced, or wrong class → **detection problem**. Look at data/augmentation, not OCR.
- Box is tight, correctly placed, green — but the OCR text label is garbled → **genuine OCR/recognition problem** on that class or condition.
- Box is green and OCR confidence is also high, but the text is still wrong → worth a closer look, but only after the first two are ruled out.
- Gray box → a real detection that currently goes nowhere. This will surface every `dietary_symbol` hit today, and would surface `batch_number` too once that class exists in the dataset.

## Scope boundaries — please don't build past this right now

- This is a **post-analysis view**, not a live pre-OCR preview. It renders after "Analyze package" finishes, reusing the existing pipeline call — not a separate, faster YOLO-only pass. An instant live preview would mean running YOLO twice per image or restructuring the pipeline; that's real additional work and isn't needed to answer "detection or OCR."
- Don't touch `rule_engine.py`, `db.py`, or `reporting.py` for this. The shape of `extracted_data` and everything downstream of it is unchanged.
- The 0.7/0.4 confidence thresholds in `debug_view.py` are reasonable starting points, not tuned values — expect to eyeball real output and adjust.

---

## Separately, worth starting today (not part of this tool)

`runs/detect/train/args.yaml` shows `hsv_h: 0.0`, `hsv_s: 0.0`, `hsv_v: 0.0` — all color/brightness augmentation was off for the run that produced `best.pt`. Given the glare complaint, this is a strong candidate to fix before the next retrain: something like `hsv_v: 0.4`, `hsv_s: 0.7`, `hsv_h: 0.015` are reasonable values to try. No new data or code needed — just different `train.py` args and a retrain, which can run in the background while the debug view above gets built.

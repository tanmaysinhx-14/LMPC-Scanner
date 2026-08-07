from __future__ import annotations

import io
from pathlib import Path
from typing import Optional

import cv2
import numpy as np
import onnxruntime as ort
from fastapi import FastAPI, HTTPException
from PIL import Image
from pydantic import BaseModel

# ── Paths ─────────────────────────────────────────────────────────────────────
# uvicorn is run from inside ai-service/, so parent = project root
PROJECT_ROOT = Path(__file__).parent.parent
MODEL_PATH   = Path(__file__).parent / "model" / "best_int8.onnx"

# ── Constants ─────────────────────────────────────────────────────────────────
IMG_SIZE       = 640
CONF_THRESHOLD = 0.30
IOU_THRESHOLD  = 0.45
ELA_QUALITY    = 90
ELA_THRESHOLD  = 12.0

# Must match the `names:` order produced by merge_datasets.py
CLASS_NAMES = ["pothole", "waterlogging", "garbage", "fallen_tree", "graffiti"]

# ── App Init ──────────────────────────────────────────────────────────────────
app = FastAPI(title="CivicConnect AI")
_session: Optional[ort.InferenceSession] = None


@app.on_event("startup")
def load_model() -> None:
    global _session
    _session = ort.InferenceSession(
        str(MODEL_PATH),
        providers=["CUDAExecutionProvider", "CPUExecutionProvider"],
    )


# ── Schema ────────────────────────────────────────────────────────────────────
class AnalyzeRequest(BaseModel):
    filepath: str  # relative to project root, e.g. "uploads/2025/01/15/abc.jpg"


# ── Preprocessing ─────────────────────────────────────────────────────────────
def preprocess(path: str) -> np.ndarray:
    img = cv2.imread(path)
    if img is None:
        raise ValueError(f"Cannot read: {path}")
    img = cv2.cvtColor(img, cv2.COLOR_BGR2RGB)
    img = cv2.resize(img, (IMG_SIZE, IMG_SIZE))
    img = img.astype(np.float32) / 255.0
    img = np.transpose(img, (2, 0, 1))       # HWC → CHW
    return np.expand_dims(img, axis=0)        # → [1, 3, 640, 640]


# ── Postprocess + NMS ─────────────────────────────────────────────────────────
def postprocess(raw: np.ndarray) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    """
    raw: [1, 4+nc, 8400]   (YOLOv8 ONNX output before NMS)
    Returns: boxes_xyxy [N,4], confidences [N], class_ids [N]
    """
    pred = raw[0].T                              # [8400, 4+nc]
    class_scores = pred[:, 4:]
    class_ids    = np.argmax(class_scores, axis=1)
    confidences  = class_scores[np.arange(len(class_ids)), class_ids]

    keep = confidences >= CONF_THRESHOLD
    pred_f = pred[keep]
    conf_f = confidences[keep]
    cid_f  = class_ids[keep]

    if len(pred_f) == 0:
        empty = np.array([])
        return empty, empty, empty

    cx, cy, w, h = pred_f[:, 0], pred_f[:, 1], pred_f[:, 2], pred_f[:, 3]
    boxes = np.stack([cx - w/2, cy - h/2, cx + w/2, cy + h/2], axis=1)

    indices = cv2.dnn.NMSBoxes(boxes.tolist(), conf_f.tolist(), CONF_THRESHOLD, IOU_THRESHOLD)
    if len(indices) == 0:
        empty = np.array([])
        return empty, empty, empty

    idx = np.array(indices).flatten()
    return boxes[idx], conf_f[idx], cid_f[idx]


# ── Severity Scoring ──────────────────────────────────────────────────────────
def compute_severity(boxes: np.ndarray, confidences: np.ndarray) -> int:
    """
    1–5 score based on:
      50% total bbox area coverage of image
      30% average detection confidence
      20% detection count (capped at 5)
    """
    if len(boxes) == 0:
        return 1

    areas       = ((boxes[:, 2] - boxes[:, 0]) * (boxes[:, 3] - boxes[:, 1])) / (IMG_SIZE ** 2)
    total_area  = float(np.clip(areas.sum(), 0.0, 1.0))
    avg_conf    = float(np.mean(confidences))
    count_score = float(np.clip(len(boxes) / 5.0, 0.0, 1.0))

    raw = 0.5 * total_area + 0.3 * avg_conf + 0.2 * count_score
    return max(1, min(5, round(1 + raw * 4)))


# ── ELA Manipulation Detection ────────────────────────────────────────────────
def ela_check(path: str) -> bool:
    """
    Re-saves image at known JPEG quality, computes pixel-level diff.
    Edited regions retain higher error than organically compressed ones.
    """
    try:
        orig = Image.open(path).convert("RGB")
        buf  = io.BytesIO()
        orig.save(buf, format="JPEG", quality=ELA_QUALITY)
        buf.seek(0)
        recomp   = Image.open(buf).convert("RGB")
        ela_map  = np.abs(np.array(orig, np.float32) - np.array(recomp, np.float32))
        return float(ela_map.mean()) > ELA_THRESHOLD
    except Exception:
        return False


# ── Endpoint ──────────────────────────────────────────────────────────────────
@app.post("/analyze")
def analyze(req: AnalyzeRequest):
    abs_path = PROJECT_ROOT / req.filepath

    if not abs_path.is_file():
        raise HTTPException(status_code=404, detail="Image not found on disk.")
    if _session is None:
        raise HTTPException(status_code=503, detail="Model not loaded.")

    try:
        is_manipulated = ela_check(str(abs_path))
        tensor         = preprocess(str(abs_path))
        raw_out        = _session.run(None, {_session.get_inputs()[0].name: tensor})
        boxes, confs, cids = postprocess(raw_out[0])

        if len(boxes) == 0:
            return {"success": True, "category": "unknown", "severity": 1,
                    "confidence": 0.0, "is_manipulated": is_manipulated}

        top = int(np.argmax(confs))
        return {
            "success":        True,
            "category":       CLASS_NAMES[int(cids[top])],
            "severity":       compute_severity(boxes, confs),
            "confidence":     round(float(confs[top]), 4),
            "is_manipulated": is_manipulated,
        }

    except Exception:
        return {"success": False, "category": "unknown", "severity": 1,
                "confidence": 0.0, "is_manipulated": False}
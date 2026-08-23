"""FastAPI service for CivicConnect object detection."""

from __future__ import annotations

import base64
from contextlib import asynccontextmanager
from io import BytesIO
import hmac
import math
import os
from pathlib import Path
from threading import Lock
from typing import Optional, Union

import torch
from fastapi import FastAPI, Header, HTTPException
from PIL import Image, ImageDraw, ImageFont
from pydantic import BaseModel, Field
from ultralytics import YOLO

try:
    from .image_check import check_manipulation
except ImportError:
    from image_check import check_manipulation


# This file is in <project-root>/ai-service/, so the parent directory is the
# project root used by the PHP backend for relative upload paths.
PROJECT_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_MODEL_PATH = PROJECT_ROOT / "civic-dataset" / "runs" / "detect" / "train" / "weights" / "best.pt"
DEPLOYMENT_MODEL_PATH = PROJECT_ROOT / "ai-service" / "models" / "best.pt"
MODEL_PATH = DEFAULT_MODEL_PATH
AI_SHARED_TOKEN = os.getenv("CIVICCONNECT_AI_TOKEN", "").strip()

# Detection models are trained at 640px in civic-dataset/train.py. Keeping the
# output preview bounded prevents a large phone photo from becoming a huge
# base64 response while preserving enough detail for a jury demo.
IMAGE_SIZE = 640
INFERENCE_BATCH_SIZE = 1
MAX_PREVIEW_DIMENSION = 1600
CONFIDENCE_THRESHOLD = 0.35
CATEGORY_DEFAULTS = {
    "pothole": {"category": "pothole", "severity_base": 3, "department": "public_works"},
    "garbage": {"category": "garbage", "severity_base": 2, "department": "sanitation"},
    "streetlight": {"category": "streetlight", "severity_base": 3, "department": "electricity"},
    "waterlogging": {"category": "waterlogging", "severity_base": 4, "department": "drainage"},
    "road_damage": {"category": "road_damage", "severity_base": 3, "department": "public_works"},
    "encroachment": {"category": "encroachment", "severity_base": 2, "department": "municipal"},
    "graffiti": {"category": "graffiti", "severity_base": 2, "department": "public_works"},
    "open_drain": {"category": "open_drain", "severity_base": 4, "department": "drainage"},
    "fallen_tree": {"category": "fallen_tree", "severity_base": 3, "department": "municipal"},
    "other": {"category": "other", "severity_base": 1, "department": "municipal"},
}
PREVIEW_COLORS = {
    "pothole": (220, 38, 38),
    "garbage": (217, 119, 6),
    "unknown": (79, 70, 229),
}


class AnalyzeRequest(BaseModel):
    """Payload sent by the PHP backend."""

    filepath: str = Field(
        ..., min_length=1, description="Image path relative to the project root"
    )
    submitted_category: Optional[str] = None
    latitude: Optional[float] = None
    longitude: Optional[float] = None
    include_preview: bool = False


class AnalyzeSuccess(BaseModel):
    success: bool
    category: str
    confidence: float
    severity: int
    is_manipulated: bool
    department: str
    low_confidence: bool
    manipulation: dict
    model_version: str
    detections: list[dict] = Field(default_factory=list)
    detection_count: int = 0
    bbox: list[float] = Field(default_factory=list)
    image_width: int = 0
    image_height: int = 0
    annotated_image: Optional[str] = None
    preview_width: Optional[int] = None
    preview_height: Optional[int] = None


class AnalyzeFailure(BaseModel):
    success: bool
    error: str


AnalyzeResponse = Union[AnalyzeSuccess, AnalyzeFailure]


_model: Optional[YOLO] = None
_model_load_error: Optional[str] = None
_model_version = "civicconnect-ai-unavailable"
_inference_lock = Lock()


def _model_candidates() -> list[Path]:
    configured = os.getenv("CIVICCONNECT_AI_MODEL", "").strip()
    candidates = []
    if configured:
        configured_path = Path(configured)
        candidates.append(configured_path if configured_path.is_absolute() else PROJECT_ROOT / configured_path)
    candidates.extend([DEFAULT_MODEL_PATH, DEPLOYMENT_MODEL_PATH])

    unique: list[Path] = []
    seen: set[Path] = set()
    for candidate in candidates:
        resolved = candidate.resolve()
        if resolved not in seen:
            unique.append(resolved)
            seen.add(resolved)
    return unique


def _load_model() -> None:
    """Load the trained detection model once when the application starts."""

    global _model, _model_load_error, _model_version

    errors = []
    for candidate in _model_candidates():
        if not candidate.is_file():
            errors.append(f"Model file not found: {candidate}")
            continue
        try:
            loaded_model = YOLO(str(candidate))
            if getattr(loaded_model, "task", None) != "detect":
                raise RuntimeError("the checkpoint is not a detection model")
            _model = loaded_model
            _model_load_error = None
            model_run = candidate.parent.parent.name
            _model_version = f"civicconnect-ultralytics-detector:{model_run}/{candidate.name}"
            return
        except Exception as exc:
            errors.append(f"Unable to load {candidate}: {exc}")

    _model = None
    _model_load_error = "; ".join(errors)
    _model_version = "civicconnect-ai-unavailable"


@asynccontextmanager
async def lifespan(_: FastAPI):
    _load_model()
    yield


app = FastAPI(
    title="CivicConnect AI Service",
    version="2.0.0",
    lifespan=lifespan,
)


def _resolve_image_path(filepath: str) -> Path:
    """Resolve a PHP-provided path while preventing traversal outside the app."""

    relative_path = Path(filepath)
    if relative_path.is_absolute():
        raise ValueError("filepath must be relative to the project root")

    resolved_path = (PROJECT_ROOT / relative_path).resolve()
    try:
        resolved_path.relative_to(PROJECT_ROOT)
    except ValueError as exc:
        raise ValueError("filepath must remain within the project root") from exc

    return resolved_path


def _class_name(names: object, class_index: int) -> str:
    """Get a class name from Ultralytics' dict or list representation."""

    if isinstance(names, dict):
        return str(names.get(class_index, names.get(str(class_index), "unknown")))
    if isinstance(names, (list, tuple)) and 0 <= class_index < len(names):
        return str(names[class_index])
    return "unknown"


def _category_for_model_name(name: str) -> str:
    """Map Roboflow labels to CivicConnect's database categories."""

    normalized = " ".join(name.strip().lower().replace("_", " ").replace("-", " ").split())
    aliases = {
        "pothole": "pothole",
        "potholes": "pothole",
        "pothole detection": "pothole",
        "trash": "garbage",
        "garbage": "garbage",
        "garbage dump": "garbage",
        "waste": "garbage",
        "rubbish": "garbage",
    }
    return aliases.get(normalized, normalized.replace(" ", "_") if normalized in CATEGORY_DEFAULTS else "unknown")


def _severity(category_name: str, confidence: float) -> int:
    metadata = CATEGORY_DEFAULTS.get(category_name, CATEGORY_DEFAULTS["other"])
    return max(1, min(5, int(round(float(metadata["severity_base"]) * confidence))))


def _gps_mismatch(manipulation: dict, request: AnalyzeRequest) -> dict:
    result = dict(manipulation)
    exif_lat = result.get("exif_lat")
    exif_lng = result.get("exif_lng")
    if (
        exif_lat is not None
        and exif_lng is not None
        and request.latitude is not None
        and request.longitude is not None
        and -90 <= request.latitude <= 90
        and -180 <= request.longitude <= 180
    ):
        distance = _distance_metres(float(request.latitude), float(request.longitude), float(exif_lat), float(exif_lng))
        result["gps_distance_metres"] = round(distance, 2)
        result["gps_mismatch"] = distance > 500
    else:
        result["gps_distance_metres"] = None
        result["gps_mismatch"] = False
    return result


def _distance_metres(lat_a: float, lon_a: float, lat_b: float, lon_b: float) -> float:
    radius = 6_371_000.0
    lat_delta = math.radians(lat_b - lat_a)
    lon_delta = math.radians(lon_b - lon_a)
    first = math.sin(lat_delta / 2) ** 2
    second = math.cos(math.radians(lat_a)) * math.cos(math.radians(lat_b)) * math.sin(lon_delta / 2) ** 2
    return radius * 2 * math.atan2(math.sqrt(first + second), math.sqrt(1 - first - second))


def _font(size: int) -> ImageFont.ImageFont:
    for font_name in ("arial.ttf", "DejaVuSans.ttf"):
        try:
            return ImageFont.truetype(font_name, size)
        except OSError:
            continue
    return ImageFont.load_default()


def _encode_annotated_preview(image: Image.Image, detections: list[dict]) -> tuple[str, int, int]:
    """Draw boxes and labels, then return a bounded JPEG data URL."""

    annotated = image.convert("RGB").copy()
    draw = ImageDraw.Draw(annotated)
    width, height = annotated.size
    line_width = max(3, round(max(width, height) / 320))
    font = _font(max(16, round(max(width, height) / 70)))

    for detection in detections:
        x1, y1, x2, y2 = detection["bbox"]
        category = detection["category"]
        color = PREVIEW_COLORS.get(category, PREVIEW_COLORS["unknown"])
        draw.rectangle((x1, y1, x2, y2), outline=color, width=line_width)
        label = f"{category.replace('_', ' ').title()} {detection['confidence']:.0%}"
        text_box = draw.textbbox((0, 0), label, font=font)
        text_width = text_box[2] - text_box[0]
        text_height = text_box[3] - text_box[1]
        label_y = max(0, y1 - text_height - line_width * 2)
        draw.rectangle(
            (x1, label_y, x1 + text_width + line_width * 4, label_y + text_height + line_width * 2),
            fill=color,
        )
        draw.text((x1 + line_width * 2, label_y + line_width), label, fill="white", font=font)

    if not detections:
        label = "No confident pothole or garbage detections"
        banner_height = max(36, round(height / 12))
        draw.rectangle((0, 0, width, banner_height), fill=(31, 41, 55))
        draw.text((line_width * 2, line_width * 2), label, fill="white", font=font)

    preview = annotated.copy()
    preview.thumbnail((MAX_PREVIEW_DIMENSION, MAX_PREVIEW_DIMENSION), Image.Resampling.LANCZOS)
    output = BytesIO()
    preview.save(output, format="JPEG", quality=88, optimize=True)
    encoded = base64.b64encode(output.getvalue()).decode("ascii")
    return f"data:image/jpeg;base64,{encoded}", preview.width, preview.height


def _run_inference(image_path: Path, include_preview: bool) -> dict:
    """Run one memory-conscious object-detection inference."""

    if _model is None:
        raise RuntimeError(_model_load_error or "The detection model is not loaded")

    use_cuda = torch.cuda.is_available()
    device: Union[int, str] = 0 if use_cuda else "cpu"

    with Image.open(image_path) as source_image:
        image = source_image.convert("RGB")
        image_width, image_height = image.size

    # The lock prevents concurrent requests from duplicating model activation
    # memory on a small GPU and keeps model access thread-safe.
    with _inference_lock:
        results = _model.predict(
            source=str(image_path),
            imgsz=IMAGE_SIZE,
            batch=INFERENCE_BATCH_SIZE,
            device=device,
            half=use_cuda,
            conf=CONFIDENCE_THRESHOLD,
            stream=False,
            verbose=False,
        )

    if not results:
        raise RuntimeError("The model did not return an inference result")

    result = results[0]
    boxes = getattr(result, "boxes", None)
    detections: list[dict] = []
    if boxes is not None:
        xyxy = boxes.xyxy.detach().cpu().tolist()
        confidences = boxes.conf.detach().cpu().tolist()
        class_ids = boxes.cls.detach().cpu().tolist()
        for bbox, confidence, class_id in zip(xyxy, confidences, class_ids):
            confidence = float(confidence)
            class_index = int(class_id)
            raw_name = _class_name(result.names, class_index)
            category = _category_for_model_name(raw_name)
            clipped_bbox = [
                round(max(0.0, min(float(bbox[0]), image_width)), 2),
                round(max(0.0, min(float(bbox[1]), image_height)), 2),
                round(max(0.0, min(float(bbox[2]), image_width)), 2),
                round(max(0.0, min(float(bbox[3]), image_height)), 2),
            ]
            detections.append(
                {
                    "class": raw_name,
                    "category": category,
                    "confidence": round(confidence, 4),
                    "bbox": clipped_bbox,
                    "class_id": class_index,
                }
            )

    detections.sort(key=lambda detection: detection["confidence"], reverse=True)
    primary = detections[0] if detections else None
    category = primary["category"] if primary else "unknown"
    confidence = float(primary["confidence"]) if primary else 0.0
    bbox = primary["bbox"] if primary else []
    preview = None
    preview_width = None
    preview_height = None
    if include_preview:
        preview, preview_width, preview_height = _encode_annotated_preview(image, detections)

    return {
        "category": category,
        "confidence": round(confidence, 4),
        "severity": _severity(category, confidence) if primary else 1,
        "low_confidence": not bool(primary) or confidence < 0.45,
        "detections": detections,
        "detection_count": len(detections),
        "bbox": bbox,
        "image_width": image_width,
        "image_height": image_height,
        "annotated_image": preview,
        "preview_width": preview_width,
        "preview_height": preview_height,
    }


@app.get("/health")
def health() -> dict:
    """Expose model readiness without exposing the local filesystem path."""

    return {
        "ok": _model is not None,
        "model_version": _model_version,
        "task": "detect",
        "error": _model_load_error,
    }


@app.post("/analyze", response_model=AnalyzeResponse)
def analyze(request: AnalyzeRequest, authorization: Optional[str] = Header(default=None)) -> AnalyzeResponse:
    """Detect civic issues and optionally return an annotated image preview."""

    if AI_SHARED_TOKEN:
        supplied = (authorization or "").removeprefix("Bearer ").strip()
        if not supplied or not hmac.compare_digest(supplied, AI_SHARED_TOKEN):
            raise HTTPException(status_code=401, detail="AI service authentication required")

    try:
        image_path = _resolve_image_path(request.filepath)
        if not image_path.is_file():
            return AnalyzeFailure(success=False, error="Image file not found")

        inference = _run_inference(image_path, request.include_preview)
        category = str(inference["category"])
        metadata = CATEGORY_DEFAULTS.get(category, CATEGORY_DEFAULTS["other"])
        try:
            manipulation = _gps_mismatch(check_manipulation(str(image_path)), request)
        except Exception as manipulation_error:
            # Authenticity checks are observable but should not make an image
            # submission fail when a malformed EXIF block is encountered.
            manipulation = {
                "flagged": False,
                "reason": f"Authenticity check unavailable: {manipulation_error}",
                "max_ela_value": None,
                "has_exif_gps": False,
                "exif_lat": None,
                "exif_lng": None,
                "gps_distance_metres": None,
                "gps_mismatch": False,
            }
        return AnalyzeSuccess(
            success=True,
            category=category,
            confidence=inference["confidence"],
            severity=inference["severity"],
            is_manipulated=bool(manipulation.get("flagged") or manipulation.get("gps_mismatch")),
            department=str(metadata.get("department", "municipal")),
            low_confidence=inference["low_confidence"],
            manipulation=manipulation,
            model_version=_model_version,
            detections=inference["detections"],
            detection_count=inference["detection_count"],
            bbox=inference["bbox"],
            image_width=inference["image_width"],
            image_height=inference["image_height"],
            annotated_image=inference["annotated_image"],
            preview_width=inference["preview_width"],
            preview_height=inference["preview_height"],
        )
    except Exception as exc:
        return AnalyzeFailure(success=False, error=str(exc))


# Run from the ai-service directory:
# python -m uvicorn main:app --host 127.0.0.1 --port 8000

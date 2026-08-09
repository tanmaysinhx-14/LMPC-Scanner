"""FastAPI service for CivicConnect image classification."""

from __future__ import annotations

from contextlib import asynccontextmanager
from pathlib import Path
from threading import Lock
import math
from typing import Optional, Union

import torch
from fastapi import FastAPI
from pydantic import BaseModel, Field
from ultralytics import YOLO

try:
    from .class_map import CIVIC_CLASSES
    from .image_check import check_manipulation
except ImportError:
    from class_map import CIVIC_CLASSES
    from image_check import check_manipulation


# This file is in <project-root>/ai-service/, so the parent directory is the
# project root used by the PHP backend for relative upload paths.
PROJECT_ROOT = Path(__file__).resolve().parent.parent
MODEL_PATH = PROJECT_ROOT / "runs" / "civicconnect_cls" / "weights" / "best.pt"
FALLBACK_MODEL_PATH = PROJECT_ROOT / "yolov8n-cls.pt"

# Classification models normally use 224x224 input images. Batch size 1 and
# half precision on CUDA keep peak VRAM usage low on a 4 GB GPU.
IMAGE_SIZE = 224
INFERENCE_BATCH_SIZE = 1
CONFIDENCE_THRESHOLD = 0.45
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


class AnalyzeRequest(BaseModel):
    """Payload sent by the PHP backend."""

    filepath: str = Field(
        ..., min_length=1, description="Image path relative to the project root"
    )
    submitted_category: Optional[str] = None
    latitude: Optional[float] = None
    longitude: Optional[float] = None


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


class AnalyzeFailure(BaseModel):
    success: bool
    error: str


AnalyzeResponse = Union[AnalyzeSuccess, AnalyzeFailure]


_model: Optional[YOLO] = None
_model_load_error: Optional[str] = None
_model_version = "civicconnect-ai-unavailable"
_inference_lock = Lock()


def _load_model() -> None:
    """Load the classifier once when the application starts."""

    global _model, _model_load_error, _model_version

    model_candidates = [MODEL_PATH, FALLBACK_MODEL_PATH]
    errors = []
    for candidate in model_candidates:
        if not candidate.is_file():
            errors.append(f"Model file not found: {candidate}")
            continue
        try:
            _model = YOLO(str(candidate))
            _model_load_error = None
            _model_version = f"civicconnect-yolov8-classifier:{candidate.name}"
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
    version="1.0.0",
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
        return str(names[class_index])
    if isinstance(names, (list, tuple)):
        return str(names[class_index])
    raise RuntimeError("The loaded model has no valid class names")


def _class_metadata(category_name: str) -> dict:
    normalized = category_name.strip().lower().replace("_", " ")
    for metadata in CIVIC_CLASSES.values():
        if normalized in {
            str(metadata.get("name", "")).lower().replace("_", " "),
            str(metadata.get("category", "")).lower().replace("_", " "),
        }:
            return metadata
    return {"category": "unknown", "severity_base": 1, "department": "municipal"}


def _submitted_category(value: Optional[str]) -> str:
    normalized = (value or "").strip().lower().replace(" ", "_")
    allowed = set(CATEGORY_DEFAULTS)
    return normalized if normalized in allowed else "unknown"


def _severity(base: int, confidence: float) -> int:
    return max(1, min(5, int(round(float(base) * max(0.0, min(1.0, confidence))))))


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


def _run_inference(image_path: Path) -> tuple[str, float]:
    """Run one memory-conscious classification inference."""

    if _model is None:
        raise RuntimeError(_model_load_error or "Model is not loaded")

    use_cuda = torch.cuda.is_available()
    device: Union[int, str] = 0 if use_cuda else "cpu"

    # The lock prevents concurrent requests from duplicating model activation
    # memory on the 4 GB GPU and keeps model access thread-safe.
    with _inference_lock:
        results = _model.predict(
            source=str(image_path),
            imgsz=IMAGE_SIZE,
            batch=INFERENCE_BATCH_SIZE,
            device=device,
            half=use_cuda,
            stream=False,
            verbose=False,
        )

    if not results or results[0].probs is None:
        raise RuntimeError("The model did not return classification probabilities")

    probabilities = results[0].probs
    class_index = int(probabilities.top1)
    confidence = float(probabilities.top1conf)
    category = _class_name(results[0].names, class_index)

    return category, round(confidence, 4)


@app.post("/analyze", response_model=AnalyzeResponse)
def analyze(request: AnalyzeRequest) -> AnalyzeResponse:
    """Classify an uploaded image and return the PHP-compatible response."""

    try:
        image_path = _resolve_image_path(request.filepath)
        if not image_path.is_file():
            return AnalyzeFailure(success=False, error="Image file not found")

        predicted_name, confidence = _run_inference(image_path)
        metadata = _class_metadata(predicted_name)
        low_confidence = confidence < CONFIDENCE_THRESHOLD
        category = _submitted_category(request.submitted_category) if low_confidence else str(metadata.get("category", "unknown"))
        if category == "unknown":
            category = "unknown"
        if low_confidence and category != "unknown":
            metadata = CATEGORY_DEFAULTS.get(category, metadata)
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
            confidence=confidence,
            severity=_severity(int(metadata.get("severity_base", 1)), confidence),
            is_manipulated=bool(manipulation.get("flagged") or manipulation.get("gps_mismatch")),
            department=str(metadata.get("department", "municipal")),
            low_confidence=low_confidence,
            manipulation=manipulation,
            model_version=_model_version,
        )
    except Exception as exc:
        return AnalyzeFailure(success=False, error=str(exc))


# Run from the ai-service directory:
# python -m uvicorn main:app --host 127.0.0.1 --port 8000

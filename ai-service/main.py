"""FastAPI service for CivicConnect image classification."""

from __future__ import annotations

from contextlib import asynccontextmanager
from pathlib import Path
from threading import Lock
from typing import Literal, Optional, Union

import torch
from fastapi import FastAPI
from pydantic import BaseModel, Field
from ultralytics import YOLO


# This file is in <project-root>/ai-service/, so the parent directory is the
# project root used by the PHP backend for relative upload paths.
PROJECT_ROOT = Path(__file__).resolve().parent.parent
MODEL_PATH = PROJECT_ROOT / "runs" / "civicconnect_cls" / "weights" / "best.pt"

# Classification models normally use 224x224 input images. Batch size 1 and
# half precision on CUDA keep peak VRAM usage low on a 4 GB GPU.
IMAGE_SIZE = 224
INFERENCE_BATCH_SIZE = 1


class AnalyzeRequest(BaseModel):
    """Payload sent by the PHP backend."""

    filepath: str = Field(
        ..., min_length=1, description="Image path relative to the project root"
    )


class AnalyzeSuccess(BaseModel):
    success: Literal[True]
    category: str
    confidence: float
    severity: Literal[3]
    is_manipulated: Literal[False]


class AnalyzeFailure(BaseModel):
    success: Literal[False]
    error: str


AnalyzeResponse = Union[AnalyzeSuccess, AnalyzeFailure]


_model: Optional[YOLO] = None
_model_load_error: Optional[str] = None
_inference_lock = Lock()


def _load_model() -> None:
    """Load the classifier once when the application starts."""

    global _model, _model_load_error

    if not MODEL_PATH.is_file():
        _model_load_error = f"Model file not found: {MODEL_PATH}"
        return

    try:
        _model = YOLO(str(MODEL_PATH))
        _model_load_error = None
    except Exception as exc:
        _model = None
        _model_load_error = f"Unable to load model: {exc}"


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

        category, confidence = _run_inference(image_path)
        return AnalyzeSuccess(
            success=True,
            category=category,
            confidence=confidence,
            severity=3,
            is_manipulated=False,
        )
    except Exception as exc:
        return AnalyzeFailure(success=False, error=str(exc))


# Run from the ai-service directory:
# python -m uvicorn main:app --host 127.0.0.1 --port 8000

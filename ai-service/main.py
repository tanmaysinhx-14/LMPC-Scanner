"""FastAPI entry point for the CivicConnect AI inference service."""

from __future__ import annotations

import logging
import time
from pathlib import Path

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from pydantic import BaseModel

try:
    from .image_check import check_manipulation
    from .inference import is_model_loaded, run_inference
except ImportError:
    from image_check import check_manipulation
    from inference import is_model_loaded, run_inference

logger = logging.getLogger(__name__)

PROJECT_ROOT = Path(__file__).resolve().parent.parent
ALLOWED_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp"}

app = FastAPI(title="CivicConnect AI Service")
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)


class AnalyzeRequest(BaseModel):
    filepath: str


def _error_response(message: str) -> JSONResponse:
    return JSONResponse(status_code=400, content={"success": False, "error": message})


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "model_loaded": is_model_loaded()}


@app.post("/analyze")
def analyze(request: AnalyzeRequest):
    started_at = time.perf_counter()
    filepath = request.filepath
    resolved_path = (PROJECT_ROOT / filepath).resolve()

    if not resolved_path.exists() or not resolved_path.is_file():
        message = f"File not found at path: {filepath}"
        logger.warning("analyze filepath=%s error=%s", filepath, message)
        return _error_response(message)

    if resolved_path.suffix.lower() not in ALLOWED_EXTENSIONS:
        message = (
            f"Invalid file type for path: {filepath}. "
            "Allowed extensions: .jpg, .jpeg, .png, .webp"
        )
        logger.warning("analyze filepath=%s error=%s", filepath, message)
        return _error_response(message)

    manipulation = {
        "flagged": False,
        "reason": "Pass",
        "has_exif_gps": False,
    }
    try:
        manipulation_result = check_manipulation(str(resolved_path))
        manipulation.update(manipulation_result)
    except Exception as error:
        logger.warning("Manipulation check failed for %s: %s", filepath, error)

    inference_started_at = time.perf_counter()
    try:
        result = run_inference(str(resolved_path))
    except Exception:
        logger.exception("Inference crashed for %s; returning unknown fallback", filepath)
        result = {
            "category": "unknown",
            "severity": 1,
            "confidence": 0.0,
            "bbox": [],
            "raw_detections": [],
        }
    inference_time_ms = (time.perf_counter() - inference_started_at) * 1000

    response = {
        "success": True,
        "category": result["category"],
        "severity": result["severity"],
        "confidence": result["confidence"],
        "bbox": result["bbox"],
        "is_manipulated": bool(manipulation["flagged"]),
        "manipulation_reason": manipulation["reason"],
        "has_exif_gps": bool(manipulation["has_exif_gps"]),
        "raw_detections": result["raw_detections"],
    }
    logger.info(
        "analyze filepath=%s category=%s severity=%s inference_time_ms=%.2f",
        filepath,
        response["category"],
        response["severity"],
        inference_time_ms,
    )
    return response

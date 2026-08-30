"""Pluggable OCR backends for the Legal Metrology label reader.

Why a backend layer
-------------------
The prototype hard-wired a single ``easyocr.Reader`` at import time with
``gpu=False``, so every crop cost 9-12 s on CPU and there was no way to compare
engines. PaddleOCR - the usual first choice for dense Indian packaging text -
cannot be used on this machine: its PIR/oneDNN runtime raises
``ConvertPirAttribute2RuntimeAttribute not support`` on every crop, and importing
``paddle`` before ``torch`` breaks torch's ``shm.dll``, which YOLO needs. See
``docs/ocr_improvement_plan.md`` for the post-mortem.

This module therefore exposes two interchangeable backends behind one interface:

* ``easyocr``  - torch-based, moved onto the GPU when one is present, with
  detector thresholds tuned for small statutory print.
* ``rapidocr`` - the PP-OCR models (the same family PaddleOCR ships) executed
  through ONNX Runtime, so no paddle native code is involved. Initialises in
  ~1.3 s against EasyOCR's ~15 s.

Both return ``OcrLine`` records with pixel boxes, which lets the caller sort text
in reading order instead of trusting each engine's internal ordering.

Usage
-----
    from ocr_backends import get_backend
    backend = get_backend("rapidocr")
    result = backend.read(crop_bgr)
    print(result.text, result.confidence)
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
import logging
import os
from threading import Lock
import time
from typing import Any, Callable, Iterable, Sequence

import numpy as np

logger = logging.getLogger(__name__)

#: Engine used when nothing is specified. Overridable with ``LMPC_OCR_BACKEND``.
DEFAULT_BACKEND = os.environ.get("LMPC_OCR_BACKEND", "rapidocr")

__all__ = [
    "OcrBackend",
    "OcrLine",
    "OcrResult",
    "available_backends",
    "backend_names",
    "get_backend",
    "resolve_backend",
    "sort_lines",
]


@dataclass(frozen=True)
class OcrLine:
    """One recognized text line with its box in crop pixel coordinates."""

    text: str
    confidence: float
    box: tuple[int, int, int, int]  # x1, y1, x2, y2

    @property
    def height(self) -> int:
        return self.box[3] - self.box[1]

    @property
    def y_center(self) -> float:
        return (self.box[1] + self.box[3]) / 2.0


def sort_lines(lines: Sequence[OcrLine]) -> list[OcrLine]:
    """Order lines the way a person reads them: top band to bottom, left to right.

    Statutory panels are dense and multi-column ("MRP" beside "Rs. 29.00"), and
    neither engine guarantees reading order. Lines whose vertical centres sit
    within half a line height of each other are treated as the same row.
    """

    if not lines:
        return []
    remaining = sorted(lines, key=lambda line: (line.y_center, line.box[0]))
    rows: list[list[OcrLine]] = []
    for line in remaining:
        placed = False
        for row in rows:
            tolerance = max(4.0, 0.6 * max(row[0].height, line.height))
            if abs(row[0].y_center - line.y_center) <= tolerance:
                row.append(line)
                placed = True
                break
        if not placed:
            rows.append([line])
    ordered: list[OcrLine] = []
    for row in sorted(rows, key=lambda row: min(line.y_center for line in row)):
        ordered.extend(sorted(row, key=lambda line: line.box[0]))
    return ordered


@dataclass
class OcrResult:
    """What one backend made of one image."""

    backend: str
    lines: list[OcrLine] = field(default_factory=list)
    elapsed_s: float = 0.0
    error: str | None = None

    @property
    def text(self) -> str:
        return " ".join(line.text.strip() for line in sort_lines(self.lines) if line.text.strip()).strip()

    @property
    def confidence(self) -> float | None:
        """Character-weighted mean confidence: a 20-char line should outweigh "g"."""

        weighted = [(len(line.text.strip()), line.confidence) for line in self.lines if line.text.strip()]
        total = sum(weight for weight, _ in weighted)
        if not total:
            return None
        return sum(weight * conf for weight, conf in weighted) / total

    @property
    def line_count(self) -> int:
        return len([line for line in self.lines if line.text.strip()])


def _quad_to_box(points: Iterable[Sequence[float]]) -> tuple[int, int, int, int]:
    """Collapse a 4-point quadrilateral (both engines return these) to x1y1x2y2."""

    xs = [float(point[0]) for point in points]
    ys = [float(point[1]) for point in points]
    return (int(min(xs)), int(min(ys)), int(max(xs)), int(max(ys)))


class OcrBackend(ABC):
    """One OCR engine, loaded lazily and guarded by a lock.

    Streamlit serves requests from multiple threads and neither engine is
    thread-safe, so every call into the engine is serialized.
    """

    name = "base"

    def __init__(self) -> None:
        self._engine: Any = None
        self._lock = Lock()
        self._init_error: Exception | None = None
        self._init_seconds: float | None = None

    @property
    def init_seconds(self) -> float | None:
        return self._init_seconds

    @property
    def init_error(self) -> Exception | None:
        return self._init_error

    @abstractmethod
    def _build_engine(self) -> Any:
        """Construct the underlying engine. Called once, under the lock."""

    @abstractmethod
    def _recognize(self, engine: Any, image: np.ndarray, **kwargs: Any) -> list[OcrLine]:
        """Run the engine and normalize its output to ``OcrLine`` records."""

    def describe(self) -> dict[str, Any]:
        """Info for the benchmark table and the admin panel."""

        return {
            "name": self.name,
            "ready": self._engine is not None,
            "init_seconds": self._init_seconds,
            "init_error": str(self._init_error) if self._init_error else None,
        }

    def is_available(self) -> bool:
        try:
            self._ensure_engine()
        except Exception:
            return False
        return True

    def _ensure_engine(self) -> Any:
        if self._engine is not None:
            return self._engine
        with self._lock:
            if self._engine is None:
                if self._init_error is not None:
                    raise RuntimeError(f"{self.name} previously failed to load") from self._init_error
                started = time.perf_counter()
                try:
                    self._engine = self._build_engine()
                except Exception as exc:
                    self._init_error = exc
                    logger.warning("OCR backend %s failed to load: %s", self.name, exc)
                    raise
                self._init_seconds = time.perf_counter() - started
                logger.info("OCR backend %s ready in %.2fs", self.name, self._init_seconds)
        return self._engine

    def read(self, image: np.ndarray, **kwargs: Any) -> OcrResult:
        """Recognize text in ``image``; never raises, failures land in ``error``."""

        if image is None or getattr(image, "size", 0) == 0:
            return OcrResult(backend=self.name, error="empty image")
        started = time.perf_counter()
        try:
            engine = self._ensure_engine()
        except Exception as exc:
            return OcrResult(backend=self.name, elapsed_s=time.perf_counter() - started, error=str(exc))
        try:
            with self._lock:
                lines = self._recognize(engine, image, **kwargs)
        except Exception as exc:  # a bad crop should degrade, not crash a scan
            logger.warning("%s failed on a %s crop: %s", self.name, getattr(image, "shape", "?"), exc)
            return OcrResult(backend=self.name, elapsed_s=time.perf_counter() - started, error=str(exc))
        return OcrResult(backend=self.name, lines=lines, elapsed_s=time.perf_counter() - started)


def _torch_gpu_available() -> bool:
    try:
        import torch
    except Exception:
        return False
    try:
        return bool(torch.cuda.is_available())
    except Exception:
        return False


#: EasyOCR defaults assume clean document scans. Statutory print is small, low
#: contrast and often dot-matrix, so the detector has to be told to keep weaker
#: candidate regions instead of discarding them.
EASYOCR_READ_KWARGS: dict[str, Any] = {
    "detail": 1,
    "paragraph": False,
    "text_threshold": 0.55,   # default 0.7 - accept fainter character blobs
    "low_text": 0.3,          # default 0.4 - grow regions further before cutting
    "link_threshold": 0.3,    # default 0.4 - join broken dot-matrix strokes
    "mag_ratio": 1.5,         # upscale inside the detector as well as outside
    "contrast_ths": 0.05,     # default 0.1 - retry low-contrast lines more often
    "adjust_contrast": 0.7,   # default 0.5 - stronger retry normalisation
    "min_size": 6,            # default 10 - keep short fragments like "g" or "15"
    "slope_ths": 0.3,         # tolerate mild skew from hand-held capture
    "ycenter_ths": 0.6,
    "height_ths": 0.6,
    "width_ths": 0.7,         # merge "MRP" with the price sitting beside it
    "add_margin": 0.15,
}


class EasyOcrBackend(OcrBackend):
    """EasyOCR (CRAFT detector + CRNN recognizer) on the GPU when one exists."""

    name = "easyocr"

    def __init__(self, languages: Sequence[str] = ("en",), gpu: bool | None = None) -> None:
        super().__init__()
        self.languages = list(languages)
        self.gpu = _torch_gpu_available() if gpu is None else bool(gpu)

    def _build_engine(self) -> Any:
        import easyocr

        return easyocr.Reader(self.languages, gpu=self.gpu, verbose=False)

    def describe(self) -> dict[str, Any]:
        info = super().describe()
        info["device"] = "cuda" if self.gpu else "cpu"
        info["languages"] = self.languages
        return info

    def _recognize(self, engine: Any, image: np.ndarray, **kwargs: Any) -> list[OcrLine]:
        options = dict(EASYOCR_READ_KWARGS)
        allowlist = kwargs.pop("allowlist", None)
        if allowlist:
            options["allowlist"] = allowlist
        options.update({key: value for key, value in kwargs.items() if value is not None})
        lines: list[OcrLine] = []
        for item in engine.readtext(image, **options) or []:
            try:
                quad, text, confidence = item[0], item[1], item[2]
            except (IndexError, TypeError):
                continue
            if not str(text).strip():
                continue
            lines.append(OcrLine(str(text), float(confidence), _quad_to_box(quad)))
        return lines


class RapidOcrBackend(OcrBackend):
    """PP-OCR models via ONNX Runtime - PaddleOCR's accuracy without paddle."""

    name = "rapidocr"

    def __init__(self, box_thresh: float = 0.3, unclip_ratio: float = 2.0, text_score: float = 0.4) -> None:
        super().__init__()
        # Looser than the PP-OCR defaults (0.5 / 1.6 / 0.5) for the same reason
        # EasyOCR's thresholds are lowered: small, low-contrast statutory print.
        self.box_thresh = box_thresh
        self.unclip_ratio = unclip_ratio
        self.text_score = text_score

    def _build_engine(self) -> Any:
        from rapidocr_onnxruntime import RapidOCR

        return RapidOCR(
            det_db_box_thresh=self.box_thresh,
            det_db_unclip_ratio=self.unclip_ratio,
            text_score=self.text_score,
        )

    def describe(self) -> dict[str, Any]:
        info = super().describe()
        info["box_thresh"] = self.box_thresh
        info["text_score"] = self.text_score
        try:
            import onnxruntime

            info["providers"] = onnxruntime.get_available_providers()
        except Exception:
            info["providers"] = None
        return info

    def _recognize(self, engine: Any, image: np.ndarray, **kwargs: Any) -> list[OcrLine]:
        # RapidOCR expects 3-channel input; binarized crops arrive as 2-D.
        if image.ndim == 2:
            image = np.stack([image] * 3, axis=-1)
        raw, _elapse = engine(image)
        lines: list[OcrLine] = []
        for item in raw or []:
            try:
                quad, text, confidence = item[0], item[1], item[2]
            except (IndexError, TypeError):
                continue
            if not str(text).strip():
                continue
            lines.append(OcrLine(str(text), float(confidence), _quad_to_box(quad)))
        return lines


_FACTORIES: dict[str, Callable[[], OcrBackend]] = {
    EasyOcrBackend.name: EasyOcrBackend,
    RapidOcrBackend.name: RapidOcrBackend,
}
_INSTANCES: dict[str, OcrBackend] = {}
_REGISTRY_LOCK = Lock()


def backend_names() -> list[str]:
    """Registered names, whether or not their dependencies are installed."""

    return sorted(_FACTORIES)


def get_backend(name: str | None = None) -> OcrBackend:
    """Return the shared instance for ``name``; engines load on first ``read``."""

    key = (name or DEFAULT_BACKEND).strip().lower()
    if key not in _FACTORIES:
        raise KeyError(f"unknown OCR backend {key!r}; known: {', '.join(backend_names())}")
    with _REGISTRY_LOCK:
        if key not in _INSTANCES:
            _INSTANCES[key] = _FACTORIES[key]()
    return _INSTANCES[key]


def available_backends() -> list[OcrBackend]:
    """Backends whose engine actually loads on this machine, cheapest first."""

    ready: list[OcrBackend] = []
    for name in backend_names():
        backend = get_backend(name)
        if backend.is_available():
            ready.append(backend)
    return sorted(ready, key=lambda backend: backend.init_seconds or 0.0)


def resolve_backend(name: str | None = None) -> OcrBackend:
    """Like ``get_backend`` but falls back to any working engine.

    The app must still run if one engine's native dependencies break after an
    unrelated ``pip install`` - which is exactly how PaddleOCR was lost here.
    """

    preferred = get_backend(name)
    if preferred.is_available():
        return preferred
    for candidate in available_backends():
        logger.warning("falling back from %s to %s", preferred.name, candidate.name)
        return candidate
    raise RuntimeError(
        "no OCR backend is usable. Install one of: "
        "pip install rapidocr-onnxruntime  |  pip install easyocr"
    )


if __name__ == "__main__":  # quick environment check
    import json

    for _backend in (get_backend(_name) for _name in backend_names()):
        _backend.is_available()
        print(json.dumps(_backend.describe(), indent=2, default=str))







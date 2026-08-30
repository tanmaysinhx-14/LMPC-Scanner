"""Shared fixtures. Kept deliberately small: most tests need synthetic images only.

The heavy dependencies (torch, an OCR engine, the trained checkpoint) are optional
here. Tests that need them are marked ``slow`` and skip themselves when the
dependency is missing, so the suite is still useful on a machine that has not
downloaded model weights.
"""

from __future__ import annotations

from pathlib import Path
import sys

import numpy as np
import pytest

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import cv2  # noqa: E402  (after sys.path fix)


def render_text_image(
    text: str = "MRP Rs. 29.00",
    width: int = 320,
    height: int = 90,
    scale: float = 1.0,
    thickness: int = 2,
    angle: float = 0.0,
) -> np.ndarray:
    """A synthetic label crop: dark text on a light panel, optionally rotated."""

    image = np.full((height, width, 3), 240, dtype=np.uint8)
    cv2.putText(image, text, (10, int(height * 0.62)), cv2.FONT_HERSHEY_SIMPLEX,
                scale, (20, 20, 20), thickness, cv2.LINE_AA)
    if angle:
        matrix = cv2.getRotationMatrix2D((width / 2, height / 2), angle, 1.0)
        image = cv2.warpAffine(image, matrix, (width, height), borderMode=cv2.BORDER_REPLICATE)
    return image


@pytest.fixture
def text_crop() -> np.ndarray:
    return render_text_image()


@pytest.fixture
def dataset_root() -> Path:
    root = ROOT / "packaged-commodity-dataset"
    if not root.is_dir():
        pytest.skip("packaged-commodity-dataset is not present")
    return root

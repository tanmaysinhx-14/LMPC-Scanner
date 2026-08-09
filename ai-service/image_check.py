"""Image authenticity checks used by the CivicConnect inference service."""

from __future__ import annotations

import io
import math
from fractions import Fraction
from typing import Any

from PIL import Image, ImageChops


def _rational_to_float(value: Any) -> float:
    """Convert a Pillow EXIF rational (or a numeric value) to a float."""

    if isinstance(value, Fraction):
        return float(value)
    if hasattr(value, "numerator") and hasattr(value, "denominator"):
        denominator = float(value.denominator)
        return float(value.numerator) / denominator if denominator else 0.0
    if isinstance(value, (tuple, list)) and len(value) == 2:
        denominator = float(value[1])
        return float(value[0]) / denominator if denominator else 0.0
    return float(value)


def _dms_to_decimal(values: Any, reference: Any) -> float | None:
    if not isinstance(values, (tuple, list)) or len(values) != 3:
        return None
    try:
        decimal = (
            _rational_to_float(values[0])
            + _rational_to_float(values[1]) / 60.0
            + _rational_to_float(values[2]) / 3600.0
        )
        if str(reference).upper() in {"S", "W"}:
            decimal *= -1
        return round(decimal, 8)
    except (TypeError, ValueError, ZeroDivisionError):
        return None


def _distance_metres(lat_a: float, lon_a: float, lat_b: float, lon_b: float) -> float:
    radius = 6_371_000.0
    lat_delta = math.radians(lat_b - lat_a)
    lon_delta = math.radians(lon_b - lon_a)
    first = math.sin(lat_delta / 2) ** 2
    second = math.cos(math.radians(lat_a)) * math.cos(math.radians(lat_b)) * math.sin(lon_delta / 2) ** 2
    return radius * 2 * math.atan2(math.sqrt(first + second), math.sqrt(1 - first - second))


def check_manipulation(image_path: str) -> dict:
    """Run ELA manipulation detection and check whether EXIF contains GPS data.

    The caller owns error handling because this check is intentionally non-critical
    to the issue-submission flow.
    """

    exif_lat = None
    exif_lng = None
    with Image.open(image_path) as image:
        try:
            exif = image._getexif() or {}
            gps_info = exif.get(34853, {})
            has_exif_gps = bool(gps_info)
            if has_exif_gps:
                exif_lat = _dms_to_decimal(gps_info.get(2), gps_info.get(1))
                exif_lng = _dms_to_decimal(gps_info.get(4), gps_info.get(3))
        except Exception:
            has_exif_gps = False

        original = image.convert("RGB")

    buffer = io.BytesIO()
    original.save(buffer, format="JPEG", quality=90)
    buffer.seek(0)

    with Image.open(buffer) as recompressed_image:
        recompressed = recompressed_image.convert("RGB")

    difference = ImageChops.difference(original, recompressed)
    extrema = difference.getextrema()
    max_diff = max(channel_extrema[1] for channel_extrema in extrema)
    flagged = max_diff > 18.0

    return {
        "flagged": flagged,
        "reason": "ELA anomaly detected" if flagged else "Pass",
        "max_ela_value": float(max_diff),
        "has_exif_gps": has_exif_gps,
        "exif_lat": exif_lat,
        "exif_lng": exif_lng,
    }

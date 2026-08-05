"""Image authenticity checks used by the CivicConnect inference service."""

from __future__ import annotations

import io

from PIL import Image, ImageChops


def check_manipulation(image_path: str) -> dict:
    """Run ELA manipulation detection and check whether EXIF contains GPS data.

    The caller owns error handling because this check is intentionally non-critical
    to the issue-submission flow.
    """

    with Image.open(image_path) as image:
        try:
            exif = image._getexif() or {}
            has_exif_gps = 34853 in exif
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
    }

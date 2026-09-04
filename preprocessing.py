"""Crop preparation for OCR on Indian packaged-commodity labels.

The statutory declarations on a retail pack are the hardest text on it: small,
often dot-matrix over-printed after packing, frequently on glossy foil that
throws specular glare, and photographed hand-held at an angle.

The prototype fed the detector's crop through a blind ``cv2.resize(fx=3, fy=3)``.
That is too little for a 7-pixel-tall printed date and wasteful for a 200-pixel
manufacturer block, so this module scales by *measured text height* instead, then
offers a small set of variants for the pipeline to try:

* ``plain``     - deskewed and scaled only; best on clean offset-printed text
* ``enhanced``  - CLAHE on the LAB luminance channel; best under glare
* ``dotmatrix`` - contrast equalisation plus a *grayscale* morphological close,
  which joins the broken strokes of dot-matrix batch/date/MRP over-printing
  without discarding the grey levels a hard threshold would (see
  ``heal_dot_matrix_text`` for the measurements that settled this)

``plain`` wins most often on every class, but never by enough to make the others
dispensable - see ``build_variants`` for the measured win rates and what they mean
for the order the variants are tried in.

Nothing here imports torch or an OCR engine, so it stays cheap to unit test.
"""

from __future__ import annotations

from dataclasses import dataclass
import os
from typing import Sequence

import cv2
import numpy as np

#: CRNN-style recognizers (both EasyOCR and PP-OCR) are trained on ~32 px text.
TARGET_TEXT_HEIGHT = 32
#: Bounds on the scale factor: below 1 we would destroy detail, above 6 we only
#: interpolate noise and pay for the extra pixels.
MIN_SCALE = 1.0
MAX_SCALE = 6.0
#: Absolute cap on the *scaled* crop's longer side, in pixels. Small statutory
#: print still gets its full 6x upscale (a 187px date crop lands at ~1122px, well
#: under this), but a large manufacturer / ingredient panel that would otherwise
#: balloon to ~2600px is held here: past this the extra pixels are interpolation,
#: not information, and cost the recognizer seconds per crop. This only ever
#: *reduces* the factor and never drops it below MIN_SCALE, so it downscales
#: nothing - an already-large crop is simply left at its native size. Tunable
#: with ``LMPC_MAX_OUTPUT_SIDE`` so the ceiling can be re-measured without a code
#: change (set it very high to effectively disable the cap).
MAX_OUTPUT_SIDE = int(os.environ.get("LMPC_MAX_OUTPUT_SIDE", "1600"))
#: Skew beyond this is a rotated pack, not a tilted photo; leave it to the user.
MAX_DESKEW_DEGREES = 12.0

#: Classes whose text is usually dot-matrix over-print rather than offset print.
DOT_MATRIX_CLASSES = frozenset({"mrp_declaration", "date_declarations", "batch_number"})

#: Restricted charsets stop the recognizer from inventing letters in numeric
#: fields (a common EasyOCR failure: "29.00" -> "Z9.OO").
CLASS_ALLOWLISTS: dict[str, str] = {
    "mrp_declaration": "0123456789.,/RsMPINCLUSIVEOFALTAXEBUP₹ ",
    "net_quantity": "0123456789.,gkmlLNETQUANTIYWPACS()x× ",
    "date_declarations": "0123456789./-ABCDEFGHIJLMNOPRSTUVYXWZ ",
    "batch_number": "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ./-: ",
}

__all__ = [
    "CLASS_ALLOWLISTS",
    "CropVariant",
    "build_variants",
    "classify_dietary_symbol",
    "deskew",
    "enhance_text_roi",
    "estimate_text_height",
    "heal_dot_matrix_text",
    "laplacian_sharpness",
    "scale_for_ocr",
    "variant_names",
]


def _to_gray(image: np.ndarray) -> np.ndarray:
    if image.ndim == 2:
        gray = image
    else:
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    if gray.dtype != np.uint8:
        gray = cv2.normalize(gray, None, 0, 255, cv2.NORM_MINMAX).astype(np.uint8)
    return gray


def _ink_mask(gray: np.ndarray) -> np.ndarray:
    """Binary mask where dark strokes are white, robust to uneven lighting."""

    block = max(11, (min(gray.shape[:2]) // 2) * 2 + 1)
    block = min(block, 51)
    if block % 2 == 0:
        block += 1
    return cv2.adaptiveThreshold(
        gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY_INV, block, 9
    )


def estimate_text_height(image: np.ndarray) -> float:
    """Median height of stroke-like connected components, in pixels.

    Used to decide how much to upscale. Components taller than a third of the
    crop are borders or graphics, and 2-pixel specks are sensor noise, so both
    are excluded before taking the median.
    """

    if image is None or image.size == 0:
        return 0.0
    gray = _to_gray(image)
    height = gray.shape[0]
    mask = _ink_mask(gray)
    count, _labels, stats, _centroids = cv2.connectedComponentsWithStats(mask, connectivity=8)
    candidates: list[int] = []
    for index in range(1, count):
        component_height = int(stats[index, cv2.CC_STAT_HEIGHT])
        component_width = int(stats[index, cv2.CC_STAT_WIDTH])
        if component_height < 3 or component_height > max(4, height // 3):
            continue
        if component_width > 20 * component_height:  # an underline or box rule
            continue
        candidates.append(component_height)
    if not candidates:
        return float(height) / 3.0
    return float(np.median(candidates))


def scale_for_ocr(image: np.ndarray, target_height: int = TARGET_TEXT_HEIGHT) -> tuple[np.ndarray, float]:
    """Upscale so the measured text height lands near ``target_height``.

    Returns the scaled image and the factor applied, so callers can map boxes
    back to original crop coordinates. The factor is additionally held so the
    scaled crop's longer side stays within ``MAX_OUTPUT_SIDE`` - see that
    constant for why over-upscaling large panels only costs time.
    """

    if image is None or image.size == 0:
        return image, 1.0
    measured = estimate_text_height(image)
    if measured <= 0:
        return image, 1.0
    factor = float(np.clip(target_height / measured, MIN_SCALE, MAX_SCALE))
    # Hold large panels to MAX_OUTPUT_SIDE. ``max(MIN_SCALE, ...)`` keeps the cap
    # from ever downscaling: a crop already past the cap is left at factor 1.0.
    longer_side = max(image.shape[:2])
    if longer_side > 0:
        factor = min(factor, max(MIN_SCALE, MAX_OUTPUT_SIDE / float(longer_side)))
    if abs(factor - 1.0) < 0.05:
        return image, 1.0
    interpolation = cv2.INTER_CUBIC if factor > 1.0 else cv2.INTER_AREA
    scaled = cv2.resize(image, None, fx=factor, fy=factor, interpolation=interpolation)
    return scaled, factor


def deskew(image: np.ndarray, max_degrees: float = MAX_DESKEW_DEGREES) -> np.ndarray:
    """Rotate the crop so text baselines are horizontal.

    The angle comes from the minimum-area rectangle around all ink pixels. Text
    lines are much wider than they are tall, so that rectangle aligns with the
    baseline. Rotations beyond ``max_degrees`` are ignored rather than trusted.
    """

    if image is None or image.size == 0:
        return image
    gray = _to_gray(image)
    mask = _ink_mask(gray)
    # Join characters into line-shaped blobs so the rectangle follows the baseline.
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (max(3, gray.shape[1] // 20), 1))
    joined = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel)
    points = cv2.findNonZero(joined)
    if points is None or len(points) < 20:
        return image
    angle = cv2.minAreaRect(points)[-1]
    if angle > 45:
        angle -= 90
    if abs(angle) < 0.5 or abs(angle) > max_degrees:
        return image
    height, width = image.shape[:2]
    matrix = cv2.getRotationMatrix2D((width / 2.0, height / 2.0), angle, 1.0)
    border = cv2.BORDER_REPLICATE
    return cv2.warpAffine(image, matrix, (width, height), flags=cv2.INTER_CUBIC, borderMode=border)


def enhance_text_roi(image: np.ndarray) -> np.ndarray:
    """CLAHE on the LAB luminance channel: kills packaging glare, keeps colour.

    Applied to the colour crop rather than a grayscale one so that coloured text
    on a coloured panel (common on Indian FMCG packs) keeps its separation.
    """

    if image is None or image.size == 0:
        return image
    if image.ndim == 2:
        clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
        return clahe.apply(_to_gray(image))
    lab = cv2.cvtColor(image, cv2.COLOR_BGR2LAB)
    luminance, a_channel, b_channel = cv2.split(lab)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    merged = cv2.merge((clahe.apply(luminance), a_channel, b_channel))
    return cv2.cvtColor(merged, cv2.COLOR_LAB2BGR)


def heal_dot_matrix_text(image: np.ndarray, kernel_size: int = 3) -> np.ndarray:
    """Close the gaps between the dots of an ink-jet / dot-matrix over-print.

    This used to binarize (CLAHE, adaptive threshold, morphological close) on the
    theory that a clean black-on-white stroke is the easiest thing to recognize.
    Measured over all 121 dot-matrix crops in the test split, that was the worst
    of five recipes on both engines - it threw away the grey levels the
    recognizer uses to disambiguate strokes, and roughly halved the number of
    characters returned:

    ==================  =============  =============  ==========
    recipe              EasyOCR score  RapidOCR score chars read
    ==================  =============  =============  ==========
    binarize (old)              0.125          0.293    7.0/8.9
    grayscale close (now)       0.216          0.380  13.7/13.2
    unsharp mask                0.191          0.390  13.1/12.7
    plain                       0.216          0.291  14.0/12.1
    ==================  =============  =============  ==========

    So the close now happens on the grey image: contrast-equalise, dilate the
    ink (a close on the inverted image), and hand back grey pixels. It is best or
    tied-best on EasyOCR and within 0.01 of the best on RapidOCR, while being
    ~3x cheaper than ``plain`` because the recognizer gets a single channel.
    """

    if image is None or image.size == 0:
        return image
    gray = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(_to_gray(image))
    kernel = np.ones((max(2, kernel_size), max(2, kernel_size)), dtype=np.uint8)
    # Ink is dark, so invert first: closing the inverted image joins the dots
    # instead of eating them.
    return 255 - cv2.morphologyEx(255 - gray, cv2.MORPH_CLOSE, kernel)


def laplacian_sharpness(image: np.ndarray) -> float:
    """Variance of the Laplacian - the focus score used to gate live capture.

    Higher is sharper. Values are scene dependent, so the live view compares
    against a threshold calibrated on this dataset rather than an absolute.
    """

    if image is None or image.size == 0:
        return 0.0
    return float(cv2.Laplacian(_to_gray(image), cv2.CV_64F).var())


def classify_dietary_symbol(image: np.ndarray) -> str:
    """Green square = vegetarian, brown/maroon triangle = non-vegetarian.

    Legal Metrology packaging rules require the mark, so reporting it needs only
    a colour decision, not a classifier. Returns ``VEG``, ``NON_VEG`` or
    ``UNCERTAIN``.
    """

    if image is None or image.size == 0 or image.ndim != 3:
        return "UNCERTAIN"
    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
    total = image.shape[0] * image.shape[1]
    if not total:
        return "UNCERTAIN"

    green = cv2.inRange(hsv, np.array([35, 40, 40]), np.array([85, 255, 255]))
    green_ratio = cv2.countNonZero(green) / total
    # Non-veg marks are maroon/brown, which straddles the hue wraparound.
    brown_low = cv2.inRange(hsv, np.array([0, 60, 20]), np.array([18, 255, 200]))
    brown_high = cv2.inRange(hsv, np.array([165, 60, 20]), np.array([179, 255, 200]))
    brown_ratio = cv2.countNonZero(cv2.bitwise_or(brown_low, brown_high)) / total

    if green_ratio > 0.08 and green_ratio >= brown_ratio:
        return "VEG"
    if brown_ratio > 0.08:
        return "NON_VEG"
    return "UNCERTAIN"


@dataclass(frozen=True)
class CropVariant:
    """One prepared version of a crop, ready to hand to an OCR backend."""

    name: str
    image: np.ndarray
    scale: float
    allowlist: str | None = None


def build_variants(
    crop_bgr: np.ndarray,
    canonical_name: str | None = None,
    include_dotmatrix: bool | None = None,
    use_allowlist: bool = True,
) -> list[CropVariant]:
    """Prepared versions of one crop, best-measured first.

    Measured over a full-dataset sweep (RapidOCR, all variants scored on every
    crop, 1397 images, ``runs/ocr_rapidocr.jsonl``), counting how often each
    variant produced the *winning* candidate for its class:

    ====================  =====  =====  =========  ========
    class                 crops  plain  dotmatrix  enhanced
    ====================  =====  =====  =========  ========
    mrp_declaration        2756    36%        32%       31%
    date_declarations      2467    36%        33%       31%
    net_quantity           1479    59%         -        41%
    manufacturer_details    915    59%         -        41%
    consumer_care_fssai     761    52%         -        48%
    product_name            633    84%         -        16%
    ====================  =====  =====  =========  ========

    ``plain`` takes the plurality in every class, so it stays first: it is also the
    cheapest, which makes it the right thing for a truncated run to get. That
    conclusion needed the full sweep - an earlier partial sample had ``enhanced``
    ahead on ``consumer_care_fssai`` (53% to 46%), which the full dataset reverses
    (52% to 48%).

    No variant is dispensable either. Outside ``product_name``, the runner-up wins
    31-48% of crops, so dropping it costs real readings: keeping only ``plain``
    lowers the share of class-groups the rule engine accepts from 38.7% to 33.7%
    overall, and from 64.9% to 55.9% on ``net_quantity``.

    ``dotmatrix`` goes ahead of ``enhanced`` on the over-printed classes, where it
    wins marginally more often (33% vs 31% on dates, 32% vs 31% on MRP) while
    handing the recognizer a single channel instead of three.

    The ``net_quantity`` and ``product_name`` rows are still biased samples: 24%
    and 72% of their crops stop after ``plain`` satisfies the early stop, so their
    ``enhanced`` share is measured only on the crops ``plain`` failed to close.
    Both are ordered on cost, not on those figures.

    ``dotmatrix`` is only worth its cost for the over-printed classes, so by
    default it is added just for those - the caller can force it either way.
    """

    if crop_bgr is None or crop_bgr.size == 0:
        return []
    if include_dotmatrix is None:
        include_dotmatrix = canonical_name in DOT_MATRIX_CLASSES
    allowlist = CLASS_ALLOWLISTS.get(canonical_name or "") if use_allowlist else None

    straightened = deskew(crop_bgr)
    scaled, factor = scale_for_ocr(straightened)

    variants = [CropVariant("plain", scaled, factor, allowlist)]
    if include_dotmatrix:
        variants.append(CropVariant("dotmatrix", heal_dot_matrix_text(scaled), factor, allowlist))
    variants.append(CropVariant("enhanced", enhance_text_roi(scaled), factor, allowlist))
    return variants


def variant_names(canonical_name: str | None = None) -> Sequence[str]:
    """Variant labels ``build_variants`` will produce for this class, in order."""

    if canonical_name in DOT_MATRIX_CLASSES:
        return ("plain", "dotmatrix", "enhanced")
    return ("plain", "enhanced")





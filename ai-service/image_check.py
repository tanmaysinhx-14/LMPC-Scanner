# ELA and EXIF Validations
from PIL import Image, ImageChops, ImageFilter
import io, math

def check_manipulation(image_bytes: bytes, quality: int = 90, threshold: float = 18.0) -> dict:
    original = Image.open(io.BytesIO(image_bytes)).convert("RGB")
    
    # Re-save at known quality and compare
    buffer = io.BytesIO()
    original.save(buffer, format="JPEG", quality=quality)
    recompressed = Image.open(buffer).convert("RGB")
    
    # Pixel-level error
    ela_image = ImageChops.difference(original, recompressed)
    extrema = ela_image.getextrema()
    max_diff = max(extrema[0][1], extrema[1][1], extrema[2][1])
    
    flagged = max_diff > threshold
    
    # Also check EXIF GPS vs none
    exif = original._getexif() or {}
    has_gps = 34853 in exif  # GPSInfo tag
    
    return {
        "flagged": flagged,
        "reason": "ELA anomaly detected (possible composite or AI-generated)" if flagged else "Pass",
        "max_ela_value": max_diff,
        "has_exif_gps": has_gps
    }
import cv2
import numpy as np


def heal_dot_matrix_text(crop_bgr: np.ndarray) -> np.ndarray:
    if crop_bgr is None or crop_bgr.size == 0:
        return crop_bgr
    if crop_bgr.ndim == 2:
        gray = crop_bgr
    else:
        gray = cv2.cvtColor(crop_bgr, cv2.COLOR_BGR2GRAY)
    if gray.dtype != np.uint8:
        gray = cv2.normalize(gray, None, 0, 255, cv2.NORM_MINMAX).astype(np.uint8)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    contrast = clahe.apply(gray)
    binary = cv2.adaptiveThreshold(
        contrast,
        255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY,
        31,
        7,
    )
    kernel = np.ones((3, 3), dtype=np.uint8)
    return cv2.morphologyEx(binary, cv2.MORPH_CLOSE, kernel, iterations=1)


def classify_dietary_symbol(crop_bgr: np.ndarray) -> str:
    """
    Classifies dietary symbol using HSV color thresholding.
    Returns: 'VEG', 'NON_VEG', or 'UNCERTAIN'
    """
    if crop_bgr is None or crop_bgr.size == 0:
        return "UNCERTAIN"

    hsv = cv2.cvtColor(crop_bgr, cv2.COLOR_BGR2HSV)
    total_pixels = crop_bgr.shape[0] * crop_bgr.shape[1]

    # Green range (Veg)
    lower_green = np.array([35, 40, 40])
    upper_green = np.array([85, 255, 255])
    green_mask = cv2.inRange(hsv, lower_green, upper_green)
    green_ratio = cv2.countNonZero(green_mask) / total_pixels

    # Brown / Dark Red range (Non-Veg)
    lower_brown = np.array([0, 50, 20])
    upper_brown = np.array([20, 255, 180])
    brown_mask = cv2.inRange(hsv, lower_brown, upper_brown)
    brown_ratio = cv2.countNonZero(brown_mask) / total_pixels

    if green_ratio > 0.08:
        return "VEG"
    elif brown_ratio > 0.08:
        return "NON_VEG"
    return "UNCERTAIN"


def enhance_text_roi(crop_bgr: np.ndarray) -> np.ndarray:
    """
    Applies CLAHE on the L-channel of LAB color space to remove
    specular packaging glare without washing out character strokes.
    """
    if crop_bgr is None or crop_bgr.size == 0:
        return crop_bgr

    lab = cv2.cvtColor(crop_bgr, cv2.COLOR_BGR2LAB)
    l, a, b = cv2.split(lab)

    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    l_enhanced = clahe.apply(l)

    merged = cv2.merge((l_enhanced, a, b))
    return cv2.cvtColor(merged, cv2.COLOR_LAB2BGR)


def extract_and_process_rois(frame: np.ndarray, yolo_results) -> dict:
    """
    Takes a raw video frame and YOLO26 predictions, returning
    preprocessed crops partitioned by statutory class.
    """
    processed_payload = {
        "text_regions": {},
        "dietary_status": "NOT_DETECTED",
        "boxes": []
    }

    names = yolo_results[0].names
    boxes = yolo_results[0].boxes

    if boxes is None or len(boxes) == 0:
        return processed_payload

    h, w, _ = frame.shape

    for box in boxes:
        cls_id = int(box.cls[0].item())
        conf = float(box.conf[0].item())
        class_name = names[cls_id]

        # Extract pixel coordinates
        x1, y1, x2, y2 = map(int, box.xyxy[0].tolist())
        x1, y1 = max(0, x1), max(0, y1)
        x2, y2 = min(w, x2), min(h, y2)

        crop = frame[y1:y2, x1:x2]
        if crop.size == 0:
            continue

        processed_payload["boxes"].append({
            "class_name": class_name,
            "conf": conf,
            "coords": (x1, y1, x2, y2)
        })

        if class_name == "dietary_symbol_region":
            processed_payload["dietary_status"] = classify_dietary_symbol(crop)
        else:
            enhanced_crop = enhance_text_roi(crop)
            if class_name in {
                "mrp_region",
                "mrp_declaration",
                "date_region",
                "date_declarations",
                "batch_number",
                "batch_number_region",
                "batch_region",
            }:
                enhanced_crop = heal_dot_matrix_text(enhanced_crop)
            if class_name not in processed_payload["text_regions"]:
                processed_payload["text_regions"][class_name] = []
            processed_payload["text_regions"][class_name].append(enhanced_crop)

    return processed_payload

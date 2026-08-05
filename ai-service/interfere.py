# YOLOv8n and Severity Logic
import onnxruntime as ort
import numpy as np
from PIL import Image
import io

SEVERITY_BASE = {
    'pothole': 3, 'waterlogging': 4, 'broken_streetlight': 2,
    'garbage_dump': 2, 'damaged_road': 3, 'encroachment': 2,
    'graffiti': 1, 'open_drain': 4
}

session = ort.InferenceSession("models/yolov8n-civic.onnx", 
                                providers=['CUDAExecutionProvider', 'CPUExecutionProvider'])

def compute_severity(class_name: str, bbox: list, img_shape: tuple) -> int:
    base = SEVERITY_BASE.get(class_name, 2)
    bbox_area = (bbox[2] - bbox[0]) * (bbox[3] - bbox[1])
    img_area = img_shape[0] * img_shape[1]
    area_ratio = bbox_area / img_area  # 0.0 to 1.0
    # Scale severity upward if object is large (severe)
    if area_ratio > 0.35: base = min(5, base + 1)
    if area_ratio < 0.05: base = max(1, base - 1)
    return base

def run_inference(image_bytes: bytes) -> dict:
    # Preprocess
    img = Image.open(io.BytesIO(image_bytes)).convert("RGB").resize((640, 640))
    inp = np.array(img).transpose(2, 0, 1)[None].astype(np.float32) / 255.0
    
    # Inference
    outputs = session.run(None, {"images": inp})[0][0]
    
    # Parse best detection
    if len(outputs) == 0:
        return {"class_name": "unknown", "severity": 1, "confidence": 0.0, "bbox": []}
    
    best = outputs[outputs[:, 4].argmax()]
    class_id = int(best[5])
    confidence = float(best[4])
    bbox = best[:4].tolist()
    class_name = list(SEVERITY_BASE.keys())[class_id] if class_id < len(SEVERITY_BASE) else "unknown"
    
    return {
        "class_name": class_name,
        "severity": compute_severity(class_name, bbox, (640, 640)),
        "confidence": round(confidence, 3),
        "bbox": bbox
    }
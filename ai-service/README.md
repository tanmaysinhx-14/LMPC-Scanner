# CivicConnect AI Inference Service

This is the Python FastAPI inference service used by CivicConnect. It runs on
`localhost:8000` and receives a JSON path to an image saved by the PHP
application. The path is relative to the project root, one directory above
`ai-service/`.

## Model setup

The service first looks for the civic-trained ONNX model at:

```text
ai-service/models/yolov8n-civic.onnx
```

### Option A: recommended civic ONNX model

Download or copy the pre-trained civic ONNX model to the path above. The model
should use 640x640 RGB input and the civic class IDs defined in `class_map.py`.
ONNX inference prefers `CUDAExecutionProvider` and falls back to
`CPUExecutionProvider`.

### Option B: run without ONNX

If `yolov8n-civic.onnx` is absent or cannot be loaded, the service logs a
warning and attempts to load the ultralytics pretrained `yolov8n.pt` weights.
The first fallback startup may download those weights.

If you have a civic `.pt` model, export it with:

```bash
yolo export model=yolov8n-civic.pt format=onnx int8=True imgsz=640
```

Place the resulting file at `ai-service/models/yolov8n-civic.onnx`.

## Run

```bash
cd ai-service
pip install -r requirements.txt
uvicorn main:app --host 0.0.0.0 --port 8000 --reload
```

Health check:

```bash
curl http://localhost:8000/health
```

## Manual test

The image must already exist under the project root. For example:

```bash
curl -X POST http://localhost:8000/analyze \
  -H "Content-Type: application/json" \
  -d '{"filepath": "uploads/test.jpg"}'
```

The response includes the primary civic category, severity from 1 to 5,
confidence, bounding box, raw detections, ELA manipulation status, and whether
EXIF GPS metadata is present.

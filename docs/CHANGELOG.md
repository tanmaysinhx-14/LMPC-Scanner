# Changelog

Newest first. Only user-visible or decision-relevant changes are listed; see
`git log` for the full history.

## 30 August 2026 — OCR overhaul, WebRTC live capture, label repair

### Added

- **Live camera capture (WebRTC)** — [`webrtc_live.py`](../webrtc_live.py). The
  Inspector screen now has an *Upload photographs* / *Live camera (WebRTC)* switch.
  Live YOLO overlay on throttled frames, an in-frame focus/coverage HUD, plain-language
  capture guidance, manual and (optional) automatic capture; the captured frame runs the
  same OCR and rule chain as an upload. Design and security notes:
  [`webrtc_integration.md`](webrtc_integration.md).
- **Pluggable OCR backends** — [`ocr_backends.py`](../ocr_backends.py). RapidOCR
  (PP-OCR weights via ONNX Runtime) is now the default; EasyOCR remains available and
  now runs on the GPU. Switch with `LMPC_OCR_BACKEND=easyocr`.
- **OCR benchmark harness** — [`benchmark_ocr.py`](../benchmark_ocr.py). Every
  backend × crop-variant combination over a dataset split, with per-crop CSV output.
  Results and conclusions: [`ocr_improvement_plan.md`](ocr_improvement_plan.md).
- **Dataset audit / repair / verify tooling** — [`dataset_tools.py`](../dataset_tools.py).
  Found and fixed 512 annotation rows Ultralytics was misreading:
  [`dataset_label_repair.md`](dataset_label_repair.md).
- **Dietary mark in the compliance report** — `rule_engine.validate_dietary_mark`. The
  veg/non-veg mark was detected but discarded; it is now an eighth, advisory report row.
  It never adds to the penalty estimate, because it is an FSS requirement rather than a
  Rule 6 one.
- **OCR diagnostics tab** in the app: per-region YOLO confidence, winning variant,
  OCR confidence, parse result, timing — plus every rejected candidate reading.
- **Automated test suite** — [`tests/`](../tests), 157 tests, `python -m pytest tests/ -q`
  (~10 s). Covers the rule engine (31), crop preparation (26), the live processor (32),
  the OCR backend layer (32) and the dataset tools (36). There was no regression suite
  before this.

### Changed

- **Crop preparation is measured, not blind.** Scaling is driven by estimated text
  height towards the recogniser's ~32 px training height (capped at 6x) instead of a
  fixed 3x resize, plus deskew up to 12°.
- **The dot-matrix variant no longer binarizes.** Over all 121 dot-matrix test crops,
  binarization was the worst of five recipes on both engines; a grayscale morphological
  close replaced it. Field yield on MRP crops roughly doubled and per-crop OCR time fell
  ~15–30%. The variant is now called `dotmatrix` rather than `binary`.
- **Candidate scoring** picks the reading the rule engine can actually parse, not just
  the most confident string (0.50 confidence + 0.35 parse signal + 0.15 yield).
- **`train.py`** refuses to start on unrepaired labels, writes to the run the app
  actually loads, defaults to `imgsz=768`, and documents every augmentation choice.
- **`requirements.txt`** regrouped with the OCR, WebRTC and test dependencies and the
  reason each one is there.

### Fixed

- `clean_ocr_text` rewrote `1 kg` to `1 PKG` (fuzzy keyword match, ratio 0.80) and
  `500ml` to `500m1` (glyph rule), so two lawful net-quantity declarations were reported
  as violations with a ₹25,000 estimate each. Unit symbols are now protected, and the
  numeric part of `5OOml` / `1Okg` is healed correctly instead of being left broken.
- The live processor's capture counter lagged one frame behind the shutter.
- `detect_calls` was inferred by comparing latencies and undercounted whenever two
  consecutive detections took the same time.

### Removed

- `ocr_engine.py`, the legacy PaddleOCR wrapper. It was unused, and keeping it invited
  the original paddle/oneDNN crash back into the environment. `ocr_backends.py` is the
  single OCR entry point.

### Known state

- **The detector has not been retrained** on the repaired labels — the largest available
  accuracy gain, and the user's call to make. Recorded metrics remain precision 0.539,
  recall 0.353, mAP50 0.318.
- Date declarations still read poorly (field yield ≤ 0.08). The crops are 13–22 px tall
  and already at the scaling cap: this is a capture-resolution limit, which is what the
  live guidance exists to fix.
- CER is unmeasured (`-` in benchmark output) until ground-truth transcriptions exist for
  the benchmark crops.
- The WebRTC path has not been exercised in a browser; see
  [`webrtc_integration.md`](webrtc_integration.md) §8 for the one manual check needed.

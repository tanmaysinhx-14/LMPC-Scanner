# Changelog: Legal Metrology Compliance Scanner (SIH26034)

All user-visible features, model/dataset updates, pipeline refactors, rule-engine fixes, and architectural decisions across the repository history[cite: 1, 2].

---

## 4 September 2026 — Multi-Source Camera Ingestion, Test Suite Expansion & Relational RBAC Overhaul

### Added
* **Multi-Source Offline Frame Ingestion (`local_camera.py`, `frame_sources.py`)**:
  * Added dedicated hardware adapters for offline field operations, supporting local webcams, USB-tunnelled MJPEG streams, Wi-Fi camera streams, virtual cameras, and Android ADB screen-recording streams[cite: 2].
  * Engineered frame-drain routines to discard stale queued buffers, prevented shell-injection vulnerabilities in ADB command strings, and added explicit device-release lifecycle management[cite: 2].
* **Test Suite Expansion (157 → 365 Passing Tests)**:
  * Expanded automated test coverage from 157 tests to 365 passing unit and regression tests running in ~16.3 seconds (`python -m pytest tests -q`)[cite: 1, 2].
  * Added test coverage across offline camera drivers, frame buffers, multi-image aggregation logic, browser cookie adapters, session revocation lifecycles, and SQLite/MySQL query translators[cite: 1, 2].
* **Relational XAMPP/MySQL Backend (`db.py`)**:
  * Built an enterprise persistence backend switch activated via `LMPC_DB_BACKEND=mysql` alongside documented `LMPC_MYSQL_*` environment variables[cite: 1, 7].
  * Implemented automated first-connection schema bootstrapping for `Users`, `Scans`, `ScanResults`, `AuditEvents`, and `SessionTokens`[cite: 1, 7].
  * Maintained full parity across existing SQLite offline storage and MySQL relational queries[cite: 1, 7].
* **Refresh-Safe Session Architecture (`session_cookie.py`, `SessionTokens`)**:
  * Implemented secure token-based authentication using random selector/validator pairs[cite: 7].
  * Only the SHA-256 hash of the validator is stored in the database; an opaque bearer token is issued to the client browser in an `lmpc_session` cookie via `streamlit-cookies-controller`[cite: 1, 7].
  * Browser page refreshes restore active user identity and RBAC role without requiring password re-entry[cite: 1, 7].
  * Explicit logout, account deactivation, or session timeout instantly revokes tokens server-side[cite: 1, 7].
* **Multi-Format Evidence Reporting Engine (`reporting.py`)**:
  * Created an in-memory export suite generating PDF summaries (ReportLab primary with Matplotlib fallback), editable DOCX reports with embedded package imagery, CSV tabular exports, and structured JSON files[cite: 2, 8].
  * Stored SHA-256 cryptographic hashes for all evidence images directly in report payloads and database audit logs[cite: 2, 8].
* **Offline Dataset OCR Extraction Suite (`extract_dataset_ocr.py`)**:
  * Added a resumable offline batch extraction tool outputting JSONL records and performance digests across thousands of dataset images[cite: 2].
  * Included low-confidence alerts, panel throughput tracking, and rule-engine verification stats[cite: 2].

### Fixed
* **Argparse Help String Interpolation Crash**:
  * Identified and fixed a format string bug where `extract_dataset_ocr.py --help` threw `ValueError: unsupported format character 't'` caused by raw percentage symbols (e.g., `38.7%`) triggering Python `%` interpolation[cite: 2].

---

## 2 September 2026 — Empirical OCR Benchmarks & Scaling Sweep

### Added
* **50-Crop Multi-Variant Baseline Benchmark**:
  * Executed a 50-crop evaluation across the test split generating 250 backend/variant data points in `runs/ocr_benchmark/`[cite: 2].
  * Evaluated EasyOCR (GPU) vs. RapidOCR (ONNX Runtime CPU) on plain, enhanced (CLAHE), and grayscale dot-matrix variants[cite: 2, 6].
* **150-Crop EasyOCR Cap Sweep**:
  * Evaluated 150 crops across 390 measurements to establish the ceiling of EasyOCR on small statutory text[cite: 2].
  * Confirmed that EasyOCR delivers a 0.175 field yield at 0.15 s/crop on the RTX 3050 GPU, but verified its confidence scores remain uncalibrated (~0.30 regardless of accuracy)[cite: 2, 6].

---

## 31 August – 1 September 2026 — Visual Explainability & Diagnostics Overlay

### Added
* **Post-Analysis Detection Debug View (`debug_view.py`)**:
  * Integrated an annotated detection overlay tab directly into the Inspector UI[cite: 2, 4].
  * Bound box colors to confidence levels: Green ($\ge 0.70$), Orange ($0.40 \le \text{conf} < 0.70$), Red ($< 0.40$), and Gray (unmapped classes like `dietary_symbol_region`)[cite: 4].
  * Rendered dual-label HUDs showing both YOLO bounding-box confidence and OCR recognition confidence per region to separate detection misses from recognition errors[cite: 4].
* **OCR Diagnostics Tab (`app.py`)**:
  * Exposed full pipeline transparency: displays the winning crop variant, OCR confidence, heuristic parsing score, character yield, processing latency, and all rejected candidate readings[cite: 1, 8].

---

## 30 August 2026 — OCR Overhaul, WebRTC Live Guidance & Dataset Repair

### Added
* **Dataset Audit, Repair & Verification Tooling (`dataset_tools.py`)**:
  * Discovered that 512 of 7,121 annotation rows (7.2%) across 234 files were corrupting detector training due to mixed bounding-box and polygon coordinate encodings[cite: 1, 3].
  * Rewrote all 2,064 label files into canonical 5-field box coordinates (`cls cx cy w h`), clamped bounding coordinates, backed up originals to `<split>/labels_original/`, generated an audit manifest, and purged stale cache files[cite: 3].
  * Added 36 isolated unit tests in `tests/test_dataset_tools.py` verifying the repair lifecycle[cite: 1, 3].
* **WebRTC Live Guided Capture (`webrtc_live.py`)**:
  * Added browser-based camera streaming via `streamlit-webrtc`, `aiortc`, and `av`[cite: 1, 9].
  * Designed an asynchronous multi-threaded pipeline: live Laplacian focus scoring runs every frame ($<1\text{ ms}$), YOLO detection runs throttled at 0.30 s intervals (~59 ms), and expensive OCR is deferred until capture[cite: 9].
  * Implemented an in-frame guidance HUD that flags blur, low resolution, or missing panels (e.g., "Only 2 of 7 panels visible — missing: mrp_region, date_region")[cite: 8, 9].
  * Calibrated the focus floor (`SHARPNESS_FLOOR = 300.0`) based on physical FMCG packaging distributions[cite: 8, 9].
  * Implemented auto-capture with a 4-second cooldown and rolling buffer capture that selects the sharpest frame from the last ~10 frames[cite: 8, 9].
* **Pluggable OCR Architecture (`ocr_backends.py`)**:
  * Abstracted OCR engines behind a uniform interface supporting lazy loading, thread locking, and graceful degradation[cite: 6, 8].
  * RapidOCR (PP-OCR v4 via ONNX Runtime) set as default due to calibrated confidence metrics (~0.85)[cite: 6, 8].
  * EasyOCR (CRAFT + CRNN via PyTorch) retained as a GPU alternative switchable via `LMPC_OCR_BACKEND=easyocr`[cite: 6, 8].
* **Advisory Dietary Mark Validation (`rule_engine.py`)**:
  * Integrated HSV color masking to classify green vegetarian vs. brown/red non-vegetarian logos[cite: 8].
  * Output mapped as an advisory compliance row with a ₹0 penalty to avoid conflating FSSAI mandates with LMPC Rule 6 violations[cite: 1, 8].
* **Candidate Scoring Engine (`pipeline.py`)**:
  * Replaced confidence-only selection with an objective evaluation formula:  
    $\text{Score} = 0.50 \times \text{Confidence} + 0.35 \times \text{Parse Signal} + 0.15 \times \text{Yield}$[cite: 1, 6].

### Changed
* **Dynamic Crop Preparation (`preprocessing.py`)**:
  * Replaced fixed $3\times$ resizing with text-height-driven scaling targeting 32 px (capped at $6\times$) and automatic deskewing up to 12°[cite: 1, 6].
* **Grayscale Morphological Dot-Matrix Healer**:
  * Replaced binary adaptive thresholding with CLAHE and an inverted grayscale morphological close (`cv2.morphologyEx`)[cite: 1, 6].
  * Doubled field yield on MRP crops and reduced crop processing time by 15–30%[cite: 1, 6].
* **Training Pipeline Hardening (`train.py`)**:
  * Forced dataset verification before training to block runs on unrepaired labels[cite: 1, 3].
  * Updated training targets to `yolo26n.pt`, default `imgsz=768`, 150 epochs, patience 30, and disabled horizontal/vertical flipping augmentations (text mirroring is invalid for declarations)[cite: 2, 3, 8].

### Fixed
* **Rule Engine Keyword Destruction**:
  * Fixed regex and Levenshtein healer bugs where `1 kg` was converted to `1 PKG` and `500ml` was altered to `500m1`, which had generated false ₹25,000 penalties[cite: 1, 6]. Added token protection guards (`_PROTECTED_TOKENS`) for SI units[cite: 6, 8].
* **Live Shutter Frame Synchronization**:
  * Fixed a bug where the capture counter lagged one frame behind the actual shutter activation[cite: 1].
* **Detector Profiling Collision**:
  * Fixed an issue in `detect_calls` where consecutive frames with identical latencies failed to increment the detection counter[cite: 1].

### Removed
* **Legacy PaddleOCR Wrapper (`ocr_engine.py`)**:
  * Removed unmaintained wrapper code causing oneDNN PIR executor crashes on CPUs and `shm.dll` DLL collisions with PyTorch[cite: 1, 6].

---

## 26–27 August 2026 — Baseline Proof-of-Concept

### Added
* Initial end-to-end prototype using Ultralytics YOLOv8n text region localization[cite: 5, 8].
* Basic deterministic parsing for MRP, Net Quantity, Dates, Manufacturer PIN, and FSSAI license numbers[cite: 5, 8].
* Single-panel analysis workflow with SQLite storage and basic Streamlit role-based UI tabs (Inspector, Verifier, Admin)[cite: 5, 8].
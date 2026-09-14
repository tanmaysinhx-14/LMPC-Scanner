# Software Requirements Specification (SRS): Legal Metrology Packaged Commodity Compliance Scanner

**Project:** SIH26034 Compliance Scanner  
**Problem Statement:** Software System to check compliance of Packaged Commodities under Legal Metrology (Packaged Commodities) Rules, 2011 by scanning products, images and labels[cite: 2, 8].  
**Target Beneficiary:** Ministry of Consumer Affairs, Food & Public Distribution / Legal Metrology Enforcement Officers[cite: 5, 8].

---

## 1. System Overview & Scope

The system functions as an officer-facing, edge-deployable decision-support tool to verify statutory label compliance on pre-packaged retail goods[cite: 2, 5]. It captures physical package packaging via upload or camera streams, identifies mandatory declaration zones using computer vision, extracts text through Optical Character Recognition (OCR), and evaluates legal declarations against deterministic rules mapped to Rule 6 of the Legal Metrology (Packaged Commodities) Rules, 2011[cite: 2, 5]. 

The system operates as a **decision-support platform with a human verifier in the loop**, generating audit-ready forensic evidence rather than issuing autonomous legal notices[cite: 2].

---

## 2. Functional Requirements (FR)

### FR-1: Image Ingestion & Input Capture
* **FR-1.1 Multi-Panel Image Upload**: The system shall accept simultaneous batch uploads of high-resolution product panel photographs in JPG, JPEG, and PNG formats[cite: 2, 8].
* **FR-1.2 WebRTC Live Browser Streaming**: The system shall ingest live video feeds directly from desktop webcams or mobile browsers over WebRTC protocols via `streamlit-webrtc` and `aiortc`[cite: 5, 9].
* **FR-1.3 Offline Multi-Source Camera Ingestion**: The system shall support hardware capture from local USB webcams, virtual cameras, Wi-Fi MJPEG streams, and Android ADB screen-recording streams without requiring internet connectivity[cite: 2].
* **FR-1.4 Real-Time Focus & Sharpness Meter**: During live preview, the system shall compute an in-frame focus score every frame using Laplacian variance over the central 60% of the image[cite: 9]. It shall evaluate against a calibrated threshold (`SHARPNESS_FLOOR = 300.0`)[cite: 8, 9].
* **FR-1.5 Guided Field Capture**: The system shall render real-time in-frame HUD guidance flagging blur, low resolution, and specific missing statutory panels to instruct inspectors how to orient the package[cite: 8, 9].
* **FR-1.6 High-Fidelity Frame Retention**: Upon manual or automatic capture, the system shall buffer the last ~10 frames, select the sharpest recent frame, and encode it as a quality-95 JPEG[cite: 8, 9].

### FR-2: Region of Interest (ROI) Localization
* **FR-2.1 Text Region Detection**: The system shall identify statutory packaging zones using a custom-trained anchor-free Ultralytics YOLOv8n detector (`yolo26n.pt`)[cite: 2, 5, 8].
* **FR-2.2 Target Class Mapping**: The detector shall identify seven canonical region classes:
  1. `generic_name_region`[cite: 2, 3]
  2. `net_quantity_region`[cite: 2, 3]
  3. `mrp_region`[cite: 2, 3]
  4. `date_region`[cite: 2, 3]
  5. `manufacturer_region`[cite: 2, 3]
  6. `consumer_care_region`[cite: 2, 3]
  7. `dietary_symbol_region`[cite: 2, 3]
* **FR-2.3 Live Preview Throttling**: Live detection passes (`detect_only()`) shall be throttled to 0.30-second intervals to maintain responsive video playback without starving CPU/GPU resources[cite: 8, 9].
* **FR-2.4 Adaptive Bounding Box Padding**: The pipeline shall expand detected bounding boxes by a 4% margin prior to cropping to preserve edge-printed statutory text[cite: 2, 8].

### FR-3: Image Preprocessing & OCR Extraction
* **FR-3.1 Dynamic Crop Rescaling**: Cropped regions shall be dynamically scaled toward a target text stroke height of ~32 pixels (capped at 6x magnification) rather than using a static resize factor[cite: 1, 8].
* **FR-3.2 Hand-Held Deskewing**: The system shall compute dominant text skew angles and apply affine transformations to deskew crops by up to 12 degrees[cite: 1, 8].
* **FR-3.3 Multi-Variant Generation**: The pipeline shall construct three distinct preprocessing variants for candidate scoring:
  * `plain`: Deskew and dynamic scaling only[cite: 2, 6].
  * `enhanced`: Contrast Limited Adaptive Histogram Equalization (CLAHE) applied to the LAB luminance channel[cite: 2, 6].
  * `dotmatrix`: CLAHE combined with an inverted grayscale morphological close (`cv2.morphologyEx`) tailored specifically for dotted inkjet text[cite: 2, 6].
* **FR-3.4 Pluggable OCR Architecture**: The system shall support selectable OCR backends via `ocr_backends.py`:
  * `rapidocr` (Default): PP-OCR v4 executed via ONNX Runtime on the CPU, providing calibrated confidence metrics (~0.85)[cite: 6, 8].
  * `easyocr`: CRAFT + CRNN executed via PyTorch on the GPU, providing accelerated per-crop inference (~0.15–0.23 s)[cite: 2, 6].
* **FR-3.5 Candidate Reading Selection**: Winning crop readings shall be selected using a multi-factor score:
  $$\text{Score} = 0.50 \times \text{OCR Confidence} + 0.35 \times \text{Parse Signal} + 0.15 \times \text{Character Yield}$$[cite: 1, 6]
* **FR-3.6 Multi-Panel Text Aggregation**: The pipeline shall aggregate, sort into human reading order, and de-duplicate text across sequential panels belonging to a single package[cite: 2, 6, 8].

### FR-4: Statutory Compliance Rule Engine
* **FR-4.1 Deterministic Evaluation**: Compliance checks shall execute deterministically via regex parsers and Levenshtein string metrics without invoking non-deterministic LLM models[cite: 2, 5, 8].
* **FR-4.2 Rule 6 Verification Matrix**:
  * **Product Identity**: Verify the presence of a readable generic or common commodity name[cite: 2, 8].
  * **Net Quantity (Rule 6(1)(c), Rule 13)**: Parse numeric values and SI units (g, kg, ml, l, m, cm); flag prohibited pluralized symbols (e.g., "kgs") and non-standard expressions; handle multi-pack declarations[cite: 2, 5, 8].
  * **MRP & Pricing (Rule 6(1)(e))**: Validate presence of currency symbols (₹, Rs, INR), numeric price, and fuzzy match the statutory phrase "inclusive of all taxes" ($\ge 80\%$ similarity threshold); extract unit sale price (USP) if declared[cite: 2, 5, 8].
  * **Date of Mfg/Packing/Import (Rule 6(1)(d), Rule 6(1)(da))**: Parse MM/YYYY, DD/MM/YYYY, ISO, and alphabetic formats (e.g., "15 JUL 26"); evaluate "Best Before" / "Use By" duration language[cite: 2, 5, 8].
  * **Batch / Lot Traceability**: Extract alphanumeric batch identifiers while stripping trailing production timestamps[cite: 2, 8].
  * **Manufacturer / Packer / Importer (Rule 6(1)(a))**: Detect entity role keywords and validate the presence of a valid six-digit Indian postal PIN code[cite: 2, 5, 8].
  * **Consumer Redressal & FSSAI (Rule 6(2))**: Verify a 10-digit telephone/toll-free number, valid email address, and 14-digit FSSAI registration number[cite: 2, 5, 8].
* **FR-4.3 Advisory Dietary Marking**: Classify vegetarian/non-vegetarian symbols via HSV color masking; display the finding as an advisory row without adding to LMPC penalty tallies[cite: 1, 8].
* **FR-4.4 Penalty Estimation**: Assign a prototype first-offense penalty of ₹25,000 per failed statutory clause under Section 36 of the Legal Metrology Act, 2009[cite: 2, 5, 8].

### FR-5: Evidence Management, Audit & Reporting
* **FR-5.1 Cryptographic Image Hashing**: Every submitted panel image shall be hashed using SHA-256 upon ingestion to guarantee chain-of-custody integrity[cite: 2, 8].
* **FR-5.2 Multi-Format Export**: The system shall compile normalized inspection payloads into downloadable formats directly in memory:
  * **PDF**: Formal summary and evidence sheets with visual inspection crops (ReportLab / Matplotlib fallback)[cite: 2, 8].
  * **DOCX**: Editable Microsoft Word compliance report with embedded package photographs[cite: 2, 8].
  * **CSV**: Tabular data export for spreadsheet workflows[cite: 2, 8].
  * **JSON**: Schema-structured export for downstream departmental integration[cite: 2, 8].
* **FR-5.3 Audit Event Trail**: All user actions (scan submission, approval, override, role modification, session revocation) shall be committed to an append-only `AuditEvents` table[cite: 1, 7, 8].

### FR-6: Role-Based Access Control (RBAC) & Sessions
* **FR-6.1 Role Hierarchy**:
  * **Inspector**: Capture images, execute pipeline analysis, inspect OCR diagnostics, review reports, and submit scans as `PENDING`[cite: 2, 7].
  * **Verifier**: Inspect pending queues, review visual evidence and rule breakdowns, and formally `APPROVE` or `OVERRIDE` the scan (overrides require mandatory written justification)[cite: 2, 7].
  * **Admin**: View system analytics, total violations, failure rates, audit logs, and manage user account activations[cite: 2, 7].
* **FR-6.2 Refresh-Safe Browser Sessions**: Authenticated sessions shall persist across browser page reloads via server-side selector/validator tokens stored in `SessionTokens` and mapped to an opaque `lmpc_session` cookie[cite: 1, 7].

---

## 3. Non-Functional Requirements (NFR)

### NFR-1: Performance & Latency
* **NFR-1.1 Real-Time Preview**: The live WebRTC video stream shall process camera frames at 30 FPS, with focus scoring overhead remaining below 1 ms per frame[cite: 5, 9].
* **NFR-1.2 Detection Speed**: Single-image YOLO region localization shall complete within $\le 60\text{ ms}$ at `imgsz=768` on local GPU hardware[cite: 8, 9].
* **NFR-1.3 Memory Boundary**: Peak VRAM utilization shall remain strictly constrained under 4GB/6GB to allow reliable execution on mid-range laptops (NVIDIA RTX 3050 mobile)[cite: 2, 5, 8].
* **NFR-1.4 Pipeline Execution**: Deferred full OCR and rule evaluation on a captured/uploaded panel set shall execute within 5–15 seconds total[cite: 6, 8].

### NFR-2: Reliability & Edge Resilience
* **NFR-2.1 Offline Operation**: The system must function entirely without external internet connectivity using local SQLite (`lmpc_scanner.db`) and local hardware camera inputs[cite: 2, 8].
* **NFR-2.2 Pluggable Degradation**: If an OCR backend fails to initialize or encounters a corrupted crop, the system shall contain the exception, log diagnostics, and fallback to an available backend without crashing the inspection session[cite: 6, 8].
* **NFR-2.3 Thread Safety**: All shared model instances, camera buffers, and database connections across Streamlit scripts and `aiortc` threads must be locked and thread-safe[cite: 6, 8, 9].

### NFR-3: Security & Cryptography
* **NFR-3.1 Password Hashing**: User authentication passwords shall be stored as salted PBKDF2-HMAC-SHA256 hashes using 310,000 iterations[cite: 2, 8].
* **NFR-3.2 Cookie Security**: The `lmpc_session` cookie must carry only an opaque bearer token; no plain usernames, passwords, or role credentials shall reside on the client browser[cite: 7]. Cookies must support the `Secure` flag over HTTPS[cite: 7].
* **NFR-3.3 WebRTC Surface Security**: Browser video capture must operate under TLS (HTTPS) or `localhost` to prevent stream interception[cite: 8, 9].

### NFR-4: Database Portability
* **NFR-4.1 Pluggable Persistence**: The application must run interchangeably against SQLite (via WAL mode) or XAMPP/MySQL via the `LMPC_DB_BACKEND` environment switch without altering query APIs[cite: 1, 7].

---

## 4. Requirement Traceability Matrix (RTM)

| Requirement ID | Module / File | Verification Method | Automated Test Coverage |
|---|---|---|---|
| **FR-1.1, FR-1.3** | `app.py`, `local_camera.py`, `frame_sources.py` | Integration Test | `tests/test_frame_sources.py`[cite: 2] |
| **FR-1.2, FR-1.4, FR-1.5** | `webrtc_live.py` | Headless Simulated WebRTC | 32 tests in `tests/test_webrtc_live.py`[cite: 1, 9] |
| **FR-2.1, FR-2.2, FR-2.4** | `pipeline.py` | Pytest Unit / Model Mock | `tests/test_pipeline.py`[cite: 2] |
| **FR-3.1, FR-3.2, FR-3.3** | `preprocessing.py` | OpenCV Synthetic Image Tests | 26 tests in `tests/test_preprocessing.py`[cite: 1] |
| **FR-3.4, FR-3.5** | `ocr_backends.py` | Backend Mock & Benchmark | 32 tests in `tests/test_ocr_backends.py`[cite: 1, 6] |
| **FR-4.1, FR-4.2, FR-4.4** | `rule_engine.py` | Rule Engine Test Cases | 31 tests in `tests/test_rule_engine.py`[cite: 1] |
| **FR-4.3** | `preprocessing.py`, `rule_engine.py` | HSV Mask Unit Tests | `tests/test_preprocessing.py`[cite: 2] |
| **FR-5.1, FR-5.2** | `reporting.py` | In-Memory Document Export Tests | `tests/test_reporting.py`[cite: 2] |
| **FR-5.3, FR-6.1** | `db.py` | SQLite/MySQL Role Enforcement | `tests/test_db.py`[cite: 1] |
| **FR-6.2** | `session_cookie.py`, `db.py` | Session Lifecycle Tests | `tests/test_db.py`[cite: 1] |
| **Dataset Tools** | `dataset_tools.py` | Audit/Repair Round-Trip | 36 tests in `tests/test_dataset_tools.py`[cite: 1, 3] |

---

## 5. Current Gaps & Known Constraints

1. **Detector Weights Baseline**: The served model checkpoint (`runs/detect/train/weights/best.pt`) was trained prior to label repair; current recorded metrics are precision 0.539, recall 0.353, and mAP50 0.318[cite: 2, 3]. Retraining via `train.py` on the verified 7,121-box dataset is required[cite: 2, 3].
2. **Date Print Resolution Limits**: Tiny inkjet dates (13–22 px) frequently hit the 6x crop scaling limit, causing poor OCR yield ($\le 0.08$) unless captured close-up using the sharpness guidance HUD[cite: 1, 6].
3. **Dedicated Batch Class**: The YOLO model currently lacks an independent `batch_number_region` label; batch data is parsed from date or manufacturer regions[cite: 2, 3].
4. **Physical Font Height Compliance**: The system inspects crop text stroke height for OCR optimization, but does not yet deliver a legally calibrated millimeter measurement of physical font height on the retail package[cite: 2].
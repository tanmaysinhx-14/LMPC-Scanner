# SIH26034 LMPC Compliance Scanner

## Project status report

**Report date:** 30 August 2026  
**SIH problem:** SIH26034  
**Problem owner:** Ministry of Consumer Affairs, Food & Public Distribution  
**Problem statement:** “Software System to check compliance of Packaged Commodities under Legal Metrology (Packaged Commodities) Rules, 2011 by scanning products, images and labels.”  
**Repository classification:** Software; Agriculture, FoodTech & Rural Development  
**Deadline recorded in the repository listing:** 20 September 2026

> **Superseded status note (4 September 2026):** This report is the 30 August
> baseline. The current consolidated assessment, including XAMPP/MySQL support,
> refresh-safe RBAC/session persistence and the 365-test result, is
> [`SIH26034_CONSOLIDATED_TECHNICAL_REPORT_2026-09-04.md`](SIH26034_CONSOLIDATED_TECHNICAL_REPORT_2026-09-04.md).
> The database setup and refresh verification procedure is in
> [`XAMPP_MYSQL_RBAC_SETUP.md`](XAMPP_MYSQL_RBAC_SETUP.md).

The SIH listing and the longer concept note are stored in [`archives/SIH PS 2026.md`](archives/SIH%20PS%202026.md) and [`archives/Legal Metrology Prototype Approach.md`](archives/Legal%20Metrology%20Prototype%20Approach.md). The date and classification above are based on the repository copy and should be confirmed against the official SIH portal before submission.

## 1. Executive summary

This project is a proof-of-concept compliance scanner for Indian FMCG packaging. It is designed as an inspector-side tool: an officer photographs a product's front, back, or side panels — by upload or through the live camera — and the system detects likely declaration regions, reads their text, validates the declarations with deterministic rules, and stores the resulting audit record for verification.

The current implementation combines:

- A custom Ultralytics YOLO detector for locating packaging regions.
- A pluggable OCR layer ([`ocr_backends.py`](ocr_backends.py)) with two interchangeable engines: RapidOCR (PP-OCR weights through ONNX Runtime, the default) and EasyOCR on the GPU. Switchable with `LMPC_OCR_BACKEND`, chosen from a measured benchmark rather than preference.
- Measured OpenCV crop preparation for low-contrast, thin, and dot-matrix text: text-height-driven scaling, deskew, and three competing crop variants scored against the rule engine.
- A deterministic regular-expression and Levenshtein-based rule engine.
- A Streamlit interface with Inspector, Verifier, and Admin roles, and a live WebRTC capture mode with a detection overlay and capture-quality guidance.
- SQLite persistence for scans, extracted result summaries, statuses, images, and analytics.
- Sequential multi-image aggregation so several product panels become one report.
- A regression suite of 157 tests over the rule engine, crop preparation, the OCR backend layer, the live processor and the dataset tools.

The build is suitable for a hackathon demonstration and technical validation. It is not yet a legally authoritative enforcement system: model accuracy, OCR reliability, statutory applicability, database security, and human approval workflows require further validation.

The change history since the 27 August report is in [`docs/CHANGELOG.md`](docs/CHANGELOG.md), with the reasoning and measurements in [`docs/ocr_improvement_plan.md`](docs/ocr_improvement_plan.md), [`docs/webrtc_integration.md`](docs/webrtc_integration.md) and [`docs/dataset_label_repair.md`](docs/dataset_label_repair.md).

**The single highest-value action outstanding is retraining the detector on the repaired labels** (`python train.py`). 512 of 7121 annotation rows were being misread by the data loader; they are now fixed on disk, but the checkpoint the app serves was trained before the repair and still carries recall 0.353. That retrain has deliberately not been launched — it is hours of GPU time on the user's machine.

## 2. Problem context

Pre-packaged goods sold in India must expose important information to consumers and enforcement officers. Depending on the product and its category, labels may include the product or generic name, net quantity, Maximum Retail Price (MRP), manufacturing or packing information, expiry or best-before information, manufacturer or marketer details, consumer-care contact details, FSSAI information, and dietary symbols.

Manual inspection is slow and inconsistent when packaging contains small type, reflective film, curved surfaces, colored backgrounds, damaged printing, or inkjet and dot-matrix declarations. The practical problem is therefore a chain of related tasks:

1. Locate declaration regions on a product image.
2. Extract text from each region despite optical noise.
3. Normalize common OCR errors without inventing declarations.
4. Apply repeatable checks against the relevant LMPC requirements.
5. Give an officer an explainable result rather than an opaque model score.
6. Preserve images and results for a verifier and later audit.

The intended users are Legal Metrology inspectors, compliance verifiers, administrators, and potentially manufacturers or retailers using the tool for pre-market quality checks. The current interface is intentionally inspector-oriented and does not assume that a regulated business will voluntarily operate the enforcement workflow.

## 3. Current architecture

```text
Uploaded product panels          Live camera (WebRTC)
        |                               |
        |                    focus score every frame
        |                    YOLO overlay every 0.30 s
        |                    in-frame guidance HUD
        |                               |
        |                    capture -> sharpest recent frame (JPEG q95)
        |                               |
        +---------------+---------------+
                        v
              Sequential image decoding
                        |
                        v
              YOLO region detection (conf 0.20, imgsz 768)
                        |
                        v
              Crop + 4% margin, measure text height,
              scale towards 32 px (cap 6x), deskew <= 12 deg
                        |
        +---------------+---------------+
        |               |               |
     plain          enhanced        dotmatrix           (MRP/date/batch only)
   deskew+scale     CLAHE on L      CLAHE + grayscale
                                    morphological close
        |               |               |
        +---------------+---------------+
                        v
              OCR backend (rapidocr default | easyocr)
                        |
                        v
              Candidate scoring: 0.50*confidence
                                + 0.35*parse signal
                                + 0.15*character yield
                        |
                        v
              Seven-class text aggregation across all panels
                        |
                        v
              Regex parsers + Levenshtein fuzzy matching
                        |
                        v
              Structured PASS/FAIL report and penalty estimate
              (+ advisory dietary-mark row)
                        |
                        +--> Inspector review and submission
                        +--> OCR diagnostics tab (every rejected candidate)
                        +--> SQLite audit record
                        +--> Verifier approval or override
                        +--> Admin analytics
```

The image path is intentionally sequential. Images are not batched into one YOLO or OCR call, which reduces peak memory pressure on the 6 GB VRAM machine. The three crop variants per region are the deliberate exception: they are cheap, and the pipeline needs more than one reading to choose between.

Only detection and the focus score run on live frames. OCR costs 0.23–1.42 s per crop against detection's 59 ms, so continuous live OCR would produce a slideshow; the full chain runs once, on capture. The reasoning is in [`docs/webrtc_integration.md`](docs/webrtc_integration.md) §2.

## 4. What has been built

### 4.1 `pipeline.py`

The pipeline is the detector/OCR integration layer.

- Loads `runs/detect/train/weights/best.pt` lazily through Ultralytics `YOLO`, under a lock, with the path overridable by `LMPC_MODEL_PATH`.
- Never names an OCR engine. It asks [`ocr_backends.py`](ocr_backends.py) for a backend, so the engine can be swapped with `LMPC_OCR_BACKEND` without touching pipeline code.
- Accepts a list of image bytes and processes each image one at a time.
- Decodes image bytes directly into an OpenCV BGR array without writing OCR intermediates to disk.
- Reads YOLO class names and bounding boxes dynamically; there are no hardcoded image coordinates. Detection runs at confidence 0.20, IoU 0.50, `imgsz=768`, all environment-overridable.
- Maps detector aliases such as `mrp_region`, `date_region`, and `manufacturer_region` to canonical report classes.
- Pads each box by 4% before cropping, because statutory print frequently sits at the very edge of a detected panel.
- Builds two or three prepared variants per crop and runs OCR on each, then picks a winner with `_candidate_score`: `0.50 × OCR confidence + 0.35 × parse signal + 0.15 × character yield`. The parse term is what makes this different from taking the most confident string — a reading is preferred when the rule engine can actually use it.
- Keeps every rejected candidate (variant, text, confidence, parse result, score, latency) and surfaces it in the app's **OCR diagnostics** tab, so a wrong reading can be explained rather than guessed at.
- Exposes `detect_only()` for the live view, which runs detection without any OCR.
- Aggregates text from repeated detections and multiple uploaded panels into one dictionary.
- Returns the seven canonical input keys:

  `product_name`, `net_quantity`, `mrp_declaration`, `date_declarations`, `batch_number`, `manufacturer_details`, and `consumer_care_fssai`.

`ocr_engine.py`, the legacy PaddleOCR wrapper, has been **deleted**. It was unimported, and keeping it in the tree invited the original paddle/oneDNN crash back into the environment. [`ocr_backends.py`](ocr_backends.py) is now the single OCR entry point.

### 4.2 `preprocessing.py` and `ocr_backends.py`

`preprocessing.py` prepares crops; `ocr_backends.py` recognises them.

The prototype fed every crop through a blind `cv2.resize(fx=3, fy=3)`. That is too little for a 7-pixel date print and wasteful for a 200-pixel manufacturer block, so crop preparation is now measured: `estimate_text_height` reads the crop's own stroke height, `scale_for_ocr` scales towards the ~32 px the recognisers were trained on (capped at 6×), and `deskew` corrects up to 12° of hand-held rotation.

`build_variants` then produces the readings the pipeline scores against each other:

| Variant | Recipe | Used for |
|---|---|---|
| `plain` | deskew + scale only | all classes |
| `enhanced` | CLAHE on the LAB luminance channel | all classes |
| `dotmatrix` | CLAHE + **grayscale** morphological close | MRP / date / batch only |

`heal_dot_matrix_text` previously binarized the crop (CLAHE → Gaussian adaptive threshold → close). Measured over **all 121 dot-matrix crops in the test split** against four alternatives, binarization was the worst recipe on both engines — it discards the grey levels the recogniser uses and roughly halved the characters returned (7.0/8.9 characters against 13.7/13.2 for a grayscale close). It now applies CLAHE and closes the inverted grayscale image, so dots join without ink being eaten. Field yield on MRP crops roughly doubled and per-crop OCR time fell 15–30%. The full comparison table is in [`docs/ocr_improvement_plan.md`](docs/ocr_improvement_plan.md) §4.

`ocr_backends.py` wraps each engine behind one interface, loads it lazily, serialises calls through a lock (Streamlit is multi-threaded and neither engine is thread-safe), and contains failures so a bad crop degrades a single region rather than crashing a scan. `resolve_backend` falls back to whichever engine actually loads — the app must survive one engine's native dependencies breaking, which is precisely how PaddleOCR was lost here.

| Backend | Engine | Notes |
|---|---|---|
| `rapidocr` | PP-OCR v4 via ONNX Runtime | default; calibrated confidence (~0.85) |
| `easyocr` | CRAFT + CRNN via torch | GPU-enabled on the RTX 3050; ~5× faster per crop |

Both are kept, and the choice is evidence-based rather than aesthetic: RapidOCR produced the best reading on 16 of 30 benchmark crops and reports usable confidence, which is what makes confidence-gated human review possible; EasyOCR is the switch to flip when latency matters. See [`docs/ocr_improvement_plan.md`](docs/ocr_improvement_plan.md) §6.

`classify_dietary_symbol` uses HSV color masks to distinguish likely green vegetarian and brown/red non-vegetarian symbols. This **is now wired into the report path** as an eighth, advisory row (`rule_engine.validate_dietary_mark`); it never contributes to the penalty estimate, because the veg/non-veg mark is an FSS Regulations requirement rather than an LMPC Rule 6 one.

### 4.3 `rule_engine.py`

The rule engine is deterministic and explainable. It does not use a transformer, an LLM, or any additional NLP model.

The public method is:

```python
validate_compliance(aggregated_data, dietary_status=None)
```

It produces seven structured report sections. The external detector names and the report names are deliberately separated so the rule engine can continue working when a detector class is renamed.

| Report section | Main extraction and validation behavior |
|---|---|
| `product_name` | Removes common field labels and checks for a usable identity string. |
| `net_quantity` | Extracts value and unit, supports grams, kilograms, milliliters, liters, and related SI forms, detects pluralized or invalid symbols, and parses multi-pack expressions such as `126 g (2 PACKS x 63 g)`. OCR `q` and `9` can be interpreted as `g` in the quantity context. |
| `pricing` | Extracts MRP and optional unit sale price, supports INR, `Rs`, `MRP`, `USP`, and known OCR fallbacks such as `#`, `U`, and `V`, accepts a space in place of a missing decimal point, and uses Levenshtein similarity against `inclusive of all taxes`. |
| `dates` | Parses numeric, ISO, month-name, and alphabetic forms such as `15 JUL 26`, associates them with MFG/PKD/EXP/Use By/Best Before prefixes, and recognizes duration declarations such as `BEST BEFORE 90 DAYS FROM MANUFACTURING`. |
| `batch_details` | Extracts a batch or lot body, removes a timestamp such as `07:53`, and keeps the remaining alphanumeric batch code. |
| `manufacturer_info` | Detects manufacturer/marketer wording and isolates a six-digit Indian PIN code. |
| `compliance_and_support` | Extracts a 14-digit FSSAI license number, email address, and consumer-care or toll-free number, including common run-on-domain OCR artifacts. |

Each section contains `extracted_text`, parsed fields where applicable, `is_compliant`, a human-readable `reason`, and `penalty_amount`. A failed section receives the configured first-offense estimate of ₹25,000. The estimate is a prototype reporting value and must not be treated as a final legal assessment without checking the current Act, Rules, notifications, product category, and enforcement facts.

`validate_dietary_mark` adds an eighth, **advisory** row from the HSV symbol classification. Its penalty is always zero: the veg/non-veg mark is required by the FSS (Packaging and Labelling) Regulations, not by LMPC Rule 6, so folding it into an LMPC penalty estimate would misstate the finding.

#### Two healer bugs that were manufacturing violations

`clean_ocr_text` repairs mangled keywords (`trur|` → `OUR`, `u5` → `US`) and glyph confusions (`2OO9` → `2009`). Both healers were too aggressive and were destroying lawful declarations:

| Input | Old output | Consequence |
|---|---|---|
| `NET QUANTITY 1 kg` | `NET QUANTITY 1 PKG` | no quantity parsed → ₹25,000 penalty on a compliant pack |
| `500ml` | `500m1` | no quantity parsed → ₹25,000 penalty on a compliant pack |

The first was a fuzzy match: `Levenshtein.ratio("kg", "pkg")` is 0.80, above the 0.68 short-token threshold, so a unit symbol was rewritten into the "packed on" keyword. The second was the `l` → `1` glyph rule firing on the unit itself. Both healers now consult a guard list of unit symbols (`_PROTECTED_TOKENS`) and a number-plus-unit pattern (`_NUMBER_WITH_UNIT`) that heals the numeric head while leaving the unit alone. This also *improved* healing: `5OOml` → `500ml` and `1Okg` → `10kg` now parse, where previously they were left broken.

The non-standard `gms` is deliberately **not** healed — LMPC Rule 6 requires SI symbols, so `200 gms` must reach the invalid-unit check intact and be reported. All of this is pinned by [`tests/test_rule_engine.py`](tests/test_rule_engine.py).

### 4.4 `app.py` and `webrtc_live.py`

The Streamlit application offers **two capture sources**, selected on the Inspector screen: *Upload photographs* (the original path, unchanged) and *Live camera (WebRTC)*.

#### Inspector

- Logs in using the Inspector role.
- Uploads multiple JPG, JPEG, or PNG product-panel images at once, **or** streams the device camera.
- Displays a thumbnail grid.
- Runs sequential detection, OCR, aggregation, and validation.
- Displays extracted text, parsed values, PASS/FAIL state, and penalty estimates.
- Exposes an **OCR diagnostics** tab: per-region YOLO confidence, winning variant, OCR confidence, parse result, timing, and every rejected candidate reading.
- Captures optional inspection context as evidence notes.
- Offers downloadable PDF, editable DOCX, CSV, and JSON reports before and after submission.
- Persists the scan and associated panel images with `PENDING` status.

#### Live camera mode ([`webrtc_live.py`](webrtc_live.py))

Built to the scope the jury asked for — a live detection overlay with guided capture, with the full OCR and rule chain running on the captured frame rather than continuously:

- Browser camera → `aiortc` → `LiveScanProcessor.recv()`.
- Every frame: a focus score (Laplacian variance over the centre 60% of the frame).
- Every 0.30 s: `pipeline.detect_only()` → live boxes and a panel count.
- An in-frame HUD (focus / panels / fps) plus a single plain-language hint, worst problem first: detector unavailable → blurred → *"Only N of 7 panels visible … missing: …"* → low camera resolution → *"Good frame — capture now."* Naming the missing classes tells the inspector which way to turn the pack.
- Manual capture is never gated on quality (a soft frame is still evidence); optional auto-capture fires only when focus ≥ floor **and** enough panels are visible, with a 4 s cooldown.
- Either way the frame handed onward is the **sharpest of the last ~10 frames**, JPEG-encoded at quality 95 — the same currency the upload path uses, so a live capture and an uploaded photograph are indistinguishable downstream and produce the same evidence record.
- `SHARPNESS_FLOOR = 300.0` is calibrated, not invented: over 40 test-split photographs of real packs the focus score runs p10 351 / p50 1099 / p90 3852, while the same images at 1.6σ Gaussian blur have a median of 19. The slider exposes 50–1500 for a different camera.
- `recv()` runs on aiortc's worker thread while Streamlit reruns the script on the main thread, so every field the UI reads is written under a lock and handed out as an immutable copy.

Guidance is the accuracy feature here, not decoration: the measured ceiling on date and MRP reading is capture resolution (those crops arrive 13–22 px tall and are already at the 6× scaling cap), and the only thing that changes that number is a better photograph. Design notes and the security requirements are in [`docs/webrtc_integration.md`](docs/webrtc_integration.md).

**Security note.** WebRTC signalling has no authentication of its own — `streamlit-webrtc` negotiates the peer connection over the Streamlit session, so the only thing between a camera stream and the network is the app's own login. That is acceptable on `localhost`; before exposing it on a tunnel or a server, terminate TLS (browsers refuse `getUserMedia` on a non-secure non-localhost origin anyway), keep it behind the login, and replace the seeded demo accounts. The public STUN server is offered as an unchecked, opt-in checkbox because contacting it discloses the client's IP address to a third party; host-candidate-only negotiation works on a LAN.

#### Verifier

- Fetches pending records from SQLite.
- Opens each scan in an expander.
- Displays all associated image paths/images and the extracted result table.
- Can download the evidence-backed report in multiple formats.
- Can approve the violation report or override/reject it; an override requires a written reason.
- Can search reviewed history by scan ID, inspector, product, or evidence note.

#### Admin

- Shows total scans.
- Shows total violations.
- Shows compliance failure rate.
- Shows total potential penalty revenue based on failed sections.
- Displays raw scan and scan-result tables for audit visibility.
- Searches the full inspection repository by status and text fields.
- Manages users and active/inactive account status.
- Reviews immutable-style workflow events recorded in the `AuditEvents` table.

The demonstration accounts are seeded by the database module:

| Username | Role | Demo password |
|---|---|---|
| `inspector` | Inspector | `pass123` |
| `verifier` | Verifier | `pass123` |
| `admin` | Admin | `pass123` |

These credentials are for local demonstration only and must be replaced before any deployment.

### 4.5 `reporting.py`

The reporting module turns a scan record into a portable evidence package.

- Builds a normalized report payload containing scan metadata, result reasons, parsed values, evidence paths, image hashes, and review notes.
- Generates UTF-8 CSV and structured JSON exports for editing, integration, and downstream analysis.
- Generates an editable DOCX report with a compliance table and embedded evidence images when available.
- Generates a PDF report with the same result summary and evidence pages. It uses ReportLab when installed and falls back to Matplotlib in the current environment, where ReportLab is unavailable.
- Keeps report generation in memory for Streamlit downloads; no report temporary file is required.

### 4.6 `db.py`

The database uses `lmpc_scanner.db` and creates:

- `Users`: user identity, password representation, and role.
- `Scans`: inspector, serialized list of image paths, status, and timestamp.
- `ScanResults`: rule class, extracted text, parsed data, explanation, compliance flag, and penalty amount.
- `AuditEvents`: actor, scan, action, details, and timestamp for workflow traceability.

The database enables foreign keys and WAL mode. Passwords are seeded and verified using salted PBKDF2-HMAC-SHA256 rather than storing newly created passwords in plaintext. Multi-image paths are serialized as JSON in the existing `image_path` column so a schema migration is not required. Legacy comma-separated values can also be read. Evidence files are hashed with SHA-256 at submission time. Review metadata records the reviewer, decision time, and decision reason.

Implemented operations include authentication, scan insertion, pending-scan retrieval, scan search, status updates, analytics, user creation, account activation/deactivation, audit-event retrieval, and raw audit-table retrieval. Database-side role checks prevent Inspector accounts from submitting as another role, reviewing scans, or accessing another Inspector’s scan history.

### 4.7 Training, the dataset, and the label defect

The detector training entry point is [`train.py`](train.py). It uses `yolo26n.pt` with the Roboflow-exported dataset described by [`packaged-commodity-dataset/data.yaml`](packaged-commodity-dataset/data.yaml).

The dataset figures quoted in earlier drafts of this report (1,914 training images, a 4 GB GPU) were wrong. The verified figures, counted from the files themselves by [`dataset_tools.py`](dataset_tools.py):

| | value |
|---|---|
| train / valid / test images | 1905 / 79 / 80 |
| annotation rows | 7121 across 2064 label files |
| detector classes (7) | `consumer_care_region`, `date_region`, `dietary_symbol_region`, `generic_name_region`, `manufacturer_region`, `mrp_region`, `net_quantity_region` |
| GPU | NVIDIA RTX 3050, 6 GB |

#### The label defect, and its repair

The export contained **two different label encodings mixed inside the same files**: 5-field detection rows (`cls cx cy w h`) and variable-length polygon rows (`cls x1 y1 x2 y2 …`). Ultralytics decides *per file* whether a label file is boxes or segments. In a mixed file the rows in the minority encoding are misread — a 5-field detection row parsed as a polygon becomes a degenerate two-point shape — so the box the detector trains on is not the box the annotator drew, and nothing warns you.

| | files | rows | bbox rows | polygon rows | mixed files | rows the loader misreads |
|---|---:|---:|---:|---:|---:|---:|
| train | 1905 | 6471 | 1041 | 5430 | 219 | 486 |
| valid | 79 | 301 | 44 | 257 | 10 | 18 |
| test | 80 | 349 | 46 | 303 | 5 | 8 |
| **total** | **2064** | **7121** | **1131** | **5990** | **234** | **512** |

**512 of 7121 annotation rows (7.2%)** were training the detector towards the wrong region. `dataset_tools.py repair` rewrote every row in the canonical 5-field form (5990 polygons converted to their clamped bounding box, 1131 correct rows preserved byte-wise), backed the originals up to `<split>/labels_original/`, wrote a manifest, and removed the stale `labels.cache` files that would otherwise have masked the change. The post-repair audit reports 7121 box rows, 0 polygon rows, 0 mixed files, 0 misread rows. Full account: [`docs/dataset_label_repair.md`](docs/dataset_label_repair.md).

#### Recorded metrics, and why they are not current

The checkpoint in `runs/detect/train/weights/best.pt` was trained **before** the repair. `runs/detect/train/results.csv` reaches epoch 98 with:

| Metric | Recorded value |
|---|---:|
| Precision | 0.53919 |
| Recall | 0.35257 |
| mAP50 | 0.31843 |
| mAP50-95 | 0.18621 |

Recall 0.35 is the binding constraint on the whole system: a panel that is never detected can never be read, so no OCR improvement can compensate for it. On a real pack the live overlay currently shows about two boxes at 0.21–0.22 confidence with one class confusion — which is what a detector trained on 7% wrong boxes looks like.

**The retrain has not been launched.** That was a deliberate decision: it is hours of GPU time on the user's machine and the user owns when it happens. `train.py` has been prepared so the run is reproducible and cannot be wasted:

- it **refuses to start** while any label row is still in the mixed encoding (`verify_dataset` runs first);
- `RUN_NAME` and the path `pipeline.MODEL_PATH` loads now agree, so a finished run is actually the one the app serves — previously the script wrote to `sih_final_model` while the app read `train`;
- defaults raised for this dataset: 150 epochs, patience 30, `imgsz=768` (statutory print is small; 640 was throwing detail away), AutoBatch for the 6 GB card;
- every augmentation carries a written reason (`python train.py --list-augmentation`); `fliplr` and `flipud` are off because mirrored text is never a valid declaration.

So the retrain is one command, and it is the largest single accuracy gain available in this repository:

```powershell
python train.py
```

Re-run `benchmark_ocr.py` afterwards, because several OCR numbers below are limited by which panels get detected at all.

### 4.8 Measurement and dataset tooling

Three tools exist so the claims in this report can be re-derived rather than trusted:

- [`benchmark_ocr.py`](benchmark_ocr.py) — runs every backend × crop-variant combination over a dataset split and writes one CSV row per crop × backend × variant, plus a summary JSON, to `runs/ocr_benchmark/`. Every table in [`docs/ocr_improvement_plan.md`](docs/ocr_improvement_plan.md) can be rebuilt from that CSV.
- [`dataset_tools.py`](dataset_tools.py) — `audit` / `repair` / `verify` / `preview` over the label files, including an emulation of Ultralytics' own per-file encoding decision so "the box the loader trains on" is computed rather than assumed.
- [`tests/`](tests) — 157 tests, `python -m pytest tests/ -q`, about 10 seconds: rule engine 31, crop preparation 26, OCR backend layer 32, live processor 32, dataset tools 36. The dataset tests build their own miniature dataset in a temporary directory and the OCR tests run against a fake engine, so the suite is safe and fast on a clean checkout. There was no regression suite before this.

## 5. Evidence from current testing

### 5.1 Automated regression suite

`python -m pytest tests/ -q` → **157 passed** in about 10 seconds. This is the first regression suite in the repository. It pins, among other things: the two healer bugs described in §4.3 and the healing that must keep working; the exact morphological property of the new dot-matrix recipe (a close on inverted ink can only darken, never brighten); the live processor's status snapshot, guidance ordering, detection throttling, detector-crash path and every capture gate; the OCR backend layer's lazy loading, error containment and fallback registry; and the dataset audit → repair → verify round trip against a synthetic mixed-encoding dataset.

### 5.2 OCR benchmark, 30 test-split crops

`python benchmark_ocr.py --limit 30 --split test` — every backend × variant combination, 150 measurements:

| backend/variant | crops | read | field yield | rule-ok | conf | s/crop |
|---|---:|---:|---:|---:|---:|---:|
| easyocr/plain | 30 | 0.97 | 0.30 | 0.23 | 0.32 | 0.32 |
| easyocr/enhanced | 30 | 0.97 | 0.33 | 0.27 | 0.29 | 0.20 |
| easyocr/dotmatrix | 15 | 1.00 | 0.10 | 0.00 | 0.28 | 0.13 |
| rapidocr/plain | 30 | 0.63 | 0.24 | 0.17 | 0.89 | 1.42 |
| rapidocr/enhanced | 30 | 0.77 | 0.29 | 0.20 | 0.83 | 1.01 |
| rapidocr/dotmatrix | 15 | 0.53 | 0.12 | 0.07 | 0.82 | 0.88 |

Taking the best variant per crop: easyocr (GPU) field yield 0.27 at 0.23 s/crop; rapidocr (CPU/ONNX) 0.23 at 1.14 s/crop but with calibrated confidence 0.85 against EasyOCR's 0.30, and the best single reading on 16 of 30 crops. Hence the "keep both" conclusion in §4.2.

### 5.3 Effect of the 30 August changes, same 30 crops

Best-of-variants parse signal per class, before → after the dot-matrix recipe change and the two healer fixes:

| Class | EasyOCR | RapidOCR |
|---|---|---|
| product_name | 1.000 → 1.000 | 0.750 → 0.750 |
| net_quantity | 0.417 → 0.500 | 0.250 → 0.333 |
| mrp_declaration | 0.157 → 0.343 | 0.114 → 0.171 |
| manufacturer_details | 0.383 → 0.433 | 0.350 → 0.400 |
| consumer_care_fssai | 0.050 → 0.100 | 0.250 → 0.300 |
| date_declarations | 0.000 → 0.000 | 0.050 → 0.075 |

Nothing regressed, MRP more than doubled on EasyOCR, and time per crop *fell* (0.27 → 0.23 s easyocr, 1.61 → 1.14 s rapidocr) because a single-channel crop is cheaper to recognise than a binarized three-channel one.

### 5.4 Live capture, verified headlessly

Detector loads; `detect_ms = 59` at `imgsz=640`; `focus = 1049` on a real pack photograph; auto-capture fires once when its gates are met and respects the cooldown; `snapshot()` returned a 234 KB JPEG and the rendered overlay was inspected visually (HUD bars, boxes, hint line and framing guide all legible).

**The camera path itself has not been exercised in a browser.** One manual run is still required: `python -m streamlit run app.py` → Inspector → *Live camera (WebRTC)* → **START**, grant camera permission, and check that boxes track the pack, that the focus number rises when the camera focuses, and that capture produces a scan with the same tabs an upload produces. Expect few boxes and low confidence until the detector is retrained.

### 5.5 Earlier checks, still valid

- Python compilation passed for `preprocessing.py`, `pipeline.py`, `rule_engine.py`, `app.py`, `db.py`, and `reporting.py`.
- Standard MRP parsing passed for `35.00 / 0.28` with the tax phrase.
- The observed noisy MRP string was parsed contextually as MRP `135.0` and unit sale price `0.68`; it remains non-compliant when the statutory tax phrase is absent.
- Alphabetic date parsing passed for `MFD 15 JUL 26 ... EXP 24/01/27`.
- A real uploaded product image completed YOLO/OCR inference without an exception, and Streamlit served the application with HTTP 200.
- An isolated SQLite migration test passed for multi-image evidence, image hashes, detailed result storage, reviewer authorization, required override reasons, user management, audit events, and analytics.
- In-memory PDF, DOCX, CSV, and JSON report generation passed, including embedded evidence handling.

The sample OCR export `uploads/2026-08-26T23-48_export.csv` cited by the 27 August report was removed in the directory cleanup (commit `f24cd624`) and is no longer in the tree. Its finding still held at the time — a correctly recognized product name alongside noisy or missing net quantity, MRP, date, batch, manufacturer, and consumer-care fields — but `runs/ocr_benchmark/` is now the better record, because it measures the same failure across every backend and crop variant instead of one ad-hoc scan.

## 6. Important current limitations

1. **Detector quality is not yet sufficient for enforcement, and the checkpoint is stale.** Recorded recall is 0.353, and the served weights were trained on the labels *before* 512 misread rows were repaired. Per-class precision/recall has still not been established. Retraining is the single largest available gain and has deliberately not been launched (§4.7).
2. **The live camera path has not been verified in a browser.** It is implemented, covered by 32 headless tests, and reasoned about in [`docs/webrtc_integration.md`](docs/webrtc_integration.md), but the one manual `getUserMedia` run in §5.4 remains outstanding.
3. **Capture resolution is now the binding OCR constraint on dates and MRP.** Those crops arrive 13–22 px tall and are already at the 6× scaling cap, so no engine choice can recover them — the information is not in the photograph. This is what the live guidance exists to fix, and the fix has to be measured on re-photographed packs before it can be claimed.
4. **CER is unmeasured.** Field yield is a proxy metric. The benchmark's CER column prints `-` until ground-truth transcriptions exist for the benchmark crops (`--truth`).
5. **The dataset has no explicit batch-number detector class.** The seven labels include `date_region` but not `batch_number_region`. The rule engine parses batch/lot text, but it only receives it when that text happens to fall inside another detected panel. Adding the class is a dataset task, not a code task.
6. **English-only OCR is a constraint.** Indian packaging can carry English, Hindi, and regional-language declarations. Both backends are currently configured for English only, though RapidOCR ships PP-OCR multilingual weights and the backend layer can host a second recogniser without touching the pipeline.
7. **Rule applicability is simplified.** Not every declaration applies identically to every commodity, package size, importer arrangement, or regulatory exemption. The prototype should distinguish "not applicable," "not detected," "uncertain," and "violation."
8. **OCR confidence is surfaced but not yet gated.** Per-region confidence, the winning variant and every rejected candidate now appear in the OCR diagnostics tab, and RapidOCR's calibration makes a "confidence < 0.5 → manual review" route meaningful — but no such route is implemented, and it must not be attempted on EasyOCR, whose confidence is ~0.3 whether it is right or wrong.
9. **Penalty values are estimates.** The fixed ₹25,000 first-offense value is useful for the prototype but is not a substitute for current statutory and departmental assessment. Note that two lawful declarations were being assessed at ₹25,000 each until the healer bugs in §4.3 were fixed — a reminder that the estimate amplifies parser errors.
10. **Security is still demonstration-grade.** Default accounts, local SQLite, and local image paths require hardening. The database layer enforces the main workflow roles, but deployment still needs MFA, account provisioning, secret rotation, stronger evidence storage controls, and security testing. **The WebRTC path adds a new surface:** signalling has no authentication of its own, so it inherits whatever the app login provides, TLS is mandatory off `localhost`, the public STUN option discloses the client IP, and the Cloudflared tunnel from commit `aa950626` publishes the whole app to the public internet.
11. **There is no CI pipeline.** The 157-test suite exists and runs locally in ~10 s, but nothing runs it automatically on a commit, and there is no model-evaluation or end-to-end workflow gate.

## 7. Future scope

Items marked **[done]** were completed on 30 August 2026; **[next]** marks the ordered short list to attack first.

### Phase 1: Accuracy and data quality

- **[next, highest value]** Retrain the detector on the repaired labels (`python train.py`) and record the new per-class metrics beside the old ones. Everything else in this phase is worth less until this is done.
- **[done]** Audit and repair the annotation set — 512 misread rows fixed, with an audit/repair/verify tool and a manifest so the change is reversible and reproducible (§4.7).
- Expand the Indian packaging dataset across dairy, biscuits, snacks, oils, spices, personal care, medicines where applicable, and household goods.
- Capture front, back, side, top, bottom, glossy, matte, curved, wrinkled, low-light, and motion-blurred samples.
- Add explicit annotations for batch/lot, marketer, importer, packer, FSSAI, unit sale price, country of origin, and dietary symbols. Batch/lot is the most valuable of these — see limitation 5.
- Create a held-out product-level test set so images of the same package do not leak across train and validation splits.
- Report per-class precision, recall, mAP, missed-field rate, false violation rate, and OCR character/field accuracy.
- Tune YOLO confidence and NMS thresholds using validation data instead of relying only on defaults.

### Phase 2: OCR and image engineering

- **[done]** Compare raw, enhanced, and healed OCR candidates using confidence *and* rule-engine consistency — this is `pipeline._candidate_score`, and the losing candidates are kept and shown rather than discarded.
- **[done]** Add controlled multi-scale crops for very small print without a heavy super-resolution model — text-height-driven scaling towards 32 px, capped at 6×.
- **[done]** Lightweight deskewing for angled labels (up to 12°).
- **[done]** Replace the adaptive-threshold dot-matrix recipe, which measurement showed was the worst of five options, with a grayscale morphological close.
- **[done]** Preserve OCR confidence, crop coordinates, preprocessing variant, and per-region timing in the diagnostics view.
- **[next]** Transcribe ground truth for the 30 benchmark crops and pass `--truth`, so the CER column stops printing `-`. Field yield is a proxy; CER is the real metric.
- **[next]** Use the live guidance to re-photograph the worst 10 packs with the focus meter green and re-run the benchmark. This is the only way to separate "the model is weak" from "the photograph was bad."
- Add a confidence-gated review route (RapidOCR only — see limitation 8).
- Add language packs and script-aware OCR for Hindi and relevant Indian regional languages; RapidOCR ships PP-OCR multilingual weights and the backend layer can host a second recogniser without touching the pipeline.
- Add perspective correction for curved and angled packs, once capture guidance is in field use and the remaining failures can be attributed.
- Persist the engine version and rule-set version into the audit record alongside the confidence data now being computed.

### Phase 3: Compliance intelligence

- **[done]** Integrate dietary-symbol detection into the final report — present as an advisory eighth row that never contributes to the penalty estimate, because it is an FSS requirement rather than an LMPC Rule 6 one.
- Store rule definitions, legal references, effective dates, applicability conditions, and thresholds in versioned tables rather than hardcoding every rule.
- Add an explicit applicability questionnaire for commodity type, package size, imported goods, institutional/industrial supply, and food/perishable status.
- Add checks for declaration legibility, font/size requirements where machine measurement is reliable, unit formatting, importer details, country of origin, and QR/barcode-linked declarations where legally applicable.
- Introduce a three-way outcome: compliant, likely violation, and manual review — and support "not applicable."
- Require verifier approval before a report is treated as an enforcement finding.

### Phase 4: Field workflow and evidence

- **[done]** Live WebRTC camera mode with throttled asynchronous inference and cached overlays, plus in-frame capture guidance. Verified headlessly; the one browser check in §5.4 is outstanding.
- **[done, partly]** Coverage feedback — the live HUD names which of the seven panels are missing from the current frame. Per-*session* coverage across several captured panels ("front captured, back captured, side panel missing") is not yet tracked.
- Generate signed PDF/CSV reports containing original images, crops, OCR text, parser output, timestamps, operator identity, model version, and rule-set version.
- Add an evidence-preservation policy with image hashing, retention periods, access logs, and immutable audit events. (Hashing and audit events exist; retention and access logging do not.)
- Add a one-click report bundle containing the PDF/DOCX/CSV/JSON report plus original evidence and a manifest of hashes.
- Add offline-first synchronization for field officers working without reliable connectivity.

### Phase 5: Production platform

- **[next, cheap]** Add CI that runs `python -m pytest tests/ -q` on every commit. The suite exists and takes ~10 s; nothing runs it automatically.
- Move from local SQLite to PostgreSQL for multi-user deployments while retaining an offline edge store.
- Store images in controlled object storage rather than arbitrary local paths.
- Put the inference engine behind a typed API and separate the Streamlit prototype from a production web/mobile client.
- Add MFA, account provisioning, password rotation, least-privilege authorization, rate limiting, structured logs, monitoring, and incident response. Terminate TLS before the live camera is exposed off `localhost`, and replace the seeded `pass123` accounts.
- Containerize the application and pin model/library versions for reproducible deployment on Windows and Linux edge devices.
- Benchmark OCR latency per backend, GPU detector latency, end-to-end panel processing time, and memory use under the 6 GB VRAM constraint. `benchmark_ocr.py` already covers the OCR half.

## 8. Suggested hackathon demonstration flow

1. Start the application with the trained weights present at the expected path.
2. Sign in as `inspector`.
3. **Open *Live camera (WebRTC)* and aim at a package.** Show the boxes tracking the pack, the focus number rising as the camera focuses, and the hint changing from "Very blurred" to "Only 2 of 7 panels visible — missing: mrp_region, date_region…" to "Good frame — capture now." Explain that guidance is an accuracy feature: the measured OCR ceiling on dates is capture resolution, and a better photograph is the only fix.
4. Capture a frame and let the full OCR and rule chain run on it. Note that a live capture is byte-identical in kind to an upload, so it produces the same evidence record.
5. Switch to *Upload photographs* and upload front, back, and side panels of the same package; explain that images are processed sequentially to control memory usage.
6. Show the seven aggregated fields, the advisory dietary row, and a noisy OCR example normalized by deterministic rules.
7. **Open the OCR diagnostics tab.** This is the strongest technical moment in the demo: per-region YOLO confidence, which of the three crop variants won, the OCR confidence, whether the rule engine could parse it, the timing — and every rejected candidate reading. Nothing is a black box.
8. Submit the scan to SQLite as `PENDING`.
9. Sign in as `verifier`, open the scan, inspect all images and results, and approve or override it.
10. Sign in as `admin` and show scan volume, failure rate, violation count, and estimated penalties.
11. Explain the honest state of the detector: recall 0.353 on a checkpoint trained before 512 misread annotation rows were repaired, so the retrain is the next step and the expected gain is large. Being able to *quantify* the defect is the point.
12. Close on the verifier remaining in the loop: the output is an inspection aid, not an autonomous legal judgment.

Run locally with:

```powershell
python -m streamlit run app.py
```

Switch the OCR engine without touching code:

```powershell
$env:LMPC_OCR_BACKEND="easyocr"; python -m streamlit run app.py
```

Retrain the detector on the repaired labels (hours of GPU time; refuses to start if the labels are not repaired):

```powershell
python train.py
```

Re-derive every measurement in this report:

```powershell
python benchmark_ocr.py --limit 30 --split test
```

```powershell
python -m pytest tests/ -q
```

```powershell
python dataset_tools.py audit
```

The environment must contain the Python dependencies in [`requirements.txt`](requirements.txt), the OCR model assets, and the trained YOLO weights. Both OCR engines download their model assets on first initialization; RapidOCR initialises in about 1.3 s against EasyOCR's ~15 s. The live camera additionally needs `streamlit-webrtc`, `aiortc`, and `av`, and a browser origin that is either `localhost` or HTTPS.

## 9. Proposed success metrics for the next milestone

The next milestone should be measured with a labeled, product-level test set rather than a few visual examples:

| Area | Suggested target for a serious pilot | Current |
|---|---:|---:|
| Mandatory-field detection recall | ≥ 95% per high-priority class | 0.353 overall, pre-repair checkpoint |
| Field-level OCR exact/acceptable extraction | ≥ 90% on clear labels; report separately for difficult print | field yield 0.23–0.27; CER unmeasured |
| False violation rate | ≤ 5% after manual-review routing | unmeasured; two known false positives fixed |
| End-to-end processing | Measured per image/panel and reported with p50/p95 latency | 59 ms detect + 0.23–1.42 s per crop |
| Peak VRAM | Remains within the 6 GB hardware budget | not yet instrumented |
| Audit completeness | 100% of submitted scans retain operator, timestamp, images, model version, rule version, and decision | operator/timestamp/images/decision yes; model and rule version no |
| Human-review traceability | 100% of non-compliant or uncertain findings receive a verifier decision | enforced by the database role checks |

These targets are proposed engineering goals. The "Current" column is what the repository actually measures today, and the gap is the work.

## 10. Conclusion

The repository has progressed from a concept into a functional hackathon prototype with a real detector, a pluggable and benchmarked OCR layer, measured image preprocessing, tolerant statutory-field parsing, multi-image aggregation, a live WebRTC capture mode with capture guidance, RBAC workflow, SQLite audit storage, and a 157-test regression suite. Its strongest demonstration value is the explainable detect-read-validate-review chain, the OCR diagnostics view that makes every reading decision inspectable, and the explicit handling of difficult Indian packaging conditions such as dot-matrix printing and multiple product panels.

The work since 27 August was mostly about replacing guesses with measurements. Three of them changed what the project does: the dot-matrix recipe that was assumed to help was in fact the worst of five options; two OCR healers were converting lawful `1 kg` and `500ml` declarations into ₹25,000 violations; and 7.2% of the annotation rows were training the detector towards boxes the annotator never drew. None of those were visible without instrumenting the thing.

The highest-value next investment is not another large model. It is the retrain on the repaired labels, then ground-truth transcriptions so character error rate replaces the field-yield proxy, then re-photographing the worst packs with the new capture guidance to separate model failure from capture failure. After that: confidence-aware human review, versioned legal rules, and evidence-grade workflow design. Those changes will determine whether the prototype can progress from a compelling SIH demonstration to a reliable decision-support tool for Legal Metrology field enforcement.

## Repository references

### Documentation

- [Change log — everything done on 30 August 2026](docs/CHANGELOG.md)
- [OCR improvement plan and measurements](docs/ocr_improvement_plan.md)
- [WebRTC live capture — design, security, verification status](docs/webrtc_integration.md)
- [Dataset label repair — the defect, the fix, the retrain](docs/dataset_label_repair.md)
- [SIH 2026 problem-statement listing](archives/SIH%20PS%202026.md)
- [Legal Metrology prototype approach note](archives/Legal%20Metrology%20Prototype%20Approach.md)

### Code

- [Streamlit application](app.py)
- [Live WebRTC capture processor](webrtc_live.py)
- [Detector/OCR pipeline](pipeline.py)
- [Pluggable OCR backends](ocr_backends.py)
- [OpenCV crop preparation](preprocessing.py)
- [Deterministic rule engine](rule_engine.py)
- [Evidence-backed report generator](reporting.py)
- [SQLite audit layer](db.py)
- [YOLO training entry point](train.py)

### Tools and data

- [OCR benchmark harness](benchmark_ocr.py)
- [Dataset audit / repair / verify tooling](dataset_tools.py)
- [Test suite (157 tests)](tests)
- [Detector class configuration](packaged-commodity-dataset/data.yaml)
- [Training results](runs/detect/train/results.csv)

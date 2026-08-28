# SIH26034 LMPC Compliance Scanner

## Project status report

**Report date:** 27 August 2026  
**SIH problem:** SIH26034  
**Problem owner:** Ministry of Consumer Affairs, Food & Public Distribution  
**Problem statement:** “Software System to check compliance of Packaged Commodities under Legal Metrology (Packaged Commodities) Rules, 2011 by scanning products, images and labels.”  
**Repository classification:** Software; Agriculture, FoodTech & Rural Development  
**Deadline recorded in the repository listing:** 20 September 2026

The SIH listing and the longer concept note are stored in [`archives/SIH PS 2026.md`](archives/SIH%20PS%202026.md) and [`archives/Legal Metrology Prototype Approach.md`](archives/Legal%20Metrology%20Prototype%20Approach.md). The date and classification above are based on the repository copy and should be confirmed against the official SIH portal before submission.

## 1. Executive summary

This project is a proof-of-concept compliance scanner for Indian FMCG packaging. It is designed as an inspector-side tool: an officer uploads one or more photographs of a product’s front, back, or side panels, and the system detects likely declaration regions, reads their text, validates the declarations with deterministic rules, and stores the resulting audit record for verification.

The current implementation combines:

- A custom Ultralytics YOLO detector for locating packaging regions.
- EasyOCR running strictly on the CPU, leaving the available 4 GB GPU memory for YOLO.
- OpenCV preprocessing for low-contrast, thin, and dot-matrix text.
- A deterministic regular-expression and Levenshtein-based rule engine.
- A Streamlit interface with Inspector, Verifier, and Admin roles.
- SQLite persistence for scans, extracted result summaries, statuses, images, and analytics.
- Sequential multi-image aggregation so several product panels become one report.

The build is suitable for a hackathon demonstration and technical validation. It is not yet a legally authoritative enforcement system: model accuracy, OCR reliability, statutory applicability, database security, and human approval workflows require further validation.

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
Uploaded product panels
        |
        v
Sequential image decoding
        |
        v
YOLO region detection
        |
        v
Crop each detected statutory region
        |
        +--> OpenCV enhancement for normal text
        |
        +--> Dot-matrix healing for MRP/date/batch crops
        |       grayscale -> CLAHE -> adaptive threshold -> closing
        |
        v
EasyOCR on CPU
        |
        v
Seven-class text aggregation across all panels
        |
        v
Regex parsers + Levenshtein fuzzy matching
        |
        v
Structured PASS/FAIL report and penalty estimate
        |
        +--> Inspector review and submission
        +--> SQLite audit record
        +--> Verifier approval or override
        +--> Admin analytics
```

The uploaded-image path is intentionally sequential. Images are not batched into one YOLO or OCR call, which reduces peak memory pressure on the 4 GB VRAM machine.

## 4. What has been built

### 4.1 `pipeline.py`

The pipeline is the detector/OCR integration layer.

- Loads `runs/detect/train/weights/best.pt` lazily through Ultralytics `YOLO`.
- Initializes EasyOCR globally with `easyocr.Reader(['en'], gpu=False)`.
- Accepts a list of image bytes and processes each image one at a time.
- Decodes image bytes directly into an OpenCV BGR array without writing OCR intermediates to disk.
- Reads YOLO class names and bounding boxes dynamically; there are no hardcoded image coordinates.
- Maps detector aliases such as `mrp_region`, `date_region`, and `manufacturer_region` to canonical report classes.
- Crops each detected region and runs EasyOCR with `detail=0`.
- Runs the healed crop path for MRP, date, and batch regions and logs MRP text before and after healing.
- Aggregates text from repeated detections and multiple uploaded panels into one dictionary.
- Returns the seven canonical input keys:

  `product_name`, `net_quantity`, `mrp_declaration`, `date_declarations`, `batch_number`, `manufacturer_details`, and `consumer_care_fssai`.

The older [`ocr_engine.py`](ocr_engine.py) remains in the repository as a legacy PaddleOCR wrapper, but it is not imported by the active application or pipeline. It should be removed or migrated before packaging the project so the distribution has one unambiguous OCR implementation and does not accidentally trigger the original PaddleOCR/OneDNN problem.

### 4.2 `preprocessing.py`

The module contains both normal ROI enhancement and specialized dot-matrix processing.

`enhance_text_roi` applies CLAHE to the luminance channel in LAB color space. This is intended to improve contrast while retaining color information for ordinary text crops.

`heal_dot_matrix_text` applies the CPU-only sequence requested for difficult printed declarations:

1. Convert BGR or grayscale input to grayscale.
2. Normalize non-8-bit input where necessary.
3. Apply CLAHE for local contrast enhancement.
4. Apply Gaussian adaptive thresholding to produce a binary text image.
5. Apply a 3 × 3 morphological close to bridge small gaps between inkjet or dot-matrix character points.

`classify_dietary_symbol` uses HSV color masks to distinguish likely green vegetarian and brown/red non-vegetarian symbols. The helper is present, but dietary status is not yet wired into the current uploaded-image report path; this is a defined future integration item.

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

### 4.4 `app.py`

The Streamlit application currently uses an upload workflow rather than a live WebRTC camera feed.

#### Inspector

- Logs in using the Inspector role.
- Uploads multiple JPG, JPEG, or PNG product-panel images at once.
- Displays a thumbnail grid.
- Runs sequential detection, OCR, aggregation, and validation.
- Displays extracted text, parsed values, PASS/FAIL state, and penalty estimates.
- Captures optional inspection context as evidence notes.
- Offers downloadable PDF, editable DOCX, CSV, and JSON reports before and after submission.
- Persists the scan and associated panel images with `PENDING` status.

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

### 4.7 Training and model artifacts

The detector training entry point is [`train.py`](train.py). It uses `yolo26n.pt` with the Roboflow-exported dataset described by [`packaged-commodity-dataset/data.yaml`](packaged-commodity-dataset/data.yaml).

Current dataset metadata reports:

- 1,914 training images.
- 80 validation images.
- 80 test images.
- Seven detector classes: consumer care, date, dietary symbol, generic name, manufacturer, MRP, and net quantity.

The training script is configured for 100 epochs, patience 20, 640-pixel images, automatic batch sizing, four workers, and device `0`. The recorded [`runs/detect/train/results.csv`](runs/detect/train/results.csv) reaches epoch 98. Its last recorded metrics are approximately:

| Metric | Recorded value |
|---|---:|
| Precision | 0.53919 |
| Recall | 0.35257 |
| mAP50 | 0.31843 |
| mAP50-95 | 0.18621 |

These values indicate that the current model is a training artifact for a hackathon prototype, not a production-validated detector. They should be reported honestly and improved through data, annotation, and threshold work. The pipeline expects `runs/detect/train/weights/best.pt`; the training script names its configured run `sih_final_model`, so the output-path convention should be aligned before a clean retraining run.

## 5. Evidence from current testing

The following checks have been completed in the current workspace:

- Python compilation passed for `preprocessing.py`, `pipeline.py`, `rule_engine.py`, `app.py`, and `db.py`.
- Python compilation passed for the new `reporting.py` module.
- Standard MRP parsing passed for `35.00 / 0.28` with the tax phrase.
- The observed noisy MRP string was parsed contextually as MRP `135.0` and unit sale price `0.68`; it remains non-compliant when the statutory tax phrase is absent.
- Alphabetic date parsing passed for `MFD 15 JUL 26 ... EXP 24/01/27`.
- A mocked dot-matrix pipeline confirmed that MRP OCR is called once on the original crop and once on the healed in-memory crop.
- A real uploaded product image completed YOLO/EasyOCR inference without an exception.
- Streamlit served the application successfully with HTTP 200.
- An isolated SQLite migration test passed for multi-image evidence, image hashes, detailed result storage, reviewer authorization, required override reasons, user management, audit events, and analytics.
- In-memory PDF, DOCX, CSV, and JSON report generation passed, including embedded evidence handling.

The sample OCR export [`uploads/2026-08-26T23-48_export.csv`](uploads/2026-08-26T23-48_export.csv) also demonstrates the remaining practical challenge. It contains a correctly recognized product-name result, but noisy or missing net quantity, MRP, date, batch, manufacturer, and consumer-care fields. This is useful evidence for tuning the dataset, crop quality, OCR preprocessing, and decision thresholds; it is not evidence of production accuracy.

## 6. Important current limitations

1. **Detector quality is not yet sufficient for enforcement.** The recorded validation metrics are modest, and per-class precision/recall has not yet been established.
2. **The deployed UI is upload-based.** The earlier live WebRTC concept is documented, but the current `app.py` does not implement a live camera stream, frame throttling, or live bounding-box overlays.
3. **The dataset has no explicit batch-number detector class.** The current dataset’s seven labels include `date_region` but not `batch_number_region`. The rule engine supports batch parsing, but reliable batch extraction requires a detector label or a stronger fallback crop strategy.
4. **English-only OCR is a constraint.** Indian packaging can contain English, Hindi, and regional-language declarations. EasyOCR is currently initialized only for English.
5. **Dietary-symbol validation is incomplete in the active path.** OpenCV color classification exists, but its result is not currently included in the final seven-section compliance response.
6. **Rule applicability is simplified.** Not every declaration applies identically to every commodity, package size, importer arrangement, or regulatory exemption. The prototype should distinguish “not applicable,” “not detected,” “uncertain,” and “violation.”
7. **OCR confidence is not exposed.** A low-confidence OCR string can currently reach the regex engine without a formal human-review threshold.
8. **Penalty values are estimates.** The fixed ₹25,000 first-offense value is useful for the prototype but is not a substitute for current statutory and departmental assessment.
9. **Security is still demonstration-grade.** Default accounts, local SQLite, and local image paths require hardening. The current database layer now enforces the main workflow roles, but deployment still needs MFA, account provisioning, secret rotation, stronger evidence storage controls, and security testing.
10. **There is no automated regression suite or CI pipeline.** Parser fixtures, crop fixtures, model evaluation, and end-to-end workflow tests should be added.

## 7. Future scope

### Phase 1: Accuracy and data quality

- Expand the Indian packaging dataset across dairy, biscuits, snacks, oils, spices, personal care, medicines where applicable, and household goods.
- Capture front, back, side, top, bottom, glossy, matte, curved, wrinkled, low-light, and motion-blurred samples.
- Add explicit annotations for batch/lot, marketer, importer, packer, FSSAI, unit sale price, country of origin, and dietary symbols.
- Create a held-out product-level test set so images of the same package do not leak across train and validation splits.
- Report per-class precision, recall, mAP, missed-field rate, false violation rate, and OCR character/field accuracy.
- Tune YOLO confidence and NMS thresholds using validation data instead of relying only on defaults.

### Phase 2: OCR and image engineering

- Add perspective correction and lightweight deskewing for angled or curved labels.
- Tune adaptive-threshold block size and constant by region type and lighting condition.
- Compare raw, enhanced, and healed OCR candidates using confidence and rule-engine consistency.
- Add controlled multi-scale crops for very small print without introducing a heavy super-resolution model.
- Add language packs and script-aware OCR for Hindi and relevant Indian regional languages.
- Preserve OCR confidence, crop coordinates, preprocessing variant, and engine version in the audit record.

### Phase 3: Compliance intelligence

- Store rule definitions, legal references, effective dates, applicability conditions, and thresholds in versioned tables rather than hardcoding every rule.
- Add an explicit applicability questionnaire for commodity type, package size, imported goods, institutional/industrial supply, and food/perishable status.
- Integrate dietary-symbol detection into the final report and support “not applicable.”
- Add checks for declaration legibility, font/size requirements where machine measurement is reliable, unit formatting, importer details, country of origin, and QR/barcode-linked declarations where legally applicable.
- Introduce a three-way outcome: compliant, likely violation, and manual review.
- Require verifier approval before a report is treated as an enforcement finding.

### Phase 4: Field workflow and evidence

- Add a live WebRTC/mobile camera mode with asynchronous inference, frame throttling, and cached overlays.
- Associate panels with a scan session and show coverage, for example “front captured,” “back captured,” and “side panel missing.”
- Generate signed PDF/CSV reports containing original images, crops, OCR text, parser output, timestamps, operator identity, model version, and rule-set version.
- Add an evidence-preservation policy with image hashing, retention periods, access logs, and immutable audit events.
- Add a one-click report bundle containing the PDF/DOCX/CSV/JSON report plus original evidence and a manifest of hashes.
- Add offline-first synchronization for field officers working without reliable connectivity.

### Phase 5: Production platform

- Move from local SQLite to PostgreSQL for multi-user deployments while retaining an offline edge store.
- Store images in controlled object storage rather than arbitrary local paths.
- Put the inference engine behind a typed API and separate the Streamlit prototype from a production web/mobile client.
- Add MFA, account provisioning, password rotation, least-privilege authorization, rate limiting, structured logs, monitoring, and incident response.
- Containerize the application and pin model/library versions for reproducible deployment on Windows and Linux edge devices.
- Benchmark CPU OCR latency, GPU detector latency, end-to-end panel processing time, and memory use under the 4 GB VRAM constraint.

## 8. Suggested hackathon demonstration flow

1. Start the application with the trained weights present at the expected path.
2. Sign in as `inspector`.
3. Upload front, back, and side photographs of one package.
4. Analyze the package and explain that images are processed sequentially to control memory usage.
5. Show the seven aggregated fields and a noisy OCR example normalized by deterministic rules.
6. Submit the scan to SQLite as `PENDING`.
7. Sign in as `verifier`, open the scan, inspect all images and results, and approve or override it.
8. Sign in as `admin` and show scan volume, failure rate, violation count, and estimated penalties.
9. Demonstrate a dot-matrix crop and the logged raw-versus-healed OCR values.
10. Explain that the verifier remains in the loop and that the output is an inspection aid, not an autonomous legal judgment.

Run locally with:

```powershell
python -m streamlit run app.py
```

Training, if required, is started with:

```powershell
python train.py
```

The environment must contain the Python dependencies used by the modules, the EasyOCR model assets, and the trained YOLO weights. EasyOCR may download its model assets on first initialization depending on the local installation.

## 9. Proposed success metrics for the next milestone

The next milestone should be measured with a labeled, product-level test set rather than a few visual examples:

| Area | Suggested target for a serious pilot |
|---|---:|
| Mandatory-field detection recall | ≥ 95% per high-priority class |
| Field-level OCR exact/acceptable extraction | ≥ 90% on clear labels; report separately for difficult print |
| False violation rate | ≤ 5% after manual-review routing |
| End-to-end processing | Measured per image/panel and reported with p50/p95 latency |
| Peak VRAM | Remains within the 4 GB hardware budget |
| Audit completeness | 100% of submitted scans retain operator, timestamp, images, model version, rule version, and decision |
| Human-review traceability | 100% of non-compliant or uncertain findings receive a verifier decision |

These targets are proposed engineering goals, not results already achieved by the current repository.

## 10. Conclusion

The repository has progressed from a concept into a functional hackathon prototype with a real detector, CPU-constrained OCR, image preprocessing, tolerant statutory-field parsing, multi-image aggregation, RBAC workflow, and SQLite audit storage. Its strongest demonstration value is the explainable detect-read-validate-review chain and the explicit handling of difficult Indian packaging conditions such as dot-matrix printing and multiple product panels.

The highest-value next investment is not another large model. It is better representative annotation, per-class evaluation, batch/dietary integration, confidence-aware human review, versioned legal rules, and evidence-grade workflow design. Those changes will determine whether the prototype can progress from a compelling SIH demonstration to a reliable decision-support tool for Legal Metrology field enforcement.

## Repository references

- [SIH 2026 problem-statement listing](archives/SIH%20PS%202026.md)
- [Legal Metrology prototype approach note](archives/Legal%20Metrology%20Prototype%20Approach.md)
- [Streamlit application](app.py)
- [YOLO/EasyOCR pipeline](pipeline.py)
- [OpenCV preprocessing](preprocessing.py)
- [Deterministic rule engine](rule_engine.py)
- [Evidence-backed report generator](reporting.py)
- [SQLite audit layer](db.py)
- [Legacy, currently unused PaddleOCR wrapper](ocr_engine.py)
- [YOLO training entry point](train.py)
- [Detector class configuration](packaged-commodity-dataset/data.yaml)
- [Training results](runs/detect/train/results.csv)
- [Sample OCR export](uploads/2026-08-26T23-48_export.csv)

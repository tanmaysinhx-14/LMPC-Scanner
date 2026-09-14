# SIH26034: Legal Metrology Packaged Commodity Compliance Scanner

## Consolidated technical report

**Assessment date:** 4 September 2026  
**Problem statement:** SIH26034 - Software System to check compliance of Packaged Commodities under the Legal Metrology (Packaged Commodities) Rules, 2011 by scanning products, images and labels.  
**Prepared from:** the current working tree, implementation source, repository documentation, test suite, model/data artifacts, and the Department of Consumer Affairs reference material.  
**Assessment status:** implementation review, not a statutory certification or production-security audit.

---

## Executive summary

This repository contains a working decision-support prototype for Legal Metrology inspection of packaged commodities. An Inspector can upload several product-panel photographs, capture stills from a browser camera, or capture stills locally without Internet access. The system detects label regions with a custom YOLO model, performs OCR on alternative image preparations, aggregates declarations from the product's panels, evaluates them using deterministic and explainable parsers, presents a compliance checklist, and stores evidence for a Verifier and Administrator workflow.

The project is materially more complete than an image-to-text demo. It includes role-based login, sequential multi-panel analysis, a detection-preview and OCR-diagnostics surface, evidence-image hashing, selectable SQLite or XAMPP/MySQL persistence, refresh-safe server-side sessions, report downloads (PDF, editable DOCX, CSV and JSON), verifier approval/override, administrative analytics, model/dataset maintenance utilities, OCR benchmarking, live guided capture, and a current regression suite. The complete local suite passed **365 tests** on 4 September 2026.

The honest maturity position is **hackathon-ready decision support, not autonomous enforcement software**. The strongest current risks are detector quality (the served checkpoint predates a repaired label set), incomplete statutory applicability logic, unmeasured character error rate, no measured font-size compliance determination, and deployment-grade security/operational work still to be done. Human verifier approval is therefore a necessary part of the workflow, not an optional cosmetic screen.

## 1. Requirement-to-implementation traceability

| SIH requirement | Current implementation | Status |
|---|---|---|
| Scan packaged-product images and labels | Multi-image JPG/JPEG/PNG upload; browser WebRTC capture; local/offline camera capture | Implemented |
| Detect mandatory declaration regions | Custom Ultralytics YOLO detector with seven trained region classes and visual overlays | Implemented, accuracy constrained by stale checkpoint |
| Extract declarations | RapidOCR default or EasyOCR; measured crop variants; multi-panel aggregation | Implemented |
| Check completeness/correctness | Deterministic parsers for product name, quantity, pricing, dates, batch, manufacturer and consumer/FSSAI details | Implemented for a core rule set; applicability is simplified |
| Placement/readability/font size | YOLO box locations, image sharpness guidance, crop text-height estimation and visual evidence | Partial: no statutory font-size/placement rule measurement or conclusive readability threshold |
| Identify missing/non-standard declarations | Per-rule PASS/FAIL, human-readable reasons, SI-unit and tax-phrase checks, OCR diagnostics | Implemented, subject to detection/OCR confidence and rule coverage |
| Compliance/violation reports | In-memory PDF, editable DOCX, CSV and JSON export with evidence and reviewer details | Implemented |
| Evidence and inspection repository | Multi-image persistence, SHA-256 evidence hashes, result rows, review notes and audit events in SQLite or XAMPP/MySQL | Implemented for local prototype; backend switch documented |
| Role-based, secure access | Inspector/Verifier/Admin roles; salted PBKDF2-HMAC-SHA256 passwords; role checks in data layer | Implemented at prototype level; default accounts and local deployment require hardening |
| Dashboards/search/retrieval | Inspector result area, verifier queue/history/search, admin analytics, raw records and audit-event views | Implemented |
| Technical deployment documentation | Architecture, OCR, dataset-repair and WebRTC design documentation in `docs/` | Implemented; production runbook/CI remains future work |

## 2. Current project scope

### 2.1 Intended operating model

The system supports an officer-led, evidence-first workflow:

1. An Inspector captures the relevant front, back, side, top or bottom panels for one retail package.
2. The pipeline finds likely declaration regions, reads their text, and aggregates non-duplicate text by declaration type across the panels.
3. The deterministic rule engine produces extracted fields, a compliance outcome, reason and prototype penalty estimate per rule.
4. The Inspector reviews the visual detection overlay, raw OCR, candidate readings, evidence and downloadable report before submitting the scan as `PENDING`.
5. A Verifier reviews the evidence and either approves it or overrides/rejects it with a mandatory written reason.
6. An Administrator manages users, inspection history, analytics and audit events.

This is deliberately **decision support with a verifier in the loop**. It is not designed to issue a legally final notice automatically.

### 2.2 Capture modes

| Mode | How it works | Current value |
|---|---|---|
| Upload photographs | Inspector uploads multiple package-panel images | Most direct and repeatable evidence path |
| Browser/WebRTC live camera | Browser camera feeds a live YOLO overlay; full OCR/rules run only on retained stills | Guided field capture; requires localhost or HTTPS when used in a browser |
| Local/offline live camera | The application opens a local webcam, USB/virtual camera, phone MJPEG stream, Wi-Fi stream or ADB screen stream; OCR/rules run only on captured stills | Supports no-Internet operation and Windows/Android demo conditions |

For live modes, detection and focus measurement run during preview; OCR is intentionally deferred until capture because per-crop OCR takes substantially longer than detection. The system retains the sharpest recent frame rather than relying only on the instantaneous shutter frame.

### 2.3 Scope boundaries

The current product handles scanned physical-package evidence. It does not yet implement e-commerce listing crawling, barcode/product-master lookup, automatic legal notice generation, national-scale multi-tenant deployment, or an authoritative determination of every product-specific exception and exemption. The `extract_dataset_ocr.py` tool is for offline corpus/data analysis; it is not a public product-listing ingestion service.

## 3. Technical architecture

```text
Upload images / browser camera / offline local camera
                    |
                    v
     sequential image decoding and evidence staging
                    |
                    v
  YOLO region detection (regions + boxes + confidence)
                    |
       +------------+-------------+
       |                          |
 live overlay / focus HUD       padded crop (4% margin)
                                  |
             measured scale, deskew and crop variants
                                  |
         RapidOCR (default) or EasyOCR (selectable)
                                  |
 confidence + parse signal + text-yield candidate score
                                  |
     de-duplicated multi-panel declaration aggregation
                                  |
 deterministic Legal Metrology/FSS advisory rule engine
                                  |
     Inspector review -> SQLite/MySQL evidence/audit -> Verifier decision
                                  |
        PDF / DOCX / CSV / JSON reports + Admin analytics
```

The image path is sequential rather than bulk-batched, which keeps peak GPU/RAM use practical on the recorded 6 GB RTX 3050 environment. The pipeline exposes both a full `analyze_images()` result and a backward-compatible `process_image()` contract. Diagnostic data is preserved per detection, including raw/canonical class, YOLO confidence, crop box, OCR text/confidence, crop variant, parsing result, timing and rejected OCR candidates.

## 4. What has been built

### 4.1 Detection, OCR and preprocessing

**YOLO detector (`pipeline.py`)**

- Loads `runs/detect/train/weights/best.pt` lazily; `LMPC_MODEL_PATH` can override it.
- Uses a default detection confidence of 0.20, IoU 0.50 and image size 768, all environment-configurable.
- Detects dynamically from model class names rather than fixed image coordinates, maps aliases into canonical report classes, and retains unmapped detections for diagnosis.
- Supports detection-only mode for live capture, avoiding live OCR latency.
- Processes each uploaded/captured product panel, aggregates by canonical class, and removes equivalent repeated OCR readings before rule evaluation.

**OCR layer (`ocr_backends.py`)**

- Provides a common backend interface, lazy loading, locking and failure containment.
- Supports RapidOCR / PP-OCR through ONNX Runtime (default, calibrated confidence) and EasyOCR / CRAFT+CRNN (GPU-capable, faster but uncalibrated confidence).
- Lets the Inspector select the engine in the interface; `LMPC_OCR_BACKEND` supports configuration outside the UI.
- Keeps every candidate's engine output rather than hiding failed variants, which makes review and debugging possible.

**Image preparation (`preprocessing.py`)**

- Estimates text height and scales toward a target of 32 pixels, bounded at 6x instead of applying a blind fixed enlargement.
- Corrects small hand-held rotation (up to 12 degrees).
- Produces plain, CLAHE-enhanced and restricted dot-matrix variants; the dot-matrix recipe is used only for MRP/date/batch-oriented regions.
- Measures Laplacian sharpness and classifies likely vegetarian/non-vegetarian symbols from HSV colour evidence.
- Uses the crop candidate score `0.50 OCR confidence + 0.35 parse signal + 0.15 character yield`; a valid machine-readable declaration is preferred over superficially confident but unusable text.

### 4.2 Compliance rule engine (`rule_engine.py`)

The rule engine is regex-, parser- and Levenshtein-based. It is deterministic: it does not use an LLM, so an Inspector can trace why a result was produced.

| Result section | Current checks/extraction |
|---|---|
| Product name | Usable generic/product identity after removing common field labels |
| Net quantity | Numeric amount, SI unit forms, multi-pack expressions and invalid/pluralized-symbol detection; context-aware recovery of OCR `q`/`9` for `g` |
| Pricing | MRP, currency, optional unit sale price, `inclusive of all taxes` language and selected OCR substitutions |
| Dates | Manufacture/packing, expiry/use-by and best-before declarations across numeric, ISO and named-month forms; duration language |
| Batch details | Batch/lot body, excluding timestamp-like fragments |
| Manufacturer information | Manufacturer/marketer role wording and six-digit PIN code |
| Consumer care/FSSAI | FSSAI licence, email, consumer-care/toll-free number, including selected run-on OCR artifacts |
| Dietary mark | Vegetarian/non-vegetarian/uncertain result as an advisory FSS row with zero LMPC penalty |

The fixed `INR 25,000` first-offence amount is explicitly a **prototype estimate**, not a final legal assessment. Two false-positive healer behaviours affecting lawful `1 kg` and `500ml` declarations were fixed and are regression-tested. The engine deliberately does not normalize `gms` into `g`, because non-standard units must remain visible to the invalid-unit check.

### 4.3 User interface, explainability and live guidance

The Streamlit application presents the following Inspector surfaces after analysis:

- **Detection preview:** detector boxes and confidence information drawn over the source image.
- **Compliance checklist:** extracted fields, parsed details, PASS/FAIL outcome, reason and penalty estimate.
- **Raw OCR:** aggregated text fed to the rule engine.
- **OCR diagnostics:** winning variant, OCR confidence, parsing signal, elapsed time and every rejected candidate reading.
- **Evidence and reports:** original image evidence, hashes and downloads.

The browser WebRTC and offline local modes share the same `LiveScanProcessor` behaviour: focus measurement, throttled detection, in-frame HUD, missed-panel guidance, optional auto-capture and manual capture. Guidance prioritizes detector failure, blur, missing statutory panels, low resolution and then a good-frame message. Manual capture is not blocked by quality; a low-quality image can still be evidence. The offline mode has explicit source adapters for a local camera, virtual camera, USB-tunnelled MJPEG phone camera, Wi-Fi MJPEG and Android ADB screen-recording. Its source layer avoids shell-built ADB commands, releases device resources, drains stale frames and reports blank/unavailable cameras clearly.

### 4.4 Evidence, review, RBAC and data storage

`db.py` persists the local prototype in `lmpc_scanner.db` with foreign keys and WAL mode, or in a real XAMPP/MySQL schema when `LMPC_DB_BACKEND=mysql` is selected. The same data-access API is used by both backends; MySQL databases are created and bootstrapped on first connection. It creates and migrates:

- `Users` - username, password representation, role and activity state.
- `Scans` - Inspector, submitted image paths, status, timestamps, evidence/reviewer metadata.
- `ScanResults` - rule, extracted text, parsed data, reason, compliance result and penalty.
- `AuditEvents` - actor, scan, action, details and timestamp.
- `SessionTokens` - selector, validator hash, expiry, last-use and revocation state for refresh-safe authentication.

The available roles are **Inspector**, **Verifier** and **Admin**. Database-layer checks restrict who can list scans, insert scans, review a scan, retrieve history and administer accounts. An override requires a non-empty review reason, and only a permitted pending scan can transition. Evidence paths are serialized as JSON for multi-image scans; legacy path data remains readable. At insertion, images are SHA-256 hashed and a submission audit event is produced.

Passwords use salted PBKDF2-HMAC-SHA256 with 310,000 iterations. This is materially safer than plaintext passwords but does not make a locally seeded demo deployment production-ready. The repository includes default `inspector`, `verifier` and `admin` demo accounts using `pass123`; they must be replaced before any network exposure.

After login, the server issues an opaque selector/validator token. Only the validator hash is stored in `SessionTokens`; the browser receives the token in the `lmpc_session` cookie. On a Streamlit rerun or browser refresh, the token is checked against the selected database and the active user's current role is restored. Logout, expiry and account deactivation revoke the token. The optional `streamlit-cookies-controller` component is required for persistence across a full browser refresh; if it is unavailable, the application warns and retains only the current Streamlit-session login.

The XAMPP/MySQL setup, environment variables, role contract, smoke test and deployment-security caveats are documented in [`docs/XAMPP_MYSQL_RBAC_SETUP.md`](XAMPP_MYSQL_RBAC_SETUP.md). This makes the local SQLite demo and a real relational database reproducible without changing the scanner's repository or review APIs.

### 4.5 Reports, dashboards and search

`reporting.py` builds a normalized payload containing scan metadata, rule results, parsed data, reasons, evidence paths/hashes and reviewer notes. It generates:

- PDF summary/evidence report (ReportLab when installed, Matplotlib fallback);
- editable DOCX report with compliance table and evidence images where available;
- CSV for spreadsheet workflows; and
- structured JSON for downstream integration.

These are application export formats required by the SIH workflow; the project
documentation and this consolidated deliverable are maintained as Markdown files.

The Verifier has pending and reviewed history, evidence display and report download. The Admin has totals, violations, failure rate, potential-penalty analytics, scan/result tables, repository search, user controls and audit-event retrieval. Search can filter by role/status and text spanning scan ID, Inspector, product and evidence notes.

### 4.6 Data, training and maintenance tooling

The detector has seven data classes:

`consumer_care_region`, `date_region`, `dietary_symbol_region`, `generic_name_region`, `manufacturer_region`, `mrp_region`, and `net_quantity_region`.

The verified dataset inventory is 1,905 train, 79 validation and 80 test images; 7,121 annotations across 2,064 label files. `dataset_tools.py` discovered mixed YOLO box/polygon encodings within individual label files, repaired all labels to five-field boxes, retained originals in `labels_original`, writes a repair manifest, removes stale caches and verifies that the loader cannot misinterpret a row. The repair corrected 512 of 7,121 rows (7.2%) that the data loader would misread.

`train.py` validates the repaired data before it starts, targets `yolo26n.pt`, uses a 150-epoch/patience-30/image-size-768 configuration, and documents augmentation choices. Text mirroring augmentations are deliberately disabled because mirrored declarations are not realistic valid labels. The trained model currently served by the application predates this repair, so retraining is essential before presenting post-repair accuracy claims.

The repository also contains:

- `benchmark_ocr.py`: reproducible backend-by-variant benchmark CSV/JSON output;
- `extract_dataset_ocr.py`: resumable offline image/detection/OCR extraction to JSONL and digest files, with low-confidence flags, throughput tracking and rule-engine summaries;
- `dataset_tools.py`: audit, repair, verify and label-preview operations;
- `debug_view.py`: detection-overlay rendering; and
- `tests/`: focused unit/regression tests for all major pure and integration boundaries.

## 5. Legal declaration coverage and gaps

The Department of Consumer Affairs' 18 January 2023 communication lists Rule 6 retail-package declarations including manufacturer/packer/importer name and address, country of origin for imported products, generic name, net quantity, manufacture/pack/import month and year, best-before/use-by for relevant goods, MRP inclusive of taxes, consumer care, relevant dimensions and unit sale price. The Department's site also lists amendments through 2025. The following table therefore describes **current software support**, not an assertion that the code is a current complete consolidation of every amendment.

| Declaration / quality expectation | Current treatment | Gap before enforcement use |
|---|---|---|
| Common/generic name | Detected and parsed | Need category-aware applicability and field-level accuracy validation |
| Manufacturer/packer/importer name and address | Manufacturer/marketer wording and PIN extraction | Does not fully distinguish/require every legal entity, address element or importer condition |
| Country of origin | Not a dedicated detector/parser requirement | Add rule, detector/data labels and applicability logic for imported packages |
| Net quantity and units | Strongest implemented quantitative parser; SI/non-standard unit checks | Validate all exception/commodity/package-size rules and physical placement/font requirements |
| MFG/PKD/import date; expiry/best before | Date parser supports multiple formats and duration declarations | OCR is weak on small print; import date/category conditions need explicit rules |
| MRP inclusive of taxes | Parses MRP/tax phrase and optional unit sale price | Requires legal-rule/version review, price-area detection validation and category exceptions |
| Consumer-care details | Phone/email/FSSAI parsing | Confirm required identity/contact form against current rule set |
| Unit sale price | Parser can extract optional unit sale price | Not enforced as a universally required/applicability-aware declaration |
| Dimensions | Not implemented | Add structured detector/parser/rule for relevant goods |
| Batch / lot | Extractor exists; falls back to date-region text | No dedicated detector class; add training labels and rule applicability |
| FSSAI number and dietary mark | Included as advisory food-labeling information | Separate from LMPC determination; legal references/versioning must be maintained |
| Placement, font size and readability | Detection boxes, crop text-height estimation, sharpness meter and visual review | No calibrated statutory font-size or declaration-placement measurement; no defensible pass/fail font engine |

## 6. Validation evidence as of this assessment

### 6.1 Verified during this assessment

| Check | Result |
|---|---|
| Full regression suite | `python -m pytest tests -q` -> **365 passed in 16.33 s** |
| Syntax compilation | `py_compile` completed for app, pipeline, preprocessing, rules, OCR, database, reporting, live-camera, data-tool and debug modules |
| XAMPP/MySQL integration smoke test | Disposable schema on `127.0.0.1:3306`: automatic schema creation, seeded authentication, scan/result/audit writes, verifier review, admin analytics, user deactivation and session revocation all completed |
| Dataset and training utilities | CLI help/augmentation listing and code inspection confirmed repair/verify/training controls |
| Current code integration | Inspection confirmed three Inspector capture choices, selected OCR backend warm-up, candidate diagnostics, multi-image aggregation and database review paths |

The 365 tests include rule-engine date/quantity/MRP/FSSAI/healing coverage; preprocessing variants/deskew/sharpness/dietary tests; OCR interface/fallback/result normalization; pipeline aggregation and early-stop selection; live WebRTC processor tests; label audit/repair/verification tests; offline camera/frame-source/ADB and buffer behaviour; dataset-OCR extraction/digest/resume/validation tests; database/session/RBAC regression tests; and browser-cookie adapter tests.

### 6.2 Historical measured evidence retained in repository documentation

The latest checked-in benchmark summary (2 September 2026) covers **50 test-split crops and 250 backend/variant measurements**. Across all variants, EasyOCR read 87.2% of crops, yielded a parsed field on 24.2%, averaged 0.429 confidence and 0.201 seconds/crop; RapidOCR read 64.0%, yielded 15.4%, averaged 0.768 confidence and 0.98 seconds/crop. The per-crop winner counts were RapidOCR enhanced 13, RapidOCR plain 12, EasyOCR plain 10, EasyOCR enhanced 6, RapidOCR dot-matrix 5 and EasyOCR dot-matrix 4. A separate 2 September EasyOCR cap sweep covers 150 crops/390 measurements and reports 0.175 field yield at 0.15 seconds/crop. These measurements are valuable for engineering direction but are not a substitute for a held-out, ground-truthed evaluation: CER remains `null` because benchmark transcriptions have not been supplied.

The repository also contains larger offline extraction sweeps. The latest `runs/ocr_dataset.jsonl` contains valid records for 1,398 images (12,688 valid JSONL records plus one malformed line); `runs/ocr_rapidocr.jsonl` contains 1,398 images and 10,818 valid records. These are diagnostic corpus outputs rather than a claim of production accuracy and should be regenerated after the detector retrain.

The served pre-repair checkpoint records precision 0.53919, recall 0.35257, mAP50 0.31843 and mAP50-95 0.18621 at epoch 98. These are **baseline historical metrics**, not post-repair model results. The next report must replace them only after retraining and repeat evaluation.

### 6.3 Reconciliation note

Older documentation reports 157 tests. That figure is stale: it predates the current local-camera, frame-source, extraction, aggregation, database-session and cookie-adapter test additions. This consolidated report uses the live suite result of 365 rather than preserving the old count.

## 7. Current limitations, risks and review findings

1. **Stale detector checkpoint:** the current model was trained before label repair; low recorded recall makes missed fields the primary accuracy risk.
2. **No enforcement-grade legal ruleset:** hardcoded/simplified logic does not yet model all 2025 amendments, exemptions, product categories, pack sizes, import situations or rule effective dates.
3. **Font/placement compliance is incomplete:** the system helps a reviewer see detected regions and capture quality but does not calculate statutory font-size compliance or conclusively assess placement/readability.
4. **OCR evaluation is incomplete:** field yield is only a proxy; there is no benchmark ground truth/CER, no category-stratified accuracy and no robust Hindi/regional-language solution enabled.
5. **No dedicated batch detector:** batch text is recovered only if caught in another region, most commonly date text.
6. **Browser live capture is not fully manually verified:** the design and headless tests are strong, but a real browser `getUserMedia` test and end-to-end capture should be run before claiming field readiness.
7. **Browser refresh persistence still needs a manual acceptance check:** the server-side token flow, cookie adapter and revocation paths are covered by tests and a real MySQL smoke test, but a browser run should confirm cookie write/read behaviour on the intended Streamlit origin.
8. **Security is demo-grade:** default credentials, local image paths, no MFA, no managed secrets, no centralized audit retention and no formal security testing. The MySQL option and revocable refresh sessions improve persistence, but the cookie component is browser-script readable and still requires TLS, short token lifetimes and authenticated deployment. Browser WebRTC off localhost needs TLS; public STUN reveals client IP to that service.
9. **Operational scale work is outstanding:** no CI pipeline, container/deployment configuration, PostgreSQL/object storage, model/rule version persistence, monitoring, backup/retention policy or offline synchronization.
10. **Concrete CLI defect found:** `python extract_dataset_ocr.py --help` currently raises `ValueError: unsupported format character 't'` because its argparse help text includes literal percentages such as `38.7%`; argparse applies `%` interpolation. Escape those percent signs as `%%` or remove the percent formatting before release. The normal test suite still passes because it does not exercise rendered `--help` output.
11. **Working-tree state must be reviewed:** the checkout contains uncommitted local-camera/data-extraction additions and modified core files. The report reflects that current working tree, not only `HEAD`; the changes should be committed with the report after review.

## 8. Future scope and recommended sequence

### Phase 1 - establish trustworthy model evidence (highest priority)

1. Retrain YOLO on the repaired label dataset with `python train.py`.
2. Record post-repair overall and per-class precision, recall, mAP50/mAP50-95, missed-field and false-violation rates.
3. Build a held-out product-level test set with package diversity: food, household, personal care, import labels, glossy/curved/wrinkled packs, orientation, low-light and small print.
4. Add dedicated annotations for batch/lot, importer, packer, country of origin, unit sale price, dimensions and other high-value declarations.
5. Transcribe ground truth for the benchmark crop set and calculate CER plus acceptable field extraction accuracy.

### Phase 2 - improve inspection accuracy and human review

1. Capture difficult packs again using the focus/coverage guidance and quantify the improvement.
2. Add confidence-aware outcomes: `compliant`, `likely violation`, `manual review` and `not applicable`. Use RapidOCR confidence only after calibration validation.
3. Add Hindi and relevant regional scripts through a validated multilingual OCR configuration.
4. Add perspective/curl correction after capture-quality work is measured.
5. Track panel coverage across a session, not only within one frame.

### Phase 3 - complete compliance intelligence

1. Move rules, legal citations, effective dates, thresholds and exemptions into a versioned rules repository.
2. Introduce a short applicability questionnaire: commodity type, package type/size, imported goods, institutional/industrial supply and food/perishable status.
3. Implement country-of-origin, dimensions and mandatory unit-sale-price handling where applicable.
4. Develop calibrated image-based font-size and placement checks only after legal and measurement methodology review; preserve an `uncertain` outcome when image scale is unknowable.
5. Maintain separate LMPC and FSSAI advisory findings so legal bases and penalties are never conflated.

### Phase 4 - production-grade workflow and platform

1. Replace demo accounts with provisioned users, MFA, secret rotation, rate limits and least-privilege controls.
2. Move from local SQLite/image paths to PostgreSQL and controlled object storage, while retaining an offline edge queue where needed.
3. Store model version, OCR engine/version, preprocessing configuration, rule-set version, capture source and evidence manifest in every report/audit record.
4. Create signed report bundles containing PDF/DOCX/CSV/JSON, source evidence and hash manifest; define retention, access logging and chain-of-custody procedures.
5. Add CI that executes the test suite on every change, containerize the application and introduce structured logs, health checks, monitoring and backup/recovery drills.
6. Add scheduled cleanup/rotation for expired `SessionTokens`, session-age policy controls and security-event alerts.
7. Separate the inference service behind a typed API from the Streamlit demonstration UI, enabling a durable web/mobile officer client.

## 9. Suggested demonstration and verification plan

1. Start the application using `python -m streamlit run app.py`.
2. Log in as Inspector using demo credentials only in a local demonstration environment.
3. Capture/upload multiple panels of one package. Prefer a known, clear package for the first run; use live capture to demonstrate focus and missing-panel guidance.
4. Show the detection preview, checklist, raw OCR and diagnostics. Explain that confidence and candidate readings are shown rather than hidden.
5. Submit as `PENDING`, sign in as Verifier and approve or override with a written reason.
6. Sign in as Admin and show history, violation/failure analytics, user management and audit events.
7. State the current detector baseline honestly and demonstrate the next command is `python train.py`, not a claim of production model accuracy.
8. Before any public demo, change seeded passwords and confirm browser WebRTC on the intended HTTPS/localhost origin. For offline field use, demonstrate the local camera path without Internet.

## 10. Reproduction commands

```powershell
# Run the application
python -m streamlit run app.py

# Regression suite
python -m pytest tests -q

# Dataset integrity
python dataset_tools.py audit
python dataset_tools.py verify

# Train only after the repaired-data verification succeeds
python train.py

# Reproduce the 50-crop OCR benchmark baseline
python benchmark_ocr.py --limit 50 --split test
```

To run against XAMPP/MySQL instead of the default SQLite store, start MySQL in XAMPP and set the connection variables before launching Streamlit:

```powershell
$env:LMPC_DB_BACKEND = "mysql"
$env:LMPC_MYSQL_HOST = "127.0.0.1"
$env:LMPC_MYSQL_PORT = "3306"
$env:LMPC_MYSQL_DATABASE = "lmpc_scanner"
$env:LMPC_MYSQL_USER = "root"
$env:LMPC_MYSQL_PASSWORD = ""
python -m streamlit run app.py
```

The first connection creates and migrates the schema. For the full refresh, logout, account-deactivation and disposable-database smoke-test procedure, see [`docs/XAMPP_MYSQL_RBAC_SETUP.md`](XAMPP_MYSQL_RBAC_SETUP.md).

For the offline corpus extraction tool, repair the `--help` percent-sign defect first. Then use its required `--dataset_dir`, `--yolo_weights` and `--output_file` arguments, or its digest-only mode against an existing JSONL output.

## 11. Conclusion

SIH26034 has a credible, executable technical base: it is an explainable multi-panel packaging scanner rather than a superficial OCR demo. Its differentiators are sequential evidence aggregation, controlled OCR selection, measurable preprocessing, transparent candidate diagnostics, live capture guidance, role-aware verification and multiple report formats. The repository also demonstrates good engineering instincts by documenting data defects, preserving rejected OCR alternatives, retaining evidence hashes and fixing false-positive OCR normalization.

The route from hackathon prototype to deployable Legal Metrology decision support is clear. Retrain on repaired labels, measure against ground truth, version the legal rules, implement applicability and font/placement methodology, and harden the evidence/security platform. Until those steps are complete, the output should remain an Inspector/Verifier aid and never be represented as an automatic statutory enforcement decision.

## Sources reviewed

- Current code: `app.py`, `pipeline.py`, `preprocessing.py`, `ocr_backends.py`, `rule_engine.py`, `db.py`, `session_cookie.py`, `reporting.py`, `webrtc_live.py`, `local_camera.py`, `frame_sources.py`, `dataset_tools.py`, `extract_dataset_ocr.py`, `benchmark_ocr.py`, `train.py`, `debug_view.py`.
- Current tests: `tests/` (365 passing on 4 September 2026).
- Measurement artifacts: `runs/ocr_benchmark/summary_20260902_172024.json`, `runs/cap_sweep/summary_20260902_222221.json`, `runs/ocr_dataset.jsonl`, `runs/ocr_rapidocr.jsonl` and their digest exports.
- Repository documentation: `docs/SIH26034_PROJECT_REPORT.md`, `docs/ocr_improvement_plan.md`, `docs/dataset_label_repair.md`, `docs/webrtc_integration.md`, `docs/CHANGELOG.md`, `docs/detection_debug_view_spec.md` and `docs/XAMPP_MYSQL_RBAC_SETUP.md`.
- Official source checked on 4 September 2026: Department of Consumer Affairs, [Legal Metrology Act and Packaged Commodities Rules page](https://consumeraffairs.gov.in/pages/legal-metrology-act), [Rule 6 mandatory-information communication](https://consumeraffairs.gov.in/public/upload/admin/cmsfiles/whatsnews/Declaration_of_mandatory_information_on_the_outer_retail_package_-_reg._whatsnews.pdf), and the Department's listing of the 2025 amendment. Legal applicability must be re-validated against the consolidated current rules before enforcement use.

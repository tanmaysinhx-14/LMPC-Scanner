# SIH26034 Legal Metrology Compliance Scanner

An inspector-facing, edge-deployable decision-support system for checking declarations on pre-packaged commodities under the Legal Metrology (Packaged Commodities) Rules, 2011.

The scanner combines product-panel object detection, OCR, deterministic compliance rules, evidence hashing, role-based review, and downloadable reports. It is designed to help Legal Metrology officers collect and explain evidence; it does not issue autonomous legal notices or replace a human verifier.

## What the system does

1. Accepts multiple product-panel images, local camera input, or a browser camera stream.
2. Detects likely statutory declaration regions with a trained Ultralytics YOLO model.
3. Pads, deskews, rescales, and prepares each detected crop for OCR.
4. Compares plain, enhanced, and dot-matrix preprocessing variants and retains rejected candidates for diagnostics.
5. Reads text through a pluggable RapidOCR or EasyOCR backend.
6. Aggregates repeated readings across panels and evaluates the result with deterministic regex, date, unit, and fuzzy-text rules.
7. Presents color-coded detections, OCR confidence, parsing evidence, PASS/FAIL results, and advisory dietary-symbol classification.
8. Stores scan evidence, SHA-256 hashes, results, review decisions, and audit events.
9. Routes scans through Inspector, Verifier, and Admin workspaces.
10. Exports PDF, DOCX, CSV, and JSON reports.

## Current status

This is a working prototype for hackathon demonstration and technical validation. The current implementation includes upload-based sequential analysis, local and WebRTC capture, visual detection/OCR diagnostics, SQLite and MySQL persistence, RBAC, refresh-safe sessions, audit logging, multi-image aggregation, and report exports.

The system is not yet a legally authoritative enforcement system. Accuracy on small, reflective, curved, low-contrast, or dot-matrix packaging text; statutory applicability; deployment security; and field acceptance still require further validation. The served detector was trained before the repaired dataset labels were available, so retraining remains an important next step.

## Architecture

```text
Images / local camera / WebRTC browser camera
                         |
                         v
             Streamlit application (app.py)
                         |
       +-----------------+------------------+
       |                                    |
       v                                    v
 YOLO region detection              Evidence staging
       |                                    |
       v                                    v
 Crop padding, deskew, scaling       SHA-256 hashing
       |                                    |
       v                                    v
 RapidOCR or EasyOCR --------------> SQLite or MySQL
       |
       v
 Deterministic LMPC rule engine
       |
       v
 Inspector submission -> Verifier decision -> Admin audit/analytics
```

Live preview is intentionally lightweight: focus scoring and throttled detection run on incoming frames, while full OCR and compliance validation run only after capture. This keeps the camera responsive and limits memory pressure on edge hardware.

## Core capabilities

### Computer vision and OCR

- Seven detector regions: generic name, net quantity, MRP, date, manufacturer, consumer care, and dietary symbol.
- YOLO detection with configurable confidence, IoU, image size, and model path.
- Four-percent bounding-box padding before crop extraction.
- Text-height-driven scaling toward approximately 32-pixel strokes, capped at 6x.
- Deskewing up to 12 degrees.
- CLAHE-enhanced and grayscale dot-matrix variants for difficult print.
- Candidate selection using OCR confidence, rule-engine parse signal, and character yield:

  ```text
  score = 0.50 * OCR confidence
        + 0.35 * parse signal
        + 0.15 * character yield
  ```

- Detection preview labels show YOLO confidence, OCR confidence, mapped/unmapped state, and healing status.

### Compliance rules

The deterministic rule engine checks fields such as:

- Generic or common product name.
- Net quantity and standard SI units.
- MRP, currency, and the “inclusive of all taxes” declaration.
- Manufacture, packing, import, best-before, and use-by dates.
- Batch or lot identifiers.
- Manufacturer, packer, or importer details and Indian PIN code.
- Consumer-care telephone/email information.
- FSSAI registration number.
- Advisory vegetarian/non-vegetarian marking.

The rule engine is deliberately separate from the detector and OCR layer. OCR output is evidence for review, not a legal determination by itself.

### Role-based review

| Role | Responsibilities |
|---|---|
| Inspector | Capture or upload panels, analyze evidence, inspect diagnostics, submit scans as `PENDING`, and view permitted history |
| Verifier | Review pending scans, inspect evidence and rule explanations, approve, or override with a required written reason |
| Admin | View analytics and audit events, search scans, manage users, and activate/deactivate accounts |

Authorization is enforced in the database access layer as well as the Streamlit UI. Passwords use salted PBKDF2-HMAC-SHA256 hashing; session cookies contain opaque tokens, while validator hashes are persisted server-side.

## Repository layout

| Path | Purpose |
|---|---|
| `app.py` | Streamlit application, authentication, role workspaces, capture controls, diagnostics, and report downloads |
| `pipeline.py` | YOLO detection, crop preparation, OCR orchestration, candidate scoring, and multi-panel aggregation |
| `preprocessing.py` | Crop scaling, deskewing, enhancement, dot-matrix preparation, and dietary-symbol classification |
| `ocr_backends.py` | Lazy, thread-safe RapidOCR/EasyOCR backend abstraction and graceful fallback |
| `rule_engine.py` | Deterministic declaration parsing, compliance rules, and penalty estimates |
| `rule_config.py` | Versioned, admin-editable rule profile configuration |
| `db.py` | SQLite/MySQL schema bootstrap, persistence, RBAC, sessions, audit events, and reporting queries |
| `session_cookie.py` | Opaque refresh-safe Streamlit session cookie handling |
| `reporting.py` | In-memory PDF, DOCX, CSV, and JSON report generation |
| `local_camera.py`, `frame_sources.py` | Offline webcam, MJPEG, virtual-camera, and Android ADB frame sources |
| `webrtc_live.py` | Browser camera streaming, focus guidance, throttled detection, and sharp-frame capture |
| `debug_view.py` | Explainable detection overlay rendering |
| `dataset_tools.py` | Dataset audit, label repair, verification, and training-safety checks |
| `extract_dataset_ocr.py` | Resumable recursive OCR/detection JSONL exporter and diagnostics digest generation |
| `train.py` | Dataset verification and Ultralytics detector training entry point |
| `tests/` | Unit and regression tests for the pipeline, OCR, rules, cameras, database, sessions, and dataset tools |
| `docs/` | Architecture, requirements, persistence, UI, technology, report, and changelog documentation |
| `archives/` | Problem statement, historical design notes, research notes, and deployment runbooks |
| `runs/` | Detector outputs, evaluation artifacts, OCR exports, and benchmark results |

## Requirements

- Python 3.10 or newer is recommended.
- A Python environment able to install [`requirements.txt`](requirements.txt).
- CPU execution is supported for the OCR path; a compatible NVIDIA GPU improves YOLO/EasyOCR latency.
- Webcam/WebRTC use requires browser camera permissions and HTTPS or `localhost`.
- MySQL/XAMPP is needed only for centralized relational deployment; SQLite is suitable for offline edge use.
- The trained detector checkpoint expected by the application is `runs/detect/train/weights/best.pt`, overridable with `LMPC_MODEL_PATH`.

## Local setup

From the project root:

```powershell
python -m venv .venv
& .\.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip
python -m pip install -r requirements.txt
```

For a self-contained offline demonstration, select SQLite explicitly before starting Streamlit:

```powershell
$env:LMPC_DB_BACKEND = "sqlite"
streamlit run app.py
```

The application creates or migrates `lmpc_scanner.db` beside `db.py`. Keep this database local and do not commit it if it contains real inspection evidence or user data.

For a local XAMPP/MySQL backend, configure the variables before starting the application:

```powershell
$env:LMPC_DB_BACKEND = "mysql"
$env:LMPC_MYSQL_HOST = "127.0.0.1"
$env:LMPC_MYSQL_PORT = "3306"
$env:LMPC_MYSQL_DATABASE = "lmpc_scanner"
$env:LMPC_MYSQL_USER = "<database-user>"
$env:LMPC_MYSQL_PASSWORD = "<database-password>"
streamlit run app.py
```

The MySQL backend bootstraps the required schema through `db.py`. Do not expose MySQL port 3306 directly to the Internet.

## OCR backend selection

RapidOCR is the default backend and runs PP-OCR models through ONNX Runtime. EasyOCR is available as a GPU-oriented alternative:

```powershell
$env:LMPC_OCR_BACKEND = "rapidocr"
# or
$env:LMPC_OCR_BACKEND = "easyocr"
streamlit run app.py
```

The backend layer loads engines lazily, serializes inference calls, and degrades a failed crop without terminating the entire scan.

## Camera modes

The Inspector workspace supports:

- **Upload photographs** for one or more product panels.
- **Live camera (offline)** for local hardware, USB/MJPEG, virtual-camera, or Android ADB sources supported by `frame_sources.py`.
- **Live camera (browser/WebRTC)** through `streamlit-webrtc`.

The WebRTC mode requires `localhost` or HTTPS. Full OCR and rule evaluation are deferred until capture; the live overlay is intended for framing and panel-visibility guidance.

## Dataset and model workflow

The training and dataset utilities are separate from normal application startup.

```powershell
python dataset_tools.py verify
python dataset_tools.py repair
python train.py
```

Run verification before training. `train.py` uses the packaged-commodity dataset configuration, `yolo26n.pt` as the base checkpoint by default, 768px images, and its configured training defaults. Review the dataset repair report before replacing the checkpoint used by the application.

To export OCR diagnostics recursively through the live detector/OCR path:

```powershell
python extract_dataset_ocr.py --help
```

The exporter writes structured JSONL records, preserves relative image paths, records zero-detection and OCR-failure groups, and can select RapidOCR, EasyOCR, or Tesseract where the required native runtime is installed.

## Testing

Run the complete regression suite with:

```powershell
python -m pytest tests -q
```

The current working-tree verification completed with 381 passing tests. Test coverage includes rule parsing, preprocessing, OCR backend behavior, detection aggregation, camera/frame sources, WebRTC state handling, session cookies, SQLite/MySQL query paths, report generation, and dataset tooling.

For a production or field release, add physical-device camera tests, HTTPS/WebRTC tests, MySQL lifecycle tests against the target deployment, permission checks, evidence-retention review, and human verification acceptance testing.

## Data and security notes

- Use SQLite for isolated/offline demonstrations and MySQL only through a controlled private network.
- Do not commit `lmpc_scanner.db`, real evidence images, passwords, session tokens, database credentials, or exported reports containing personal data.
- Replace any development account passwords before sharing the application beyond a disposable local demo.
- Keep browser sessions on HTTPS in deployed environments; WebRTC camera capture should not be served over an untrusted connection.
- Review image retention, precise location metadata, audit-log retention, and access policy before field deployment.
- Treat detector/OCR output as review evidence. A low-confidence or missing reading is uncertainty, not proof that a declaration is absent.
- Review licenses and redistribution terms for model weights, datasets, OCR models, map or camera dependencies, and archived research material before publishing.

## Documentation

- [Problem statement and regulatory context](docs/problem-statement.md)
- [System architecture](docs/architecture.md)
- [Software requirements](docs/requirements.md)
- [Technology stack](docs/tech-stack.md)
- [Database and persistence architecture](docs/database.md)
- [UI and UX guidelines](docs/ui-guidelines.md)
- [Project status report](docs/report.md)
- [Changelog](docs/changelogs.md)
- [Historical RBAC and MySQL runbook](archives/rbac_setup.md)
- [Historical camera integration notes](archives/webrtc_integration.md)
- [Historical OCR improvement plan](archives/ocr_improvement_plan.md)
- [Dataset label repair notes](archives/dataset_label_repair.md)

## License

No license file is currently included. Add an explicit license before publishing this project if you want others to use, modify, or redistribute it.

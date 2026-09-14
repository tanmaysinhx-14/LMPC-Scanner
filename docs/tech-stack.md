# Tech Stack

The SIH26034 Legal Metrology Packaged Commodity Compliance Scanner is built using a highly optimized, edge-deployable artificial intelligence pipeline paired with a deterministic NLP rule engine[cite: 2, 5]. Below is the comprehensive technology stack powering the system, selected for low-latency field operation and accurate OCR extraction[cite: 5].

## Core Application & Frontend
* **Python**: The primary programming language utilized for the backend, machine learning pipeline, data processing, and rule engine[cite: 5, 8].
* **Streamlit**: Operates as the rapid-prototyping, web-based user interface framework, chosen for its accessibility to non-technical metrology inspectors[cite: 2, 5]. 
* **WebRTC (`streamlit-webrtc`, `aiortc`, `av`)**: Powers the real-time video streaming directly from the inspector's local webcam or mobile device to the Python backend[cite: 5, 9]. This circumvents the latency overhead of transmitting continuous HTTP POST requests[cite: 5].

## Computer Vision & Object Localization
* **Ultralytics YOLO (YOLOv8n)**: An anchor-free, lightweight object detection model used to isolate relevant statutory text regions (e.g., MRP, Net Quantity, Manufacturer details)[cite: 2, 5]. The YOLOv8n (nano) variant is explicitly chosen for its small footprint, executing inference rapidly on environments with less than 4GB of VRAM or standard multi-core CPUs[cite: 5].
* **OpenCV (`cv2`)**: Handles core image decoding without writing intermediates to disk, and applies critical adaptive preprocessing[cite: 6, 8]. It is used for mathematical deskewing (correcting up to 12 degrees of hand-held rotation) and scaling[cite: 8].
* **CLAHE (Contrast Limited Adaptive Histogram Equalization)**: Utilized via OpenCV to enhance character stroke definition and mitigate localized glare on glossy packaging[cite: 5, 8].
* **Morphological Operations**: A grayscale morphological close is applied specifically for dot-matrix text (MRP, dates, batch numbers) to join printed dots without destroying the characters[cite: 6, 8].

## Optical Character Recognition (OCR) Layer
The pipeline utilizes a pluggable OCR backend layer (`ocr_backends.py`) that scores and selects the most accurate output without hardcoding a specific engine[cite: 8].
* **RapidOCR (Default)**: Runs PP-OCR v4 weights through ONNX Runtime on the CPU[cite: 6, 8]. It is the default engine due to its calibrated confidence scores (~0.85) and superior field yield, enabling confidence-gated human review routing[cite: 6]. *(Note: Native PaddleOCR was dropped from the environment due to oneDNN executor crashes and import conflicts with PyTorch[cite: 6]).*
* **EasyOCR**: Built on PyTorch (CRAFT + CRNN), this engine is kept as a switchable alternative (`LMPC_OCR_BACKEND=easyocr`)[cite: 6, 8]. It is GPU-enabled (tested on an NVIDIA RTX 3050 6GB) and operates roughly 5x faster per crop than the CPU-bound RapidOCR, making it ideal when latency is the primary constraint[cite: 6, 8].

## Deterministic Rule Engine & NLP
The system does not rely on an LLM for final compliance judgments; instead, it uses a deterministic, explainable NLP rule engine[cite: 2, 8].
* **Regular Expressions (Regex)**: Used heavily to parse isolated numerical values, specific date strings (MM/YYYY), standard SI units, and standardized contact formats (emails, 10-digit phone numbers)[cite: 5, 8].
* **Levenshtein Distance**: A fuzzy string-matching algorithm deployed to compare localized text against strict statutory targets (e.g., evaluating if a damaged string matches "inclusive of all taxes")[cite: 5, 8]. 
* **Python `datetime`**: Used to parse and standardize the extracted date strings into computable timestamps[cite: 5, 8].

## Database & Data Persistence
The system incorporates a selectable relational database architecture accessed through a unified `db.py` API[cite: 1, 8].
* **SQLite (Offline Default)**: The default database (`lmpc_scanner.db`) operating in WAL (Write-Ahead Logging) mode with foreign keys, utilized for offline, local edge deployments[cite: 1, 8].
* **MySQL / XAMPP**: A selectable backend activated via the `LMPC_DB_BACKEND=mysql` environment variable, enabling centralized, multi-user deployments[cite: 1, 7]. The integration utilizes `mysql-connector-python` to bootstrap the schema (`Users`, `Scans`, `ScanResults`, `AuditEvents`, `SessionTokens`)[cite: 7, 8].

## Security, RBAC & Authentication
* **PBKDF2-HMAC-SHA256**: Secures user credentials in the database using salted hashing with 310,000 iterations[cite: 2, 8].
* **Server-Side Session Tokens**: Employs a revocable session architecture where only a validator hash (SHA-256) is persisted in the database[cite: 1, 7].
* **Browser Cookies (`streamlit-cookies-controller`)**: Writes opaque bearer tokens to an `lmpc_session` cookie to ensure Role-Based Access Control (RBAC) persists across browser refreshes[cite: 1, 7].

## Testing, Analytics & Reporting
* **Pytest**: Powers the robust, automated regression suite containing up to 365 tests (executing in ~16 seconds) covering rules, RBAC, database integrity, OCR degradation fallbacks, and the WebRTC processor[cite: 2, 7, 8].
* **ReportLab & Matplotlib**: Used to generate downloadable PDF summary and evidence reports dynamically in memory (ReportLab as primary, Matplotlib as the fallback renderer)[cite: 8].
* **Export Utilities**: Supports generating outputs in editable DOCX, CSV (for spreadsheets), and structured JSON for downstream platform integration[cite: 2, 8].
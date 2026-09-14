# System Architecture

The SIH26034 Legal Metrology Compliance Scanner employs an edge-deployable, cascaded machine learning architecture tailored for real-time field operations[cite: 5]. By combining anchor-free object detection, pluggable Optical Character Recognition (OCR), and a deterministic NLP rule engine, the system processes high-resolution physical packaging without requiring massive cloud compute infrastructure or suffering from high network latency[cite: 5].

## High-Level System Flow

The core data path is highly sequential[cite: 2, 8]. Instead of bulk-batching images—which would cause peak memory overloads on the target 6GB VRAM environment—the pipeline processes uploaded or captured product panels one at a time[cite: 2, 8]. 

The end-to-end processing pipeline operates as follows:
1. **Input**: An inspector provides images via bulk upload or through the live WebRTC browser camera[cite: 2].
2. **Decoding**: Images undergo sequential decoding and evidence staging[cite: 2].
3. **Detection**: The YOLO model detects regions, yielding bounding boxes and confidence scores[cite: 2].
4. **Crop Preparation**: The system extracts the region (adding a 4% padding margin), measures text height, deskews up to 12°, and scales the image toward a 32-pixel target (capped at 6x)[cite: 8].
5. **Variant Generation & OCR**: Three image variants (plain, enhanced, and dot-matrix) are generated and fed into the selected OCR backend[cite: 8].
6. **Candidate Scoring**: OCR outputs are scored using a weighted formula (`0.50 confidence + 0.35 parse signal + 0.15 character yield`) to pick the most legally viable reading[cite: 8].
7. **Aggregation**: Duplicate readings across multiple panels are merged and de-duplicated by canonical class[cite: 2, 8].
8. **Rule Engine**: The deterministic NLP engine extracts legal data, applies fuzzy matching, checks standard SI units, and assigns PASS/FAIL status along with prototype penalty estimates[cite: 2, 5, 8].
9. **Review & Persistence**: The scan result, YOLO/OCR diagnostics, and evidence hashes are persisted to the database (SQLite or MySQL) pending Verifier approval[cite: 2, 8].

## Live Capture & WebRTC Architecture

The live camera integration (`webrtc_live.py`) utilizes a robust multi-threaded approach to prevent blocking the main UI thread during heavy inference[cite: 5, 9]. 

* **Thread A (Video Receiver / aiortc worker thread)**: Continuously captures frames from the WebRTC stream at 30 FPS, storing the latest frame in a thread-safe buffer[cite: 5, 9].
* **Thread B (Throttled Inference Engine)**: 
  * A focus score (Laplacian variance over the center 60% of the frame) is calculated on every frame in less than 1 millisecond[cite: 9].
  * The YOLO detection pass (`detect_only()`) is heavily throttled, running only every 0.30 seconds (costing ~59 ms per run) to maintain a live overlay without lagging[cite: 9].
  * Heavy OCR and rule validation intentionally **do not** run live; they execute only upon frame capture[cite: 8, 9].
* **Thread C (Video Transmitter / Main Streamlit Thread)**: Reads the latest ML states (focus, panel counts, guidance hints) using thread-safe locks and overlays this HUD directly onto the video frame[cite: 5, 9]. 

When the user triggers a capture (manual or automatic), the system selects the sharpest frame from a rolling memory buffer of the last ~10 frames, encodes it as a JPEG (quality 95), and passes it to the sequential OCR pipeline[cite: 9].

## Machine Learning Pipeline (Detect-then-Read)

The core ML architecture operates on a "detect-then-read" principle, drastically reducing the noise passed to the OCR engine[cite: 7].

### 1. Object Localization (YOLOv8n)
* The pipeline utilizes YOLOv8n (nano), an anchor-free detection model[cite: 5].
* The decoupled head architecture separates classification from regression, yielding highly accurate bounding boxes around unpredictable text aspect ratios[cite: 5].
* The detector parses seven canonical regions (e.g., `mrp_region`, `date_region`, `manufacturer_region`) running dynamically at a default 0.20 confidence and `imgsz=768`[cite: 8].

### 2. Adaptive Preprocessing
Extracted regions are processed using OpenCV to normalize optical data[cite: 5]. 
* **CLAHE**: Contrast Limited Adaptive Histogram Equalization is applied to the LAB luminance channel to enhance strokes and mitigate glare[cite: 8].
* **Grayscale Morphological Close**: For dot-matrix text (dates, batch numbers, MRP), the system avoids destructive binarization, instead applying a grayscale close on inverted ink to join dots effectively[cite: 8].

### 3. Pluggable OCR Backend Layer
The `ocr_backends.py` wrapper isolates the OCR execution logic[cite: 8]. 
* It supports multiple engines (RapidOCR via ONNX Runtime and EasyOCR via PyTorch)[cite: 8].
* The layer loads engines lazily, serializes concurrent calls through thread locks (as the underlying engines are not thread-safe), and contains failures so a bad crop degrades gracefully instead of crashing the scan[cite: 8].

## Deterministic Rule Engine

The NLP rule engine (`rule_engine.py`) sits entirely decoupled from the machine learning models[cite: 5, 8]. 
* It operates deterministically using Regular Expressions (Regex) and Python's `datetime` parsers to isolate prices, dates, weights, and addresses[cite: 5, 8].
* A Levenshtein distance algorithm fuzzy-matches OCR output against strict statutory phrases (e.g., "inclusive of all taxes"), utilizing an 80% similarity threshold to overcome minor transcription errors[cite: 5].
* Custom healers fix common glyph confusions (e.g., `5OOml` to `500ml`) using strict protective token lists that ensure valid SI units (like `kg`) are not accidentally corrupted into keywords[cite: 8].

## Data Persistence & Access Control (RBAC)

The system relies on a hybrid data layer (`db.py`) supporting offline Edge (SQLite) and centralized (XAMPP/MySQL) deployments[cite: 1, 8].

* **Database Schema**: Includes highly normalized tables such as `Users`, `Scans`, `ScanResults`, `AuditEvents`, and `SessionTokens`[cite: 7, 8]. Evidence image paths are serialized into JSON arrays for multi-image scans, and images are hashed using SHA-256 upon submission[cite: 8].
* **Authentication & Refresh Persistence**: 
  * Passwords use salted PBKDF2-HMAC-SHA256 with 310,000 iterations[cite: 8].
  * Upon login, the server generates a random selector and validator[cite: 7]. 
  * Only the SHA-256 hash of the validator is saved in the `SessionTokens` table, while the opaque token is issued to the browser via the `lmpc_session` cookie (`streamlit-cookies-controller`)[cite: 7]. 
  * Streamlit reruns and browser refreshes automatically validate the cookie against the database, restoring the active user's role securely[cite: 7, 8].
* **Role Enforcement**: Authorization constraints are enforced at the database query level, preventing Inspectors from altering Verifier data or circumventing mandatory override-reason requirements[cite: 8].
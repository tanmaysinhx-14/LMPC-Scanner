# UI & UX Guidelines: SIH26034 Compliance Scanner

This document outlines the user interface and user experience guidelines for the Streamlit-based Legal Metrology compliance scanner[cite: 5, 8]. The design philosophy centers on **explainability**, **field-readiness**, and **role-segregation**, ensuring non-technical metrology inspectors can confidently operate the tool and defend its outputs[cite: 5, 8].

---

## 1. Design Philosophy & Explainability
* **No Black Boxes:** Never present a final compliance score without showing the work[cite: 8]. The UI must surface YOLO confidence, OCR confidence, rejected candidate readings, and parsing heuristics[cite: 1, 8]. 
* **Officer-Centric Design:** The interface targets government inspectors and verifiers, not consumer retail users[cite: 2, 5]. Design interactions around formal evidence collection, auditing, and report generation[cite: 2, 8].
* **State Persistence:** The UI must respect field conditions where browsers may accidentally refresh. Utilize server-side tokens and the `lmpc_session` cookie so a refresh restores the active user and role workspace without requiring password re-entry[cite: 1, 7].

---

## 2. Role-Based Workspaces
The application interface changes dynamically based on the authenticated user's role[cite: 2, 8]. Role checks are enforced at the database level, meaning UI elements for other roles are completely hidden[cite: 7, 8].

### 2.1 Inspector View
* **Capture Controls:** Must feature a primary toggle between *Upload photographs* (for bulk batch processing) and *Live camera (WebRTC)*[cite: 1, 8].
* **Evidence Tabs:** Post-analysis, the UI must render four distinct tabs:
  1. **Detection preview:** Shows the image with color-coded bounding boxes[cite: 2, 4].
  2. **Compliance checklist:** Displays extracted text, parsed fields, PASS/FAIL states, LMPC penalty estimates, and the advisory FSSAI dietary row[cite: 2, 8].
  3. **Raw OCR / Diagnostics:** Displays the raw aggregated text alongside a detailed table showing processing time, winning variants, and every rejected candidate reading[cite: 1, 2, 8].
  4. **Evidence and reports:** Provides thumbnails of captured panels and download buttons for PDF, DOCX, CSV, and JSON formats[cite: 2, 8].

### 2.2 Verifier View
* **Pending Queue:** Renders an expander list of `PENDING` scans fetched from the database[cite: 8].
* **Decision Controls:** Must display the evidence images alongside the extracted result table[cite: 8].
* **Override Logic:** If the Verifier chooses to `OVERRIDE` or reject a scan, the UI **must** require a mandatory written reason before allowing the state transition[cite: 2, 7, 8].

### 2.3 Admin View
* **Analytics Dashboards:** Must display top-level metrics including total scans, total violations, compliance failure rates, and potential penalty revenue[cite: 2, 8].
* **Data Grids:** Renders searchable tables for raw scan records, scan results, and system-wide immutable audit events[cite: 2, 8].
* **User Management:** Provides UI controls to create users and toggle active/inactive account statuses[cite: 2, 8].

---

## 3. Live Capture Guidance HUD (WebRTC)
The live camera mode operates as an augmented-reality HUD designed to maximize image capture quality, as physical resolution is the binding constraint on OCR accuracy[cite: 5, 8, 9]. 

* **Overlay Elements:** The HUD must be drawn directly into the video frame rather than relying on Streamlit widgets, ensuring the inspector can react without taking their eyes off the feed[cite: 9].
* **Dynamic Feedback Loop:** 
  * Displays the live focus score (Laplacian variance), current FPS, and the number of visible panels[cite: 9].
  * Bounding boxes update every 0.30 seconds to prevent rendering lag[cite: 9].
* **Plain-Language Hint Engine:** The UI must display a single, prioritized guidance message based on real-time conditions, ranked by severity[cite: 9]:
  1. `Detector unavailable: ...` (Critical failure)[cite: 9].
  2. `Very blurred` (Score is $< 60\%$ of the calibrated `SHARPNESS_FLOOR`)[cite: 9].
  3. `Slightly soft` (Score is below the `SHARPNESS_FLOOR`)[cite: 9].
  4. `No statutory panel found` / `Only N of 7 panels visible ... missing: [class names]` (Tells the inspector exactly how to rotate the package)[cite: 9].
  5. `Camera is streaming a low resolution`[cite: 9].
  6. `Good frame - capture now.`[cite: 9].
* **Capture Triggers:** The UI must provide a manual capture button (which allows capturing even low-quality evidence) and an optional auto-capture toggle (which fires automatically when focus floors and minimum panel counts are met, utilizing a 4-second cooldown)[cite: 8, 9].

---

## 4. Visual Diagnostics & Debug View
To prevent the model from feeling like a black box, the detection preview applies strict visual paradigms[cite: 4].

* **Color-Coded Bounding Boxes:** Detections mapped over the uploaded or captured image must adhere to this scale[cite: 4]:
  * **Green:** High YOLO confidence ($\ge 0.70$)[cite: 4].
  * **Orange:** Medium YOLO confidence ($0.40 \le \text{conf} < 0.70$)[cite: 4].
  * **Red:** Low YOLO confidence ($< 0.40$)[cite: 4].
  * **Gray:** Detected region but currently unmapped to a formal report field (e.g., `dietary_symbol_region`)[cite: 4].
* **Dual-Confidence Labels:** Each bounding box must display a label showing both the YOLO detection confidence and the OCR text recognition confidence (e.g., `mrp_region | yolo 0.85 | ocr 0.92`)[cite: 4].
* **Healed Indicators:** If the OCR text was passed through the dot-matrix healer, the label must visibly append `(healed)` to the HUD tag[cite: 4].

---

## 5. System Status & Error Handling
* **Database Backend Indicators:** The application sidebar must explicitly show the active database connection string (e.g., `MySQL 127.0.0.1:3306/lmpc_scanner`)[cite: 7].
* **Graceful Degradation:** If MySQL is unavailable, the login screen must not feign functionality; it must display the exact connection error and instruct the user on how to resolve the connection or fallback to SQLite[cite: 7].